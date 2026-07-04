<?php

declare(strict_types=1);

/**
 * Tiny JSON response helper for the REST API.
 */
final class Response
{
    /**
     * Emit a JSON payload with the given HTTP status and stop execution.
     *
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * @param mixed $data
     */
    public static function success(mixed $data, int $status = 200): never
    {
        self::json(['status' => 'success', 'data' => $data], $status);
    }

    /**
     * @param array<int, string> $errors
     */
    public static function error(string $message, int $status = 400, array $errors = []): never
    {
        $payload = ['status' => 'error', 'message' => $message];
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }
        self::json($payload, $status);
    }
}
