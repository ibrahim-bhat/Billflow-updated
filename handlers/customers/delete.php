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

$customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
if ($customer_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid customer id']);
    exit();
}

try {
    $conn->begin_transaction();

    // Delete customer payments
    $stmt = $conn->prepare("DELETE FROM customer_payments WHERE customer_id = ?");
    $stmt->bind_param('i', $customer_id);
    $stmt->execute();

    // Delete customer invoice items (via customer_invoices)
    $sql = "DELETE cii FROM customer_invoice_items cii
            INNER JOIN customer_invoices ci ON cii.invoice_id = ci.id
            WHERE ci.customer_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $customer_id);
    $stmt->execute();

    // Delete customer invoices
    $stmt = $conn->prepare("DELETE FROM customer_invoices WHERE customer_id = ?");
    $stmt->bind_param('i', $customer_id);
    $stmt->execute();

    // Finally delete the customer
    $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
    $stmt->bind_param('i', $customer_id);
    $stmt->execute();

    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Deletion failed: ' . $e->getMessage()]);
}
?>


