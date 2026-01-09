<?php
// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Check if vendor ID is provided
if (!isset($_GET['vendor_id']) || empty($_GET['vendor_id'])) {
    echo "Vendor ID is required.";
    exit();
}

$vendor_id = sanitizeInput($_GET['vendor_id']);

// Get vendor details
$vendor_sql = "SELECT name, balance, type FROM vendors WHERE id = ?";
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

// Get company settings
$company_name = "KICHLOO AND CO."; // Default fallback
try {
    $sql = "SELECT company_name FROM company_settings LIMIT 1";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $settings = $result->fetch_assoc();
        if (!empty($settings['company_name'])) {
            $company_name = strtoupper($settings['company_name']);
        }
    }
} catch (Exception $e) {
    error_log("Error fetching company settings: " . $e->getMessage());
}

// Get all vendor transactions
$sql = "SELECT 
        CAST('watak' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci as type,
        inventory_date as date,
        CAST(CONCAT('Watak #', watak_number) AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci as description,
        net_payable as debit,
        NULL as credit
        FROM vendor_watak 
        WHERE vendor_id = ?
        
        UNION ALL
        
        SELECT 
        CAST('purchase_invoice' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci as type,
        invoice_date as date,
        CAST(CONCAT('Purchase Invoice #', invoice_number) AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci as description,
        total_amount as debit,
        NULL as credit
        FROM vendor_invoices
        WHERE vendor_id = ?
        
        UNION ALL
        
        SELECT 
        CAST('payment' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci as type,
        date,
        CAST(CONCAT('Payment - ', 
            CASE payment_mode 
                WHEN 'Cash' THEN 'Cash Payment'
                WHEN 'Bank' THEN 'Bank Transfer'
            END,
            CASE 
                WHEN receipt_no IS NOT NULL AND receipt_no != '' 
                THEN CONCAT(' (Receipt #', receipt_no, ')')
                ELSE ''
            END
        ) AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci as description,
        NULL as debit,
        amount + discount as credit
        FROM vendor_payments
        WHERE vendor_id = ?
        
        ORDER BY date ASC";

// Calculate opening balance by reversing all transactions
$opening_balance = $vendor['balance']; // Start with current balance

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("iii", $vendor_id, $vendor_id, $vendor_id);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        
        // Reverse all transactions to get the original opening balance
        while ($row = $result->fetch_assoc()) {
            // Reverse the transaction: subtract wataks, add payments
            if ($row['debit']) {
                $opening_balance -= $row['debit'];
            }
            if ($row['credit']) {
                $opening_balance += $row['credit'];
            }
        }
        
        // Reset result pointer to beginning
        $result->data_seek(0);
        
        // Calculate running balance starting from opening balance
        $running_balance = $opening_balance;
        $ledger = array();
        
        // Add opening balance entry
        $ledger[] = array(
            'date' => 'Opening Balance',
            'description' => 'Opening Balance',
            'debit' => '',
            'credit' => '',
            'balance' => number_format(abs(($opening_balance - floor($opening_balance)) >= 0.5 ? ceil($opening_balance) : floor($opening_balance)), 0),
            'balance_type' => $opening_balance > 0 ? 'To Pay to Vendor' : 'Vendor to Pay'
        );
        
        while ($row = $result->fetch_assoc()) {
            // Update running balance
            if ($row['debit']) {
                $running_balance += $row['debit'];
            }
            if ($row['credit']) {
                $running_balance -= $row['credit'];
            }
            
            $ledger[] = array(
                'date' => date('d-m-Y', strtotime($row['date'])),
                'description' => $row['description'],
                'debit' => $row['debit'] ? number_format(($row['debit'] - floor($row['debit'])) >= 0.5 ? ceil($row['debit']) : floor($row['debit']), 0) : '',
                'credit' => $row['credit'] ? number_format(($row['credit'] - floor($row['credit'])) >= 0.5 ? ceil($row['credit']) : floor($row['credit']), 0) : '',
                'balance' => number_format(abs(($running_balance - floor($running_balance)) >= 0.5 ? ceil($running_balance) : floor($running_balance)), 0),
                'balance_type' => $running_balance > 0 ? 'To Pay to Vendor' : 'Vendor to Pay'
            );
        }
    }
    
    $stmt->close();
}

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
    <title>Vendor Ledger - ' . htmlspecialchars($vendor['name']) . '</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #ffffff;
            color: #333;
            font-size: 12px;
        }
        
        .invoice-container {
            width: 100%;
            max-width: 750px;
            margin: 0 auto;
            background-color: #ffffff;
            padding: 20px;
        }
        
        header {
            text-align: center;
            margin-bottom: 30px;
            clear: both;
        }
        
        header .title h1 {
            font-family: "Times New Roman", serif;
            font-size: 2.5em;
            color: #2E8B57;
            margin: 0;
            font-weight: bold;
        }
        
        header .invoice-type {
            font-size: 1.1em;
            color: #555;
            margin-top: 5px;
        }
        
        .company-details {
            margin-bottom: 30px;
            clear: both;
        }
        
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .vendor-details {
            margin-bottom: 30px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 5px;
            clear: both;
        }
        
        .invoice-info {
            text-align: right;
            font-weight: bold;
        }
        
        .ledger-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            clear: both;
        }
        
        .ledger-table th {
            background-color: #2c3e50;
            color: white;
            padding: 10px;
            text-align: center;
            font-weight: bold;
            border: 1px solid #2c3e50;
        }
        
        .ledger-table td {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: center;
        }
        
        .ledger-table td:nth-child(2) {
            text-align: left;
        }
        
        .ledger-table td:nth-child(3),
        .ledger-table td:nth-child(4),
        .ledger-table td:nth-child(5) {
            text-align: right;
        }
        
        .ledger-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        .balance-info {
            padding: 5px 10px;
            border-radius: 4px;
            display: inline-block;
            font-weight: bold;
            background-color: #e2e3e5;
            color: #383d41;
        }
        
        .receivable {
            color: #28a745;
        }
        
        .payable {
            color: #dc3545;
        }
        
        footer {
            clear: both;
            text-align: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }
        
        footer p {
            color: #6c757d;
            font-size: 0.9em;
            margin: 0;
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <header>
            <div class="title">
                <h1>' . htmlspecialchars($company_name) . '</h1>
            </div>
            <div class="invoice-type">
                VENDOR LEDGER
            </div>
        </header>

        <div class="vendor-details">
            <h5>Vendor:</h5>
            <p><strong>' . htmlspecialchars($vendor['name']) . '</strong> (' . htmlspecialchars($vendor['type']) . ')</p>
            <p class="mt-2"><strong>Current Balance:</strong> 
                <span class="balance-info">
                    Rs ' . number_format(abs(($vendor['balance'] - floor($vendor['balance'])) >= 0.5 ? ceil($vendor['balance']) : floor($vendor['balance'])), 0) . '
                    <span class="' . ($vendor['balance'] > 0 ? 'payable' : 'receivable') . '">
                        (' . ($vendor['balance'] > 0 ? 'To Pay to Vendor' : 'Vendor to Pay') . ')
                    </span>
                </span>
            </p>
            <div class="invoice-info">
                <p>Date: ' . date('d/m/Y') . '</p>
            </div>
        </div>

        <table class="ledger-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Description</th>
                    <th>Debit (Rs)</th>
                    <th>Credit (Rs)</th>
                    <th>Balance (Rs)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>';

if (count($ledger) > 0) {
    foreach ($ledger as $entry) {
        $html .= '
                <tr>
                    <td>' . $entry['date'] . '</td>
                    <td>' . $entry['description'] . '</td>
                    <td>' . $entry['debit'] . '</td>
                    <td>' . $entry['credit'] . '</td>
                    <td>Rs ' . $entry['balance'] . '</td>
                    <td class="' . ($entry['balance_type'] === 'Vendor to Pay' ? 'receivable' : 'payable') . '">
                        ' . $entry['balance_type'] . '
                    </td>
                </tr>';
    }
} else {
    $html .= '
                <tr>
                    <td colspan="6" style="text-align: center;">No ledger entries found</td>
                </tr>';
}

$html .= '
            </tbody>
        </table>
        
        <footer>
            <p>Thank you for your business!</p>
            <p>Software by ibrahimbhat.com</p>
            <p>Powered by Evotec.in - Complete Business Management Solution</p>
        </footer>
    </div>
</body>
</html>';

// Load HTML into Dompdf
$dompdf->loadHtml($html);

// Render PDF
$dompdf->render();

// Output PDF
$dompdf->stream('vendor_ledger_' . $vendor_id . '_' . date('Y-m-d') . '.pdf', array('Attachment' => true));
?> 