<?php
require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');

if (!isset($_GET['item_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Item ID is required'
    ]);
    exit();
}

$item_id = sanitizeInput($_GET['item_id']);

$sql = "SELECT i.id as inventory_id, i.date_received, i.vehicle_no, 
        v.name as vendor_name, ii.quantity, ii.remaining_stock
        FROM inventory i
        JOIN vendors v ON i.vendor_id = v.id
        JOIN inventory_items ii ON i.id = ii.inventory_id
        WHERE ii.item_id = ?
        ORDER BY i.date_received DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $item_id);
$stmt->execute();
$result = $stmt->get_result();

$history = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $row['date'] = date('d M Y', strtotime($row['date_received']));
        $history[] = $row;
    }
}

echo json_encode([
    'success' => true,
    'history' => $history
]); 