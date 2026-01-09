<?php
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Set JSON header
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Get parameters
$item_id = isset($_GET['item_id']) ? intval($_GET['item_id']) : 0;
$vendor_id = isset($_GET['vendor_id']) ? intval($_GET['vendor_id']) : 0;

if ($item_id <= 0 || $vendor_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid item or vendor ID']);
    exit;
}

try {
    // Get customer purchase history for this item from this vendor
    $customer_history_sql = "SELECT 
        DATE_FORMAT(ci.date, '%d/%m/%Y') as date,
        cii.quantity,
        cii.weight,
        cii.rate,
        cii.amount
        FROM customer_invoice_items cii
        JOIN customer_invoices ci ON cii.invoice_id = ci.id
        WHERE cii.item_id = ? AND cii.vendor_id = ?
        ORDER BY ci.date DESC";

    $customer_history_stmt = $conn->prepare($customer_history_sql);
    $customer_history_stmt->bind_param('ii', $item_id, $vendor_id);
    $customer_history_stmt->execute();
    $customer_history_result = $customer_history_stmt->get_result();

    // Calculate totals
    $total_customer_qty = 0;
    $total_customer_weight = 0;
    $total_customer_amount = 0;
    $customer_data = [];

    while ($row = $customer_history_result->fetch_assoc()) {
        $total_customer_qty += $row['quantity'];
        $total_customer_weight += ($row['weight'] ?? 0);
        $total_customer_amount += $row['amount'];
        
        // Format data for JSON response
        $customer_data[] = [
            'date' => $row['date'],
            'quantity' => number_format($row['quantity'], 2),
            'weight' => number_format($row['weight'] ?? 0, 2) . ' kg',
            'rate' => number_format($row['rate'], 2),
            'amount' => number_format($row['amount'], 2)
        ];
    }

    // Calculate average rate correctly
    $average_rate = 0;
    if ($total_customer_weight > 0) {
        // If weight is present, calculate average rate based on weight
        $average_rate = $total_customer_amount / $total_customer_weight;
    } elseif ($total_customer_qty > 0) {
        // If only quantity is present, calculate average rate based on quantity
        $average_rate = $total_customer_amount / $total_customer_qty;
    }

    // Prepare summary data
    $summary = [
        'total_qty' => number_format($total_customer_qty, 2),
        'total_weight' => number_format($total_customer_weight, 2),
        'total_amount' => number_format($total_customer_amount, 2),
        'avg_rate' => number_format($average_rate, 2)
    ];

    echo json_encode([
        'success' => true,
        'history' => $customer_data,
        'summary' => $summary
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?> 