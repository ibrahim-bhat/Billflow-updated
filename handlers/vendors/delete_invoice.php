<?php
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Get invoice ID from URL
$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$invoice_id) {
    $_SESSION['error_message'] = "No invoice specified.";
    header('Location: ../../views/vendors/index.php');
    exit();
}

// Get invoice details to check if it's from today
$sql = "SELECT vi.*, v.id as vendor_id, v.name as vendor_name, v.balance as vendor_balance 
        FROM vendor_invoices vi 
        JOIN vendors v ON vi.vendor_id = v.id 
        WHERE vi.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $invoice_id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice) {
    $_SESSION['error_message'] = "Invoice not found.";
    header('Location: ../../views/vendors/index.php');
    exit();
}

// Check if invoice was created today (same day restriction for deletion)
$created_date = date('Y-m-d', strtotime($invoice['created_at']));
$today = date('Y-m-d');

if ($created_date !== $today) {
    $_SESSION['error_message'] = "Cannot delete invoices created on previous days. Only today's invoices can be deleted.";
    header('Location: ../../views/vendors/index.php');
    exit();
}

// Start transaction
$conn->begin_transaction();

try {
    // Delete any payments linked to this invoice
    $delete_payments_sql = "DELETE FROM vendor_payments WHERE vendor_id = ? AND date >= ?";
    $delete_payments_stmt = $conn->prepare($delete_payments_sql);
    $delete_payments_stmt->bind_param('is', $invoice['vendor_id'], $invoice['invoice_date']);
    $delete_payments_stmt->execute();
    $payments_deleted = $delete_payments_stmt->affected_rows;
    $delete_payments_stmt->close();
    
    // Delete invoice items
    $delete_items_sql = "DELETE FROM vendor_invoice_items WHERE invoice_id = ?";
    $delete_items_stmt = $conn->prepare($delete_items_sql);
    $delete_items_stmt->bind_param('i', $invoice_id);
    $delete_items_stmt->execute();
    $delete_items_stmt->close();
    
    // Update vendor balance (subtract the invoice amount)
    $update_balance_sql = "UPDATE vendors SET balance = balance - ? WHERE id = ?";
    $update_balance_stmt = $conn->prepare($update_balance_sql);
    $update_balance_stmt->bind_param('di', $invoice['total_amount'], $invoice['vendor_id']);
    $update_balance_stmt->execute();
    $update_balance_stmt->close();
    
    // Delete the invoice
    $delete_invoice_sql = "DELETE FROM vendor_invoices WHERE id = ?";
    $delete_invoice_stmt = $conn->prepare($delete_invoice_sql);
    $delete_invoice_stmt->bind_param('i', $invoice_id);
    $delete_invoice_stmt->execute();
    $delete_invoice_stmt->close();
    
    // Commit transaction
    $conn->commit();
    
    $payment_message = $payments_deleted > 0 ? " and " . $payments_deleted . " associated payment(s)" : "";
    $_SESSION['success_message'] = "Invoice #" . $invoice['invoice_number'] . $payment_message . " has been deleted successfully.";
    
    // Check if we need to redirect back to the combined view
    if (isset($_GET['redirect']) && $_GET['redirect'] === 'combined') {
        header('Location: ../../views/vendors/view_invoice.php?vendor_id=' . $invoice['vendor_id'] . '&date=' . urlencode($invoice['invoice_date']));
    } else {
        header('Location: ../../views/vendors/index.php');
    }
    exit();
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    $_SESSION['error_message'] = "Error deleting invoice: " . $e->getMessage();
    
    // Check if we need to redirect back to the combined view
    if (isset($_GET['redirect']) && $_GET['redirect'] === 'combined') {
        header('Location: ../../views/vendors/view_invoice.php?vendor_id=' . $invoice['vendor_id'] . '&date=' . urlencode($invoice['invoice_date']));
    } else {
        header('Location: ../../views/vendors/view_invoice.php?id=' . $invoice_id);
    }
    exit();
}
?>
