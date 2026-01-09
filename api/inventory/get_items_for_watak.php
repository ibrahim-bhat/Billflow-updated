<?php
require_once __DIR__ . '/../../config/config.php';

// Set JSON header
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    // Check database connection
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    // Test if tables exist
    $test_sql = "SHOW TABLES LIKE 'items'";
    $test_result = $conn->query($test_sql);
    if ($test_result->num_rows === 0) {
        throw new Exception('Items table does not exist');
    }

    // Query to get ALL items from the system for manual watak creation
    // Simplified query to avoid potential JOIN issues
    $sql = "SELECT 
            id,
            name,
            0 as rate,
            0 as weight
            FROM items 
            ORDER BY id";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare statement failed: ' . $conn->error);
    }

    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    if (!$result) {
        throw new Exception('Get result failed: ' . $stmt->error);
    }

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'rate' => $row['rate'],
            'weight' => $row['weight']
        ];
    }

    // Log success for debugging
    error_log('get_all_items_for_watak.php success: Found ' . count($items) . ' items');

    echo json_encode([
        'success' => true, 
        'items' => $items,
        'count' => count($items),
        'debug_info' => [
            'database' => DB_NAME,
            'server' => DB_SERVER,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);

} catch (Exception $e) {
    // Log error for debugging
    error_log('get_all_items_for_watak.php error: ' . $e->getMessage());
    error_log('get_all_items_for_watak.php database: ' . DB_NAME);
    error_log('get_all_items_for_watak.php server: ' . DB_SERVER);
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'items' => [],
        'count' => 0,
        'debug_info' => [
            'database' => DB_NAME,
            'server' => DB_SERVER,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
}
?> 