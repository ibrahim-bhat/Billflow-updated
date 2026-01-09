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
if (!isset($data['quantity']) || !isset($data['rate'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

// Extract values
$quantity = floatval($data['quantity']);
$weight = floatval($data['weight'] ?? 0);
$rate = floatval($data['rate']);

// Calculate amount based on updated logic:
// If weight is provided, use weight × rate
// If only quantity is provided, use quantity × rate
$amount = 0;
if ($weight > 0) {
    $amount = $weight * $rate;
} else {
    $amount = $quantity * $rate;
}

// Return calculated amount as JSON
echo json_encode([
    'success' => true,
    'amount' => $amount,
    'quantity' => $quantity,
    'weight' => $weight,
    'rate' => $rate
]);
?>
