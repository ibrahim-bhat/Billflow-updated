<?php
// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Check if vendor ID and date range are provided
if (!isset($_GET['vendor_id']) || empty($_GET['vendor_id']) || 
    !isset($_GET['start_date']) || empty($_GET['start_date']) || 
    !isset($_GET['end_date']) || empty($_GET['end_date'])) {
    echo "Vendor ID and date range are required.";
    exit();
}

$vendor_id = sanitizeInput($_GET['vendor_id']);
$start_date = sanitizeInput($_GET['start_date']);
$end_date = sanitizeInput($_GET['end_date']);

// Validate date format
if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $start_date) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $end_date)) {
    echo "Invalid date format. Please use YYYY-MM-DD format.";
    exit();
}

// Get vendor details
$vendor_sql = "SELECT name, type, balance FROM vendors WHERE id = ?";
$vendor_stmt = $conn->prepare($vendor_sql);
$vendor_stmt->bind_param("i", $vendor_id);
$vendor_stmt->execute();
$vendor_result = $vendor_stmt->get_result();
$vendor = $vendor_result->fetch_assoc();
$vendor_stmt->close();

if (!$vendor) {
    echo "Vendor not found.";
    exit();
}

// Get all wataks for this vendor within the date range
$sql = "SELECT w.*, v.name as vendor_name, v.type as vendor_type, v.balance as vendor_balance
        FROM vendor_watak w
        JOIN vendors v ON w.vendor_id = v.id
        WHERE w.vendor_id = ? AND DATE(w.inventory_date) BETWEEN ? AND ?
        ORDER BY w.inventory_date ASC, CAST(w.watak_number AS UNSIGNED) ASC";

$wataks = array();

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("iss", $vendor_id, $start_date, $end_date);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            // Get watak items
            $items_sql = "SELECT * FROM watak_items WHERE watak_id = ?";
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
            $wataks[] = $row;
        }
    }
    
    $stmt->close();
}

// Get company settings
$company_settings = [
    'company_name' => 'KICHLOO AND CO.',
    'company_address' => '75, 313 Iqbal Sabzi Mandi, Bagh Nand Singh, Tatoo Ground, Batamaloo, Sgr.',
    'company_phone' => 'Ali Mohd: 9419087654, Sajad Ali: 7889718295, Umer Ali: 7006342174',
    'company_email' => ''
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
    }
} catch (Exception $e) {
    error_log("Error fetching company settings: " . $e->getMessage());
}

// Format dates for display
$formatted_start_date = date('d/m/Y', strtotime($start_date));
$formatted_end_date = date('d/m/Y', strtotime($end_date));

// Generate filename
$filename = 'vendor_wataks_' . $vendor['name'] . '_' . $start_date . '_to_' . $end_date . '.pdf';

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
        <title>Vendor Wataks - ' . htmlspecialchars($vendor['name']) . '</title>
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

            /* Main container for the watak */
            .watak-container {
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
                color: #2E8B57;
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

            /* Watak details section with Flexbox for layout */
            .watak-details {
                display: flex;
                justify-content: space-between;
                margin-bottom: 20px;
                font-size: 12px;
                width: 100%;
            }
            
            .watak-details .left-column,
            .watak-details .right-column {
                display: flex;
                flex-direction: column;
            }

            .watak-details .left-column {
                flex: 1;
            }

            .watak-details .right-column {
                text-align: right;
                flex: 1;
                justify-content: flex-end;
            }

            .watak-details p {
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
                background-color: #8E44AD;
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
            
            /* Summary Section */
            .summary-section {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-top: 15px;
                margin-bottom: 20px;
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
                font-size: 12px;
            }
            
            .label {
                display: inline-block;
                width: 120px;
                color: #333;
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
            
            /* Watak section for multiple wataks */
            .watak-section {
                page-break-inside: avoid;
                page-break-before: always;
                margin-bottom: 30px;
            }
            .watak-section:first-child {
                page-break-before: auto;
            }
        </style>
    </head>
    <body>';

    // Add each watak
    foreach ($wataks as $index => $watak) {
        // Calculate labor charges using stored values
        $total_labor = 0;
        foreach ($watak['items'] as $item) {
            $total_labor += $item['labor'];
        }
        
        $html .= '
        <div class="watak-section">
            <div class="watak-container">
                <header>
                    <h1>' . htmlspecialchars($company_settings['company_name']) . '</h1>
                    <h2>Watak Invoice</h2>
                    <hr>
                </header>

                <section class="watak-details">
                    <div class="left-column">
                        <p style="font-size: 16px; font-weight: bold;">Date: ' . date('d/m/Y', strtotime($watak['inventory_date'] ?? $watak['date'])) . '</p>
                        <p style="font-size: 16px; font-weight: bold;">Vehicle Number: ' . htmlspecialchars($watak['vehicle_no'] ?? 'N/A') . '</p>
                        <p style="font-size: 16px; font-weight: bold;">Chalan No: ' . htmlspecialchars($watak['chalan_no'] ?? 'N/A') . '</p>
                    </div>
                    <div class="right-column">
                        <p style="font-size: 16px; font-weight: bold;">Vendor: ' . htmlspecialchars($vendor['name']) . '</p>
                        <p style="font-size: 16px; font-weight: bold;">Watak Number: ' . htmlspecialchars($watak['watak_number']) . '</p>
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

        $sno = 1;
        foreach ($watak['items'] as $item) {
            $html .= '
                        <tr>
                            <td>' . $sno++ . '</td>
                            <td>' . htmlspecialchars($item['item_name']) . '</td>
                            <td>' . number_format($item['quantity'], 0) . '</td>
                            <td>' . ($item['weight'] ? number_format($item['weight'], 1) : '-') . '</td>
                            <td>' . number_format($item['rate'], 1) . '</td>
                            <td>' . number_format($item['amount'], 0) . '</td>
                        </tr>';
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
    error_log("Error generating vendor wataks: " . $e->getMessage());
    // Display a user-friendly error message
    echo "Error: " . $e->getMessage();
    exit;
}
?>
