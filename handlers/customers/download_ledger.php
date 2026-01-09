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

// Create a union query to get all transactions for this customer
$sql = "
    (
        -- Invoices (add to balance)
        SELECT 
            ci.display_date AS date,
            CONCAT('Invoice #', ci.invoice_number) AS description,
            ci.total_amount AS debit,
            0 AS credit,
            1 AS transaction_type,
            ci.id AS transaction_id
        FROM customer_invoices ci
        WHERE ci.customer_id = ?
    )
    UNION
    (
        -- Payments (subtract from balance)
        SELECT 
            cp.date AS date,
            CONCAT('Payment (', cp.payment_mode, ')', 
                   CASE WHEN cp.receipt_no IS NOT NULL AND cp.receipt_no != '' 
                        THEN CONCAT(' - Receipt #', cp.receipt_no) 
                        ELSE '' END) AS description,
            0 AS debit,
            cp.amount + cp.discount AS credit,
            2 AS transaction_type,
            cp.id AS transaction_id
        FROM customer_payments cp
        WHERE cp.customer_id = ?
    )
    ORDER BY date ASC, transaction_type ASC, transaction_id ASC
";

$ledger = array();

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("ii", $customer_id, $customer_id);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        
        // Calculate opening balance by reversing all transactions
        $opening_balance = $customer['balance']; // Start with current balance
        
        // Reverse all transactions to get the original opening balance
        while ($row = $result->fetch_assoc()) {
            // Reverse the transaction: subtract invoices, add payments
            $opening_balance -= $row['debit'] - $row['credit'];
        }
        
        // Reset result pointer to beginning
        $result->data_seek(0);
        
        // Calculate running balance starting from opening balance
        $balance = $opening_balance;
        
        // Add opening balance entry
        $ledger[] = array(
            'date' => 'Opening Balance',
            'description' => 'Opening Balance',
            'debit' => '',
            'credit' => '',
            'balance' => number_format(abs($opening_balance), 2),
            'balance_type' => $opening_balance > 0 ? 'Customer to Pay' : 'To Pay to Customer'
        );
        
        while ($row = $result->fetch_assoc()) {
            // Update running balance
            $balance += $row['debit'] - $row['credit'];
            
            $ledger[] = array(
                'date' => date('d-m-Y', strtotime($row['date'])),
                'description' => $row['description'],
                'debit' => $row['debit'] > 0 ? number_format($row['debit'], 2) : '',
                'credit' => $row['credit'] > 0 ? number_format($row['credit'], 2) : '',
                'balance' => number_format(abs($balance), 2),
                'balance_type' => $balance > 0 ? 'Customer to Pay' : 'To Pay to Customer'
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
    <title>Customer Ledger - ' . htmlspecialchars($customer['name']) . '</title>
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
        
        .customer-details {
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
                CUSTOMER LEDGER
            </div>
        </header>

        <div class="customer-details">
            <h5>Customer:</h5>
            <p><strong>' . htmlspecialchars($customer['name']) . '</strong></p>';
if (!empty($customer['contact'])) {
    $html .= '<p>Contact: ' . htmlspecialchars($customer['contact']) . '</p>';
}
if (!empty($customer['location'])) {
    $html .= '<p>Location: ' . htmlspecialchars($customer['location']) . '</p>';
}
$html .= '
            <p class="mt-2"><strong>Current Balance:</strong> 
                <span class="balance-info">
                    Rs ' . number_format(abs($customer['balance']), 2) . '
                    <span class="' . ($customer['balance'] > 0 ? 'receivable' : 'payable') . '">
                        (' . ($customer['balance'] > 0 ? 'Customer to Pay' : 'To Pay to Customer') . ')
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
                    <td class="' . ($entry['balance_type'] === 'Customer to Pay' ? 'receivable' : 'payable') . '">
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
$dompdf->stream('customer_ledger_' . $customer_id . '_' . date('Y-m-d') . '.pdf', array('Attachment' => true));
?> 