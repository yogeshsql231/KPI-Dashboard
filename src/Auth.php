<?php

declare(strict_types=1);

/**
 * Reusable API authentication guard.
 */
final class Auth
{
    /**
     * Require a valid shared-secret API key (X-API-Key header).
     *
     * When no key is configured (local dev), the check is silently skipped.
     */
    public static function requireApiKey(): void
    {
        $expected = env('API_KEY');
        if ($expected === null || $expected === '') {
            return;
        }
        $provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (!is_string($provided) || !hash_equals((string) $expected, $provided)) {
            Response::error('Unauthorized.', 401);
        }
    }
}
