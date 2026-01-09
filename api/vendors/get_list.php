<?php
// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit();
}

// Initialize response array
$response = array('success' => false, 'vendors' => array());

// Get all vendors
$sql = "SELECT id, name FROM vendors ORDER BY name";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $vendors = array();
    while ($row = $result->fetch_assoc()) {
        $vendors[] = array(
            'id' => $row['id'],
            'name' => $row['name']
        );
    }
    
    $response['success'] = true;
    $response['vendors'] = $vendors;
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response); 