<?php
declare(strict_types=1);
namespace WHCSystem\Module;

/** A connector's JWT is cached server-side, encrypted, to avoid repeated login throttling. */
final class TokenCache
{
    public static function remember(string $identity, string $secret, \Closure $login, bool $force = false): array
    {
        if (!function_exists('openssl_encrypt')) throw new ApiError(503,'OPENSSL_UNAVAILABLE');
        $directory = sys_get_temp_dir() . '/whcsystem-v7-' . substr(hash('sha256',__DIR__),0,16);
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new ApiError(503,'TOKEN_CACHE_UNAVAILABLE');
        @chmod($directory,0700);
        $file = $directory . '/' . hash('sha256',$identity) . '.cache';
        $handle = fopen($file,'c+b');
        if (!$handle) throw new ApiError(503,'TOKEN_CACHE_UNAVAILABLE');
        @chmod($file,0600);
        $key = hash('sha256',$secret . "\0" . $identity,true);
        try {
            if (!flock($handle,LOCK_EX)) throw new ApiError(503,'TOKEN_CACHE_UNAVAILABLE');
            $raw = stream_get_contents($handle,1048576);
            $cached = null;
            if ($raw) {
                $parts = explode('.', $raw);
                if (count($parts)===3) {
                    $iv=base64_decode($parts[0],true);$tag=base64_decode($parts[1],true);$body=base64_decode($parts[2],true);
                    if (is_string($iv)&&strlen($iv)===12&&is_string($tag)&&strlen($tag)===16&&is_string($body)) {
                        $plain=openssl_decrypt($body,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$identity);
                        if ($plain!==false) $cached=json_decode($plain,true);
                    }
                }
            }
            if (!$force&&is_array($cached)&&is_string($cached['token']??null)&&($cached['expiresAt']??0)>time()+20) return $cached;
            $fresh=$login();
            $iv=random_bytes(12);$tag='';
            $encrypted=openssl_encrypt(json_encode($fresh,JSON_THROW_ON_ERROR),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$identity);
            if ($encrypted===false) throw new ApiError(503,'TOKEN_CACHE_UNAVAILABLE');
            $encoded=base64_encode($iv).'.'.base64_encode($tag).'.'.base64_encode($encrypted);
            rewind($handle);
            if (!ftruncate($handle,0)||fwrite($handle,$encoded)!==strlen($encoded)||!fflush($handle)) throw new ApiError(503,'TOKEN_CACHE_UNAVAILABLE');
            return $fresh;
        } finally { flock($handle,LOCK_UN); fclose($handle); }
    }
}
