<?php
require_once __DIR__ . '/../../config/config.php';

// Set header to JSON
header('Content-Type: application/json');

// Get item ID
$item_id = isset($_GET['item_id']) ? intval($_GET['item_id']) : 0;

if (!$item_id) {
    echo json_encode(['error' => 'Invalid item ID']);
    exit;
}

// Get purchase history
$sql = "SELECT 
            inv.date_received as date,
            v.name as vendor_name,
            ii.quantity_received as quantity,
            ii.rate,
            (ii.quantity_received * ii.rate) as total
        FROM inventory_items ii
        JOIN inventory inv ON ii.inventory_id = inv.id
        JOIN vendors v ON inv.vendor_id = v.id
        WHERE ii.item_id = ?
        ORDER BY inv.date_received DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $item_id);
$stmt->execute();
$result = $stmt->get_result();

$history = [];
while ($row = $result->fetch_assoc()) {
    $row['date'] = date('d-m-Y', strtotime($row['date']));
    $row['quantity'] = number_format($row['quantity'], 2);
    $row['rate'] = number_format($row['rate'], 2);
    $row['total'] = number_format($row['total'], 2);
    $history[] = $row;
}

echo json_encode($history);
?> 