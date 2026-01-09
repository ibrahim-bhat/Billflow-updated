<?php
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');
ini_set('display_errors', 0);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$vendor_id = isset($_POST['vendor_id']) ? intval($_POST['vendor_id']) : 0;
if ($vendor_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid vendor id']);
    exit();
}

try {
    $conn->begin_transaction();

    // Delete vendor payments
    $stmt = $conn->prepare("DELETE FROM vendor_payments WHERE vendor_id = ?");
    $stmt->bind_param('i', $vendor_id);
    $stmt->execute();

    // Delete watak items joined via vendor_watak
    $sql = "DELETE wi FROM watak_items wi
            INNER JOIN vendor_watak w ON wi.watak_id = w.id
            WHERE w.vendor_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $vendor_id);
    $stmt->execute();

    // Delete vendor wataks
    $stmt = $conn->prepare("DELETE FROM vendor_watak WHERE vendor_id = ?");
    $stmt->bind_param('i', $vendor_id);
    $stmt->execute();

    // Delete vendor invoice items joined via vendor_invoices
    $sql = "DELETE vii FROM vendor_invoice_items vii
            INNER JOIN vendor_invoices vi ON vii.invoice_id = vi.id
            WHERE vi.vendor_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $vendor_id);
    $stmt->execute();

    // Delete vendor invoices
    $stmt = $conn->prepare("DELETE FROM vendor_invoices WHERE vendor_id = ?");
    $stmt->bind_param('i', $vendor_id);
    $stmt->execute();

    // Delete inventory items joined via inventory
    $sql = "DELETE ii FROM inventory_items ii
            INNER JOIN inventory inv ON ii.inventory_id = inv.id
            WHERE inv.vendor_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $vendor_id);
    $stmt->execute();

    // Delete inventory headers
    $stmt = $conn->prepare("DELETE FROM inventory WHERE vendor_id = ?");
    $stmt->bind_param('i', $vendor_id);
    $stmt->execute();

    // Finally delete the vendor
    $stmt = $conn->prepare("DELETE FROM vendors WHERE id = ?");
    $stmt->bind_param('i', $vendor_id);
    $stmt->execute();

    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Deletion failed: ' . $e->getMessage()]);
}
?>


