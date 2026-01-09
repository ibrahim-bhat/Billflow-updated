<?php
require_once __DIR__ . '/../../config/config.php';

// Check if date parameter is provided, default to today
$date = isset($_GET['date']) ? sanitizeInput($_GET['date']) : date('Y-m-d');

// Get all local watak invoices for this date
$watak_sql = "SELECT w.*, v.name as vendor_name, v.contact as vendor_contact, v.type as vendor_type, v.balance as vendor_balance
               FROM vendor_watak w
               JOIN vendors v ON w.vendor_id = v.id
               WHERE DATE(w.date) = ? AND v.type = 'Local'
               ORDER BY w.watak_number ASC";
$watak_stmt = $conn->prepare($watak_sql);
$watak_stmt->bind_param("s", $date);
$watak_stmt->execute();
$watak_result = $watak_stmt->get_result();

if ($watak_result->num_rows === 0) {
    header('Location: ../../views/watak/index.php');
    exit();
}

// Get all watak details for local wataks on this date
$watak_details_sql = "SELECT w.*, v.name as vendor_name, v.type as vendor_type
                      FROM vendor_watak w
                      JOIN vendors v ON w.vendor_id = v.id
                      WHERE DATE(w.date) = ? AND v.type = 'Local'
                      ORDER BY w.watak_number ASC";
$watak_details_stmt = $conn->prepare($watak_details_sql);
$watak_details_stmt->bind_param("s", $date);
$watak_details_stmt->execute();
$watak_details_result = $watak_details_stmt->get_result();

// Group watak details by vendor and watak (separate each watak individually)
$grouped_wataks = [];
while ($watak = $watak_details_result->fetch_assoc()) {
    $vendor_name = $watak['vendor_name'];
    $watak_id = $watak['id'];
    
    // Create unique key for each watak (vendor + watak_id)
    $watak_key = $vendor_name . '_' . $watak_id;
    
    if (!isset($grouped_wataks[$watak_key])) {
        $grouped_wataks[$watak_key] = [];
    }
    $grouped_wataks[$watak_key][] = $watak;
}

// Get all watak items for local wataks on this date
$items_sql = "SELECT wi.*, w.watak_number, w.inventory_date, w.id as watak_id, v.name as vendor_name, v.type as vendor_type
              FROM watak_items wi
              JOIN vendor_watak w ON wi.watak_id = w.id
              JOIN vendors v ON w.vendor_id = v.id
              WHERE DATE(w.date) = ? AND v.type = 'Local'
              ORDER BY w.watak_number ASC, wi.id";
$items_stmt = $conn->prepare($items_sql);
$items_stmt->bind_param("s", $date);
$items_stmt->execute();
$items_result = $items_stmt->get_result();

// Group items by vendor and watak (separate each watak individually)
$grouped_items = [];
while ($item = $items_result->fetch_assoc()) {
    $vendor_name = $item['vendor_name'];
    $watak_id = $item['watak_id'];
    $watak_number = $item['watak_number'];
    $inventory_date = $item['inventory_date'];
    
    // Create unique key for each watak (vendor + watak_id)
    $watak_key = $vendor_name . '_' . $watak_id;
    
    if (!isset($grouped_items[$watak_key])) {
        $grouped_items[$watak_key] = [
            'vendor_name' => $vendor_name,
            'watak_id' => $watak_id,
            'watak_number' => $watak_number,
            'inventory_date' => $inventory_date,
            'items' => []
        ];
    }
    
    $grouped_items[$watak_key]['items'][] = $item;
}

// Get company settings
$company_sql = "SELECT * FROM company_settings WHERE id = 1";
$company_result = $conn->query($company_sql);
$company = $company_result->fetch_assoc();

// Format the date for display
$formatted_date = date('d/m/Y', strtotime($date));

// HTML for printing
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Local Watak Invoices - <?php echo $formatted_date; ?></title>
    
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

        .top-section .left-info p, .top-section .right-info p {
            margin: 0 0 1px 0;
        }
        
        .top-section .left-info {
            flex: 1;
            margin-right: 20px;
        }
        
        .top-section .right-info {
            flex: 1;
            text-align: right;
        }
        
        /* Bill To & Vehicle Info */
        .meta-info {
            line-height: 1.7;
        }

        .vehicle-info {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 10px;
            margin-bottom: 20px;
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
        
        /* Summary Section */
        .summary-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 18px;
            line-height: 1.5;
            font-size: 0.9em;
        }
        
        .summary-section p {
            margin: 0 0 2px 0;
        }

        .summary-section .label {
            display: inline-block;
            width: 100px; /* Reduced width for better fit */
        }
        
        .expenses-breakdown {
            flex: 1;
            margin-right: 20px;
        }
        
        .sale-summary {
            flex: 1;
            text-align: right;
        }
        
        .sale-summary .net-amount {
            border-top: 1px solid #dee2e6;
            padding-top: 8px;
            margin-top: 8px;
            font-size: 1.1em;
            font-weight: bold;
            color: #2E8B57;
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

        .footer-details {
            text-align: right;
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
            
            .summary-section {
                font-size: 0.85em;
            }
            
            .top-section {
                font-size: 0.8em;
            }
            
            .sale-summary .net-amount {
                font-size: 1em;
                font-weight: bold;
                color: #2E8B57;
            }
            
            .bottom-section {
                font-size: 0.85em;
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
    <button class="print-button no-print" onclick="window.print()">Print All Local Watak</button>
    
    <?php 
    // Get vendor details for each watak
    $vendor_details = [];
    foreach ($grouped_items as $watak_key => $watak_data) {
        $vendor_name = $watak_data['vendor_name'];
        if (!isset($vendor_details[$vendor_name])) {
            $vendor_sql = "SELECT * FROM vendors WHERE name = ? AND type = 'Local'";
            $vendor_stmt = $conn->prepare($vendor_sql);
            $vendor_stmt->bind_param("s", $vendor_name);
            $vendor_stmt->execute();
            $vendor_result = $vendor_stmt->get_result();
            $vendor_details[$vendor_name] = $vendor_result->fetch_assoc();
        }
    }
    
    foreach ($grouped_items as $watak_key => $watak_data): 
        $vendor_name = $watak_data['vendor_name'];
        $watak_id = $watak_data['watak_id'];
        $watak_number = $watak_data['watak_number'];
        $inventory_date = $watak_data['inventory_date'];
        $items = $watak_data['items'];
        
        $vendor = $vendor_details[$vendor_name];
        $vendor_wataks = $grouped_wataks[$watak_key];
        
        // Calculate totals from stored watak values for this specific watak
        $vendor_total = 0;
        $vendor_commission = 0;
        $vendor_vehicle_charges = 0;
        $vendor_other_charges = 0;
        $vendor_bardan = 0;
        
        $vehicle_no = '';
        $chalan_no = '';
        foreach ($vendor_wataks as $watak) {
            $vendor_total += $watak['total_amount'];
            $vendor_commission += $watak['total_commission'];
            $vendor_vehicle_charges += $watak['vehicle_charges'];
            $vendor_other_charges += $watak['other_charges'];
            $vendor_bardan += $watak['bardan'];
            // Get vehicle and chalan info from the first watak (they should be the same for grouped wataks)
            if (empty($vehicle_no)) {
                $vehicle_no = $watak['vehicle_no'];
                $chalan_no = $watak['chalan_no'];
            }
        }
    ?>
    <div class="vendor-section">
        <div class="invoice-container">
            <header>
                <h1><?php echo htmlspecialchars($company['company_name']); ?></h1>
                <p><?php echo !empty($company['business_tagline']) ? htmlspecialchars($company['business_tagline']) : 'Wholesale Dealers of Vegetables'; ?></p>
            </header>

            <section class="top-section">
                <div class="left-info">
                    <p><?php echo !empty($company['company_address']) ? htmlspecialchars($company['company_address']) : '75, 313 Iqbal Sabzi Mandi, Bagh Nand Singh'; ?></p>
                    <p><?php echo !empty($company['company_address']) ? '' : 'Tatoo Ground, Batamaloo, Sgr.'; ?></p>
                    <p>Watak No: <strong><?php echo $watak_number; ?></strong></p>
                </div>
                <div class="right-info">
                    <p>Vendor Type: Local</p>
                    <?php if (!empty($company['contact_numbers'])): ?>
                        <?php echo nl2br(htmlspecialchars($company['contact_numbers'])); ?>
                    <?php else: ?>
                        <p>Ali Mohd: 9419067657</p>
                        <p>Sajad Ali: 7889718295</p>
                        <p>Umer Ali: 7006342374</p>
                    <?php endif; ?>
                    <br>
                    <p>Date: <strong><?php echo date('d/m/Y', strtotime($inventory_date ?? $formatted_date)); ?></strong></p>
                </div>
            </section>

            <section class="meta-info">
                <p>Bill to: <strong><?php echo htmlspecialchars($vendor['name']); ?></strong></p>
            </section>

            <div class="vehicle-info">
                <span>Vehicle Number: <strong><?php echo !empty($vehicle_no) ? htmlspecialchars($vehicle_no) : 'N/A'; ?></strong></span>
                <span>Chalan No: <strong><?php echo !empty($chalan_no) ? htmlspecialchars($chalan_no) : 'N/A'; ?></strong></span>
            </div>

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
                    foreach ($items as $item): 
                    ?>
                    <tr>
                        <td><?php echo $sno++; ?></td>
                        <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td><?php echo number_format($item['quantity'], 0); ?></td>
                        <td><?php echo $item['weight'] ? number_format($item['weight'], 1) : '-'; ?></td>
                        <td>₹<?php echo number_format($item['rate'], 1); ?></td>
                        <td>₹<?php echo number_format($item['amount'], 0); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="4" style="border: none;"></td>
                        <td class="total-label">Total:</td>
                        <td>₹<?php echo number_format($vendor_total, 0); ?></td>
                    </tr>
                </tbody>
            </table>

            <section class="summary-section">
                <div class="expenses-breakdown">
                    <?php 
                    // Calculate labor charges for this specific watak
                    $total_labor = 0;
                    
                    foreach ($items as $item) {
                        // Use stored labor value instead of recalculating
                        $total_labor += $item['labor'];
                    }
                    ?>
                    <p><strong>Expenses Breakdown:</strong></p>
                    <p><span class="label">Commission:</span> ₹<?php echo number_format(floor($vendor_commission), 0); ?></p>
                    <p><span class="label">Labor Charges:</span> ₹<?php echo number_format(floor($total_labor), 0); ?></p>
                    <p><span class="label">Vehicle Charges:</span> ₹<?php echo number_format(floor($vendor_vehicle_charges), 0); ?></p>
                    <p><span class="label">Other Charges:</span> ₹<?php echo number_format(floor($vendor_other_charges), 0); ?></p>
                    <p><span class="label">Bardan:</span> ₹<?php echo number_format(floor($vendor_bardan), 0); ?></p>
                </div>
                <div class="sale-summary">
                    <?php 
                    // Apply rounding logic for Goods Sale Proceeds
                    $goods_sale_proceeds = $vendor_total;
                    $decimal_part = $goods_sale_proceeds - floor($goods_sale_proceeds);
                    if ($decimal_part >= 0.5) {
                        $goods_sale_proceeds = ceil($goods_sale_proceeds);
                    } else {
                        $goods_sale_proceeds = floor($goods_sale_proceeds);
                    }
                    
                    // Calculate expenses with no decimals using stored values
                    $total_expenses_rounded = floor($vendor_commission) + floor($total_labor) + floor($vendor_vehicle_charges) + floor($vendor_other_charges) + floor($vendor_bardan);
                    ?>
                    <p>Goods Sale Proceeds: <strong>₹<?php echo number_format($goods_sale_proceeds, 0); ?></strong></p>
                    <p>Expenses: <strong>₹<?php echo number_format($total_expenses_rounded, 0); ?></strong></p>
                    <p class="net-amount">Net Amount: <strong>₹<?php echo number_format(floor($goods_sale_proceeds - $total_expenses_rounded), 0); ?></strong></p>
                </div>
            </section>

            <section class="bottom-section">
                <div class="payment-details">
                    <p>Thank you for your business!</p>
                </div>
                <div class="footer-details">
                    <p style="margin-top: 4px; font-size: 6px; color: #999;">Software by ibrahimbhat.com</p>
                    <p style="margin-top: 4px; font-size: 6px; color: #999;">Powered by Evotec.in - Complete Business Management Solution</p>
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