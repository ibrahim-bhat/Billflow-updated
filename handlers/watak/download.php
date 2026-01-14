<?php
/**
 * Watak Download Handler - Refactored to use MVC PDF system
 */

require_once __DIR__ . '/../../core/pdf/WatakPDF.php';
require_once __DIR__ . '/../../config/config.php';

$watak_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$watak_id) {
    echo "Invalid watak ID";
    exit();
}

try {
    $pdf = new WatakPDF($conn);
    $pdf->generateWatak($watak_id);
} catch (Exception $e) {
    error_log("Error generating watak: " . $e->getMessage());
    echo "Error generating PDF: " . htmlspecialchars($e->getMessage());
    echo '<br><a href="../../views/watak/index.php">Back to Watak</a>';
}
