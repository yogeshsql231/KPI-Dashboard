<?php

declare(strict_types=1);

/**
 * Read-only access to the expanded Warehouse view caches (migration 010):
 * stock on hand, packaging/UoM, batch aging and material movement. Each cache
 * is refreshed by its own ETL on the XAMPP box. Every method gates on the
 * table existing so the page degrades gracefully before a migration/ETL runs
 * (returns empty instead of erroring — same approach as AlertRepository).
 *
 * All queries are parameterised. Warehouse + item filtering reuses the shared
 * DeliveryFilters so the panels behave like the rest of the dashboard.
 */
final class WarehouseInventoryRepository
{
    /** @var array<string,bool> */
    private array $existsCache = [];

    public function __construct(private PDO $pdo)
    {
    }

    private function tableExists(string $name): bool
    {
        if (array_key_exists($name, $this->existsCache)) {
            return $this->existsCache[$name];
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$name]);
        return $this->existsCache[$name] = ((int) $stmt->fetchColumn() > 0);
    }

    public function hasStock(): bool
    {
        return $this->tableExists('warehouse_stock')
            && (int) $this->pdo->query('SELECT COUNT(*) FROM warehouse_stock')->fetchColumn() > 0;
    }

    public function hasBatches(): bool
    {
        return $this->tableExists('inventory_batches')
            && (int) $this->pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn() > 0;
    }

    public function hasPackaging(): bool
    {
        return $this->tableExists('material_packaging')
            && (int) $this->pdo->query('SELECT COUNT(*) FROM material_packaging')->fetchColumn() > 0;
    }

    public function hasMovements(): bool
    {
        return $this->tableExists('material_movements')
            && (int) $this->pdo->query('SELECT COUNT(*) FROM material_movements')->fetchColumn() > 0;
    }

    /**
     * Shared warehouse/item WHERE fragment for the stock & batch tables.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private function whItemClause(DeliveryFilters $f, string $whCol, string $itemPrefix = ''): array
    {
        $conds = ['1 = 1'];
        $params = [];
        if ($f->warehouse !== null) {
            $conds[] = "$whCol = ?";
            $params[] = $f->warehouse;
        }
        if ($f->item !== null) {
            $conds[] = "({$itemPrefix}item_code LIKE ? OR {$itemPrefix}item_description LIKE ?)";
            $like = '%' . $f->item . '%';
            $params[] = $like;
            $params[] = $like;
        }
        return [implode(' AND ', $conds), $params];
    }

    // ---- Case-to-pallet conversion (SCRUM-63) -------------------------------

    /**
     * Shared case-to-pallet SQL expression. Precedence: the row's stored pallet
     * count, else the item's packaging conversion (units/pallet, then
     * units-per-case x cases-per-pallet from material_packaging, alias `mp`),
     * else the warehouse default cases_per_pallet (warehouse_capacity, alias
     * `wc`) — mirroring DeliveryRepository::warehouseCapacity.
     */
    private function palletExpr(string $qtyCol, string $storedCol): string
    {
        $wcFallback = $this->tableExists('warehouse_capacity')
            ? ", $qtyCol / NULLIF(wc.cases_per_pallet, 0)"
            : '';
        return "COALESCE(
                    $storedCol,
                    $qtyCol / NULLIF(mp.units_per_pallet, 0),
                    $qtyCol / NULLIF(mp.units_per_case * mp.cases_per_pallet, 0)$wcFallback)";
    }

    /** LEFT JOINs backing palletExpr(); mp keyed by item, wc by warehouse. */
    private function palletJoins(string $itemCol, string $whCol): string
    {
        $joins = $this->tableExists('material_packaging')
            ? "LEFT JOIN material_packaging mp ON mp.item_code = $itemCol"
            : "LEFT JOIN (SELECT NULL AS item_code, NULL AS units_per_case,
                                 NULL AS cases_per_pallet, NULL AS units_per_pallet) mp
                    ON mp.item_code = $itemCol";
        if ($this->tableExists('warehouse_capacity')) {
            $joins .= " LEFT JOIN warehouse_capacity wc ON wc.warehouse = $whCol";
        }
        return $joins;
    }

    // ---- Summary cards -----------------------------------------------------

    /** @return array<string,mixed> */
    public function summary(DeliveryFilters $f): array
    {
        $out = [
            'warehouses' => 0, 'materials' => 0, 'on_hand_pallets' => 0.0,
            'aged_90' => 0, 'expired' => 0, 'waste_pct' => null,
        ];

        if ($this->hasStock()) {
            [$where, $params] = $this->whItemClause($f, 's.warehouse', 's.');
            $palletExpr = $this->palletExpr('s.on_hand', 's.pallets');
            $joins = $this->palletJoins('s.item_code', 's.warehouse');
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(DISTINCT s.warehouse) AS warehouses,
                        COUNT(DISTINCT s.item_code) AS materials,
                        COALESCE(SUM($palletExpr), 0) AS on_hand_pallets
                 FROM warehouse_stock s $joins WHERE $where"
            );
            $stmt->execute($params);
            $row = $stmt->fetch() ?: [];
            $out['warehouses'] = (int) ($row['warehouses'] ?? 0);
            $out['materials'] = (int) ($row['materials'] ?? 0);
            $out['on_hand_pallets'] = (float) ($row['on_hand_pallets'] ?? 0);
        }

        if ($this->hasBatches()) {
            [$where, $params] = $this->whItemClause($f, 'std_warehouse');
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(SUM(CASE WHEN age_bucket = '90+' THEN 1 ELSE 0 END), 0) AS aged_90,
                        COALESCE(SUM(is_expired), 0) AS expired
                 FROM vw_inventory_batches WHERE $where"
            );
            $stmt->execute($params);
            $row = $stmt->fetch() ?: [];
            $out['aged_90'] = (int) ($row['aged_90'] ?? 0);
            $out['expired'] = (int) ($row['expired'] ?? 0);
        }

        if ($this->hasMovements()) {
            $issued = (float) ($this->movementStageTotal('issue') + $this->movementStageTotal('waste'));
            $waste = (float) $this->movementStageTotal('waste');
            $out['waste_pct'] = $issued > 0 ? $waste / $issued : null;
        }

        return $out;
    }

    // ---- Stock on hand -----------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function stockRows(DeliveryFilters $f, int $limit = 300): array
    {
        if (!$this->hasStock()) {
            return [];
        }
        [$where, $params] = $this->whItemClause($f, 's.warehouse', 's.');
        $palletExpr = $this->palletExpr('s.on_hand', 's.pallets');
        $joins = $this->palletJoins('s.item_code', 's.warehouse');
        $stmt = $this->pdo->prepare(
            "SELECT s.item_code, s.item_description, s.warehouse, s.on_hand, s.committed,
                    s.on_order, s.unit_of_measure, $palletExpr AS pallets
             FROM warehouse_stock s $joins
             WHERE $where
             ORDER BY s.on_hand DESC, s.item_code
             LIMIT " . (int) $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ---- Packaging ---------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function packagingRows(DeliveryFilters $f, int $limit = 300): array
    {
        if (!$this->hasPackaging()) {
            return [];
        }
        $conds = ['1 = 1'];
        $params = [];
        if ($f->item !== null) {
            $conds[] = '(item_code LIKE ? OR item_description LIKE ?)';
            $like = '%' . $f->item . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $where = implode(' AND ', $conds);
        $stmt = $this->pdo->prepare(
            "SELECT item_code, item_description, base_uom, units_per_case,
                    cases_per_pallet, units_per_pallet, pack_description
             FROM material_packaging
             WHERE $where
             ORDER BY item_code
             LIMIT " . (int) $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ---- Aging -------------------------------------------------------------

    /**
     * Age distribution per warehouse: bucket counts + aged/expired + % aged.
     *
     * @return array<int,array<string,mixed>>
     */
    public function agedByWarehouse(DeliveryFilters $f): array
    {
        if (!$this->hasBatches()) {
            return [];
        }
        [$where, $params] = $this->whItemClause($f, 'b.std_warehouse', 'b.');
        $palletExpr = $this->palletExpr('b.quantity', 'b.pallets');
        $joins = $this->palletJoins('b.item_code', 'b.std_warehouse');
        $stmt = $this->pdo->prepare(
            "SELECT b.std_warehouse AS warehouse,
                    COUNT(*)                                                       AS batches,
                    COALESCE(SUM($palletExpr), 0)                                  AS total_pallets,
                    SUM(CASE WHEN b.age_bucket = '0-30'  THEN 1 ELSE 0 END)        AS b0_30,
                    SUM(CASE WHEN b.age_bucket = '30-60' THEN 1 ELSE 0 END)        AS b30_60,
                    SUM(CASE WHEN b.age_bucket = '60-90' THEN 1 ELSE 0 END)        AS b60_90,
                    SUM(CASE WHEN b.age_bucket = '90+'   THEN 1 ELSE 0 END)        AS b90,
                    SUM(CASE WHEN b.age_bucket = 'unknown' THEN 1 ELSE 0 END)      AS b_unknown,
                    COALESCE(SUM(b.is_expired), 0)                                 AS expired
             FROM vw_inventory_batches b $joins
             WHERE $where
             GROUP BY b.std_warehouse
             ORDER BY b90 DESC, warehouse"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Aged-out material worklist: batches in the 90+ bucket or already expired,
     * oldest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function agedOutRows(DeliveryFilters $f, int $limit = 200): array
    {
        if (!$this->hasBatches()) {
            return [];
        }
        [$where, $params] = $this->whItemClause($f, 'b.std_warehouse', 'b.');
        $palletExpr = $this->palletExpr('b.quantity', 'b.pallets');
        $joins = $this->palletJoins('b.item_code', 'b.std_warehouse');
        $stmt = $this->pdo->prepare(
            "SELECT b.item_code, b.item_description, b.batch_number, b.std_warehouse AS warehouse,
                    b.quantity, b.unit_of_measure, b.admission_date, b.expiry_date,
                    b.age_days, b.is_expired, $palletExpr AS pallets
             FROM vw_inventory_batches b $joins
             WHERE $where AND (b.age_bucket = '90+' OR b.is_expired = 1)
             ORDER BY b.is_expired DESC, b.age_days DESC, b.expiry_date
             LIMIT " . (int) $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ---- Movement flow -----------------------------------------------------

    private function movementStageTotal(string $type): float
    {
        if (!$this->hasMovements()) {
            return 0.0;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(quantity), 0) FROM material_movements WHERE movement_type = ?'
        );
        $stmt->execute([$type]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Totals per stage for the flow strip. Date range (if set) applies to
     * doc_date.
     *
     * @return array<string,float>
     */
    public function movementFlow(DeliveryFilters $f): array
    {
        $stages = ['receipt' => 0.0, 'transfer' => 0.0, 'issue' => 0.0, 'waste' => 0.0];
        if (!$this->hasMovements()) {
            return $stages;
        }
        $conds = ['1 = 1'];
        $params = [];
        if ($f->fromDate !== null) {
            $conds[] = 'doc_date >= ?';
            $params[] = $f->fromDate;
        }
        if ($f->toDate !== null) {
            $conds[] = 'doc_date <= ?';
            $params[] = $f->toDate;
        }
        if ($f->item !== null) {
            $conds[] = '(item_code LIKE ? OR item_description LIKE ?)';
            $like = '%' . $f->item . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $where = implode(' AND ', $conds);
        $stmt = $this->pdo->prepare(
            "SELECT movement_type, COALESCE(SUM(quantity), 0) AS qty
             FROM material_movements
             WHERE $where
             GROUP BY movement_type"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $t = (string) $row['movement_type'];
            if (array_key_exists($t, $stages)) {
                $stages[$t] = (float) $row['qty'];
            }
        }
        return $stages;
    }

    /**
     * Stock stage tracking (SCRUM-64): quantity sitting at each stage of the
     * stock lifecycle — on-hand raw stock, moved to staging, issued to
     * production, finished goods on hand, and returned/wasted. On-hand and
     * finished goods read the warehouse_stock cache (finished goods = FG-*
     * warehouses); the movement stages read material_movements over the
     * selected date range. A stage is null when its cache table is missing.
     *
     * @return array<string,float|null>
     */
    public function stockStages(DeliveryFilters $f): array
    {
        $out = [
            'on_hand' => null, 'to_staging' => null, 'to_production' => null,
            'finished_goods' => null, 'waste' => null,
        ];

        if ($this->hasStock()) {
            [$where, $params] = $this->whItemClause($f, 's.warehouse', 's.');
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(SUM(s.on_hand), 0) AS on_hand,
                        COALESCE(SUM(CASE WHEN s.warehouse LIKE 'FG%' THEN s.on_hand ELSE 0 END), 0) AS finished_goods
                 FROM warehouse_stock s WHERE $where"
            );
            $stmt->execute($params);
            $row = $stmt->fetch() ?: [];
            $out['on_hand'] = (float) ($row['on_hand'] ?? 0);
            $out['finished_goods'] = (float) ($row['finished_goods'] ?? 0);
        }

        if ($this->hasMovements()) {
            $flow = $this->movementFlow($f);
            $out['to_staging'] = $flow['transfer'];
            $out['to_production'] = $flow['issue'];
            $out['waste'] = $flow['waste'];
        }

        return $out;
    }

    /** Distinct warehouses seen across the stock/batch caches, for the buttons. */
    public function warehouseOptions(): array
    {
        $set = [];
        if ($this->hasStock()) {
            foreach ($this->pdo->query('SELECT DISTINCT warehouse FROM warehouse_stock WHERE warehouse <> \'\' ORDER BY warehouse')->fetchAll(PDO::FETCH_COLUMN) as $w) {
                $set[(string) $w] = true;
            }
        }
        if ($this->hasBatches()) {
            foreach ($this->pdo->query('SELECT DISTINCT std_warehouse FROM vw_inventory_batches WHERE std_warehouse <> \'\' ORDER BY std_warehouse')->fetchAll(PDO::FETCH_COLUMN) as $w) {
                $set[(string) $w] = true;
            }
        }
        $out = array_keys($set);
        sort($out);
        return $out;
    }

    /** Most recent refresh across the four caches, or null. */
    public function lastRefreshed(): ?string
    {
        $times = [];
        foreach (['warehouse_stock', 'inventory_batches', 'material_packaging', 'material_movements'] as $t) {
            if ($this->tableExists($t)) {
                $v = $this->pdo->query("SELECT MAX(refreshed_at) FROM $t")->fetchColumn();
                if ($v) {
                    $times[] = (string) $v;
                }
            }
        }
        if ($times === []) {
            return null;
        }
        return max($times);
    }
}
