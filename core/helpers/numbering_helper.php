<?php
/**
 * Helper functions for generating sequential invoice and watak numbers
 */

/**
 * Get the next sequential invoice number starting from 1
 */
function getNextInvoiceNumber($conn) {
    // First check if we have any new format numbers (4 digits starting with 0)
    $sql = "SELECT MAX(CAST(invoice_number AS UNSIGNED)) as max_number 
            FROM customer_invoices 
            WHERE invoice_number REGEXP '^[0-9]{4}$'";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    
    if ($row['max_number']) {
        // We have new format numbers, continue from the highest
        $next_number = $row['max_number'] + 1;
    } else {
        // No new format numbers yet, start from 1
        $next_number = 1;
    }
    
    return $next_number;
}

/**
 * Get the next sequential watak number starting from 1
 */
function getNextWatakNumber($conn) {
    // First check if we have any new format numbers (4 digits starting with 0)
    $sql = "SELECT MAX(CAST(watak_number AS UNSIGNED)) as max_number 
            FROM vendor_watak 
            WHERE watak_number REGEXP '^[0-9]{4}$'";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    
    if ($row['max_number']) {
        // We have new format numbers, continue from the highest
        $next_number = $row['max_number'] + 1;
    } else {
        // No new format numbers yet, start from 1
        $next_number = 1;
    }
    
    return $next_number;
}

/**
 * Format invoice number with leading zeros (e.g., 0001, 0002, etc.)
 */
function formatInvoiceNumber($number) {
    return sprintf('%04d', $number);
}

/**
 * Format watak number with leading zeros (e.g., 0001, 0002, etc.)
 */
function formatWatakNumber($number) {
    return sprintf('%04d', $number);
}
?> 