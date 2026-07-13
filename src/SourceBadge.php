<?php

declare(strict_types=1);

/**
 * Standardized per-metric data-source clarification (SCRUM-24).
 *
 * Every dashboard metric renders the same badge format:
 *   [System · dataset]  with a tooltip holding the exact definition.
 *
 * The registry below is the single place where "which system feeds this
 * number and what exactly does it mean" is defined, so Delivered vs.
 * Invoiced, SAP vs. WMS vs. externally-entered data can never drift
 * between pages.
 */
final class SourceBadge
{
    /** Canonical system names — the only spellings allowed on any page. */
    private const SYSTEMS = [
        'sap'    => 'SAP B1',
        'beas'   => 'Beas WMS',
        'api'    => 'External intake',
        'manual' => 'Manual master data',
    ];

    /**
     * Metric/panel registry: key => [system, dataset, definition].
     * dataset = SAP object(s) → local cache table.
     */
    private const METRICS = [
        'ordered' => ['sap', 'ORDR/RDR1 → delivery_lines',
            'Ordered = sales-order line quantity from SAP sales orders.'],
        'delivered' => ['sap', 'ODLN/DLN1 → delivery_lines',
            'Delivered = actual delivery-note quantity from SAP deliveries — NOT invoiced quantity.'],
        'invoiced' => ['sap', 'OINV → ar_payments',
            'Invoiced/paid = A/R invoice and payment amounts — may differ from delivered quantity.'],
        'fulfilment' => ['sap', 'ORDR/RDR1 + ODLN/DLN1 → delivery_lines',
            'Fill Rate = delivered ÷ ordered; OTIF/Late/Short come from SAP delivery vs. due dates. Delivered means delivery-note qty, not invoiced.'],
        'shipments' => ['sap', 'ODLN/OPKL → order_shipments',
            'Shipped cases and order/pick status from SAP deliveries and pick lists.'],
        'stock' => ['sap', 'OITW/OITM → warehouse_stock',
            'On-hand stock per item per warehouse from SAP inventory.'],
        'packaging' => ['sap', 'OITM UoM + Beas pallet master → material_packaging',
            'Case/pallet conversion factors per material.'],
        'batches' => ['sap', 'OBTN/OIBT → inventory_batches',
            'Batch admission/expiry dates for aging buckets.'],
        'movements' => ['sap', 'inventory documents → material_movements',
            'Stock transfers, goods issues to production and scrap issues.'],
        'stockout' => ['sap', 'OITW/OITM → inventory_stock_snapshots',
            'Stockout Frequency = active SKUs that hit zero on-hand during the period ÷ active SKUs, from daily on-hand snapshots.'],
        'production' => ['sap', 'OWOR/WOR1 → production_usage',
            'Planned vs. actually issued component quantities on production orders.'],
        'lpn' => ['beas', '@BMM_PALLETMASTER/@BMM_BINDETAIL → lpn_pallets',
            'Live pallet License Plate Numbers from the Beas warehouse management add-on.'],
        'complaints' => ['api', 'REST insert API → customer_complaints',
            'Customer complaints entered through the intake API (external quality system) — not from SAP.'],
        'readings' => ['sap', 'Beas silo/batch masters → operational_readings',
            'Operational readings flagged out of range or past expiry.'],
        'customers' => ['sap', 'OCRD/OCRG → delivery_lines',
            'Customer names and groups from the SAP business-partner master.'],
        'capacity' => ['manual', 'warehouse_capacity table',
            'Warehouse pallet capacities maintained by hand in the local cache — not from SAP.'],
        'alerts' => ['api', 'audit engine → audit_alerts',
            'Alerts raised by the local audit engine over cached SAP data.'],
    ];

    /** Render the standardized badge for a metric key. */
    public static function render(string $key): string
    {
        if (!isset(self::METRICS[$key])) {
            return '';
        }
        [$sys, $dataset, $definition] = self::METRICS[$key];
        $system = self::SYSTEMS[$sys];
        $title = htmlspecialchars($system . ' · ' . $dataset . ' — ' . $definition, ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars($system, ENT_QUOTES, 'UTF-8');
        $ds = htmlspecialchars($dataset, ENT_QUOTES, 'UTF-8');

        return '<span class="src-badge src-' . $sys . '" title="' . $title . '">'
            . $label . ' <span class="src-ds">· ' . $ds . '</span></span>';
    }
}
