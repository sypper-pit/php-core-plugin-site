<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    echo "CLI only\n";
    exit(1);
}

define('APP_ROOT', __DIR__);
require_once APP_ROOT . '/libs/autoload.php';

use Core\Files;

$command = $argv[1] ?? 'help';

switch ($command) {
    case 'audit':
        echo "Running security audit...\n";
        $violations = 0;

        $forbiddenFuncs = [
            'ev' . 'al(',
            'ex' . 'ec(',
            'shell_' . 'exec(',
            'sys' . 'tem(',
            'pass' . 'thru(',
            'proc_' . 'open(',
            'pop' . 'en(',
            'create_' . 'function('
        ];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT));
        foreach ($files as $file) {
            if ($file->isDir()) continue;
            $path = $file->getPathname();
            if (basename($path) === 'cli.php' || str_contains($path, '/plugins/store/') || str_contains($path, '/libs/sleekdb/') || str_contains($path, '/.git/')) {
                continue;
            }
            if ($file->getExtension() === 'php') {
                $content = file_get_contents($path);
                if (str_contains($content, 'use SleekDB\\') || str_contains($content, 'new Store(')) {
                    echo "[VIOLATION] Direct SleekDB usage in: {$path}\n";
                    $violations++;
                }
                if (!str_contains($content, "defined('APP_ROOT')") && basename($path) !== 'index.php') {
                    echo "[VIOLATION] Missing APP_ROOT guard in: {$path}\n";
                    $violations++;
                }
                foreach ($forbiddenFuncs as $func) {
                    if (str_contains($content, $func)) {
                        echo "[VIOLATION] Forbidden function pattern in: {$path}\n";
                        $violations++;
                    }
                }
            }
        }

        if ($violations > 0) {
            echo "Audit failed with {$violations} violations!\n";
            exit(1);
        }

        echo "Audit passed cleanly!\n";
        exit(0);

    case 'verify-assets':
        echo "Verifying asset checksums...\n";
        $sumsFile = APP_ROOT . '/libs/vendor-js/SHA256SUMS';
        if (!file_exists($sumsFile)) {
            echo "SHA256SUMS file missing.\n";
            exit(1);
        }
        $lines = file($sumsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 2) continue;
            [$expectedHash, $file] = $parts;
            $fullPath = APP_ROOT . '/' . ltrim($file, '/');
            if (!file_exists($fullPath)) {
                echo "Asset missing: {$file}\n";
                exit(1);
            }
            $actualHash = hash_file('sha256', $fullPath);
            if (!hash_equals($expectedHash, $actualHash)) {
                echo "Hash mismatch for {$file}: expected {$expectedHash}, got {$actualHash}\n";
                exit(1);
            }
        }
        echo "Asset integrity verified!\n";
        exit(0);

    case 'setup-token':
        $token = bin2hex(random_bytes(16));
        Files::writeTextConfig('setup-token.txt', $token);
        echo "Generated Setup Token: {$token}\n";
        exit(0);

    case 'vault-init':
        $masterPass = $argv[2] ?? null;
        if (!$masterPass || strlen($masterPass) < 12) {
            echo "Usage: php cli.php vault-init <masterPassword >= 12 chars>\n";
            exit(1);
        }

        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE;
        $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE;

        $kek = sodium_crypto_pwhash(
            32,
            $masterPass,
            $salt,
            $ops,
            $mem,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );

        $dek = sodium_crypto_secretbox_keygen();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $wrappedDek = sodium_crypto_secretbox($dek, $nonce, $kek);

        Files::writeConfig('vault-meta.json', [
            'salt' => base64_encode($salt),
            'ops' => $ops,
            'mem' => $mem,
            'wrapped_dek' => base64_encode($nonce . $wrappedDek)
        ]);

        echo "Server Vault initialized successfully!\n";
        exit(0);

    default:
        echo "Available commands: audit, verify-assets, setup-token, vault-init\n";
        exit(0);
}
