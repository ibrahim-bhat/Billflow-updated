<?php
/**
 * Vendor Invoice Download Handler - Refactored to use MVC PDF system
 */

require_once __DIR__ . '/../../core/pdf/InvoicePDF.php';
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Get parameters
$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Validate required parameters
if (!$invoice_id) {
    echo "Invoice ID is required";
    echo '<br><a href="../../views/vendors/index.php">Back to Vendors</a>';
    exit();
}

try {
    $pdf = new InvoicePDF($conn);
    $pdf->generateVendorInvoice($invoice_id);
} catch (Exception $e) {
    error_log("Error generating vendor invoice: " . $e->getMessage());
    echo "Error generating PDF: " . htmlspecialchars($e->getMessage());
    echo '<br><a href="../../views/vendors/index.php">Back to Vendors</a>';
}
