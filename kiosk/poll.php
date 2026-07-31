<?php
/**
 * Quick Order — Live Kiosk Poll Endpoint
 *
 * Returns JSON list of unseen order notifications and marks them as displayed.
 * Requires NO PrestaShop session — uses a static token for auth.
 *
 * Usage: GET poll.php?token=<QUICKORDER_KIOSK_TOKEN>
 */

/* Minimal PrestaShop bootstrap — no session, no front controller
 * File is at: modules/quickorder/kiosk/poll.php  (4 levels from PS root)
 * dirname level 4  → PS root (public_html/)
 */
$_PS_ROOT_ = dirname(__FILE__, 4);
if (!defined('_PS_ADMIN_DIR_')) {
    define('_PS_ADMIN_DIR_', $_PS_ROOT_ . '/adminstationa');
}
require_once $_PS_ROOT_ . '/config/config.inc.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex');

/* ── Auth ── */
$expectedToken = Configuration::get('QUICKORDER_KIOSK_TOKEN');
$providedToken = isset($_GET['token']) ? (string) $_GET['token'] : '';

if (!$expectedToken || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    die(json_encode(['error' => 'forbidden']));
}

/* ── Fetch unseen notifications ── */
$rows = Db::getInstance()->executeS(
    'SELECT `id_notification`, `id_order`, `reference`, `customer_name`,
            `phone`, `address`, `product_name`, `quantity`, `total_paid`,
            `created_at`
     FROM `' . _DB_PREFIX_ . 'qo_notifications`
     WHERE `displayed_at` IS NULL
     ORDER BY `created_at` ASC
     LIMIT 10'
);

/* ── Mark as displayed ── */
if (!empty($rows)) {
    $ids = implode(',', array_map(function ($r) { return (int) $r['id_notification']; }, $rows));
    Db::getInstance()->execute(
        'UPDATE `' . _DB_PREFIX_ . 'qo_notifications`
         SET `displayed_at` = NOW()
         WHERE `id_notification` IN (' . $ids . ')'
    );
}

die(json_encode(['orders' => $rows ?: []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
