<?php
require_once __DIR__ . '/../../config/config.php';

$watak_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Get company settings
$sql = "SELECT * FROM company_settings LIMIT 1";
$company = $conn->query($sql)->fetch_assoc();

// Get watak details
$sql = "SELECT w.*, v.name as vendor_name, v.contact as vendor_contact, v.type as vendor_type, v.balance as vendor_balance
        FROM vendor_watak w
        JOIN vendors v ON w.vendor_id = v.id
        WHERE w.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $watak_id);
$stmt->execute();
$watak = $stmt->get_result()->fetch_assoc();

if (!$watak) {
    echo "<div class='alert alert-danger'>Watak not found</div>";
    exit;
}

// Get watak items
$sql = "SELECT * FROM watak_items WHERE watak_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $watak_id);
$stmt->execute();
$items_result = $stmt->get_result();

// Calculate totals using stored values
$total_amount = 0;
$total_labor = 0;
$items = [];

while ($item = $items_result->fetch_assoc()) {
    $items[] = $item;
    $total_amount += $item['amount'];
    // Use stored labor value instead of recalculating
    $total_labor += $item['labor'];
}

// Use stored commission amount instead of recalculating
$total_commission = $watak['total_commission'];

// Calculate expenses using stored values
$total_expenses = $total_commission + $total_labor + $watak['vehicle_charges'] + $watak['other_charges'] + $watak['bardan'];
$net_amount = $total_amount - $total_expenses;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Watak Invoice #<?php echo htmlspecialchars($watak['watak_number']); ?></title>
    
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

        /* Footer */
        footer {
            text-align: center;
            color: #6c757d;
            font-size: 0.9em;
            margin-top: 15px;
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
    <button class="print-button no-print" onclick="window.print()">Print</button>
    
    <div class="invoice-container">
        <header>
            <h1><?php echo htmlspecialchars($company['company_name']); ?></h1>
            <p><?php echo !empty($company['business_tagline']) ? htmlspecialchars($company['business_tagline']) : 'Wholesale Dealers of Vegetables'; ?></p>
        </header>

        <section class="top-section">
            <div class="left-info">
                <p><?php echo !empty($company['company_address']) ? htmlspecialchars($company['company_address']) : '75, 313 Iqbal Sabzi Mandi, Bagh Nand Singh'; ?></p>
                <p><?php echo !empty($company['company_address']) ? '' : 'Tatoo Ground, Batamaloo, Sgr.'; ?></p>
                <p>Watak No: <strong><?php echo htmlspecialchars($watak['watak_number']); ?></strong></p>
            </div>
            <div class="right-info">
                <p>Vendor Type: Local</p>
                <?php if (!empty($company['contact_numbers'])): ?>
                    <?php echo nl2br(htmlspecialchars($company['contact_numbers'])); ?>
                <?php else: ?>
                    <p>Contact: +91 XXXXX XXXXX</p>
                <?php endif; ?>
                <br>
                <p>Date: <strong><?php echo date('d/m/Y', strtotime($watak['inventory_date'] ?? $watak['date'])); ?></strong></p>
            </div>
        </section>

        <section class="meta-info">
            <p>Bill to: <strong><?php echo htmlspecialchars($watak['vendor_name']); ?></strong></p>
        </section>

        <div class="vehicle-info">
            <span>Vehicle Number: <strong><?php echo !empty($watak['vehicle_no']) ? htmlspecialchars($watak['vehicle_no']) : 'N/A'; ?></strong></span>
            <span>Chalan No: <strong><?php echo !empty($watak['chalan_no']) ? htmlspecialchars($watak['chalan_no']) : 'N/A'; ?></strong></span>
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
                <?php foreach ($items as $index => $item): ?>
                <tr>
                    <td><?php echo $index + 1; ?></td>
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
                    <td>₹<?php echo number_format($total_amount, 0); ?></td>
                </tr>
            </tbody>
        </table>

        <section class="summary-section">
            <div class="expenses-breakdown">
                <p><strong>Expenses Breakdown:</strong></p>
                <p><span class="label">Commission:</span> ₹<?php echo number_format(floor($total_commission), 0); ?></p>
                <p><span class="label">Labor Charges:</span> ₹<?php echo number_format(floor($total_labor), 0); ?></p>
                <p><span class="label">Vehicle Charges:</span> ₹<?php echo number_format(floor($watak['vehicle_charges']), 0); ?></p>
                <p><span class="label">Other Charges:</span> ₹<?php echo number_format(floor($watak['other_charges']), 0); ?></p>
                <p><span class="label">Bardan:</span> ₹<?php echo number_format(floor($watak['bardan']), 0); ?></p>
            </div>
            <div class="sale-summary">
                <?php 
                // Apply rounding logic for Goods Sale Proceeds
                $goods_sale_proceeds = $total_amount;
                $decimal_part = $goods_sale_proceeds - floor($goods_sale_proceeds);
                if ($decimal_part >= 0.5) {
                    $goods_sale_proceeds = ceil($goods_sale_proceeds);
                } else {
                    $goods_sale_proceeds = floor($goods_sale_proceeds);
                }
                
                // Calculate expenses with no decimals
                $total_expenses_rounded = floor($total_commission) + floor($total_labor) + floor($watak['vehicle_charges']) + floor($watak['other_charges']) + floor($watak['bardan']);
                ?>
                <p>Goods Sale Proceeds: <strong>₹<?php echo number_format($goods_sale_proceeds, 0); ?></strong></p>
                <p>Expenses: <strong>₹<?php echo number_format($total_expenses_rounded, 0); ?></strong></p>
                <p class="net-amount">Net Amount: <strong>₹<?php echo number_format(floor($goods_sale_proceeds - $total_expenses_rounded), 0); ?></strong></p>
            </div>
        </section>

        <footer>
            <p>Thank you for your business!</p>
            <p style="margin-top: 10px; font-size: 8px; color: #999;">Software by ibrahimbhat.com</p>
        </footer>
    </div>

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