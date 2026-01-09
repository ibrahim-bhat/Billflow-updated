<?php
// Required headers for JSON response
header('Content-Type: application/json');

// Get the JSON data from the request
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

// Check if data was received properly
if ($data === null) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON data received'
    ]);
    exit;
}

// Extract values
$quantity = isset($data['quantity']) ? floatval($data['quantity']) : 0;
$weight = isset($data['weight']) ? floatval($data['weight']) : 0;
$rate = isset($data['rate']) ? floatval($data['rate']) : 0;

// Calculate amount
$amount = 0;
if ($weight > 0) {
    $amount = $weight * $rate;
} else {
    $amount = $quantity * $rate;
}

// Return the result
echo json_encode([
    'success' => true,
    'amount' => $amount
]);
