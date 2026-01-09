<?php
// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Get JSON data from request
$jsonData = file_get_contents('php://input');
$data = json_decode($jsonData, true);

// Validate data
if (!isset($data['items']) || !is_array($data['items'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid data format']);
    exit();
}

// Calculate totals
$totalAmount = 0;
$totalWeight = 0;

foreach ($data['items'] as $item) {
    $totalAmount += floatval($item['amount'] ?? 0);
    $totalWeight += floatval($item['weight'] ?? 0);
}

// Return calculated totals as JSON
echo json_encode([
    'success' => true,
    'totalAmount' => $totalAmount,
    'totalWeight' => $totalWeight
]);
?>
