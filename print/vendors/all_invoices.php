<?php
require_once __DIR__ . '/../../config/config.php';

// Check if date parameter is provided
if (!isset($_GET['date'])) {
    header('Location: ../../views/vendors/index.php');
    exit();
}

$date = sanitizeInput($_GET['date']);

// Get all invoices for this date
$invoices_sql = "SELECT vi.*, v.name as vendor_name, v.contact as vendor_contact, 
                 v.type as vendor_type, v.vendor_category, v.balance as vendor_balance
                 FROM vendor_invoices vi
                 JOIN vendors v ON vi.vendor_id = v.id
                 WHERE DATE(vi.invoice_date) = ?
                 ORDER BY CAST(vi.invoice_number AS UNSIGNED)";
$invoices_stmt = $conn->prepare($invoices_sql);
$invoices_stmt->bind_param("s", $date);
$invoices_stmt->execute();
$invoices_result = $invoices_stmt->get_result();

if ($invoices_result->num_rows === 0) {
    header('Location: ../../views/vendors/index.php');
    exit();
}

// Get all invoice items for all invoices on this date
$items_sql = "SELECT vii.*, i.name as item_name, 
              vi.id as invoice_id, vi.invoice_number, vi.invoice_date, v.name as vendor_name
              FROM vendor_invoice_items vii
              JOIN items i ON vii.item_id = i.id
              JOIN vendor_invoices vi ON vii.invoice_id = vi.id
              JOIN vendors v ON vi.vendor_id = v.id
              WHERE DATE(vi.invoice_date) = ?
              ORDER BY CAST(vi.invoice_number AS UNSIGNED), vii.id";
$items_stmt = $conn->prepare($items_sql);
$items_stmt->bind_param("s", $date);
$items_stmt->execute();
$items_result = $items_stmt->get_result();

// Group items by invoice_id to prevent duplicates
$grouped_items = [];
while ($item = $items_result->fetch_assoc()) {
    $invoice_id = $item['invoice_id'];
    $vendor_name = $item['vendor_name'];
    $invoice_date = $item['invoice_date'];
    $invoice_number = $item['invoice_number'];
    
    // Use invoice_id as the unique key to prevent duplicate invoices
    $group_key = $invoice_id;
    
    if (!isset($grouped_items[$group_key])) {
        $grouped_items[$group_key] = [
            'vendor_name' => $vendor_name,
            'invoice_date' => $invoice_date,
            'invoice_number' => $invoice_number,
            'items' => []
        ];
    }
    
    $grouped_items[$group_key]['items'][] = $item;
}

// Get company settings
$company_sql = "SELECT * FROM company_settings WHERE id = 1";
$company_result = $conn->query($company_sql);
$company = $company_result->fetch_assoc();

// Format the date for display
$formatted_date = date('d/m/Y', strtotime($date));

// Get vendor details for each vendor
$vendor_details = [];
foreach ($grouped_items as $group_key => $group) {
    $vendor_name = $group['vendor_name'];
    $invoice_date = $group['invoice_date'];
    $invoice_number = $group['invoice_number'];
    
    $vendor_sql = "SELECT * FROM vendors WHERE name = ?";
    $vendor_stmt = $conn->prepare($vendor_sql);
    $vendor_stmt->bind_param("s", $vendor_name);
    $vendor_stmt->execute();
    $vendor_result = $vendor_stmt->get_result();
    $vendor_details[$group_key] = $vendor_result->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Purchase Invoices - <?php echo $formatted_date; ?></title>
    
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
        
        /* Vendor To section */
        .vendor-to {
            padding: 8px 0;
            margin-bottom: 20px;
            font-size: 0.9em;
            line-height: 1.7;
        }

        .vendor-to strong {
            font-size: 1.2em;
        }

        .vendor-to p {
            margin: 0 0 2px 0;
        }

        /* Vendor section - each vendor gets a new page */
        .vendor-section {
            page-break-inside: avoid;
            page-break-before: always;
            margin-bottom: 30px;
        }

        /* First vendor doesn't need page break */
        .vendor-section:first-child {
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
            
            .vendor-section {
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
    $vendor = $vendor_details[$group_key];
    
    // Get stored total amounts from database instead of recalculating
    $vendor_total = 0;
    $invoice_number = $group['invoice_number'];
    $invoice_date = $group['invoice_date'];
    $vendor_name = $group['vendor_name'];
    $total_sql = "SELECT vi.total_amount as vendor_total 
                 FROM vendor_invoices vi
                 JOIN vendors v ON vi.vendor_id = v.id
                 WHERE vi.invoice_number = ? 
                 AND DATE(vi.invoice_date) = ?
                 AND v.name = ?";
    $total_stmt = $conn->prepare($total_sql);
    $total_stmt->bind_param("sss", $invoice_number, $invoice_date, $vendor_name);
    $total_stmt->execute();
    $total_result = $total_stmt->get_result();
    $total_row = $total_result->fetch_assoc();
    $vendor_total = $total_row['vendor_total'] ?? 0;
?>

<div class="vendor-section">
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
                <p><?php echo !empty($company['trademark']) ? 'Trade Mark (' . htmlspecialchars($company['trademark']) . ')' : 'Trade Mark (KAC)'; ?></p>
                <?php if (!empty($company['contact_numbers'])): ?>
                    <?php echo nl2br(htmlspecialchars($company['contact_numbers'])); ?>
                <?php else: ?>
                    <p>Ali Mohd: 9419067657</p>
                    <p>Sajad Ali: 7889718295</p>
                    <p>Umer Ali: 7006342374</p>
                <?php endif; ?>
            </div>
        </section>
        
        <section class="invoice-details">
            <div class="left-column">
                <p>Invoice No: <strong><?php echo $group['invoice_number']; ?></strong></p>
            </div>
            <div class="right-column">
                <p>Date: <strong><?php echo $group['invoice_date']; ?></strong></p>
            </div>
        </section>

        <section class="vendor-to">
            <p>Vendor: <strong><?php echo htmlspecialchars($vendor['name']); ?></strong></p>
            <?php if (!empty($vendor['contact'])): ?>
                <p>Contact: <?php echo htmlspecialchars($vendor['contact']); ?></p>
            <?php endif; ?>
            <p>Type: <?php echo htmlspecialchars($vendor['type']); ?> | Category: <?php echo htmlspecialchars($vendor['vendor_category']); ?></p>
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
                    <td class="total-value">₹<?php echo number_format($vendor_total, 2); ?></td>
                </tr>
            </tbody>
        </table>

        <section class="bottom-section">
            <div class="payment-details">
                <p>Ledger Balance: <strong>₹<?php echo number_format(abs($vendor['balance']), 2); ?></strong></p>
            </div>
            <div class="software-credits">
                <p style="margin-top: 10px; font-size: 6px; color: #999; font-weight: bold; text-align: right;">Software by ibrahimbhat.com</p>
                <p style="margin-top: 5px; font-size: 6px; color: #999; font-weight: bold; text-align: right;">Powered by Evotec.in - Complete Business Management Solution</p>
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
