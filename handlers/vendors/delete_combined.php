<?php
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Get vendor ID and date from URL
$vendor_id = isset($_GET['vendor_id']) ? intval($_GET['vendor_id']) : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';

if (!$vendor_id || empty($date)) {
    $_SESSION['error_message'] = "Invalid request. Vendor ID and date are required.";
    header('Location: ../../views/vendors/index.php');
    exit();
}

// Get vendor details
$vendor_sql = "SELECT * FROM vendors WHERE id = ?";
$vendor_stmt = $conn->prepare($vendor_sql);
$vendor_stmt->bind_param('i', $vendor_id);
$vendor_stmt->execute();
$vendor = $vendor_stmt->get_result()->fetch_assoc();
$vendor_stmt->close();

if (!$vendor) {
    $_SESSION['error_message'] = "Vendor not found.";
    header('Location: ../../views/vendors/index.php');
    exit();
}

// We need to check if these invoices were created today
// First, get one invoice to check its created_at date
$check_sql = "SELECT created_at FROM vendor_invoices 
             WHERE vendor_id = ? AND DATE(invoice_date) = ? 
             LIMIT 1";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param('is', $vendor_id, $date);
$check_stmt->execute();
$check_result = $check_stmt->get_result()->fetch_assoc();
$check_stmt->close();

if ($check_result) {
    $created_date = date('Y-m-d', strtotime($check_result['created_at']));
    $today = date('Y-m-d');
    
    if ($created_date !== $today) {
        $_SESSION['error_message'] = "Cannot delete invoices created on previous days. Only today's invoices can be deleted.";
        header('Location: ../../views/vendors/index.php');
        exit();
    }
}

// Get all invoices for this vendor on this date that were created today
$invoices_sql = "SELECT * FROM vendor_invoices 
                WHERE vendor_id = ? AND DATE(invoice_date) = ? AND DATE(created_at) = CURDATE()
                ORDER BY CAST(invoice_number AS UNSIGNED)";
$invoices_stmt = $conn->prepare($invoices_sql);
$invoices_stmt->bind_param('is', $vendor_id, $date);
$invoices_stmt->execute();
$invoices_result = $invoices_stmt->get_result();
$invoices = [];
$total_invoice_amount = 0;

while ($row = $invoices_result->fetch_assoc()) {
    $invoices[] = $row;
    $total_invoice_amount += $row['total_amount'];
}
$invoices_stmt->close();

if (empty($invoices)) {
    $_SESSION['error_message'] = "No invoices found for this vendor on the selected date.";
    header('Location: ../../views/vendors/index.php');
    exit();
}

// Get invoice IDs
$invoice_ids = array_column($invoices, 'id');

// Start transaction
$conn->begin_transaction();

try {
    // Delete any payments linked to these invoices
    $delete_payments_sql = "DELETE FROM vendor_payments WHERE vendor_id = ? AND date >= ?";
    $delete_payments_stmt = $conn->prepare($delete_payments_sql);
    $delete_payments_stmt->bind_param('is', $vendor_id, $date);
    $delete_payments_stmt->execute();
    $payments_deleted = $delete_payments_stmt->affected_rows;
    $delete_payments_stmt->close();
    
    // Delete all invoice items for these invoices
    $invoice_ids_str = implode(',', $invoice_ids);
    $delete_items_sql = "DELETE FROM vendor_invoice_items WHERE invoice_id IN ($invoice_ids_str)";
    $conn->query($delete_items_sql);
    
    // Update vendor balance (subtract the total invoice amount)
    $update_balance_sql = "UPDATE vendors SET balance = balance - ? WHERE id = ?";
    $update_balance_stmt = $conn->prepare($update_balance_sql);
    $update_balance_stmt->bind_param('di', $total_invoice_amount, $vendor_id);
    $update_balance_stmt->execute();
    $update_balance_stmt->close();
    
    // Delete all invoices
    $delete_invoices_sql = "DELETE FROM vendor_invoices WHERE id IN ($invoice_ids_str)";
    $conn->query($delete_invoices_sql);
    
    // Commit transaction
    $conn->commit();
    
    $payment_message = $payments_deleted > 0 ? " and " . $payments_deleted . " associated payment(s)" : "";
    $_SESSION['success_message'] = "All invoices for " . $vendor['name'] . " on " . date('d/m/Y', strtotime($date)) . $payment_message . " have been deleted successfully.";
    header('Location: ../../views/vendors/index.php');
    exit();
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    $_SESSION['error_message'] = "Error deleting invoices: " . $e->getMessage();
    header('Location: ../../views/vendors/view_invoice.php?vendor_id=' . $vendor_id . '&date=' . $date);
    exit();
}
?>
