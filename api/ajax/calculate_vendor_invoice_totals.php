<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json');

// Get input parameters
$amounts = isset($_POST['amounts']) ? $_POST['amounts'] : [];

// Calculate totals
$subtotal = 0;
foreach ($amounts as $amount) {
    $subtotal += floatval($amount);
}

// Round to 2 decimal places
$subtotal = round($subtotal, 2);

// Return result
echo json_encode([
    'subtotal' => $subtotal,
    'formatted_subtotal' => '₹' . number_format($subtotal, 2),
    'total' => $subtotal,
    'formatted_total' => '₹' . number_format($subtotal, 2)
]);
?>
