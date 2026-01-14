<?php
// Include session configuration
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
    'company_address' => '',
    'company_phone' => '',
    'company_email' => '',
    'company_gst' => '',
    'business_tagline' => '',
    'trademark' => '',
    'contact_numbers' => '',
    'bank_account_details' => '',
    'logo_path' => ''
];

try {
    $sql = "SELECT * FROM company_settings LIMIT 1";
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

// Get last payment date
$last_payment_sql = "SELECT MAX(date) as last_payment_date FROM customer_payments WHERE customer_id = ?";
$last_payment_stmt = $conn->prepare($last_payment_sql);
$last_payment_stmt->bind_param("i", $customer_id);
$last_payment_stmt->execute();
$last_payment_result = $last_payment_stmt->get_result();
$last_payment_row = $last_payment_result->fetch_assoc();
$last_payment_stmt->close();

$last_payment_date = $last_payment_row['last_payment_date'];

// Get last invoice date
$last_invoice_sql = "SELECT MAX(date) as last_invoice_date FROM customer_invoices WHERE customer_id = ?";
$last_invoice_stmt = $conn->prepare($last_invoice_sql);
$last_invoice_stmt->bind_param("i", $customer_id);
$last_invoice_stmt->execute();
$last_invoice_result = $last_invoice_stmt->get_result();
$last_invoice_row = $last_invoice_result->fetch_assoc();
$last_invoice_stmt->close();

$last_invoice_date = $last_invoice_row['last_invoice_date'];

// Use Dompdf for PDF generation
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../../vendor/autoload.php';

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Arial');

$dompdf = new Dompdf($options);
$dompdf->setPaper('A4', 'portrait');

// Generate HTML content
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Customer Payment Details</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #ffffff;
            color: #333;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: #ffffff;
            padding: 20px;
        }
        
        h1 {
            text-align: center;
            font-size: 24px;
            margin-bottom: 30px;
            color: #333;
        }
        
        .notification-box {
            background-color: #fff9e6;
            border: 1px solid #e6d9b8;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 5px;
        }
        
        .detail-row {
            margin-bottom: 15px;
        }
        
        .detail-label {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .detail-value {
            margin-bottom: 15px;
        }
        
        .balance {
            color: #d9534f;
            font-weight: bold;
        }
        
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 12px;
            color: #777;
        }
        
        .copyright {
            font-size: 14px;
            font-weight: bold;
            margin-top: 10px;
        }
        
        .generated-date {
            font-size: 12px;
            color: #777;
            text-align: center;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Customer Payment Details</h1>
        
        <div class="notification-box">
            <p><strong>Dear Valued Customer,</strong></p>
            <p>We kindly remind you that ' . ($last_payment_date ? 'your last payment was on ' . date('d/m/Y', strtotime($last_payment_date)) . ' (' . floor((time() - strtotime($last_payment_date)) / (60*60*24)) . ' days ago).' : 'you have not made any payment yet.') . ' Your outstanding balance is Rs. ' . number_format(abs($customer['balance']), 2) . '. Your continued business is important to us. Please arrange the payment at your earliest convenience.</p>
            <p><strong>Thank you for your cooperation.</strong></p>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Customer Name:</div>
            <div class="detail-value">' . htmlspecialchars($customer['name']) . '</div>
            </div>
            
        <div class="detail-row">
            <div class="detail-label">Outstanding Balance:</div>
            <div class="detail-value balance">Rs. ' . number_format(abs($customer['balance']), 2) . '</div>
            </div>
            
        <div class="detail-row">
            <div class="detail-label">Payment Status:</div>
            <div class="detail-value">' . ($last_payment_date ? date('d/m/Y', strtotime($last_payment_date)) : 'No payments made') . '</div>
            </div>
            
        <div class="detail-row">
            <div class="detail-label">Last Invoice:</div>
            <div class="detail-value">' . ($last_invoice_date ? date('d/m/Y', strtotime($last_invoice_date)) : 'No invoices') . '</div>
            </div>
            
        <div class="detail-row">
            <div class="detail-label">Last Payment:</div>
            <div class="detail-value">' . ($last_payment_date ? floor((time() - strtotime($last_payment_date)) / (60*60*24)) . ' days ago' : 'No payments') . '</div>
            </div>
            
        <div class="generated-date">
            Generated on ' . date('d/m/Y') . ' at ' . date('H:i:s') . '
        </div>
        
        <div class="footer">
            <div class="copyright" style="font-size: 14px; font-weight: bold;">Software by ibrahimbhat.com</div>
            <div class="copyright" style="font-size: 14px; font-weight: bold;">Powered by Evotec.in - Complete Business Management Solution</div>
        </div>
    </div>
</body>
</html>';

// Load HTML into Dompdf
$dompdf->loadHtml($html);

// Render PDF
$dompdf->render();

// Output PDF
$dompdf->stream('customer_payment_details_' . $customer_id . '_' . date('Y-m-d') . '.pdf', array('Attachment' => true));
exit;
?> 