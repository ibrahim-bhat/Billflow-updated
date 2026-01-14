<?php
/**
 * Vendor Ledger Download Handler - Refactored to use MVC PDF system
 */

require_once __DIR__ . '/../../core/pdf/LedgerPDF.php';
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Check if vendor ID is provided
if (!isset($_GET['vendor_id']) || empty($_GET['vendor_id'])) {
    echo "Vendor ID is required.";
    exit();
}

$vendor_id = sanitizeInput($_GET['vendor_id']);

// Get date range (default to all time if not provided)
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '2000-01-01';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

try {
    $pdf = new LedgerPDF($conn);
    $pdf->generateVendorLedger($vendor_id, $start_date, $end_date);
} catch (Exception $e) {
    error_log("Error generating vendor ledger: " . $e->getMessage());
    echo "Error generating PDF: " . htmlspecialchars($e->getMessage());
    echo '<br><a href="../../views/vendors/index.php">Back to Vendors</a>';
}
