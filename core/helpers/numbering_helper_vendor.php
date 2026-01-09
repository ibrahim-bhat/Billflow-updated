<?php
/**
 * Helper functions for generating sequential vendor invoice numbers
 */

/**
 * Get the next sequential vendor invoice number starting from 1
 */
function getNextVendorInvoiceNumber($conn) {
    // Get the highest invoice number across all vendors
    $sql = "SELECT MAX(CAST(invoice_number AS UNSIGNED)) as max_number 
            FROM vendor_invoices";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    
    if ($row && $row['max_number'] !== null) {
        // Increment the last invoice number
        $next_number = $row['max_number'] + 1;
    } else {
        // No invoice numbers yet, start from 1
        $next_number = 1;
    }
    
    return $next_number;
}

/**
 * Format vendor invoice number with leading zeros (e.g., 001, 002, etc.)
 */
function formatVendorInvoiceNumber($number) {
    return sprintf('%03d', $number);
}

// AJAX endpoint to get next invoice number
if (isset($_GET['action']) && $_GET['action'] === 'get_next_invoice_number') {
    require_once __DIR__ . '/../../config/config.php';
    $next_number = getNextVendorInvoiceNumber($conn);
    echo formatVendorInvoiceNumber($next_number);
    exit;
}
?>