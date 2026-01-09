<?php
// Include database configuration
require_once __DIR__ . '/../../config/config.php';

// Set content type to JSON
header('Content-Type: application/json');

// Initialize response array
$response = array('success' => false);

// Check if item ID is provided
if (isset($_GET['item_id']) && !empty($_GET['item_id'])) {
    $item_id = intval($_GET['item_id']);
    
    // Get item details
    $sql = "SELECT i.*, 
            (SELECT COALESCE(SUM(ii.remaining_stock), 0) FROM inventory_items ii WHERE ii.item_id = i.id) AS available_stock
            FROM items i 
            WHERE i.id = ?";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $item_id);
        
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $response['item'] = array(
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'unit' => $row['unit'],
                    'default_rate' => $row['default_rate'],
                    'available_stock' => $row['available_stock']
                );
                
                $response['success'] = true;
            } else {
                $response['error'] = "Item not found";
            }
        } else {
            $response['error'] = "Query execution failed: " . $stmt->error;
        }
        
        $stmt->close();
    } else {
        $response['error'] = "Query preparation failed: " . $conn->error;
    }
} else {
    $response['error'] = "Item ID is required";
}

// Return JSON response
echo json_encode($response);
?> 