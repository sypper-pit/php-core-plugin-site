<?php
declare(strict_types=1);

namespace Core;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class VaultLockedException extends \RuntimeException {}

class Vault
{
    private static ?string $dek = null;
    private static ?int $expiresAt = null;

    public static function isUnlocked(): bool
    {
        if (self::$dek === null) {
            self::loadCachedDek();
        }
        if (self::$dek !== null && self::$expiresAt !== null && time() >= self::$expiresAt) {
            self::lock();
        }
        return self::$dek !== null;
    }

    public static function unlock(Context $context, string $masterPassword): bool
    {
        if (!RateLimit::check($context, 'vault_unlock', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', 5, 900)) {
            throw new VaultLockedException('Too many failed vault unlock attempts. Locked for 15 minutes.');
        }

        $meta = Files::readConfig('vault-meta.json');
        if (!$meta) {
            throw new \RuntimeException('Vault meta configuration not found');
        }

        $salt = base64_decode($meta['salt']);
        $ops = $meta['ops'] ?? SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE;
        $mem = $meta['mem'] ?? SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE;

        $kek = sodium_crypto_pwhash(
            32,
            $masterPassword,
            $salt,
            $ops,
            $mem,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );

        $encDek = base64_decode($meta['wrapped_dek']);
        $nonce = substr($encDek, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($encDek, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $dek = sodium_crypto_secretbox_open($ciphertext, $nonce, $kek);
        sodium_memzero($kek);

        if ($dek === false) {
            return false;
        }

        self::$dek = $dek;
        self::$expiresAt = time() + 900; // 15 mins
        self::cacheDek($dek, self::$expiresAt);

        return true;
    }

    public static function lock(): void
    {
        if (self::$dek !== null) {
            sodium_memzero(self::$dek);
            self::$dek = null;
        }
        self::$expiresAt = null;
        self::clearCachedDek();
    }

    public static function encrypt(string $plaintext): string
    {
        if (!self::isUnlocked() || self::$dek === null) {
            throw new VaultLockedException('Vault is locked.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, self::$dek);
        return base64_encode($nonce . $ciphertext);
    }

    public static function decrypt(string $payload): string
    {
        if (!self::isUnlocked() || self::$dek === null) {
            throw new VaultLockedException('Vault is locked.');
        }
        $raw = base64_decode($payload);
        if (strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \InvalidArgumentException('Invalid encrypted payload');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, self::$dek);
        if ($plaintext === false) {
            throw new \RuntimeException('Vault decryption failed');
        }
        return $plaintext;
    }

    private static function cacheDek(string $dek, int $expiresAt): void
    {
        $dir = APP_ROOT . '/data/cache/';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $cacheFile = $dir . 'vault.cache';
        $procKey = hash('sha256', __DIR__ . (string)getmygid());
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $data = json_encode(['dek' => base64_encode($dek), 'exp' => $expiresAt]);
        $enc = sodium_crypto_secretbox($data, $nonce, hex2bin($procKey));
        Files::atomicWrite($cacheFile, base64_encode($nonce . $enc));
    }

    private static function loadCachedDek(): void
    {
        $cacheFile = APP_ROOT . '/data/cache/vault.cache';
        if (!file_exists($cacheFile)) {
            return;
        }
        $raw = base64_decode((string)file_get_contents($cacheFile));
        if (strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return;
        }
        $procKey = hash('sha256', __DIR__ . (string)getmygid());
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $enc = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $data = sodium_crypto_secretbox_open($enc, $nonce, hex2bin($procKey));
        if ($data === false) {
            return;
        }
        $decoded = json_decode($data, true);
        if (!is_array($decoded) || ($decoded['exp'] ?? 0) <= time()) {
            self::clearCachedDek();
            return;
        }
        self::$dek = base64_decode($decoded['dek']);
        self::$expiresAt = (int)$decoded['exp'];
    }

    private static function clearCachedDek(): void
    {
        $cacheFile = APP_ROOT . '/data/cache/vault.cache';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }
    }
}
