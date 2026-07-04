<?php

declare(strict_types=1);

/**
 * Centralised write operations for the order_shipments table.
 *
 * Both the REST API (single-row insert) and the ETL pipeline (bulk upsert)
 * were maintaining their own copies of the column list and parameter bindings.
 * This class is the single source of truth for those operations.
 */
final class ShipmentRepository
{
    private ?PDOStatement $insertStmt = null;
    private ?PDOStatement $upsertStmt = null;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Insert a single shipment row (API path — no source_system / source_key).
     *
     * @param array<string, mixed> $data Cleaned / validated field values.
     * @return int  The new row's auto-increment ID.
     */
    public function insert(array $data): int
    {
        if ($this->insertStmt === null) {
            $this->insertStmt = $this->pdo->prepare(
                'INSERT INTO order_shipments
                    (ship_date, po_number, customer, ship_via, item_number,
                     qty_requested, qty_shipped, order_date, requested_date,
                     actual_date, is_sample, comments)
                 VALUES
                    (:ship_date, :po_number, :customer, :ship_via, :item_number,
                     :qty_requested, :qty_shipped, :order_date, :requested_date,
                     :actual_date, :is_sample, :comments)'
            );
        }

        $this->insertStmt->execute([
            ':ship_date'      => $data['ship_date'],
            ':po_number'      => $data['po_number'],
            ':customer'       => $data['customer'],
            ':ship_via'       => $data['ship_via'],
            ':item_number'    => $data['item_number'],
            ':qty_requested'  => $data['qty_requested'],
            ':qty_shipped'    => $data['qty_shipped'],
            ':order_date'     => $data['order_date'],
            ':requested_date' => $data['requested_date'],
            ':actual_date'    => $data['actual_date'],
            ':is_sample'      => $data['is_sample'],
            ':comments'       => $data['comments'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Idempotent upsert keyed on (source_system, source_key) — ETL path.
     *
     * @param array<string, mixed> $data Field values including source_system and source_key.
     */
    public function upsert(array $data): void
    {
        if ($this->upsertStmt === null) {
            $this->upsertStmt = $this->pdo->prepare(
                'INSERT INTO order_shipments
                    (ship_date, po_number, customer, ship_via, item_number,
                     qty_requested, qty_shipped, order_date, requested_date,
                     actual_date, source_system, source_key)
                 VALUES
                    (:ship_date, :po_number, :customer, :ship_via, :item_number,
                     :qty_requested, :qty_shipped, :order_date, :requested_date,
                     :actual_date, :source_system, :source_key)
                 ON DUPLICATE KEY UPDATE
                     ship_date      = VALUES(ship_date),
                     po_number      = VALUES(po_number),
                     customer       = VALUES(customer),
                     ship_via       = VALUES(ship_via),
                     item_number    = VALUES(item_number),
                     qty_requested  = VALUES(qty_requested),
                     qty_shipped    = VALUES(qty_shipped),
                     order_date     = VALUES(order_date),
                     requested_date = VALUES(requested_date),
                     actual_date    = VALUES(actual_date)'
            );
        }

        $this->upsertStmt->execute([
            ':ship_date'      => $data['ship_date'],
            ':po_number'      => $data['po_number'],
            ':customer'       => $data['customer'],
            ':ship_via'       => $data['ship_via'],
            ':item_number'    => $data['item_number'],
            ':qty_requested'  => $data['qty_requested'],
            ':qty_shipped'    => $data['qty_shipped'],
            ':order_date'     => $data['order_date'],
            ':requested_date' => $data['requested_date'],
            ':actual_date'    => $data['actual_date'],
            ':source_system'  => $data['source_system'],
            ':source_key'     => $data['source_key'],
        ]);
    }
}
