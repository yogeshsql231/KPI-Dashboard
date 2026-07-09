<?php

declare(strict_types=1);

/**
 * Dashboard filter state, parsed and sanitised from the query string.
 *
 * Produces reusable SQL WHERE fragments with positional (?) placeholders plus
 * the matching parameter array, so every KPI query stays parameterised (no
 * string interpolation of user input).
 */
final class Filters
{
    public function __construct(
        public readonly ?string $fromDate = null,
        public readonly ?string $toDate = null,
        public readonly ?string $customer = null,
        public readonly ?string $item = null,
        public readonly ?string $po = null,
        public readonly ?string $warehouse = null,
        public readonly ?string $salesOrder = null,
    ) {
    }

    /**
     * Build from a request array ($_GET), validating dates and trimming text.
     *
     * @param array<string, mixed> $q
     */
    public static function fromRequest(array $q): self
    {
        [$fromDate, $toDate] = self::orderDateRange(
            self::cleanDate($q['from_date'] ?? null),
            self::cleanDate($q['to_date'] ?? null),
        );

        return new self(
            $fromDate,
            $toDate,
            self::cleanText($q['customer'] ?? null),
            self::cleanText($q['item'] ?? null),
            self::cleanText($q['po'] ?? null),
            self::cleanText($q['warehouse'] ?? null),
            self::cleanText($q['so'] ?? null),
        );
    }

    public function isActive(): bool
    {
        return $this->fromDate !== null
            || $this->toDate !== null
            || $this->customer !== null
            || $this->item !== null
            || $this->po !== null
            || $this->warehouse !== null
            || $this->salesOrder !== null;
    }

    /**
     * WHERE fragment (without the leading WHERE/AND) + params for queries over
     * order_shipments / vw_order_shipment_kpi. Always excludes samples.
     *
     * @param string $dateColumn column used for the date range (default ship_date)
     * @return array{0:string,1:array<int,mixed>}
     */
    public function shipmentClause(string $dateColumn = 'ship_date'): array
    {
        $conds = ['is_sample = 0'];
        $params = [];

        if ($this->fromDate !== null) {
            $conds[] = "$dateColumn >= ?";
            $params[] = $this->fromDate;
        }
        if ($this->toDate !== null) {
            $conds[] = "$dateColumn <= ?";
            $params[] = $this->toDate;
        }
        if ($this->customer !== null) {
            $conds[] = 'customer = ?';
            $params[] = $this->customer;
        }
        if ($this->item !== null) {
            $conds[] = 'item_number = ?';
            $params[] = $this->item;
        }
        if ($this->po !== null) {
            $conds[] = 'po_number LIKE ?';
            $params[] = '%' . $this->po . '%';
        }
        if ($this->warehouse !== null) {
            $conds[] = 'warehouse = ?';
            $params[] = $this->warehouse;
        }
        if ($this->salesOrder !== null) {
            $conds[] = 'so_docentry LIKE ?';
            $params[] = '%' . $this->salesOrder . '%';
        }

        return [implode(' AND ', $conds), $params];
    }

    /**
     * WHERE fragment + params for customer_complaints (date + customer + item).
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    public function complaintClause(): array
    {
        $conds = ['1 = 1'];
        $params = [];

        if ($this->fromDate !== null) {
            $conds[] = 'complaint_date >= ?';
            $params[] = $this->fromDate;
        }
        if ($this->toDate !== null) {
            $conds[] = 'complaint_date <= ?';
            $params[] = $this->toDate;
        }
        if ($this->customer !== null) {
            $conds[] = 'customer = ?';
            $params[] = $this->customer;
        }
        if ($this->item !== null) {
            $conds[] = 'item_number = ?';
            $params[] = $this->item;
        }

        return [implode(' AND ', $conds), $params];
    }

    /**
     * Keep the date range in chronological order. If both ends are supplied
     * and the user entered them backwards (from later than to), swap them so
     * the "from <= column <= to" clause still selects the intended window
     * instead of silently matching nothing.
     *
     * @return array{0:?string,1:?string}
     */
    private static function orderDateRange(?string $from, ?string $to): array
    {
        if ($from !== null && $to !== null && $from > $to) {
            return [$to, $from];
        }
        return [$from, $to];
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
