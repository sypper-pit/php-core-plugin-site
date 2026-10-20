<?php
declare(strict_types=1);

namespace Core;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class E2ee
{
    public static function validateEnvelope(array $env): bool
    {
        if (($env['v'] ?? 0) !== 1) {
            return false;
        }
        if (($env['alg'] ?? '') !== 'xchacha20poly1305-ietf+secretstream') {
            return false;
        }
        $kdf = $env['kdf'] ?? [];
        if (($kdf['name'] ?? '') !== 'argon2id13') {
            return false;
        }
        if (empty($kdf['salt']) || empty($env['wrap_dek']['nonce']) || empty($env['wrap_dek']['ct'])) {
            return false;
        }
        return true;
    }

    public static function validateFileInput(array $file): bool
    {
        if (($file['v'] ?? 0) !== 1) {
            return false;
        }
        if (empty($file['wrap_fek']['nonce']) || empty($file['wrap_fek']['ct'])) {
            return false;
        }
        if (empty($file['meta']['nonce']) || empty($file['meta']['ct'])) {
            return false;
        }
        if (empty($file['header']) || empty($file['blob'])) {
            return false;
        }
        $blobSize = strlen(base64_decode($file['blob']));
        if ($blobSize > 3 * 1024 * 1024) { // 3MB limit
            return false;
        }
        return true;
    }
}
