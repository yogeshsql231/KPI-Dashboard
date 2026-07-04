<?php

declare(strict_types=1);

/**
 * Minimal, dependency-free input validator.
 *
 * Collects human-readable error messages and produces a cleaned,
 * type-cast value bag that is safe to bind into a prepared statement.
 */
final class Validator
{
    /** @var array<int, string> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $clean = [];

    /**
     * @param array<string, mixed> $data Raw decoded JSON input.
     */
    public function __construct(private array $data)
    {
    }

    /** Field is present and not an empty string. */
    public function required(string $field): self
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $this->errors[] = "Field '$field' is required.";
        }
        return $this;
    }

    /** Trimmed string with an optional maximum length. */
    public function string(string $field, int $max = 255): self
    {
        if (!array_key_exists($field, $this->data) || $this->data[$field] === null) {
            return $this;
        }
        $value = $this->data[$field];
        if (!is_scalar($value)) {
            $this->errors[] = "Field '$field' must be a string.";
            return $this;
        }
        $value = trim((string) $value);
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length > $max) {
            $this->errors[] = "Field '$field' must be at most $max characters.";
            return $this;
        }
        $this->clean[$field] = $value === '' ? null : $value;
        return $this;
    }

    /** Non-negative integer. */
    public function integer(string $field, int $min = 0): self
    {
        if (!array_key_exists($field, $this->data) || $this->data[$field] === null) {
            return $this;
        }
        $value = $this->data[$field];
        if (!is_numeric($value) || (int) $value != $value) {
            $this->errors[] = "Field '$field' must be an integer.";
            return $this;
        }
        $int = (int) $value;
        if ($int < $min) {
            $this->errors[] = "Field '$field' must be >= $min.";
            return $this;
        }
        $this->clean[$field] = $int;
        return $this;
    }

    /** ISO date (YYYY-MM-DD). Stored as null when absent/empty. */
    public function date(string $field): self
    {
        if (!array_key_exists($field, $this->data) || $this->data[$field] === null || $this->data[$field] === '') {
            return $this;
        }
        $value = (string) $this->data[$field];
        $dt = DateTime::createFromFormat('Y-m-d', $value);
        if (!$dt || $dt->format('Y-m-d') !== $value) {
            $this->errors[] = "Field '$field' must be a valid date (YYYY-MM-DD).";
            return $this;
        }
        $this->clean[$field] = $value;
        return $this;
    }

    /** Boolean coerced to 0/1. */
    public function boolean(string $field): self
    {
        if (!array_key_exists($field, $this->data) || $this->data[$field] === null) {
            return $this;
        }
        $this->clean[$field] = filter_var($this->data[$field], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Cleaned values. Missing optional fields fall back to $defaults.
     *
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public function validated(array $defaults = []): array
    {
        return array_merge($defaults, $this->clean);
    }
}
