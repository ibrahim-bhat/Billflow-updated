<?php
// Include configuration
require_once __DIR__ . '/../../config/config.php';

// Set content type to JSON
header('Content-Type: application/json');

try {
    // Get all items
    $sql = "SELECT id, name FROM items ORDER BY name";
    $result = $conn->query($sql);
    
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => $row['id'],
            'name' => $row['name']
        ];
    }
    
    // Return the items in the format expected by the JavaScript
    echo json_encode([
        'success' => true,
        'items' => $items
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>