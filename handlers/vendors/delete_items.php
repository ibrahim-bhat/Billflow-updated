<?php
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Check if vendor_id and date are provided
if (!isset($_POST['vendor_id']) || !isset($_POST['date'])) {
    echo json_encode(['success' => false, 'error' => 'Vendor ID and date are required']);
    exit;
}

$vendor_id = intval($_POST['vendor_id']);
$date = $_POST['date'];

// Initialize response
$response = [
    'success' => true,
    'deleted_items' => [],
    'deleted_count' => 0
];

try {
    // Get all inventory items for this vendor and specific date
    $items_sql = "SELECT 
                    ii.id as inventory_item_id,
                    i.name as item_name,
                    ii.inventory_id,
                    DATE(inv.date_received) as date_received
                FROM inventory_items ii
                JOIN inventory inv ON ii.inventory_id = inv.id
                JOIN items i ON ii.item_id = i.id
                WHERE inv.vendor_id = ? AND DATE(inv.date_received) = ?";
    
    $items_stmt = $conn->prepare($items_sql);
    $items_stmt->bind_param("is", $vendor_id, $date);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();
    
    // Delete items for this vendor and date
    while ($item = $items_result->fetch_assoc()) {
        $delete_sql = "DELETE FROM inventory_items WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $item['inventory_item_id']);
        
        if ($delete_stmt->execute()) {
            $response['deleted_items'][] = [
                'name' => $item['item_name'],
                'date' => $item['date_received']
            ];
            $response['deleted_count']++;
        }
    }
    
    // Clean up empty inventory records for this specific date
    $cleanup_sql = "DELETE FROM inventory 
                    WHERE vendor_id = ? 
                    AND DATE(date_received) = ? 
                    AND id NOT IN (SELECT DISTINCT inventory_id FROM inventory_items)";
    $cleanup_stmt = $conn->prepare($cleanup_sql);
    $cleanup_stmt->bind_param("is", $vendor_id, $date);
    $cleanup_stmt->execute();
    
    $response['message'] = "Successfully deleted {$response['deleted_count']} items for date {$date}.";
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => 'Error during deletion: ' . $e->getMessage()
    ]);
}
?>