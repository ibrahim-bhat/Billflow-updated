<?php
// Include database connection
require_once __DIR__ . '/../../config/config.php';

// Set header to JSON
header('Content-Type: application/json');

// Initialize response array
$response = [];

// Check if vendor_id is provided
$vendor_id = isset($_GET['vendor_id']) ? intval($_GET['vendor_id']) : null;

// SQL query
if ($vendor_id) {
    // Get items associated with this vendor (from inventory)
    $sql = "SELECT DISTINCT i.id, i.name, i.default_rate 
            FROM items i
            JOIN inventory_items ii ON i.id = ii.item_id
            JOIN inventory inv ON ii.inventory_id = inv.id
            WHERE inv.vendor_id = ?
            ORDER BY i.name";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $vendor_id);
} else {
    // Get all items
    $sql = "SELECT id, name, default_rate FROM items ORDER BY name";
    $stmt = $conn->prepare($sql);
}

// Execute query
if ($stmt->execute()) {
    $result = $stmt->get_result();
    
    // Fetch all items
    while ($row = $result->fetch_assoc()) {
        $response[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'default_rate' => $row['default_rate']
        ];
    }
} else {
    // Handle error
    $response = ['error' => 'Failed to fetch items'];
}

// Close statement
$stmt->close();

// Return JSON response
echo json_encode($response);
?> 