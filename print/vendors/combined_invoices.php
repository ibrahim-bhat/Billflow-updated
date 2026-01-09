<?php
require_once __DIR__ . '/../../config/config.php';

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
        header('Location: ../../views/invoices/index.php');
        exit();
    }
    
    $invoice = $invoice_result->fetch_assoc();
    $customer_id = $invoice['customer_id'];
    // Prefer display_date for showing the invoice date; fallback to system date
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
        header('Location: ../../views/invoices/index.php');
        exit();
    }
    
    // Keep using system date for grouping/filtering; display date used per invoice
    $date = sanitizeInput($_GET['date']);
    $customer_id = intval($_GET['customer_id']);
    
    // Get customer information
    $customer_sql = "SELECT * FROM customers WHERE id = ?";
    $customer_stmt = $conn->prepare($customer_sql);
    $customer_stmt->bind_param("i", $customer_id);
    $customer_stmt->execute();
    $customer_result = $customer_stmt->get_result();
    
    if ($customer_result->num_rows === 0) {
        header('Location: ../../views/invoices/index.php');
        exit();
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
    $common_display_date = null;
    $all_same_display_date = true;

    while ($invoice = $invoices_result->fetch_assoc()) {
        $invoice_ids[] = $invoice['id'];
        $invoice_numbers[] = $invoice['invoice_number'];
        $curr_disp = $invoice['display_date'];
        if ($common_display_date === null) {
            $common_display_date = $curr_disp;
        } else {
            if ($common_display_date !== $curr_disp) {
                $all_same_display_date = false;
            }
        }
    }
    
    // Get the total amount from database using SUM instead of manual addition
    if (!empty($invoice_ids)) {
        $placeholders = str_repeat('?,', count($invoice_ids) - 1) . '?';
        $total_sql = "SELECT SUM(total_amount) as total_amount FROM customer_invoices 
                     WHERE id IN ($placeholders)";
        $total_stmt = $conn->prepare($total_sql);
        $total_stmt->bind_param(str_repeat('i', count($invoice_ids)), ...$invoice_ids);
        $total_stmt->execute();
        $total_result = $total_stmt->get_result();
        $total_row = $total_result->fetch_assoc();
        $total_amount = $total_row['total_amount'] ?? 0;
    } else {
        $total_amount = 0;
    }
    
    if (empty($invoice_ids)) {
        header('Location: ../../views/invoices/index.php');
        exit();
    }
    
    // If display dates differ, do not combine: print error page or instruct to open individually
    $all_same_display_date = true; $common_display_date = null;
    $check_sql = "SELECT display_date FROM customer_invoices WHERE id IN (" . implode(',', $invoice_ids) . ")";
    $check_rs = $conn->query($check_sql);
    while ($r = $check_rs->fetch_assoc()) {
        if ($common_display_date === null) { $common_display_date = $r['display_date']; }
        else if ($common_display_date !== $r['display_date']) { $all_same_display_date = false; break; }
    }
    if (!$all_same_display_date) {
        // Render a minimal page indicating separate dates
        echo '<!DOCTYPE html><html><body><p>Invoices have different dates. Please print individually.</p></body></html>';
        exit();
    }
    // Get all invoice items (safe to combine)
    $items_sql = "SELECT cii.*, i.name as item_name, v.name as vendor_name 
                 FROM customer_invoice_items cii
                 JOIN items i ON cii.item_id = i.id
                 LEFT JOIN vendors v ON cii.vendor_id = v.id
                 WHERE cii.invoice_id IN (" . implode(',', $invoice_ids) . ")
                 ORDER BY cii.id";
    $items_result = $conn->query($items_sql);
}

// Get company settings - use actual data
$company_sql = "SELECT * FROM company_settings WHERE id = 1";
$company_result = $conn->query($company_sql);
$company = $company_result->fetch_assoc();

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
$page_title = $is_multiple ? "COMBINED INVOICE" : "INVOICE";

// HTML for printing
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_multiple ? "Combined Invoice" : "Invoice #" . $invoice_numbers[0]; ?> - <?php echo htmlspecialchars($customer['name']); ?> - <?php echo $formatted_date; ?></title>
    
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
        
        .whatsapp-icon {
            color: #25D366; /* WhatsApp green */
            font-weight: bold;
        }

        /* Invoice details section */
        .invoice-details {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            font-size: 0.9em;
        }

        .invoice-details .left-column,
        .invoice-details .right-column {
            flex: 1;
        }

        .invoice-details .right-column {
            text-align: right;
        }

        .invoice-details p {
            margin: 0;
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
        
        /* Table headers */
        .items-table thead th {
            text-align: center;
            font-weight: bold;
        }
        
        /* Align specific columns */
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

        /* Table footer row with the total */
        .total-row td {
            font-weight: bold;
        }
        .total-row .total-label {
            text-align: right;
            border-left: none; /* Clean look for the total label */
        }
        .total-row .total-value {
             text-align: right;
        }

        /* Bottom section with payment details */
        .bottom-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-end; /* Aligns items to the bottom */
            margin-top: 20px;
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

        /* Bottom section with payment details and software credits */
        .bottom-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-top: 20px;
            font-size: 0.8em;
        }
        
        .payment-details {
            flex: 1;
        }
        
        .software-credits {
            flex: 1;
            text-align: right;
        }

        /* Print buttons */
        .no-print {
            margin-bottom: 20px;
        }
        .no-print button {
            padding: 10px 20px;
            margin-right: 10px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .no-print button:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="window.print()">Print Invoice</button>
        <button onclick="window.location.href='../../views/invoices/index.php?filter_date=<?php echo $date; ?>'">Back to Invoices</button>
    </div>

    <div class="invoice-container">
        <header>
            <h1><?php echo htmlspecialchars($company['company_name']); ?></h1>
            <p><?php echo !empty($company['business_tagline']) ? htmlspecialchars($company['business_tagline']) : 'Wholesale Dealers of Vegetables'; ?></p>
        </header>

        <section class="top-section">
            <div class="address">
                <p><?php echo !empty($company['company_address']) ? htmlspecialchars($company['company_address']) : '75, 313 Iqbal Sabzi Mandi, Bagh Nand Singh'; ?></p>
                <p><?php echo !empty($company['company_address']) ? '' : 'Tatoo Ground, Batamaloo, Sgr.'; ?></p>
            </div>
            <div class="contact">
                <?php if (!empty($company['contact_numbers'])): ?>
                    <?php echo nl2br(htmlspecialchars($company['contact_numbers'])); ?>
                <?php else: ?>
                    <p>Ali Mohd: 9419067657</p>
                    <p>Sajad Ali: 7889718295 <i class="fab fa-whatsapp whatsapp-icon"></i></p>
                    <p>Umer Ali: 7006342374 <i class="fab fa-whatsapp whatsapp-icon"></i></p>
                <?php endif; ?>
            </div>
        </section>

        <section class="invoice-details">
            <div class="left-column">
                <p>Invoice No: <strong><?php echo $combined_invoice_number; ?></strong></p>
            </div>
            <div class="right-column">
                <p>Date: <strong><?php echo $formatted_date; ?></strong></p>
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
                while ($item = $items_result->fetch_assoc()): 
                ?>
                <tr>
                    <td><?php echo $sno++; ?></td>
                    <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                    <td><?php echo number_format($item['quantity'], 0); ?></td>
                    <td><?php echo ($item['weight'] ? number_format($item['weight'], 1) : '-'); ?></td>
                    <td>₹<?php echo number_format($item['rate'], 1); ?></td>
                    <td>₹<?php echo number_format($item['amount'], 2); ?></td>
                </tr>
                <?php endwhile; ?>
                <tr class="total-row">
                    <td colspan="4" style="border-right: none; border-left: none; border-bottom: none;"></td>
                    <td class="total-label">Total:</td>
                    <td class="total-value">₹<?php echo number_format($total_amount, 2); ?></td>
                </tr>
            </tbody>
        </table>

        <section class="bottom-section">
            <div class="payment-details">
                <p>Ledger Balance: <strong>₹<?php echo number_format(abs($customer['balance']), 2); ?></strong></p>
                <?php if (!empty($company['bank_account_details'])): ?>
                    <p style="margin-top: 10px; font-size: 6px;"><strong>Bank Details:</strong></p>
                    <?php echo nl2br(htmlspecialchars($company['bank_account_details'])); ?>
                <?php else: ?>
                    <p style="margin-top: 10px; font-size: 6px;"><strong>Bank Details:</strong></p>
                    <p style="margin-top: 2px; font-size: 6px;">A/c No: 0634020100000100</p>
                    <p style="margin-top: 2px; font-size: 6px;">IFSC: JAKA0MEHJUR</p>
                    <p style="margin-top: 2px; font-size: 6px;">GPay/MPay: 7889718295</p>
                <?php endif; ?>
            </div>
            <div class="software-credits">
                <p style="margin-top: 10px; font-size: 6px; color: #999; font-weight: bold; text-align: right;">Software by ibrahimbhat.com</p>
                <p style="margin-top: 5px; font-size: 6px; color: #999; font-weight: bold; text-align: right;">Powered by Evotec.in - Complete Business Management Solution</p>
            </div>
        </section>
    </div>

</body>
</html> 