<?php

declare(strict_types=1);

/**
 * REST endpoint: /api/shipments.php
 *
 *   POST  -> validate a JSON body and INSERT one order-shipment line using a
 *            prepared statement (safe against SQL injection).
 *   GET   -> return the current KPI summary plus the most recent shipment lines
 *            (handy for quick checks and for the dashboard to consume).
 *
 * Example:
 *   curl -X POST http://localhost/KPI-Dashboard/api/shipments.php \
 *        -H "Content-Type: application/json" \
 *        -H "X-API-Key: <API_KEY>" \
 *        -d '{"ship_date":"2026-06-26","po_number":"PO123","customer":"Acme",
 *             "item_number":"100074","qty_requested":100,"qty_shipped":98,
 *             "requested_date":"2026-06-26","actual_date":"2026-06-26"}'
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/Validator.php';

// --- CORS / security headers (adjust the allowed origin for production) ---
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $pdo = Database::connection();
} catch (Throwable $e) {
    Response::error('Service unavailable.', 503);
}

switch ($method) {
    case 'GET':
        handleGet($pdo);
        break;
    case 'POST':
        handlePost($pdo);
        break;
    default:
        header('Allow: GET, POST');
        Response::error('Method not allowed.', 405);
}

/**
 * Return KPI summary + latest shipment lines.
 */
function handleGet(PDO $pdo): never
{
    $summary = $pdo->query('SELECT * FROM vw_kpi_summary')->fetch() ?: [];

    $stmt = $pdo->query(
        'SELECT id, ship_date, po_number, customer, item_number,
                qty_requested, qty_shipped
         FROM order_shipments
         ORDER BY id DESC
         LIMIT 20'
    );
    $recent = $stmt->fetchAll();

    Response::success([
        'summary' => $summary,
        'recent'  => $recent,
    ]);
}

/**
 * Validate JSON input and insert a shipment line via a prepared statement.
 */
function handlePost(PDO $pdo): never
{
    requireApiKey();

    // Read and decode the JSON body.
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        Response::error('Request body must be valid JSON.', 400);
    }

    // Validate + clean.
    $v = new Validator($data);
    $v->required('ship_date')->date('ship_date')
      ->required('po_number')->string('po_number', 64)
      ->required('customer')->string('customer', 255)
      ->required('item_number')->string('item_number', 64)
      ->string('ship_via', 32)
      ->integer('qty_requested', 0)
      ->integer('qty_shipped', 0)
      ->date('order_date')
      ->date('requested_date')
      ->date('actual_date')
      ->boolean('is_sample')
      ->string('comments', 255);

    if ($v->fails()) {
        Response::error('Validation failed.', 422, $v->errors());
    }

    $clean = $v->validated([
        'ship_via'       => null,
        'qty_requested'  => 0,
        'qty_shipped'    => 0,
        'order_date'     => null,
        'requested_date' => null,
        'actual_date'    => null,
        'is_sample'      => 0,
        'comments'       => null,
    ]);

    // Prepared statement with named placeholders — values are bound, never
    // concatenated, so the input can never alter the SQL structure.
    $sql = 'INSERT INTO order_shipments
                (ship_date, po_number, customer, ship_via, item_number,
                 qty_requested, qty_shipped, order_date, requested_date,
                 actual_date, is_sample, comments)
            VALUES
                (:ship_date, :po_number, :customer, :ship_via, :item_number,
                 :qty_requested, :qty_shipped, :order_date, :requested_date,
                 :actual_date, :is_sample, :comments)';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':ship_date'      => $clean['ship_date'],
            ':po_number'      => $clean['po_number'],
            ':customer'       => $clean['customer'],
            ':ship_via'       => $clean['ship_via'],
            ':item_number'    => $clean['item_number'],
            ':qty_requested'  => $clean['qty_requested'],
            ':qty_shipped'    => $clean['qty_shipped'],
            ':order_date'     => $clean['order_date'],
            ':requested_date' => $clean['requested_date'],
            ':actual_date'    => $clean['actual_date'],
            ':is_sample'      => $clean['is_sample'],
            ':comments'       => $clean['comments'],
        ]);
    } catch (PDOException $e) {
        error_log('[api/shipments] insert failed: ' . $e->getMessage());
        Response::error('Failed to save the shipment.', 500);
    }

    Response::success(['id' => (int) $pdo->lastInsertId()], 201);
}

/**
 * Optional shared-secret guard for write operations.
 */
function requireApiKey(): void
{
    $expected = env('API_KEY');
    if ($expected === null || $expected === '') {
        return; // disabled (local dev)
    }
    $provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!is_string($provided) || !hash_equals((string) $expected, $provided)) {
        Response::error('Unauthorized.', 401);
    }
}
