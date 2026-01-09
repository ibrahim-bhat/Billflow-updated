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

// Get all invoices for this customer within the date range
$sql = "SELECT ci.*, c.name as customer_name, c.contact as customer_contact, 
        c.location as customer_location, c.balance as customer_balance
        FROM customer_invoices ci
        JOIN customers c ON ci.customer_id = c.id
        WHERE ci.customer_id = ? AND DATE(ci.date) BETWEEN ? AND ?
        ORDER BY ci.date ASC, CAST(ci.invoice_number AS UNSIGNED) ASC";

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

// Get company settings
$company_settings = [
    'company_name' => 'BillFlow',
    'company_address' => 'Srinagar 190021',
    'company_phone' => '+91 9906622700',
    'company_email' => 'info@billflow.com'
];

try {
    $sql = "SELECT * FROM company_settings LIMIT 1";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $db_settings = $result->fetch_assoc();
        if (!empty($db_settings['company_name'])) {
            $company_settings['company_name'] = $db_settings['company_name'];
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
        if (!empty($db_settings['bank_account_details'])) {
            $company_settings['bank_account_details'] = $db_settings['bank_account_details'];
        }
    }
} catch (Exception $e) {
    error_log("Error fetching company settings: " . $e->getMessage());
}

$terms_conditions = "1. Payment is due within 30 days\n2. Please include invoice number on your payment\n3. Make all checks payable to Bill Flow";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Bills</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body {
            font-size: 14px;
            color: #333;
        }
        .invoice-title {
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .company-details {
            margin-bottom: 30px;
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
        }
        .invoice-info {
            text-align: right;
            font-weight: bold;
        }
        .table th {
            background-color: #2c3e50;
            color: white;
        }
        .table-bordered td, .table-bordered th {
            border-color: #dee2e6;
        }
        .total-amount {
            font-size: 16px;
            font-weight: bold;
            color: #2c3e50;
        }
        .footer {
            margin-top: 50px;
            text-align: center;
            font-size: 12px;
            color: #6c757d;
            border-top: 1px solid #dee2e6;
            padding-top: 15px;
        }
        .balance-info {
            padding: 5px 10px;
            border-radius: 4px;
            display: inline-block;
            font-weight: bold;
            background-color: #e2e3e5;
            color: #383d41;
        }
        .page-break {
            page-break-after: always;
        }
        @media print {
            @page {
                size: A5;
                margin: 1cm;
            }
            .no-print {
                display: none;
            }
            .table th {
                background-color: #2c3e50 !important;
                color: white !important;
                -webkit-print-color-adjust: exact;
            }
            .customer-details {
                background-color: #f8f9fa !important;
                -webkit-print-color-adjust: exact;
            }
            .balance-info {
                background-color: #e2e3e5 !important;
                color: #383d41 !important;
                -webkit-print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <div class="no-print mb-3 container mt-4">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print"></i> Print All Bills
        </button>
        <button onclick="window.close()" class="btn btn-secondary">
            <i class="fas fa-times"></i> Close
        </button>
    </div>

    <?php if (count($invoices) > 0): ?>
        <?php foreach ($invoices as $index => $invoice): ?>
            <div class="container mt-4 <?php if ($index < count($invoices) - 1) echo 'page-break'; ?>">
                <div class="invoice-title text-center">
                    <h2>INVOICE</h2>
                </div>

                <div class="company-details">
                    <div class="company-name"><?php echo htmlspecialchars($company_settings['company_name']); ?></div>
                    <p><?php echo htmlspecialchars($company_settings['company_address']); ?></p>
                    <p>Phone: <?php echo htmlspecialchars($company_settings['company_phone']); ?></p>
                    <p>Email: <?php echo htmlspecialchars($company_settings['company_email']); ?></p>
                </div>

                <div class="row">
                    <div class="col-md-6 customer-details">
                        <h5>Bill To:</h5>
                        <p><strong><?php echo htmlspecialchars($customer['name']); ?></strong></p>
                        <?php if (!empty($customer['contact'])): ?>
                        <p>Contact: <?php echo htmlspecialchars($customer['contact']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($customer['location'])): ?>
                        <p>Location: <?php echo htmlspecialchars($customer['location']); ?></p>
                        <?php endif; ?>
                        <p class="mt-2"><strong>Account Balance:</strong> 
                            <span class="balance-info">
                                ₹<?php echo number_format(abs($customer['balance']), 0); ?>
                            </span>
                        </p>
                    </div>
                    <div class="col-md-6 invoice-info">
                        <p>Invoice #: <?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                        <p>Date: <?php echo date('d/m/Y', strtotime($invoice['date'])); ?></p>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Quantity</th>
                                <th>Weight</th>
                                <th>Rate</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Use stored total amount from database instead of recalculating
                            $total_amount = $invoice['total_amount'];
                            foreach ($invoice['items'] as $item):
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                                    <td><?php echo number_format($item['quantity'], 0); ?></td>
                    <td><?php echo number_format($item['weight'], 1); ?></td>
                    <td>₹<?php echo number_format($item['rate'], 1); ?></td>
                    <td>₹<?php echo number_format($item['amount'], 0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="text-right"><strong>Total Amount:</strong></td>
                                <td class="total-amount">₹<?php echo number_format($total_amount, 0); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="row mt-4">
                    <div class="col-md-12">
                        <h5>Terms & Conditions:</h5>
                        <ol>
                            <?php 
                            $terms_lines = explode("\n", $terms_conditions);
                            foreach ($terms_lines as $line) {
                                if (!empty(trim($line))) {
                                    echo "<li>" . htmlspecialchars(trim($line)) . "</li>";
                                }
                            }
                            ?>
                        </ol>
                    </div>
                </div>
                
                <div class="footer">
                    <p>Thank you for your business!</p>
                    <?php if (!empty($company_settings['bank_account_details'])): ?>
                        <?php echo nl2br(htmlspecialchars($company_settings['bank_account_details'])); ?>
                    <?php else: ?>
                        <p>A/c No: 0634020100000100</p>
                        <p>IFSC: JAKA0MEHJUR</p>
                        <p>GPay/MPay: 7889718295</p>
                    <?php endif; ?>
                    <p>Software by ibrahimbhat.com</p>
                    <p>Powered by Evotec.in - Complete Business Management Solution</p>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="container mt-4">
            <div class="alert alert-info">No invoices found for the selected date range.</div>
        </div>
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html> 