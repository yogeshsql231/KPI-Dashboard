<?php

declare(strict_types=1);

/**
 * Filter state for the delivery / OMS dashboard, parsed and sanitised from the
 * query string. Produces a reusable SQL WHERE fragment (positional ?
 * placeholders) plus the matching params, so every query stays parameterised.
 */
final class DeliveryFilters
{
    /** Fixed warehouse buttons on the Overview; Others = everything else. */
    public const WAREHOUSE_GROUPS = ['Newark', 'Clifton', 'Brooklyn', 'Others'];

    public function __construct(
        public readonly ?string $fromDate = null,
        public readonly ?string $toDate = null,
        public readonly ?string $warehouse = null,
        public readonly ?string $salesOrder = null,
        public readonly ?string $po = null,
        public readonly ?string $carrier = null,
        public readonly ?string $soStatus = null,
        public readonly ?string $pickStatus = null,
        public readonly ?string $item = null,
    ) {
    }

    /** @param array<string, mixed> $q */
    public static function fromRequest(array $q): self
    {
        [$fromDate, $toDate] = self::orderDateRange(
            self::cleanDate($q['from_date'] ?? null),
            self::cleanDate($q['to_date'] ?? null),
        );

        return new self(
            $fromDate,
            $toDate,
            self::cleanText($q['warehouse'] ?? null),
            self::cleanText($q['so'] ?? null),
            self::cleanText($q['po'] ?? null),
            self::cleanText($q['carrier'] ?? null),
            self::cleanText($q['so_status'] ?? null),
            self::cleanText($q['pick_status'] ?? null),
            self::cleanText($q['item'] ?? null),
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
        return $this->build(null);
    }

    /**
     * Same as clause() but omits the conditions belonging to one filter
     * dimension. Used to build cascading option lists: e.g. when listing the
     * available carriers we keep every other active filter but drop the
     * carrier condition, so the dropdown shows every carrier valid for the
     * rest of the selection (and never hides the one already chosen).
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    public function clauseExcept(?string $exceptField): array
    {
        return $this->build($exceptField);
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private function build(?string $except): array
    {
        $conds = ['1 = 1'];
        $params = [];
        $add = static function (string $field, string $sql, array $vals) use (&$conds, &$params, $except): void {
            if ($field === $except) {
                return;
            }
            $conds[] = $sql;
            foreach ($vals as $v) {
                $params[] = $v;
            }
        };

        if ($this->fromDate !== null) {
            $add('date', 'posting_date >= ?', [$this->fromDate]);
        }
        if ($this->toDate !== null) {
            $add('date', 'posting_date <= ?', [$this->toDate]);
        }
        if ($this->warehouse !== null) {
            [$whSql, $whParams] = self::warehouseCondition('warehouse', $this->warehouse);
            $add('warehouse', $whSql, $whParams);
        }
        if ($this->salesOrder !== null) {
            $add('so', 'sales_order LIKE ?', ['%' . $this->salesOrder . '%']);
        }
        if ($this->po !== null) {
            $add('po', 'po_number LIKE ?', ['%' . $this->po . '%']);
        }
        if ($this->carrier !== null) {
            $add('carrier', 'carrier = ?', [$this->carrier]);
        }
        if ($this->soStatus !== null) {
            $add('so_status', 'so_status = ?', [$this->soStatus]);
        }
        if ($this->pickStatus !== null) {
            $add('pick_status', 'pick_status = ?', [$this->pickStatus]);
        }
        if ($this->item !== null) {
            // Match on item number OR description so users can search either.
            $add('item', '(item_code LIKE ? OR item_description LIKE ?)', ['%' . $this->item . '%', '%' . $this->item . '%']);
        }

        return [implode(' AND ', $conds), $params];
    }

    /**
     * Condition for one warehouse filter value. The group names used by the
     * Overview buttons match every naming variant of that site (e.g. Clifton
     * covers Cliffton and Clifton-MissingLPN); Others matches every warehouse
     * that is not Newark/Clifton/Brooklyn. Any other value matches exactly.
     *
     * @return array{0:string,1:array<int,string>}
     */
    public static function warehouseCondition(string $column, string $value): array
    {
        switch ($value) {
            case 'Newark':
                return ["LOWER($column) LIKE ?", ['%newark%']];
            case 'Clifton':
                return ["(LOWER($column) LIKE ? OR LOWER($column) LIKE ?)", ['%clifton%', '%cliffton%']];
            case 'Brooklyn':
                return ["LOWER($column) LIKE ?", ['%brooklyn%']];
            case 'Others':
                return [
                    "(LOWER($column) NOT LIKE ? AND LOWER($column) NOT LIKE ?"
                        . " AND LOWER($column) NOT LIKE ? AND LOWER($column) NOT LIKE ?)",
                    ['%newark%', '%clifton%', '%cliffton%', '%brooklyn%'],
                ];
            default:
                return ["$column = ?", [$value]];
        }
    }

    /**
     * SQL expression for the base company name of a customer: SAP CardName
     * embeds the branch/address after ":" or " - " (e.g. "SK FOOD
     * GROUP:Tolleson, AZ", "Chick-fil-A Supply LLC - CARTERSVILLE, GA"), so
     * cutting at those separators merges branches of the same customer.
     * Hyphens without surrounding spaces (Chick-fil-A) are preserved.
     */
    public static function customerBaseExpr(string $column): string
    {
        return "TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX($column, ':', 1), ' - ', 1))";
    }

    /**
     * Site group a warehouse name belongs to — the PHP-side twin of
     * warehouseCondition(), for grouping already-fetched rows.
     */
    public static function warehouseGroup(string $name): string
    {
        $n = strtolower($name);
        if (strpos($n, 'newark') !== false) {
            return 'Newark';
        }
        if (strpos($n, 'clifton') !== false || strpos($n, 'cliffton') !== false) {
            return 'Clifton';
        }
        if (strpos($n, 'brooklyn') !== false) {
            return 'Brooklyn';
        }
        return 'Others';
    }

    /**
     * SQL twin of warehouseGroup(): a CASE expression bucketing a warehouse
     * column into the Newark/Clifton/Brooklyn/Others site groups.
     */
    public static function warehouseGroupCase(string $column): string
    {
        return "CASE WHEN LOWER($column) LIKE '%newark%' THEN 'Newark'"
            . " WHEN LOWER($column) LIKE '%clifton%' OR LOWER($column) LIKE '%cliffton%' THEN 'Clifton'"
            . " WHEN LOWER($column) LIKE '%brooklyn%' THEN 'Brooklyn'"
            . " ELSE 'Others' END";
    }

    /**
     * Keep the date range in chronological order. If both ends are supplied
     * and the user entered them backwards (from later than to), swap them so
     * the "from <= posting_date <= to" clause still selects the intended
     * window instead of silently matching nothing.
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
