<?php

declare(strict_types=1);

/**
 * Filter state for the delivery / OMS dashboard, parsed and sanitised from the
 * query string. Produces a reusable SQL WHERE fragment (positional ?
 * placeholders) plus the matching params, so every query stays parameterised.
 */
final class DeliveryFilters
{
    public function __construct(
        public readonly ?string $fromDate = null,
        public readonly ?string $toDate = null,
        public readonly ?string $warehouse = null,
        public readonly ?string $salesOrder = null,
        public readonly ?string $po = null,
        public readonly ?string $carrier = null,
        public readonly ?string $soStatus = null,
        public readonly ?string $pickStatus = null,
    ) {
    }

    /** @param array<string, mixed> $q */
    public static function fromRequest(array $q): self
    {
        return new self(
            self::cleanDate($q['from_date'] ?? null),
            self::cleanDate($q['to_date'] ?? null),
            self::cleanText($q['warehouse'] ?? null),
            self::cleanText($q['so'] ?? null),
            self::cleanText($q['po'] ?? null),
            self::cleanText($q['carrier'] ?? null),
            self::cleanText($q['so_status'] ?? null),
            self::cleanText($q['pick_status'] ?? null),
        );
    }

    /**
     * WHERE fragment (without leading WHERE) + params over vw_delivery_lines.
     * The date range applies to posting_date.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    public function clause(): array
    {
        $conds = ['1 = 1'];
        $params = [];

        if ($this->fromDate !== null) {
            $conds[] = 'posting_date >= ?';
            $params[] = $this->fromDate;
        }
        if ($this->toDate !== null) {
            $conds[] = 'posting_date <= ?';
            $params[] = $this->toDate;
        }
        if ($this->warehouse !== null) {
            $conds[] = 'warehouse = ?';
            $params[] = $this->warehouse;
        }
        if ($this->salesOrder !== null) {
            $conds[] = 'sales_order LIKE ?';
            $params[] = '%' . $this->salesOrder . '%';
        }
        if ($this->po !== null) {
            $conds[] = 'po_number LIKE ?';
            $params[] = '%' . $this->po . '%';
        }
        if ($this->carrier !== null) {
            $conds[] = 'carrier = ?';
            $params[] = $this->carrier;
        }
        if ($this->soStatus !== null) {
            $conds[] = 'so_status = ?';
            $params[] = $this->soStatus;
        }
        if ($this->pickStatus !== null) {
            $conds[] = 'pick_status = ?';
            $params[] = $this->pickStatus;
        }

        return [implode(' AND ', $conds), $params];
    }

    private static function cleanDate(mixed $v): ?string
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        $v = trim($v);
        $dt = DateTime::createFromFormat('Y-m-d', $v);
        return ($dt && $dt->format('Y-m-d') === $v) ? $v : null;
    }

    private static function cleanText(mixed $v): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, 255);
    }
}
