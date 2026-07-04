<?php

declare(strict_types=1);

/**
 * Shared HTML / formatting helpers for server-rendered views.
 */
final class ViewHelper
{
    /** HTML-escape a value for safe output. */
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /** Format a 0..1 ratio as a percentage string. */
    public static function pct(mixed $v, int $dp = 2): string
    {
        if ($v === null || $v === '') {
            return "\u{2014}";
        }
        return number_format(((float) $v) * 100, $dp) . '%';
    }

    /** Format a number with thousands separators (em-dash when null). */
    public static function num(mixed $v): string
    {
        if ($v === null || $v === '') {
            return "\u{2014}";
        }
        return number_format((float) $v);
    }

    /**
     * CSS class for a "higher is better" ratio metric relative to its target.
     */
    public static function ratioClass(?float $value, ?float $target): string
    {
        if ($value === null || $target === null) {
            return 'neutral';
        }
        if ($value >= $target) {
            return 'good';
        }
        return $value >= $target * 0.95 ? 'warn' : 'bad';
    }
}
