<?php
// Include session configuration
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
    'company_name' => 'KICHLOO AND CO.',
    'company_address' => '75, 313 Iqbal Sabzi Mandi, Bagh Nand Singh, Tatoo Ground, Batamaloo, Sgr.',
    'company_phone' => 'Ali Mohd: 9419087654, Sajad Ali: 7889718295, Umer Ali: 7006342174',
    'company_email' => '',
    'business_tagline' => 'Wholesale Dealers of Vegetables',
    'trademark' => 'KAC',
    'contact_numbers' => 'Ali Mohd: 9419067657\nSajad Ali: 7889718295\nUmer Ali: 7006342374',
    'bank_account_details' => 'A/c No: 0634020100000100, IFSC: JAKA0MEHJUR, GPay/MPay: 7889718295'
];

try {
    $sql = "SELECT * FROM company_settings LIMIT 1";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $db_settings = $result->fetch_assoc();
        if (!empty($db_settings['company_name'])) {
            $company_settings['company_name'] = strtoupper($db_settings['company_name']);
        }
        if (!empty($db_settings['company_address'])) {
            $company_settings['company_address'] = $db_settings['company_address'];
        }
        if (!empty($db_settings['company_phone'])) {
            $company_settings['company_phone'] = $db_settings['company_phone'];
        }
        if (!empty($db_settings['company_email'])) {
            $company_settings['company_email'] = $db_settings['company_email'];
        }
        if (!empty($db_settings['business_tagline'])) {
            $company_settings['business_tagline'] = $db_settings['business_tagline'];
        }
        if (!empty($db_settings['trademark'])) {
            $company_settings['trademark'] = $db_settings['trademark'];
        }
        if (!empty($db_settings['contact_numbers'])) {
            $company_settings['contact_numbers'] = $db_settings['contact_numbers'];
        }
        if (!empty($db_settings['bank_account_details'])) {
            $company_settings['bank_account_details'] = $db_settings['bank_account_details'];
        }
    }
} catch (Exception $e) {
    error_log("Error fetching company settings: " . $e->getMessage());
}

// Get all invoices for this customer within the date range
$sql = "SELECT ci.*, c.name as customer_name, c.contact as customer_contact, 
        c.location as customer_location, c.balance as customer_balance
        FROM customer_invoices ci
        JOIN customers c ON ci.customer_id = c.id
        WHERE ci.customer_id = ? AND DATE(ci.date) BETWEEN ? AND ?
        ORDER BY COALESCE(ci.display_date, ci.date) ASC, CAST(ci.invoice_number AS UNSIGNED) ASC";

$invoices = array();

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("iss", $customer_id, $start_date, $end_date);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            // Get invoice items
            $items_sql = "SELECT cii.*, i.name as item_name 
                         FROM customer_invoice_items cii 
                         LEFT JOIN items i ON cii.item_id = i.id
                         WHERE cii.invoice_id = ?";
            $items_stmt = $conn->prepare($items_sql);
            $items_stmt->bind_param("i", $row['id']);
            $items_stmt->execute();
            $items_result = $items_stmt->get_result();
            
            $items = array();
            while ($item = $items_result->fetch_assoc()) {
                $items[] = $item;
            }
            $items_stmt->close();
            
            $row['items'] = $items;
            $invoices[] = $row;
        }
    }
    
    $stmt->close();
}

// Format dates for display
$formatted_start_date = date('d/m/Y', strtotime($start_date));
$formatted_end_date = date('d/m/Y', strtotime($end_date));

// Generate filename
$filename = 'customer_bills_' . $customer['name'] . '_' . $start_date . '_to_' . $end_date . '.pdf';

// Include DomPDF
require_once __DIR__ . '/../../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

try {
    // Create DomPDF instance
    $dompdf = new Dompdf();
    
    // Configure DomPDF options
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Arial');
    $dompdf->setOptions($options);
    
    // Set paper size
    $dompdf->setPaper('A4', 'portrait');
    
    // Generate HTML content
    $html = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Customer Bills - ' . htmlspecialchars($customer['name']) . '</title>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">

        <style>
            /* General Body Styles */
            body {
                font-family: "Poppins", Arial, sans-serif;
                background-color: #ffffff;
                color: #333;
                margin: 0;
                padding: 0;
                font-size: 12px;
            }

            /* Main container for the invoice */
            .invoice-container {
                max-width: 800px;
                margin: 0 auto;
                background-color: #ffffff;
                padding: 10px;
            }

            /* Header section */
            header {
                text-align: center;
                margin-bottom: 20px;
            }

            header h1 {
                font-size: 24px;
                font-weight: 700;
                margin: 0;
                margin-bottom: 5px;
            }

            header h2 {
                font-size: 16px;
                font-weight: 600;
                margin: 0;
                color: #333;
                text-transform: uppercase;
            }

            header hr {
                border: 0;
                height: 1px;
                background-color: #ccc;
                margin-top: 10px;
                margin-bottom: 15px;
            }

            /* Invoice details section with Flexbox for layout */
            .invoice-details {
                display: flex;
                justify-content: space-between;
                margin-bottom: 20px;
                font-size: 12px;
                width: 100%;
            }
            
            .invoice-details .left-column,
            .invoice-details .right-column {
                display: flex;
                flex-direction: column;
            }

            .invoice-details .left-column {
                flex: 1;
            }

            .invoice-details .right-column {
                text-align: right;
                flex: 1;
                justify-content: flex-end;
            }

            .invoice-details p {
                margin: 2px 0;
                font-size: 12px;
            }

            /* Items Table */
            .items-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
                font-size: 11px;
            }

            /* Table Header */
            .items-table thead th {
                background-color: #0d6efd;
                color: #ffffff;
                padding: 8px;
                font-weight: 600;
                font-size: 11px;
                text-align: center;
            }

            /* Table Body */
            .items-table tbody td {
                padding: 6px 8px;
                border-bottom: 1px solid #dee2e6;
                font-size: 11px;
            }
            
            /* Align specific columns */
            .items-table td:nth-child(1),
            .items-table td:nth-child(3),
            .items-table td:nth-child(4) {
                text-align: center;
            }
            
            .items-table td:nth-child(2) {
                text-align: left;
            }

            .items-table td:nth-child(5),
            .items-table td:nth-child(6) {
                text-align: right;
            }

            /* Alternating row color */
            .items-table tbody tr:nth-child(even) {
                background-color: #f8f9fa;
            }
            
            /* Grand Total Section */
            .grand-total {
                font-size: 14px;
                font-weight: 700;
                text-align: left;
                margin-top: 15px;
            }
            
            /* Footer */
            .footer {
                margin-top: 20px;
                font-size: 10px;
                color: #6c757d;
                border-top: 1px solid #dee2e6;
                padding-top: 10px;
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
            }
            .footer .account-details {
                text-align: left;
            }
            .footer .copyright {
                text-align: right;
            }
            .footer p {
                margin: 2px 0;
            }
            
            /* Invoice section for multiple invoices */
            .invoice-section {
                page-break-inside: avoid;
                page-break-before: always;
                margin-bottom: 30px;
            }
            .invoice-section:first-child {
                page-break-before: auto;
            }
        </style>
    </head>
    <body>';

    // Add each invoice
    foreach ($invoices as $index => $invoice) {
        $html .= '
        <div class="invoice-section">
            <div class="invoice-container">
                <header>
                    <h1>' . htmlspecialchars($company_settings['company_name']) . '</h1>
                    <hr>
                </header>

                <section class="invoice-details">
                    <div class="left-column">
                        <p style="font-size: 16px; font-weight: bold;">Date: ' . date('d/m/Y', strtotime($invoice['display_date'] ?: $invoice['date'])) . '</p>
                        <p style="font-size: 16px; font-weight: bold;">Ledger Balance: Rs. ' . number_format(abs($customer['balance']), 2) . '</p>
                    </div>
                    <div class="right-column">
                        <p style="font-size: 16px; font-weight: bold;">Customer Name: ' . htmlspecialchars($customer['name']) . '</p>
                        <p style="font-size: 16px; font-weight: bold;">Invoice Number: ' . htmlspecialchars($invoice['invoice_number']) . '</p>
                    </div>
                </section>

                <table class="items-table">
                    <thead>
                        <tr>
                            <th>SNO</th>
                            <th>ITEM NAME</th>
                            <th>QTY</th>
                            <th>WEIGHT</th>
                            <th>RATE (Rs.)</th>
                            <th>TOTAL (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>';

        $sno = 1;
        // Use stored total amount from database instead of recalculating
        $invoice_total = $invoice['total_amount'];
        foreach ($invoice['items'] as $item) {
            $html .= '
                        <tr>
                            <td>' . $sno++ . '</td>
                            <td>' . htmlspecialchars($item['item_name']) . '</td>
                            <td>' . number_format($item['quantity'], 0) . '</td>
                            <td>' . ($item['weight'] ? number_format($item['weight'], 2) : '-') . '</td>
                            <td>Rs. ' . number_format($item['rate'], 2) . '</td>
                            <td>Rs. ' . number_format($item['amount'], 2) . '</td>
                        </tr>';
        }

        $html .= '
                    </tbody>
                </table>

                <div class="grand-total">
                    Total Amount: Rs. ' . number_format($invoice_total, 2) . '
                </div>

                <!-- Footer -->
                <div class="footer">
                    <div class="account-details">
                        <p>Thank you for your business!</p>
                        <p><strong>Bank Details:</strong></p>
                        ' . nl2br(htmlspecialchars($company_settings['bank_account_details'])) . '
                    </div>
                    <div class="copyright">
                        <p style="font-size: 14px; font-weight: bold;">Software by ibrahimbhat.com</p>
                        <p style="font-size: 14px; font-weight: bold;">Powered by Evotec.in - Complete Business Management Solution</p>
                    </div>
                </div>
            </div>
        </div>';
    }

    $html .= '
    </body>
    </html>';

    // Load HTML into DomPDF
    $dompdf->loadHtml($html);
    
    // Render PDF
    $dompdf->render();
    
    // Output PDF
    $dompdf->stream($filename, array('Attachment' => true));
    exit;
    
} catch (Exception $e) {
    // Log the error
    error_log("Error generating customer bills: " . $e->getMessage());
    // Display a user-friendly error message
    echo "Error: " . $e->getMessage();
    exit;
}
?> 