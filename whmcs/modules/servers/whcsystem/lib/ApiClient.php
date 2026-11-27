<?php
declare(strict_types=1);

namespace WHCSystem\Module;

require_once __DIR__ . '/TokenCache.php';

final class ApiError extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, public readonly ?string $traceId = null)
    {
        parent::__construct($errorCode);
    }
}

/** A server-side client for the verified WHCSystemAPI v3 JWT contract. */
final class ApiClient
{
    private string $base;
    private ?string $accessToken = null;
    private int $expiresAt = 0;
    private readonly \Closure $transport;
    private readonly bool $cacheEnabled;

    public function __construct(string $endpoint, private readonly string $email, private readonly string $password, ?\Closure $transport = null)
    {
        $parts = parse_url($endpoint);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('An HTTPS customer API address is required');
        }
        $apiPath = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($apiPath !== '' && $apiPath !== '/whcapi/v3') throw new \InvalidArgumentException('API path must be /whcapi/v3');
        $this->base = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . '/whcapi/v3';
        $this->transport = $transport ?? self::curlTransport(...);
        $this->cacheEnabled = $transport === null;
    }

    public function request(string $method, string $path, ?array $body = null, ?string $idempotency = null): array
    {
        if (!preg_match('~^/(?:whmcs|wisecp)(?:/[^\r\n#]*)?$~D', $path)) throw new \InvalidArgumentException('Module API path is required');
        $this->authenticate();
        $headers = ['Authorization: Bearer ' . $this->accessToken];
        if ($idempotency !== null) {
            if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $idempotency)) throw new \InvalidArgumentException('Invalid idempotency key');
            $headers[] = 'Idempotency-Key: ' . $idempotency;
        }
        try { return $this->send($method, $path, $body, $headers); }
        catch (ApiError $error) {
            if ($error->status !== 401) throw $error;
            $this->accessToken = null;
            $this->authenticate(true);
            $headers[0] = 'Authorization: Bearer ' . $this->accessToken;
            return $this->send($method, $path, $body, $headers);
        }
    }

    private function authenticate(bool $force = false): void
    {
        if (!$force && $this->accessToken !== null && $this->expiresAt > time() + 10) return;
        $login = function (): array {
            $response = $this->send('POST', '/auth/login', ['email' => $this->email, 'password' => $this->password, 'remember' => false], []);
            $data = $response['data'] ?? $response;
            if (!is_string($data['accessToken'] ?? null) || !is_numeric($data['expiresIn'] ?? null)) throw new ApiError(502, 'PROVIDER_RESPONSE_INVALID');
            return ['token'=>$data['accessToken'],'expiresAt'=>time()+(int) $data['expiresIn']];
        };
        $token = $this->cacheEnabled ? TokenCache::remember($this->base . "\0" . $this->email,$this->password,$login,$force) : $login();
        $this->accessToken = $token['token'];
        $this->expiresAt = $token['expiresAt'];
    }

    private function send(string $method, string $path, ?array $body, array $headers): array
    {
        $result = ($this->transport)($method, $this->base . $path, $body, $headers);
        $status = (int) ($result['status'] ?? 502);
        try { $payload = json_decode((string) ($result['body'] ?? ''), true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ApiError(502, 'PROVIDER_RESPONSE_INVALID'); }
        if (!is_array($payload)) throw new ApiError(502, 'PROVIDER_RESPONSE_INVALID');
        if ($status < 200 || $status >= 300) throw new ApiError($status,
            (string) ($payload['code'] ?? $payload['error']['code'] ?? 'API_REQUEST_FAILED'),
            isset($payload['traceId']) ? (string) $payload['traceId'] : null);
        return $payload;
    }

    private static function curlTransport(string $method, string $url, ?array $body, array $headers): array
    {
        if (!function_exists('curl_init')) throw new ApiError(503, 'CURL_UNAVAILABLE');
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers)]);
        if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        $raw = curl_exec($curl);
        if ($raw === false) {
            $timeout = curl_errno($curl) === CURLE_OPERATION_TIMEDOUT;
            curl_close($curl);
            throw new ApiError($timeout ? 504 : 503, $timeout ? 'PROVIDER_TIMEOUT' : 'PROVIDER_UNAVAILABLE');
        }
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return ['status' => $status, 'body' => $raw];
    }
}
