<?php
/**
 * Vendor Wataks Download Handler - Refactored to use MVC PDF system
 */

require_once __DIR__ . '/../../core/pdf/WatakPDF.php';
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Check if vendor ID and date range are provided
if (!isset($_GET['vendor_id']) || empty($_GET['vendor_id']) || 
    !isset($_GET['start_date']) || empty($_GET['start_date']) || 
    !isset($_GET['end_date']) || empty($_GET['end_date'])) {
    echo "Vendor ID and date range are required.";
    exit();
}

$vendor_id = sanitizeInput($_GET['vendor_id']);
$start_date = sanitizeInput($_GET['start_date']);
$end_date = sanitizeInput($_GET['end_date']);

// Validate date format
if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $start_date) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $end_date)) {
    echo "Invalid date format. Please use YYYY-MM-DD format.";
    exit();
}

try {
    $pdf = new WatakPDF($conn);
    $pdf->generateVendorWataks($vendor_id, $start_date, $end_date);
} catch (Exception $e) {
    error_log("Error generating vendor wataks: " . $e->getMessage());
    echo "Error generating PDF: " . htmlspecialchars($e->getMessage());
    echo '<br><a href="../../views/vendors/index.php">Back to Vendors</a>';
}
