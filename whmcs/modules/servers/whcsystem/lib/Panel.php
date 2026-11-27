<?php
declare(strict_types=1);

namespace WHCSystem\Module;

require_once __DIR__ . '/ApiClient.php';

final class Panel
{
    public const VERSION = '7.0.0';
    public const PAGES = ['overview','access','console','reinstall','installprogress','ipv4','ipv6','ipattach',
        'rdns','rdnsadd','rdnsedit','dns','dnsadd','dnsedit','backup','backupadd','restore','monitor','logs',
        'logdetail','disk','power','deleteconfirm','settings','mapping','adminservice','unavailable',
        'dnsdelete','backupdelete','start','stop','restart'];
    private const ADMIN_PAGES = ['settings','mapping','adminservice'];

    public function __construct(private readonly ApiClient $api, private readonly string $platform,
        private readonly string $serviceId, private readonly string $customerId, private readonly bool $active)
    {
        if (!in_array($platform, ['whmcs','wisecp'], true)) throw new \InvalidArgumentException('Unknown platform');
        foreach ([$serviceId, $customerId] as $id) if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id)) throw new \InvalidArgumentException('Invalid identity');
    }

    public static function page(string $page, bool $admin = false): string
    {
        if (!in_array($page, self::PAGES, true) || (!$admin && in_array($page, self::ADMIN_PAGES, true))) return 'unavailable';
        return $page;
    }

    public static function csrf(string $platform, string $serviceId): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) throw new \RuntimeException('An authenticated platform session is required');
        $key = 'whcsystem_' . $platform . '_' . $serviceId;
        if (!isset($_SESSION[$key])) $_SESSION[$key] = bin2hex(random_bytes(32));
        return (string) $_SESSION[$key];
    }

    public function read(string $resource, array $query = []): array
    {
        $suffix = match ($resource) {
            'overview' => '', 'disks' => '/disks', 'nics' => '/nics', 'snapshots' => '/snapshots', 'operations' => '/operations',
            'metrics' => '/metrics', 'templates'=>'/templates', 'operation' => '/operations/' . self::identifier($query['id'] ?? ''),
            'network'=>'/network','rdns'=>'/rdns',
            default => throw new ApiError(422, 'PROVIDER_CAPABILITY_NOT_SUPPORTED'),
        };
        $params = ['customerId' => $this->customerId];
        if ($resource === 'metrics') {
            $range = (string) ($query['range'] ?? '1h');
            if (!in_array($range, ['1h','6h','24h','7d','30d'], true)) throw new ApiError(400, 'VALIDATION_FAILED');
            $params['range'] = $range;
        }
        return $this->api->request('GET', $this->base() . $suffix . '?' . http_build_query($params));
    }

    public function mutate(string $action, array $data, string $csrf, string $key): array
    {
        if (!hash_equals(self::csrf($this->platform, $this->serviceId), $csrf)) throw new ApiError(403, 'CSRF_INVALID');
        if (!$this->active) throw new ApiError(403, 'SERVICE_INACTIVE');
        $method = 'POST'; $body = []; $confirm = ($data['confirm'] ?? false) === true;
        switch ($action) {
            case 'power':
                $verb = (string) ($data['action'] ?? '');
                if (!$confirm || !in_array($verb, ['start','stop','shutdown','reboot','reset'], true)) throw new ApiError(400, 'VALIDATION_FAILED');
                $suffix = '/power'; $body = ['action' => $verb, 'confirm' => true,'reason'=>substr((string)($data['reason']??''),0,500)]; break;
            case 'password':
                $password = (string) ($data['password'] ?? '');
                if (strlen($password) < 12 || strlen($password) > 128 || !hash_equals($password, (string) ($data['passwordConfirmation'] ?? ''))) throw new ApiError(400, 'VALIDATION_FAILED');
                $username = (string) ($data['username'] ?? 'root');
                if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', $username)) throw new ApiError(400, 'VALIDATION_FAILED');
                $suffix = '/password'; $body = ['username' => $username, 'password' => $password]; break;
            case 'snapshot-create':
                $name = trim((string) ($data['name'] ?? ''));
                if ($name === '' || strlen($name) > 80 || preg_match('/[\r\n\t]/', $name)) throw new ApiError(400, 'VALIDATION_FAILED');
                if (($data['kind'] ?? 'snapshot') !== 'snapshot') throw new ApiError(422, 'PROVIDER_CAPABILITY_NOT_SUPPORTED');
                $suffix = '/snapshots'; $body = ['name' => $name, 'description' => substr((string) ($data['description'] ?? ''), 0, 500),
                    'includeMemory' => ($data['includeMemory']??false)===true, 'quiesce' => ($data['quiesce']??false)===true]; break;
            case 'snapshot-revert': case 'snapshot-delete':
                if (!$confirm) throw new ApiError(400, 'CONFIRMATION_REQUIRED');
                $suffix = '/snapshots/' . self::identifier($data['snapshotId'] ?? '') . ($action === 'snapshot-revert' ? '/revert' : '');
                if ($action === 'snapshot-revert') {
                    $name = (string) ($this->read('overview')['data']['name'] ?? '');
                    if ($name === '' || ($data['confirmation'] ?? '') !== $name) throw new ApiError(400, 'CONFIRMATION_REQUIRED');
                } else {
                    self::confirmDeletion($data);
                }
                $method = $action === 'snapshot-delete' ? 'DELETE' : 'POST'; break;
            case 'disk':
                if (!$confirm || !is_numeric($data['sizeGb'] ?? null) || (float) $data['sizeGb'] < 1) throw new ApiError(400, 'VALIDATION_FAILED');
                $suffix = '/disk'; $body = ['diskId' => (string) ($data['diskId'] ?? ''), 'sizeBytes' => (int) round((float) $data['sizeGb'] * 1073741824), 'confirm' => true]; break;
            case 'console': $suffix = '/console'; $body=['origin'=>(string)($_SERVER['HTTP_ORIGIN']??'')];break;
            case 'rdns-create':
                $suffix='/rdns';$body=['ipAddress'=>(string)($data['ipAddress']??''),'hostname'=>(string)($data['hostname']??''),'ttl'=>(int)($data['ttl']??3600)];break;
            case 'rdns-update':
                $method='PUT';$suffix='/rdns/'.self::identifier($data['recordId']??'');$body=['hostname'=>(string)($data['hostname']??''),'ttl'=>(int)($data['ttl']??3600)];break;
            case 'rdns-delete':
                if(!$confirm)throw new ApiError(400,'CONFIRMATION_REQUIRED');
                self::confirmDeletion($data);
                $method='DELETE';$suffix='/rdns/'.self::identifier($data['recordId']??'');break;
            case 'reinstall':
                if(!$confirm)throw new ApiError(400,'CONFIRMATION_REQUIRED');
                $suffix='/reinstall';$body=['templateId'=>(string)($data['templateId']??''),'username'=>(string)($data['username']??'root'),
                    'password'=>($data['password']??'')!==''?(string)$data['password']:null,'confirmation'=>(string)($data['confirmation']??''),'confirm'=>true];
                if(!empty($data['sshPublicKey']))$body['sshPublicKey']=(string)$data['sshPublicKey'];
                break;
            default: throw new ApiError(422, 'PROVIDER_CAPABILITY_NOT_SUPPORTED');
        }
        $query = ['customerId' => $this->customerId];
        if ($confirm) $query['confirm'] = 'true';
        return $this->api->request($method, $this->base() . $suffix . '?' . http_build_query($query), $body, $key);
    }

    public static function render(string $platform, string $page, array $config, bool $admin = false): string
    {
        $page = self::page($page, $admin);
        if (!in_array($platform, ['whmcs','wisecp'], true)) throw new \InvalidArgumentException('Unknown platform');
        $file = __DIR__ . '/views/' . $platform . '/' . $page . '.json';
        if (!is_file($file)) throw new \RuntimeException('The Figma view has not been compiled: ' . $page);
        $config['screen'] = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        $config['platform'] = $platform; $config['page'] = $page; $config['version'] = self::VERSION;
        $language = strtolower((string) ($config['language'] ?? 'tr'));
        if (!in_array($language, ['tr','en','de'], true)) $language = 'en';
        $config['language'] = $language;
        $catalog=__DIR__.'/locale/catalog.json';
        if(is_file($catalog))$config['translations']=json_decode((string)file_get_contents($catalog),true,64,JSON_THROW_ON_ERROR);
        $asset = htmlspecialchars(rtrim((string) $config['assetBase'], '/'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $json = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        return '<link rel="stylesheet" href="' . $asset . '/panel.css?v=' . self::VERSION . '">'
            . '<link rel="stylesheet" href="' . $asset . '/design.css?v=' . self::VERSION . '">'
            . '<div class="whc-module" lang="' . $language . '"><script type="application/json" class="whc-config">' . $json . '</script></div>'
            . '<script src="' . $asset . '/panel.js?v=' . self::VERSION . '" defer></script>';
    }

    private function base(): string { return '/' . $this->platform . '/services/' . rawurlencode($this->serviceId); }
    private static function confirmDeletion(array $data): void
    {
        if (!in_array($data['confirmation'] ?? '', ['SİL','DELETE','LÖSCHEN'], true)) throw new ApiError(400, 'CONFIRMATION_REQUIRED');
    }
    private static function identifier(mixed $value): string
    {
        $value = (string) $value;
        if ($value === '' || strlen($value) > 256 || preg_match('/[\x00-\x1f]/', $value)) throw new ApiError(400, 'VALIDATION_FAILED');
        return rawurlencode($value);
    }
}
