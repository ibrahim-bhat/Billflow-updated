<?php
require_once __DIR__ . '/../../config/config.php';

// Check if date parameter is provided
if (!isset($_GET['date'])) {
    header('Location: ../../views/invoices/index.php');
    exit();
}

$date = sanitizeInput($_GET['date']);

// Get all invoices for this date
$invoices_sql = "SELECT ci.*, c.name as customer_name, c.contact as customer_contact, 
                 c.location as customer_location, c.balance as customer_balance
                 FROM customer_invoices ci
                 JOIN customers c ON ci.customer_id = c.id
                 WHERE DATE(ci.date) = ?
                 ORDER BY CAST(ci.invoice_number AS UNSIGNED)";
$invoices_stmt = $conn->prepare($invoices_sql);
$invoices_stmt->bind_param("s", $date);
$invoices_stmt->execute();
$invoices_result = $invoices_stmt->get_result();

if ($invoices_result->num_rows === 0) {
    header('Location: ../../views/invoices/index.php');
    exit();
}

// Get all invoice items for all invoices on this date
$items_sql = "SELECT cii.*, i.name as item_name, v.name as vendor_name, 
              ci.invoice_number, ci.date as invoice_date, ci.display_date, c.name as customer_name
              FROM customer_invoice_items cii
              JOIN items i ON cii.item_id = i.id
              LEFT JOIN vendors v ON cii.vendor_id = v.id
              JOIN customer_invoices ci ON cii.invoice_id = ci.id
              JOIN customers c ON ci.customer_id = c.id
              WHERE DATE(ci.date) = ?
              ORDER BY CAST(ci.invoice_number AS UNSIGNED), cii.id";
$items_stmt = $conn->prepare($items_sql);
$items_stmt->bind_param("s", $date);
$items_stmt->execute();
$items_result = $items_stmt->get_result();

// Group items by customer and display date (combine multiple bills for same customer on same date)
$grouped_items = [];
while ($item = $items_result->fetch_assoc()) {
    $customer_name = $item['customer_name'];
    $display_date = $item['display_date'] ?: $item['invoice_date'];
    
    // Create a unique key combining only customer and display date (no invoice number)
    $group_key = $customer_name . '_' . $display_date;
    
    if (!isset($grouped_items[$group_key])) {
        $grouped_items[$group_key] = [
            'customer_name' => $customer_name,
            'invoice_date' => $display_date,
            'invoice_numbers' => [], // Store all invoice numbers for this customer/date
            'items' => []
        ];
    }
    
    // Add invoice number to the list if not already present
    if (!in_array($item['invoice_number'], $grouped_items[$group_key]['invoice_numbers'])) {
        $grouped_items[$group_key]['invoice_numbers'][] = $item['invoice_number'];
    }
    
    $grouped_items[$group_key]['items'][] = $item;
}

// Get company settings
$company_sql = "SELECT * FROM company_settings WHERE id = 1";
$company_result = $conn->query($company_sql);
$company = $company_result->fetch_assoc();

// Format the date for display
$formatted_date = date('d/m/Y', strtotime($date));

// Get customer details for each customer
$customer_details = [];
foreach ($grouped_items as $group_key => $group) {
    $customer_name = $group['customer_name'];
    $customer_sql = "SELECT * FROM customers WHERE name = ?";
    $customer_stmt = $conn->prepare($customer_sql);
    $customer_stmt->bind_param("s", $customer_name);
    $customer_stmt->execute();
    $customer_result = $customer_stmt->get_result();
    $customer_details[$customer_name] = $customer_result->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Invoices - <?php echo $formatted_date; ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
    
    <style>
        /* General Body Styles */
        body {
            font-family: Arial, sans-serif;
            background-color: #ffffff;
            color: #333;
            margin: 0;
            padding: 15px;
            font-size: 10px;
            overflow-x: hidden;
        }
        
        /* Ensure content doesn't overflow */
        * {
            box-sizing: border-box;
        }

        /* Main container for the invoice */
        .invoice-container {
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
            background-color: #ffffff;
            padding: 20px;
        }

        /* Header section */
        header {
            text-align: center;
            margin-bottom: 15px;
        }

        header h1 {
            font-family: "Times New Roman", serif;
            font-size: 1.8em;
            font-weight: bold;
            letter-spacing: 2px;
            margin: 0;
            color: #2E8B57;
        }

        header p {
            font-size: 1em;
            margin: 2px 0 0;
        }
        
        /* Top section with address and contact details */
        .top-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 18px;
            font-size: 0.85em;
            line-height: 1.3;
        }

        .top-section .address p, .top-section .contact p {
            margin: 0 0 1px 0;
        }
        
        .top-section .address {
            flex: 1;
            margin-right: 20px;
        }
        
        .top-section .contact {
            flex: 1;
            text-align: right;
        }
        
        /* Bill To section */
        .bill-to {
            padding: 8px 0;
            margin-bottom: 20px;
            font-size: 0.9em;
            line-height: 1.7;
        }

        .bill-to strong {
            font-size: 1.2em;
        }

        .bill-to p {
            margin: 0 0 2px 0;
        }

        /* Customer section - each customer gets a new page */
        .customer-section {
            page-break-inside: avoid;
            page-break-before: always;
            margin-bottom: 30px;
        }

        /* First customer doesn't need page break */
        .customer-section:first-child {
            page-break-before: auto;
        }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            table-layout: fixed;
        }

        .items-table th, .items-table td {
            border: 1px solid #000;
            padding: 5px 3px;
            font-size: 0.8em;
            word-wrap: break-word;
            overflow: hidden;
        }
        
        /* Column widths for better fit */
        .items-table th:nth-child(1), .items-table td:nth-child(1) { width: 8%; }  /* SNO */
        .items-table th:nth-child(2), .items-table td:nth-child(2) { width: 35%; } /* ITEM NAME */
        .items-table th:nth-child(3), .items-table td:nth-child(3) { width: 12%; } /* QTY */
        .items-table th:nth-child(4), .items-table td:nth-child(4) { width: 15%; } /* WEIGHT */
        .items-table th:nth-child(5), .items-table td:nth-child(5) { width: 15%; } /* RATE */
        .items-table th:nth-child(6), .items-table td:nth-child(6) { width: 15%; } /* TOTAL */
        
        .items-table thead th {
            text-align: center;
            font-weight: bold;
        }
        
        .items-table tbody td:nth-child(1),  /* SNO */
        .items-table tbody td:nth-child(3),  /* QTY */
        .items-table tbody td:nth-child(4) { /* WEIGHT */
            text-align: center;
        }
        .items-table tbody td:nth-child(2) { /* ITEM NAME */
            text-align: left;
        }
        .items-table tbody td:nth-child(5),  /* RATE */
        .items-table tbody td:nth-child(6) { /* TOTAL */
            text-align: right;
        }
        
        .items-table .total-row td {
            font-weight: bold;
        }
        .items-table .total-label {
            text-align: right;
            border-left: none;
        }
        .items-table .total-value {
            text-align: right;
        }

        /* Bottom section with payment details */
        .bottom-section {
            margin-top: 15px;
            font-size: 0.9em;
            line-height: 1.5;
        }

        .bottom-section .payment-details p {
            margin: 0 0 2px 0;
        }
        
        .bottom-section .payment-details p strong {
            font-size: 1.2em;
        }

        /* Print Styles */
        @media print {
            @page {
                size: A4;
                margin: 1cm;
            }
            body {
                padding: 0;
                margin: 0;
                font-size: 10px;
            }
            
            .no-print {
                display: none;
            }
            
            .customer-section {
                page-break-inside: avoid;
            }
            
            .invoice-container {
                padding: 15px;
                max-width: 100%;
            }
            
            .items-table {
                font-size: 0.75em;
            }
            
            .items-table th, .items-table td {
                padding: 4px 2px;
            }
            
            .bottom-section {
                font-size: 0.85em;
            }
            
            .top-section {
                font-size: 0.8em;
            }
        }
        
        /* Print Button */
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .print-button:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">Print All Invoices</button>

<?php foreach ($grouped_items as $group_key => $group): 
    $customer = $customer_details[$group['customer_name']];
    
    // Get stored total amounts from database for all invoices in this group
    $customer_total = 0;
    $invoice_numbers = $group['invoice_numbers'];
    
    // Calculate total for all invoices in this group
    foreach ($invoice_numbers as $invoice_number) {
        $total_sql = "SELECT total_amount FROM customer_invoices 
                     WHERE invoice_number = ?";
        $total_stmt = $conn->prepare($total_sql);
        $total_stmt->bind_param("s", $invoice_number);
        $total_stmt->execute();
        $total_result = $total_stmt->get_result();
        $total_row = $total_result->fetch_assoc();
        $customer_total += $total_row['total_amount'] ?? 0;
        $total_stmt->close();
    }
?>

<div class="customer-section">
    <div class="invoice-container">
        <header>
            <h1><?php echo htmlspecialchars($company['company_name']); ?></h1>
            <p><?php echo !empty($company['business_tagline']) ? htmlspecialchars($company['business_tagline']) : 'Wholesale Dealers of Vegetables'; ?></p>
        </header>

        <section class="top-section">
            <div class="address">
                <p><?php echo !empty($company['company_address']) ? htmlspecialchars($company['company_address']) : '75, 313 Iqbal Sabzi Mandi, Bagh Nand Singh'; ?></p>
                <p><?php echo !empty($company['company_address']) ? '' : 'Tatoo Ground, Batamaloo, Sgr.'; ?></p>
                <p>Invoice No: <strong><?php echo implode(', ', $group['invoice_numbers']); ?></strong></p>
            </div>
            <div class="contact">
                <p><?php echo !empty($company['trademark']) ? 'Trade Mark (' . htmlspecialchars($company['trademark']) . ')' : 'Trade Mark (KAC)'; ?></p>
                <?php if (!empty($company['contact_numbers'])): ?>
                    <?php echo nl2br(htmlspecialchars($company['contact_numbers'])); ?>
                <?php else: ?>
                    <p>Contact: +91 XXXXX XXXXX</p>
                <?php endif; ?>
                <br>
                <p>Date: <strong><?php echo date('d/m/Y', strtotime($group['invoice_date'])); ?></strong></p>
            </div>
        </section>

        <section class="bill-to">
            <p>Bill to: <strong><?php echo htmlspecialchars($customer['name']); ?></strong></p>
            <?php if (!empty($customer['contact'])): ?>
                <p>Contact: <?php echo htmlspecialchars($customer['contact']); ?></p>
            <?php endif; ?>
            <?php if (!empty($customer['location'])): ?>
                <p>Location: <?php echo htmlspecialchars($customer['location']); ?></p>
            <?php endif; ?>
        </section>

        <table class="items-table">
            <thead>
                <tr>
                    <th>SNO</th>
                    <th>ITEM NAME</th>
                    <th>QTY</th>
                    <th>WEIGHT</th>
                    <th>RATE</th>
                    <th>TOTAL</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $sno = 1;
                foreach ($group['items'] as $item): 
                ?>
                <tr>
                    <td><?php echo $sno++; ?></td>
                    <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                    <td><?php echo number_format($item['quantity'], 0); ?></td>
                    <td><?php echo ($item['weight'] ? number_format($item['weight'], 1) : '-'); ?></td>
                    <td>₹<?php echo number_format($item['rate'], 1); ?></td>
                    <td>₹<?php echo number_format($item['amount'], 2); ?></td>
                </tr>
                <?php 
                    endforeach;
                ?>
                
                <tr class="total-row">
                    <td colspan="4" style="border-right: none; border-left: none; border-bottom: none;"></td>
                    <td class="total-label">Total:</td>
                    <td class="total-value">₹<?php echo number_format($customer_total, 2); ?></td>
                </tr>
            </tbody>
        </table>

        <section class="bottom-section">
      
            <div class="payment-details font-size: 6px;">
                <p>Ledger Balance: <strong>₹<?php echo number_format(abs($customer['balance']), 2); ?></strong></p>
                <?php if (!empty($company['bank_account_details'])): ?>
                    <?php echo nl2br(htmlspecialchars($company['bank_account_details'])); ?>
                <?php endif; ?>
            </div>
            <div class="software-credits">
                <p style="margin-bottom: 10px; font-size: 8px; color: #999; font-weight: bold; text-align: left;">Software by ibrahimbhat.com</p>
            </div>
        </section>
    </div>
</div>

<?php endforeach; ?>

<script>
    // Automatically trigger print when the page loads
    window.onload = function() {
        // Small delay to ensure everything is loaded
        setTimeout(function() {
            window.print();
        }, 500);
    };
</script>

</body>
</html>
