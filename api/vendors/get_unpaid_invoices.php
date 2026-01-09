<?php
// Include configuration
require_once __DIR__ . '/../../config/config.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if vendor_id is provided
if (!isset($_GET['vendor_id']) || empty($_GET['vendor_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Vendor ID is required']);
    exit();
}

$vendor_id = intval($_GET['vendor_id']);

try {
    // Get unpaid invoices for the vendor
    $sql = "SELECT 
                vi.id,
                vi.invoice_number,
                vi.invoice_date,
                vi.total_amount,
                vi.total_amount as remaining_amount
            FROM vendor_invoices vi
            WHERE vi.vendor_id = ? 
            AND vi.payment_status = 'pending'
            ORDER BY vi.invoice_date ASC, vi.id ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $invoices = [];
    while ($row = $result->fetch_assoc()) {
                    $invoices[] = [
                'id' => $row['id'],
                'invoice_number' => $row['invoice_number'],
                'invoice_date' => $row['invoice_date'],
                'total_amount' => $row['total_amount']
            ];
    }
    
    $stmt->close();
    
    // Return the invoices as JSON
    echo json_encode($invoices);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
