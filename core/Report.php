<?php
declare(strict_types=1);

namespace Core;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class Report
{
    private static ?Context $context = null;
    private static bool $isLogging = false;

    public static function setContext(Context $context): void
    {
        self::$context = $context;
    }

    public static function sanitizeMessage(string $msg): string
    {
        // Strip sensitive strings/patterns
        $msg = preg_replace('/(?i)(password|master_password|token|key|secret|authorization)["\']?\s*[:=]\s*["\']?[^"\'\s,;]+/', '$1: [REDACTED]', $msg);
        // Mask long base64 chunks
        $msg = preg_replace('/[A-Za-z0-9+\/]{64,}={0,2}/', '[BASE64_DATA]', $msg);
        return $msg;
    }

    public static function logError(string $message, array $contextData = []): string
    {
        $incidentId = bin2hex(random_bytes(8));
        $sanitized = self::sanitizeMessage($message);

        $logEntry = [
            'incidentId' => $incidentId,
            'time' => date('c'),
            'message' => $sanitized,
            'caller' => self::$context ? self::$context->caller : 'unknown',
            'userId' => self::$context ? self::$context->viewer->userId : null,
        ];

        if (self::$context !== null && !self::$isLogging) {
            self::$isLogging = true;
            try {
                self::$context->gql('mutation($ns: String!, $data: JSON!) { store_insert(ns: $ns, data: $data) }', [
                    'ns' => 'core.errors',
                    'data' => $logEntry
                ]);
            } catch (\Throwable $e) {
                error_log("Failed to log error to store: " . $e->getMessage());
            } finally {
                self::$isLogging = false;
            }
        } else {
            error_log("Error logged: " . $sanitized);
        }

        return $incidentId;
    }
}
