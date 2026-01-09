<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1); // Enable for debugging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

// Start output buffering to prevent any unwanted output
ob_start();

// Increase memory limit for PDF generation
ini_set('memory_limit', '512M');

// Set maximum execution time
set_time_limit(120);

// Check if Composer autoload exists
if (!file_exists('vendor/autoload.php')) {
    die("Dependencies not found (vendor/autoload.php missing). Please run 'composer install' in the project root.");
}

try {
    require_once __DIR__ . '/../../config/config.php';
    require_once __DIR__ . '/../../vendor/autoload.php';
} catch (Exception $e) {
    die("Error loading dependencies: " . $e->getMessage());
}

use Dompdf\Dompdf;
use Dompdf\Options;

try {
    // Check if we're viewing a single invoice or combined invoices
    $is_single_invoice = isset($_GET['id']);

    if ($is_single_invoice) {
        // Get single invoice details
        $invoice_id = intval($_GET['id']);
        
        // Get invoice details
        $invoice_sql = "SELECT ci.*, c.name as customer_name, c.contact as customer_contact, 
                       c.location as customer_location, c.balance as customer_balance
                       FROM customer_invoices ci
                       JOIN customers c ON ci.customer_id = c.id
                       WHERE ci.id = ?";
        $invoice_stmt = $conn->prepare($invoice_sql);
        $invoice_stmt->bind_param("i", $invoice_id);
        $invoice_stmt->execute();
        $invoice_result = $invoice_stmt->get_result();
        
        if ($invoice_result->num_rows === 0) {
            throw new Exception("Invoice not found");
        }
        
        $invoice = $invoice_result->fetch_assoc();
        $customer_id = $invoice['customer_id'];
        // Prefer display date for rendering; fallback to system date
        $date = $invoice['display_date'] ?: $invoice['date'];
        $customer = [
            'id' => $customer_id,
            'name' => $invoice['customer_name'],
            'contact' => $invoice['customer_contact'],
            'location' => $invoice['customer_location'],
            'balance' => $invoice['customer_balance']
        ];
        $total_amount = $invoice['total_amount'];
        $invoice_numbers = [$invoice['invoice_number']];
        $invoice_ids = [$invoice_id];
        
        // Get invoice items
        $items_sql = "SELECT cii.*, i.name as item_name, v.name as vendor_name 
                     FROM customer_invoice_items cii
                     JOIN items i ON cii.item_id = i.id
                     LEFT JOIN vendors v ON cii.vendor_id = v.id
                     WHERE cii.invoice_id = ?
                     ORDER BY cii.id";
        $items_stmt = $conn->prepare($items_sql);
        $items_stmt->bind_param("i", $invoice_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        
    } else {
        // Handle combined invoices
        if (!isset($_GET['date']) || !isset($_GET['customer_id'])) {
            throw new Exception("Missing required parameters");
        }
        
        // System date for grouping combined invoices
        $date = sanitizeInput($_GET['date']);
        $customer_id = intval($_GET['customer_id']);
        
        // Get customer information
        $customer_sql = "SELECT * FROM customers WHERE id = ?";
        $customer_stmt = $conn->prepare($customer_sql);
        $customer_stmt->bind_param("i", $customer_id);
        $customer_stmt->execute();
        $customer_result = $customer_stmt->get_result();
        
        if ($customer_result->num_rows === 0) {
            throw new Exception("Customer not found");
        }
        
        $customer = $customer_result->fetch_assoc();
        
        // Get all invoices for this customer on this system date
        $invoices_sql = "SELECT * FROM customer_invoices 
                        WHERE customer_id = ? AND DATE(date) = ? 
                        ORDER BY CAST(invoice_number AS UNSIGNED)";
        $invoices_stmt = $conn->prepare($invoices_sql);
        $invoices_stmt->bind_param("is", $customer_id, $date);
        $invoices_stmt->execute();
        $invoices_result = $invoices_stmt->get_result();
        
        // Get all invoice items for these invoices
        $invoice_ids = [];
        $invoice_numbers = [];
        $total_amount = 0;
        $common_display_date = null;
        $all_same_display_date = true;
        
        while ($invoice = $invoices_result->fetch_assoc()) {
            $invoice_ids[] = $invoice['id'];
            $invoice_numbers[] = $invoice['invoice_number'];
            $total_amount += $invoice['total_amount'];
            $curr_disp = $invoice['display_date'];
            if ($common_display_date === null) {
                $common_display_date = $curr_disp;
            } else {
                if ($common_display_date !== $curr_disp) {
                    $all_same_display_date = false;
                }
            }
        }
        
        if (empty($invoice_ids)) {
            throw new Exception("No invoices found for this customer on this date");
        }
        
        // If display dates differ, do not combine
        if (!$all_same_display_date) {
            throw new Exception('Invoices have different dates. Please download individually.');
        }
        // Get all invoice items
        $items_sql = "SELECT cii.*, i.name as item_name, v.name as vendor_name 
                     FROM customer_invoice_items cii
                     JOIN items i ON cii.item_id = i.id
                     LEFT JOIN vendors v ON cii.vendor_id = v.id
                     WHERE cii.invoice_id IN (" . implode(',', $invoice_ids) . ")
                     ORDER BY cii.id";
        $items_result = $conn->query($items_sql);
    }

    // Get company settings
    $company_sql = "SELECT * FROM company_settings WHERE id = 1";
    $company_result = $conn->query($company_sql);
    if (!$company_result || $company_result->num_rows === 0) {
        // Create default company settings if not exists
        $company = [
            'company_name' => 'BillFlow',
            'company_address' => 'Srinagar 190021',
            'company_phone' => '+91 9906622700',
            'company_email' => 'info@billflow.com',
            'company_gst' => '',
            'bank_account_details' => '',
            'contact_numbers' => ''
        ];
    } else {
        $company = $company_result->fetch_assoc();
    }

    // Parse bank account details and contact numbers from the database
    $bank_account_details = $company['bank_account_details'] ? explode("\r\n", $company['bank_account_details']) : [];
    $contact_numbers = $company['contact_numbers'] ? explode("\r\n", $company['contact_numbers']) : [];
    
    // Format account details for display
    $account_html = '';
    foreach ($bank_account_details as $detail) {
        $account_html .= '<p>' . htmlspecialchars($detail) . '</p>';
    }
    
    // Format contact numbers for display
    $contact_html = '';
    foreach ($contact_numbers as $contact) {
        $contact_html .= '<p>' . htmlspecialchars($contact) . '</p>';
    }

    // Format the date for display (prefer common display date when uniform)
    if ($is_single_invoice) {
        $formatted_date = date('d/m/Y', strtotime($date));
    } else {
        if ($all_same_display_date && !empty($common_display_date)) {
            $formatted_date = date('d/m/Y', strtotime($common_display_date));
        } else {
            $formatted_date = date('d/m/Y', strtotime($date));
        }
    }
    $combined_invoice_number = implode(', ', $invoice_numbers);

    // Determine if we're viewing a single invoice or multiple
    $is_multiple = count($invoice_ids) > 1;
    $page_title = $is_multiple ? " INVOICE" : "INVOICE";

    // Set filename
    $filename = ($is_multiple ? 'Invoice_' : 'Invoice_') . $customer['name'] . '_' . $date . '.pdf';
    $filename = str_replace(' ', '_', $filename);

    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Initialize domPDF with production settings
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Arial');
    
    $dompdf = new Dompdf($options);
    $dompdf->setPaper('A4', 'portrait');

    // Create HTML content
    $html = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Invoice</title>
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
        </style>
    </head>
    <body>

        <div class="invoice-container">
            <header>
                <h1>' . htmlspecialchars($company['company_name']) . '</h1>
                <hr>
            </header>

            <section class="invoice-details">
                <div class="left-column">
                                    <p style="font-size: 16px; font-weight: bold;">Date: ' . $formatted_date . '</p>
                    <p style="font-size: 16px; font-weight: bold;">Ledger Balance: Rs. ' . number_format(abs($customer['balance']), 2) . '</p>
 
                </div>
                <div class="right-column">
                <p style="font-size: 16px; font-weight: bold;">Customer Name: ' . htmlspecialchars($customer['name']) . '</p>
                                       <p style="font-size: 16px; font-weight: bold;">Invoice Number: ' . $combined_invoice_number . '</p>
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

    // Add invoice items
    $sno = 1;
    while ($item = $items_result->fetch_assoc()) {
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
                Total Amount: Rs. ' . number_format($total_amount, 2) . '
            </div>

            <!-- Footer -->
            <div class="footer">
                <div class="account-details">
                    <p>Thank you for your business!</p>';
    
    // Add bank account details from the database
    if (!empty($bank_account_details)) {
        foreach ($bank_account_details as $detail) {
            $html .= '<p>' . htmlspecialchars($detail) . '</p>';
        }
    } else {
        $html .= '<p>Please contact us for payment details</p>';
    }
    
    $html .= '
                </div>
                <div class="copyright">
                    <p>Software by ibrahimbhat.com</p>
                    <p>Powered by Evotec.in - Complete Business Management Solution</p>
                </div>
            </div>
        </div>

    </body>
    </html>';

    // Load HTML content
    $dompdf->loadHtml($html);
    
    // Render PDF
    $dompdf->render();
    
    // Output PDF
    $dompdf->stream($filename, array('Attachment' => true));
    exit;
} catch (Exception $e) {
    // Log the error
    error_log("Error generating invoice: " . $e->getMessage());
    // Display a user-friendly error message
    echo "Error generating PDF: " . $e->getMessage();
    echo "<br><a href='../../views/invoices/index.php'>Back to Invoices</a>";
    exit;
}