<?php
// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Get company settings
$company_name = "BillFlow"; // Default fallback
try {
    $sql = "SELECT company_name FROM company_settings LIMIT 1";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $settings = $result->fetch_assoc();
        if (!empty($settings['company_name'])) {
            $company_name = $settings['company_name'];
        }
    }
} catch (Exception $e) {
    error_log("Error fetching company settings: " . $e->getMessage());
}

// Get inventory details
if (!isset($_GET['id'])) {
    header('Location: ../../views/products/index.php');
    exit();
}

$inventory_id = sanitizeInput($_GET['id']);

$sql = "SELECT i.*, v.name as vendor_name, v.type as vendor_type
        FROM inventory i
        JOIN vendors v ON i.vendor_id = v.id
        WHERE i.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $inventory_id);
$stmt->execute();
$inventory = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$inventory) {
    header('Location: ../../views/products/index.php');
    exit();
}

// Get inventory items
$sql = "SELECT ii.*, i.name as item_name
        FROM inventory_items ii
        JOIN items i ON ii.item_id = i.id
        WHERE ii.inventory_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $inventory_id);
$stmt->execute();
$items_result = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Inventory - <?php echo htmlspecialchars($inventory['vendor_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            @page {
                size: A5;
                margin: 1cm;
            }
            body {
                padding: 20px;
            }
            .no-print {
                display: none !important;
            }
        }
        .company-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .company-header h1 {
            margin: 0;
            font-size: 24px;
        }
        .company-header p {
            margin: 5px 0;
            font-size: 14px;
        }
        .details-section {
            margin-bottom: 20px;
        }
        .details-table {
            width: 100%;
            margin-bottom: 20px;
        }
        .details-table th {
            width: 150px;
            text-align: left;
            padding: 5px;
        }
        .details-table td {
            padding: 5px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .items-table th,
        .items-table td {
            border: 1px solid #ddd;
            padding: 8px;
        }
        .items-table th {
            background-color: #f8f9fa;
        }
        .text-end {
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="no-print mb-3">
        <button type="button" class="btn btn-primary" onclick="window.print()">
            <i class="fas fa-print"></i> Print
        </button>
        <button type="button" class="btn btn-secondary" onclick="window.close()">
            <i class="fas fa-times"></i> Close
        </button>
    </div>

    <div class="company-header">
        <h1><?php echo htmlspecialchars($company_name); ?></h1>
        <p>Inventory Receipt</p>
    </div>

    <div class="details-section">
        <div class="row">
            <div class="col-md-6">
                <table class="details-table">
                    <tr>
                        <th>Vendor Name:</th>
                        <td><?php echo htmlspecialchars($inventory['vendor_name']); ?></td>
                    </tr>
                    <tr>
                        <th>Vendor Type:</th>
                        <td><?php echo htmlspecialchars($inventory['vendor_type']); ?></td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="details-table">
                    <tr>
                        <th>Date Received:</th>
                        <td><?php echo date('d M Y', strtotime($inventory['date_received'])); ?></td>
                    </tr>
                    <tr>
                        <th>Vehicle No:</th>
                        <td><?php echo htmlspecialchars($inventory['vehicle_no'] ?: '-'); ?></td>
                    </tr>
                    <tr>
                        <th>Vehicle Charges:</th>
                        <td>₹<?php echo number_format($inventory['vehicle_charges'], 0); ?></td>
                    </tr>
                    <tr>
                        <th>Bardan:</th>
                        <td>₹<?php echo $inventory['bardan'] ? number_format($inventory['bardan'], 0) : '-'; ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th>Sr. No.</th>
                <th>Item Name</th>
                <th class="text-end">Quantity Received</th>
                <th class="text-end">Remaining Stock</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $sr_no = 1;
            while ($item = $items_result->fetch_assoc()): 
            ?>
                <tr>
                    <td><?php echo $sr_no++; ?></td>
                    <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                            <td class="text-end"><?php echo number_format($item['quantity'], 0); ?></td>
                        <td class="text-end"><?php echo number_format($item['remaining_stock'], 0); ?></td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <div class="row mt-5">
        <div class="col-md-6">
            <p>Received By: _____________________</p>
        </div>
        <div class="col-md-6 text-end">
            <p>Authorized Signature: _____________________</p>
        </div>
    </div>

    <div class="footer text-center mt-5 pt-3 border-top">
        <p>Thank you for your business!</p>
        <p>Software by ibrahimbhat.com</p>
        <p>Powered by Evotec.in - Complete Business Management Solution</p>
    </div>

    <script>
        window.onload = function() {
            if (!window.location.search.includes('noprint')) {
                window.print();
            }
        };
    </script>
</body>
</html> 