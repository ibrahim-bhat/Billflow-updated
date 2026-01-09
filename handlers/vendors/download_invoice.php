<?php
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Get parameters
$vendor_id = isset($_GET['vendor_id']) ? intval($_GET['vendor_id']) : 0;
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$date = isset($_GET['date']) ? $_GET['date'] : '';
$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Handle single date parameter (used for "Download All" on a specific date)
if ($date && !$start_date && !$end_date) {
    $start_date = $date;
    $end_date = $date;
}

// Validate required parameters
if ((!$vendor_id || !$start_date || !$end_date) && !$invoice_id) {
    die("Missing required parameters");
}

// Get vendor details
$vendor = null;
if ($vendor_id) {
    $sql = "SELECT * FROM vendors WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $vendor = $result->fetch_assoc();
    $stmt->close();
    
    if (!$vendor) {
        die("Vendor not found");
    }
}

// Get invoices
$invoices = [];
if ($invoice_id) {
    // Get a single invoice by ID
    $sql = "SELECT vi.*, v.name as vendor_name, v.type as vendor_type, v.vendor_category
            FROM vendor_invoices vi
            JOIN vendors v ON vi.vendor_id = v.id
            WHERE vi.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $invoice_id);
} else {
    // Get multiple invoices by vendor and date range
    // Use DATE() function to ensure exact date matching
    if ($start_date === $end_date) {
        // Single date - use DATE() for exact match
        $sql = "SELECT vi.*, v.name as vendor_name, v.type as vendor_type, v.vendor_category
                FROM vendor_invoices vi
                JOIN vendors v ON vi.vendor_id = v.id
                WHERE vi.vendor_id = ? AND DATE(vi.invoice_date) = ?
                ORDER BY CAST(vi.invoice_number AS UNSIGNED), vi.id ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $vendor_id, $start_date);
    } else {
        // Date range
        $sql = "SELECT vi.*, v.name as vendor_name, v.type as vendor_type, v.vendor_category
                FROM vendor_invoices vi
                JOIN vendors v ON vi.vendor_id = v.id
                WHERE vi.vendor_id = ? AND vi.invoice_date BETWEEN ? AND ?
                ORDER BY vi.invoice_date ASC, CAST(vi.invoice_number AS UNSIGNED), vi.id ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iss", $vendor_id, $start_date, $end_date);
    }
}

$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $invoices[] = $row;
}
$stmt->close();

if (empty($invoices)) {
    die("No invoices found for the specified criteria");
}

// Get invoice items for each invoice
foreach ($invoices as &$invoice) {
    $sql = "SELECT vii.*, i.name as item_name
            FROM vendor_invoice_items vii
            JOIN items i ON vii.item_id = i.id
            WHERE vii.invoice_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $invoice['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $invoice['items'] = [];
    $total_weight = 0;
    
    while ($item = $result->fetch_assoc()) {
        $invoice['items'][] = $item;
        $total_weight += floatval($item['weight']);
    }
    
    $invoice['total_weight'] = $total_weight;
    $stmt->close();
}

// Get company settings
$company = [];
$sql = "SELECT * FROM company_settings LIMIT 1";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    $company = $result->fetch_assoc();
}

// Set default company name if not found
if (empty($company['company_name'])) {
    $company['company_name'] = "BillFlow";
}

// Require DOMPDF library
require_once __DIR__ . '/../../vendor/autoload.php';

// Reference the Dompdf namespace
use Dompdf\Dompdf;
use Dompdf\Options;

// Initialize dompdf class
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Arial');
$dompdf = new Dompdf($options);

// Generate HTML content for PDF
$html = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Invoice</title>
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
            font-size: 12px;
            font-weight: bold;
        }
        .footer p {
            margin: 2px 0;
        }
        
        /* Page break */
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>';

// Parse bank account details and contact numbers from the database
$bank_account_details = !empty($company['bank_account_details']) ? explode("\r\n", $company['bank_account_details']) : [];

// For each invoice, generate a section
foreach ($invoices as $index => $invoice) {
    $formatted_date = date('d/m/Y', strtotime($invoice['invoice_date']));
    
    $html .= '
        <div class="invoice-container">
            <header>
                <h1>' . htmlspecialchars($company['company_name']) . '</h1>
                <hr>
            </header>

            <section class="invoice-details">
                <div class="left-column">
                    <p style="font-size: 16px; font-weight: bold;">Date: ' . $formatted_date . '</p>
                    <p style="font-size: 16px; font-weight: bold;">Status: ' . ucfirst(htmlspecialchars($invoice['payment_status'] ?? 'Pending')) . '</p>
                </div>
                <div class="right-column">
                    <p style="font-size: 16px; font-weight: bold;">Vendor Name: ' . htmlspecialchars($invoice['vendor_name']) . '</p>
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
    
    $total_amount = 0;
    foreach ($invoice['items'] as $i => $item) {
        $html .= '
                    <tr>
                        <td>' . ($i + 1) . '</td>
                        <td>' . htmlspecialchars($item['item_name']) . '</td>
                        <td>' . number_format($item['quantity'], 0) . '</td>
                        <td>' . ($item['weight'] ? number_format($item['weight'], 2) : '-') . '</td>
                        <td>Rs. ' . number_format($item['rate'], 2) . '</td>
                        <td>Rs. ' . number_format($item['amount'], 2) . '</td>
                    </tr>';
        $total_amount += $item['amount'];
    }
    
    $html .= '
                </tbody>
            </table>

            <div class="grand-total">
                Total Amount: Rs. ' . number_format($total_amount, 2) . '
            </div>

            <!-- Footer -->
            <div class="footer">
                <div class="account-details">
                    <p>Thank you for your business!</p>
                </div>
                <div class="copyright">
                    <p>Software by ibrahimbhat.com</p>
                    <p>Powered by Evotec.in - Complete Business Management Solution</p>
                </div>
            </div>
        </div>';
    
    // Add page break except for the last invoice
    if ($index < count($invoices) - 1) {
        $html .= '<div class="page-break"></div>';

    }
}

$html .= '</body></html>';

// Load HTML content
$dompdf->loadHtml($html);

// Set paper size and orientation
$dompdf->setPaper('A4', 'portrait');

// Render the HTML as PDF
$dompdf->render();

// Set filename
if (count($invoices) == 1) {
    $filename = 'Vendor_Invoice_' . $invoices[0]['invoice_number'] . '.pdf';
} else {
    $filename = 'Vendor_Invoices_' . $vendor['name'] . '_' . $start_date . '_to_' . $end_date . '.pdf';
    $filename = str_replace(' ', '_', $filename);
}

// Output the generated PDF (download)
$dompdf->stream($filename, array('Attachment' => true));