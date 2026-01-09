<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json');

// Get input parameters
$quantity = isset($_POST['quantity']) ? floatval($_POST['quantity']) : 0;
$weight = isset($_POST['weight']) ? floatval($_POST['weight']) : 0;
$rate = isset($_POST['rate']) ? floatval($_POST['rate']) : 0;

// Calculate amount
$amount = 0;
if ($weight > 0) {
    $amount = $weight * $rate;
} else if ($quantity > 0) {
    $amount = $quantity * $rate;
}

// Return result
echo json_encode([
    'amount' => round($amount, 2),
    'formatted_amount' => '₹' . number_format($amount, 2)
]);
?>
