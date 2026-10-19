<?php
declare(strict_types=1);

namespace Core;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class Security
{
    private static ?string $nonce = null;

    public static function getCspNonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = base64_encode(random_bytes(16));
        }
        return self::$nonce;
    }

    public static function setSecurityHeaders(): void
    {
        header_remove('X-Powered-By');

        $nonce = self::getCspNonce();
        $csp = "default-src 'none'; " .
               "script-src 'self' 'nonce-{$nonce}' 'wasm-unsafe-eval'; " .
               "style-src 'self' 'nonce-{$nonce}'; " .
               "img-src 'self' blob: data:; " .
               "font-src 'self'; " .
               "connect-src 'self'; " .
               "form-action 'self'; " .
               "base-uri 'none'; " .
               "frame-ancestors 'none'; " .
               "object-src 'none'";

        header("Content-Security-Policy: " . $csp);
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: DENY");
        header("Referrer-Policy: no-referrer");
        header("Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()");
        header("Cross-Origin-Opener-Policy: same-origin");
        header("Cross-Origin-Resource-Policy: same-origin");

        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
        }
    }

    public static function validateHost(array $allowedHosts): bool
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (empty($host)) {
            return false;
        }
        return in_array($host, $allowedHosts, true);
    }

    public static function validateCsrfToken(?string $token, string $sessionSecret): bool
    {
        if (empty($token) || empty($sessionSecret)) {
            return false;
        }
        return hash_equals($sessionSecret, $token);
    }

    public static function sanitizeString(string $input): string
    {
        if (!mb_check_encoding($input, 'UTF-8')) {
            return '';
        }
        // Remove control characters except tab, CR, LF
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input);
    }
}
