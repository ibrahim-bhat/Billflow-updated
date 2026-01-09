<?php
// Required headers for JSON response
header('Content-Type: application/json');

// Get the JSON data from the request
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

// Check if data was received properly
if ($data === null || !isset($data['items']) || !is_array($data['items'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON data received'
    ]);
    exit;
}

// Calculate totals
$totalAmount = 0;
$totalWeight = 0;

foreach ($data['items'] as $item) {
    $weight = isset($item['weight']) ? floatval($item['weight']) : 0;
    $amount = isset($item['amount']) ? floatval($item['amount']) : 0;
    
    $totalAmount += $amount;
    $totalWeight += $weight;
}

// Return the result
echo json_encode([
    'success' => true,
    'totalAmount' => $totalAmount,
    'totalWeight' => $totalWeight
]);
