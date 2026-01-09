<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1); // Enable display errors for debugging

// Start output buffering to prevent any unwanted output
ob_start();

// Increase memory limit for PDF generation
ini_set('memory_limit', '256M');

// Set maximum execution time
set_time_limit(60);

// Check if vendor/autoload.php exists
if (!file_exists('vendor/autoload.php')) {
    die("Dependencies not found. Please run 'composer install' to install dependencies.");
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

try {
$watak_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if (!$watak_id) {
        throw new Exception("Invalid watak ID");
    }

// Get company settings
$company = [
    'company_name' => 'BillFlow',
    'company_address' => 'Company Address',
    'business_tagline' => '',
    'trademark' => '',
    'contact_numbers' => '',
    'bank_account_details' => ''
];

try {
    $sql = "SELECT * FROM company_settings LIMIT 1";
    $company_result = $conn->query($sql);
    if ($company_result && $company_result->num_rows > 0) {
        $db_company = $company_result->fetch_assoc();
        // Merge database values with defaults
        $company = array_merge($company, $db_company);
    }
} catch (Exception $e) {
    // Keep default values if there's an error
    error_log("Error fetching company settings: " . $e->getMessage());
}

// Get watak details
$sql = "SELECT w.*, v.name as vendor_name, v.contact as vendor_contact, v.type as vendor_type, v.balance as vendor_balance
        FROM vendor_watak w
        JOIN vendors v ON w.vendor_id = v.id
        WHERE w.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $watak_id);
$stmt->execute();
$watak = $stmt->get_result()->fetch_assoc();

if (!$watak) {
        throw new Exception("Watak not found");
}

// Get watak items
$sql = "SELECT * FROM watak_items WHERE watak_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $watak_id);
$stmt->execute();
$items_result = $stmt->get_result();

// Store items in array for multiple use
$items_array = [];
while ($item = $items_result->fetch_assoc()) {
    $items_array[] = $item;
}

// Calculate labor charges using stored values
$total_labor = 0;

foreach ($items_array as $item) {
    // Use stored labor value instead of recalculating
    $total_labor += $item['labor'];
}

    // Set filename
    $filename = 'Watak_' . $watak['watak_number'] . '.pdf';

    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Initialize DomPDF with production settings
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Arial');
    
    $dompdf = new Dompdf($options);
    
    // Set document properties
    $dompdf->setPaper('A4', 'portrait');

// Create HTML content
    $html = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Watak Invoice - ' . htmlspecialchars($company['company_name']) . '</title>
        
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 0;
                padding: 15px;
                background-color: #ffffff;
                color: #333;
                font-size: 12px;
            }
            
            .invoice-container {
                width: 100%;
                max-width: 800px;
                margin: 0 auto;
                background-color: #ffffff;
                padding: 20px;
            }
            
            header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 15px;
            }
            
            header .title h1 {
                font-family: "Times New Roman", serif;
                font-size: 1.8em;
                color: #2E8B57;
                margin: 0;
                font-weight: bold;
                letter-spacing: 2px;
            }
            
            header .invoice-type {
                font-size: 1em;
                color: #333;
                margin-top: 0;
                font-weight: normal;
            }
            
            .top-section {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 15px;
                font-size: 0.9em;
                line-height: 1.4;
            }
            
            .top-section .left-info {
                flex: 1;
            }
            
            .top-section .right-info {
                flex: 1;
                text-align: right;
            }
            
            .top-section .right-info p strong {
                font-size: 1.2em;
            }
            
            .top-section p {
                margin: 0;
            }
            
            .invoice-details {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 15px;
                font-size: 0.9em;
            }
            
            .invoice-details .left-details {
                flex: 1;
            }
            
            .invoice-details .right-details {
                flex: 1;
                text-align: right;
            }
            
            .invoice-details p {
                margin: 0;
            }
            
            .items-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 15px;
            }
            
            .items-table th {
                background-color: #8E44AD;
                color: #ffffff;
                padding: 8px;
                text-align: center;
                font-weight: bold;
                border: 1px solid #8E44AD;
                font-size: 0.9em;
            }
            
            .items-table td {
                padding: 8px;
                border: 1px solid #ddd;
                text-align: center;
                font-size: 0.9em;
            }
            
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
            
            .summary-section {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-top: 5px;
                margin-bottom: 15px;
                line-height: 1.6;
            }
            
            .expenses-breakdown {
                flex: 1;
            }
            
            .sale-summary {
                flex: 1;
                text-align: right;
            }
            
            .sale-summary p {
                margin: 0;
                line-height: 1.4;
            }
            
            .summary-section p {
                margin: 0;
                font-size: 1em;
            }
            
            .label {
                display: inline-block;
                width: 120px;
                color: #333;
            }
            
            footer {
                text-align: center;
                margin-top: 15px;
                padding-top: 10px;
                border-top: 1px solid #000;
            }
            
            footer p {
                color: #333;
                font-size: 1em;
                margin: 0;
            }
        </style>
    </head>
    <body>

        <div class="invoice-container">
            <header>
                <div class="title">
                    <h1>' . htmlspecialchars($company['company_name']) . '</h1>
                </div>
                <div class="invoice-type">
                    Watak Invoice
                </div>
            </header>

            <section class="top-section">
                <div class="left-info">
                    ' . (!empty($company['business_tagline']) ? '<p>' . htmlspecialchars($company['business_tagline']) . '</p>' : '') . '
                    ' . (!empty($company['company_address']) ? '<p>' . htmlspecialchars($company['company_address']) . '</p>' : '') . '
                </div>
                <div class="right-info">
                      <p><strong>Bill To: ' . htmlspecialchars($watak['vendor_name']) . '</strong></p>
                      <p><strong>Date: ' . date('d/m/Y', strtotime($watak['inventory_date'] ?? $watak['date'])) . '</strong></p>
                    <p><strong>Vehicle Number: ' . htmlspecialchars($watak['vehicle_no'] ?? 'N/A') . '</strong></p>
                    <p><strong>Chalan No: ' . htmlspecialchars($watak['chalan_no'] ?? 'N/A') . '</strong></p>

 
                </div>
            </section>

            <section class="invoice-details">
                <div class="left-details">
                    ' . (!empty($company['contact_numbers']) ? '<p>' . nl2br(htmlspecialchars($company['contact_numbers'])) . '</p>' : '') . '
                </div>
                <div class="right-details">
                      <p><strong>Watak No: ' . htmlspecialchars($watak['watak_number']) . '</strong></p>

                </div>
            </section>

            <table class="items-table">
                <thead>
                    <tr>
                        <th>SNO</th>
                        <th>ITEM NAME</th>
                        <th>QTY</th>
                        <th>WEIGHT</th>
                        <th>RATE (Rs)</th>
                        <th>TOTAL (Rs)</th>
                    </tr>
                </thead>
                <tbody>';

    // Add watak items
    $sno = 1;
    foreach ($items_array as $item) {
        $html .= '
                    <tr>
                        <td>' . $sno . '</td>
                        <td>' . htmlspecialchars($item['item_name']) . '</td>
                        <td>' . number_format($item['quantity'], 0) . '</td>
                        <td>' . ($item['weight'] ? number_format($item['weight'], 1) : '-') . '</td>
                        <td>' . number_format($item['rate'], 1) . '</td>
                        <td>' . number_format($item['amount'], 0) . '</td>
                    </tr>';
        $sno++;
    }

    $html .= '
                </tbody>
            </table>

            <section class="summary-section">
                <div class="expenses-breakdown">
                    <p><strong>Expenses Breakdown:</strong></p>
                    <p><span class="label">Commission:</span> Rs. ' . number_format(floor($watak['total_commission']), 0) . '</p>
                    <p><span class="label">Labor Charges:</span> Rs. ' . number_format(floor($total_labor), 0) . '</p>
                    <p><span class="label">Vehicle Charges:</span> Rs. ' . number_format(floor($watak['vehicle_charges']), 0) . '</p>
                    <p><span class="label">Other Charges:</span> Rs. ' . number_format(floor($watak['other_charges']), 0) . '</p>
                    <p><span class="label">Bardan:</span> Rs. ' . number_format(floor($watak['bardan'] ?? 0), 0) . '</p>
                    <p><span class="label">Total Expenses:</span> Rs. ' . number_format(floor($watak['total_commission']) + floor($total_labor) + floor($watak['vehicle_charges']) + floor($watak['other_charges']) + floor($watak['bardan'] ?? 0), 0) . '</p>
                </div>
                <div class="sale-summary">';
                    
    // Apply rounding logic for Goods Sale Proceeds
    $goods_sale_proceeds = $watak['total_amount'];
    $decimal_part = $goods_sale_proceeds - floor($goods_sale_proceeds);
    if ($decimal_part >= 0.5) {
        $goods_sale_proceeds = ceil($goods_sale_proceeds);
    } else {
        $goods_sale_proceeds = floor($goods_sale_proceeds);
    }
    
    // Calculate expenses with no decimals
    $total_expenses_rounded = floor($watak['total_commission']) + floor($total_labor) + floor($watak['vehicle_charges']) + floor($watak['other_charges']) + floor($watak['bardan'] ?? 0);
    
    $html .= '
                    <p><strong>Goods Sale Proceeds:</strong> Rs.' . number_format($goods_sale_proceeds, 0) . '</p>
                    <p><strong>Expenses:</strong> Rs.' . number_format($total_expenses_rounded, 0) . '</p>
                    <p><strong>Net Amount:</strong> Rs.' . number_format(floor($goods_sale_proceeds - $total_expenses_rounded), 0) . '</p>
                </div>
            </section>

            <footer>
                <p>Thank you for your business!</p>
                <p style="margin-top: 5px; font-size: 10px; color: #999;">Software by ibrahimbhat.com</p>
                <p style="margin-top: 2px; font-size: 10px; color: #999;">Powered by Evotec.in - Complete Business Management Solution</p>
            </footer>
        </div>

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
    error_log("Error generating watak: " . $e->getMessage());
    // Display a user-friendly error message
    echo "Error: " . $e->getMessage();
exit; 
}
?> 