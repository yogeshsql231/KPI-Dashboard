<?php

declare(strict_types=1);

/**
 * Shared data-sanitisation helpers used by both the REST API and the ETL
 * pipeline.  Keeps date normalisation and null-coercion in one place.
 */
final class DataHelper
{
    /**
     * Normalise a source date/datetime value to YYYY-MM-DD, or null.
     */
    public static function normDate(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            return (new DateTime((string) $v))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Trim a value to a string, converting empty strings to null.
     */
    public static function nullify(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
