<?php
/**
 * Customer Bills Download Handler - Uses DomPDF for PDF generation
 */

require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Check if customer ID and date range are provided
if (!isset($_GET['customer_id']) || empty($_GET['customer_id']) || 
    !isset($_GET['start_date']) || empty($_GET['start_date']) || 
    !isset($_GET['end_date']) || empty($_GET['end_date'])) {
    echo "Customer ID and date range are required.";
    exit();
}

$customer_id = sanitizeInput($_GET['customer_id']);
$start_date = sanitizeInput($_GET['start_date']);
$end_date = sanitizeInput($_GET['end_date']);

// Validate date format
if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $start_date) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $end_date)) {
    echo "Invalid date format. Please use YYYY-MM-DD format.";
    exit();
}

// Use Dompdf for PDF generation
use Dompdf\Dompdf;
use Dompdf\Options;

$vendorPath = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($vendorPath)) {
    die("Dependencies not found (vendor/autoload.php missing). Please run 'composer install' in the project root.");
}

require_once $vendorPath;

// Get customer details
$customer_sql = "SELECT name, contact, location FROM customers WHERE id = ?";
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

// Get invoices for the customer within the date range
$invoices_sql = "SELECT ci.* 
                 FROM customer_invoices ci 
                 WHERE ci.customer_id = ? 
                 AND ci.date BETWEEN ? AND ? 
                 ORDER BY ci.date ASC, ci.invoice_number ASC";
$invoices_stmt = $conn->prepare($invoices_sql);
$invoices_stmt->bind_param("iss", $customer_id, $start_date, $end_date);
$invoices_stmt->execute();
$invoices_result = $invoices_stmt->get_result();
$invoices = [];
while ($row = $invoices_result->fetch_assoc()) {
    $invoices[] = $row;
}
$invoices_stmt->close();

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
    <title>Customer Bills - ' . htmlspecialchars($customer['name']) . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; font-size: 12px; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 15px; }
        .company-name { font-size: 24px; font-weight: bold; margin: 10px 0; }
        .document-title { font-size: 18px; font-weight: bold; margin: 20px 0; }
        .customer-info { margin: 15px 0; padding: 10px; background: #f8f9fa; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th { background: #0d6efd; color: white; padding: 8px; text-align: left; font-size: 11px; }
        td { padding: 6px 8px; border: 1px solid #dee2e6; font-size: 11px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .invoice-section { margin: 30px 0; page-break-inside: avoid; }
        .invoice-header { background: #e9ecef; padding: 8px; margin-bottom: 10px; font-weight: bold; }
        .totals { text-align: right; margin-top: 15px; font-weight: bold; font-size: 14px; }
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
$html .= '<div class="document-title text-center">Customer Bills</div>';
$html .= '<div class="customer-info">';
$html .= '<strong>Customer:</strong> ' . htmlspecialchars($customer['name']) . '<br>';
$html .= '<strong>Contact:</strong> ' . htmlspecialchars($customer['contact']) . '<br>';
$html .= '<strong>Location:</strong> ' . htmlspecialchars($customer['location']) . '<br>';
$html .= '<strong>Period:</strong> ' . date('d/m/Y', strtotime($start_date)) . ' to ' . date('d/m/Y', strtotime($end_date));
$html .= '</div>';

// Invoices
$grand_total = 0;
foreach ($invoices as $invoice) {
    $html .= '<div class="invoice-section">';
    $html .= '<div class="invoice-header">';
    $html .= 'Invoice #' . htmlspecialchars($invoice['invoice_number']) . ' - ' . date('d/m/Y', strtotime($invoice['date']));
    $html .= '</div>';
    
    // Get invoice items
    $items_sql = "SELECT cii.*, i.name as item_name 
                  FROM customer_invoice_items cii 
                  JOIN items i ON cii.item_id = i.id 
                  WHERE cii.invoice_id = ?";
    $items_stmt = $conn->prepare($items_sql);
    $items_stmt->bind_param("i", $invoice['id']);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();
    
    $html .= '<table>';
    $html .= '<thead><tr>';
    $html .= '<th>#</th><th>Item</th><th class="text-right">Qty</th><th class="text-right">Rate</th><th class="text-right">Amount</th>';
    $html .= '</tr></thead><tbody>';
    
    $sr = 1;
    while ($item = $items_result->fetch_assoc()) {
        $html .= '<tr>';
        $html .= '<td>' . $sr++ . '</td>';
        $html .= '<td>' . htmlspecialchars($item['item_name']) . '</td>';
        $html .= '<td class="text-right">' . number_format($item['quantity'], 2) . '</td>';
        $html .= '<td class="text-right">₹' . number_format($item['rate'], 2) . '</td>';
        $html .= '<td class="text-right">₹' . number_format($item['amount'], 2) . '</td>';
        $html .= '</tr>';
    }
    $items_stmt->close();
    
    $html .= '</tbody></table>';
    $html .= '<div class="totals">Invoice Total: ₹' . number_format($invoice['total_amount'], 2) . '</div>';
    $html .= '</div>';
    
    $grand_total += $invoice['total_amount'];
}

$html .= '<div class="totals" style="font-size: 16px; border-top: 2px solid #333; padding-top: 10px; margin-top: 20px;">';
$html .= 'Grand Total: ₹' . number_format($grand_total, 2);
$html .= '</div>';

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
    $dompdf->stream('Customer_Bills_' . $customer['name'] . '_' . $start_date . '_to_' . $end_date . '.pdf', ['Attachment' => true]);
} catch (Exception $e) {
    error_log("Error generating PDF: " . $e->getMessage());
    echo "Error generating PDF: " . htmlspecialchars($e->getMessage());
    echo '<br><a href="../../views/customers/index.php">Back to Customers</a>';
}
?>
