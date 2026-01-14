<?php
/**
 * Customer Ledger Download Handler - Uses DomPDF for PDF generation
 */

require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Check if customer ID is provided
if (!isset($_GET['customer_id']) || empty($_GET['customer_id'])) {
    echo "Customer ID is required.";
    exit();
}

$customer_id = sanitizeInput($_GET['customer_id']);

// Get date range (default to all time if not provided)
$start_date = isset($_GET['start_date']) ? sanitizeInput($_GET['start_date']) : '2000-01-01';
$end_date = isset($_GET['end_date']) ? sanitizeInput($_GET['end_date']) : date('Y-m-d');

// Use Dompdf for PDF generation
use Dompdf\Dompdf;
use Dompdf\Options;

$vendorPath = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($vendorPath)) {
    die("Dependencies not found (vendor/autoload.php missing). Please run 'composer install' in the project root.");
}

require_once $vendorPath;

// Get customer details
$customer_sql = "SELECT name, contact, location, balance FROM customers WHERE id = ?";
$customer_stmt = $conn->prepare($customer_sql);
$customer_stmt->bind_param("i", $customer_id);
$customer_stmt->execute();
$customer_result = $customer_stmt->get_result();
$customer = $customer_result->fetch_assoc();
$customer_stmt->close();

if (!$customer) {
    echo "Customer not found.";
    exit();
}

// Get company settings
$company_settings = [
    'company_name' => 'BillFlow',
    'business_tagline' => '',
    'trademark' => '',
    'contact_numbers' => '',
    'logo_path' => ''
];

try {
    $sql = "SELECT company_name, business_tagline, trademark, contact_numbers, logo_path FROM company_settings LIMIT 1";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $db_settings = $result->fetch_assoc();
        foreach ($company_settings as $key => $value) {
            if (isset($db_settings[$key]) && !empty($db_settings[$key])) {
                $company_settings[$key] = $db_settings[$key];
            }
        }
    }
} catch (Exception $e) {
    error_log("Error fetching company settings: " . $e->getMessage());
}

// Get all transactions (invoices and payments)
$transactions = [];

// Get invoices
$invoices_sql = "SELECT 'invoice' as type, id, invoice_number as reference, date, total_amount as amount, 0 as payment 
                 FROM customer_invoices 
                 WHERE customer_id = ? AND date BETWEEN ? AND ?";
$invoices_stmt = $conn->prepare($invoices_sql);
$invoices_stmt->bind_param("iss", $customer_id, $start_date, $end_date);
$invoices_stmt->execute();
$invoices_result = $invoices_stmt->get_result();
while ($row = $invoices_result->fetch_assoc()) {
    $transactions[] = $row;
}
$invoices_stmt->close();

// Get payments
$payments_sql = "SELECT 'payment' as type, id, receipt_no as reference, date, 0 as amount, amount as payment 
                 FROM customer_payments 
                 WHERE customer_id = ? AND date BETWEEN ? AND ?";
$payments_stmt = $conn->prepare($payments_sql);
$payments_stmt->bind_param("iss", $customer_id, $start_date, $end_date);
$payments_stmt->execute();
$payments_result = $payments_stmt->get_result();
while ($row = $payments_result->fetch_assoc()) {
    $transactions[] = $row;
}
$payments_stmt->close();

// Sort by date
usort($transactions, function($a, $b) {
    return strtotime($a['date']) - strtotime($b['date']);
});

// Calculate running balance
$opening_balance = 0;
// Get opening balance (transactions before start date)
$opening_sql = "SELECT 
                (COALESCE(SUM(ci.total_amount), 0) - COALESCE(SUM(cp.amount), 0)) as balance
                FROM customers c
                LEFT JOIN customer_invoices ci ON c.id = ci.customer_id AND ci.date < ?
                LEFT JOIN customer_payments cp ON c.id = cp.customer_id AND cp.date < ?
                WHERE c.id = ?";
$opening_stmt = $conn->prepare($opening_sql);
$opening_stmt->bind_param("ssi", $start_date, $start_date, $customer_id);
$opening_stmt->execute();
$opening_result = $opening_stmt->get_result();
$opening_row = $opening_result->fetch_assoc();
$opening_balance = floatval($opening_row['balance']);
$opening_stmt->close();

// Initialize PDF
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Arial');

$dompdf = new Dompdf($options);
$dompdf->setPaper('A4', 'portrait');

// Start HTML
$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Customer Ledger - ' . htmlspecialchars($customer['name']) . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; font-size: 12px; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 15px; }
        .company-name { font-size: 24px; font-weight: bold; margin: 10px 0; }
        .document-title { font-size: 18px; font-weight: bold; margin: 20px 0; text-align: center; }
        .customer-info { margin: 15px 0; padding: 10px; background: #f8f9fa; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th { background: #0d6efd; color: white; padding: 8px; text-align: left; font-size: 11px; border: 1px solid #000; }
        td { padding: 6px 8px; border: 1px solid #dee2e6; font-size: 11px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .balance-row { font-weight: bold; background: #e9ecef; }
        .footer { margin-top: 30px; text-align: center; font-size: 10px; border-top: 1px solid #dee2e6; padding-top: 10px; }
    </style>
</head>
<body>';

// Header
$html .= '<div class="header">';
if (!empty($company_settings['logo_path']) && file_exists(__DIR__ . '/../../' . $company_settings['logo_path'])) {
    $html .= '<img src="' . __DIR__ . '/../../' . htmlspecialchars($company_settings['logo_path']) . '" style="max-width: 100px; max-height: 70px;" /><br>';
}
$html .= '<div class="company-name">' . htmlspecialchars($company_settings['company_name']) . '</div>';
if (!empty($company_settings['business_tagline'])) {
    $html .= '<div style="font-size: 14px;">' . htmlspecialchars($company_settings['business_tagline']) . '</div>';
}
if (!empty($company_settings['contact_numbers'])) {
    $html .= '<div style="font-size: 11px; margin-top: 5px;">' . htmlspecialchars($company_settings['contact_numbers']) . '</div>';
}
$html .= '</div>';

// Document title and customer info
$html .= '<div class="document-title">Customer Ledger</div>';
$html .= '<div class="customer-info">';
$html .= '<strong>Customer:</strong> ' . htmlspecialchars($customer['name']) . '<br>';
$html .= '<strong>Contact:</strong> ' . htmlspecialchars($customer['contact']) . '<br>';
$html .= '<strong>Location:</strong> ' . htmlspecialchars($customer['location']) . '<br>';
$html .= '<strong>Period:</strong> ' . date('d/m/Y', strtotime($start_date)) . ' to ' . date('d/m/Y', strtotime($end_date)) . '<br>';
$html .= '<strong>Current Balance:</strong> ₹' . number_format($customer['balance'], 2);
$html .= '</div>';

// Transactions table
$html .= '<table>';
$html .= '<thead><tr>';
$html .= '<th>Date</th><th>Type</th><th>Reference</th><th class="text-right">Debit</th><th class="text-right">Credit</th><th class="text-right">Balance</th>';
$html .= '</tr></thead><tbody>';

// Opening balance row
$running_balance = $opening_balance;
$html .= '<tr class="balance-row">';
$html .= '<td colspan="5">Opening Balance</td>';
$html .= '<td class="text-right">₹' . number_format($running_balance, 2) . '</td>';
$html .= '</tr>';

// Transaction rows
foreach ($transactions as $transaction) {
    $html .= '<tr>';
    $html .= '<td>' . date('d/m/Y', strtotime($transaction['date'])) . '</td>';
    $html .= '<td>' . ucfirst($transaction['type']) . '</td>';
    $html .= '<td>' . htmlspecialchars($transaction['reference']) . '</td>';
    
    if ($transaction['type'] == 'invoice') {
        $running_balance += $transaction['amount'];
        $html .= '<td class="text-right">₹' . number_format($transaction['amount'], 2) . '</td>';
        $html .= '<td class="text-right">-</td>';
    } else {
        $running_balance -= $transaction['payment'];
        $html .= '<td class="text-right">-</td>';
        $html .= '<td class="text-right">₹' . number_format($transaction['payment'], 2) . '</td>';
    }
    
    $html .= '<td class="text-right">₹' . number_format($running_balance, 2) . '</td>';
    $html .= '</tr>';
}

// Closing balance
$html .= '<tr class="balance-row">';
$html .= '<td colspan="5">Closing Balance</td>';
$html .= '<td class="text-right">₹' . number_format($running_balance, 2) . '</td>';
$html .= '</tr>';

$html .= '</tbody></table>';

// Footer
$html .= '<div class="footer">';
if (!empty($company_settings['trademark'])) {
    $html .= htmlspecialchars($company_settings['trademark']) . '<br>';
}
$html .= 'Generated on: ' . date('d/m/Y h:i A');
$html .= '</div>';

$html .= '</body></html>';

// Generate PDF
try {
    $dompdf->loadHtml($html);
    $dompdf->render();
    $dompdf->stream('Customer_Ledger_' . $customer['name'] . '_' . $start_date . '_to_' . $end_date . '.pdf', ['Attachment' => true]);
} catch (Exception $e) {
    error_log("Error generating PDF: " . $e->getMessage());
    echo "Error generating PDF: " . htmlspecialchars($e->getMessage());
    echo '<br><a href="../../views/customers/index.php">Back to Customers</a>';
}
?>
