<?php
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Check if required parameters are provided
if (!isset($_POST['inventory_item_id']) || !isset($_POST['inventory_id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

$inventory_item_id = (int)$_POST['inventory_item_id'];
$inventory_id = (int)$_POST['inventory_id'];

try {
    // Check if the item exists and belongs to the specified inventory
    $check_sql = "SELECT ii.id, ii.remaining_stock, ii.quantity_received, i.name as item_name 
                  FROM inventory_items ii 
                  JOIN items i ON ii.item_id = i.id 
                  WHERE ii.id = ? AND ii.inventory_id = ?";
    
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $inventory_item_id, $inventory_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Item not found']);
        exit;
    }
    
    $item = $result->fetch_assoc();
    
    // Allow deletion of any item, but warn about sales
    $sold_quantity = $item['quantity_received'] - $item['remaining_stock'];
    $warning_message = '';
    if ($sold_quantity > 0) {
        $warning_message = ' (Warning: ' . $sold_quantity . ' units have been sold)';
    }
    
    // Delete the inventory item (hard delete)
    $delete_sql = "DELETE FROM inventory_items WHERE id = ? AND inventory_id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("ii", $inventory_item_id, $inventory_id);
    
    if ($delete_stmt->execute()) {
        // Check if this was the last item in the inventory
        $check_inventory_sql = "SELECT COUNT(*) as item_count FROM inventory_items WHERE inventory_id = ?";
        $check_inventory_stmt = $conn->prepare($check_inventory_sql);
        $check_inventory_stmt->bind_param("i", $inventory_id);
        $check_inventory_stmt->execute();
        $inventory_result = $check_inventory_stmt->get_result();
        $inventory_count = $inventory_result->fetch_assoc()['item_count'];
        
        // If no items left in this inventory, delete the inventory record too
        if ($inventory_count == 0) {
            $delete_inventory_sql = "DELETE FROM inventory WHERE id = ?";
            $delete_inventory_stmt = $conn->prepare($delete_inventory_sql);
            $delete_inventory_stmt->bind_param("i", $inventory_id);
            $delete_inventory_stmt->execute();
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Item "' . $item['item_name'] . '" has been deleted from inventory' . $warning_message
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to delete item']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?> 