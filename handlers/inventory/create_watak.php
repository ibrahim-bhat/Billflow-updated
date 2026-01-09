<?php
// Start output buffering to prevent headers already sent error
ob_start();

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../views/layout/header.php';
require_once __DIR__ . '/../../core/helpers/numbering_helper.php';

// Get vendor and date from POST or GET
$vendor_id = isset($_POST['vendor_id']) ? intval($_POST['vendor_id']) : (isset($_GET['vendor_id']) ? intval($_GET['vendor_id']) : 0);
$inventory_date = isset($_POST['inventory_date']) ? $_POST['inventory_date'] : (isset($_GET['date']) ? $_GET['date'] : '');

// Validate input
if (!$vendor_id || !$inventory_date) {
    echo "<div class='alert alert-danger'>Invalid request. Please provide vendor and date.</div>";
    echo "<a href='../../views/inventory/index.php' class='btn btn-primary mt-3'>Back to Inventory</a>";
    exit;
}

// Get vendor details
$sql = "SELECT * FROM vendors WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $vendor_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();

if (!$vendor) {
    echo "<div class='alert alert-danger'>Vendor not found</div>";
    echo "<a href='../../views/inventory/index.php' class='btn btn-primary mt-3'>Back to Inventory</a>";
    exit;
}

// Get inventory items with detailed information
$sql = "SELECT 
        i.id as item_id,
        i.name as item_name,
        ii.id as inventory_item_id,
        ii.quantity_received as quantity,
        ii.remaining_stock,
        inv.id as inventory_id,
        inv.date_received,
        inv.vehicle_no,
        inv.vehicle_charges,
        inv.bardan,
        COALESCE(
            (SELECT vii.rate 
             FROM vendor_invoice_items vii 
             JOIN vendor_invoices vi ON vii.invoice_id = vi.id 
             WHERE vii.item_id = i.id 
             AND vi.vendor_id = inv.vendor_id
             AND DATE(vi.invoice_date) = DATE(inv.date_received)
             LIMIT 1), 
            i.default_rate,
            0
        ) as rate,
        COALESCE(
            (SELECT vii.weight 
             FROM vendor_invoice_items vii 
             JOIN vendor_invoices vi ON vii.invoice_id = vi.id 
             WHERE vii.item_id = i.id 
             AND vi.vendor_id = inv.vendor_id
             AND DATE(vi.invoice_date) = DATE(inv.date_received)
             LIMIT 1), 
            0
        ) as weight
        FROM inventory inv
        JOIN inventory_items ii ON inv.id = ii.inventory_id
        JOIN items i ON ii.item_id = i.id
        WHERE inv.vendor_id = ? AND DATE(inv.date_received) = ?
        ORDER BY ii.id";

$stmt = $conn->prepare($sql);
$stmt->bind_param('is', $vendor_id, $inventory_date);
$stmt->execute();
$items_result = $stmt->get_result();
$items = [];

while ($row = $items_result->fetch_assoc()) {
    $row['amount'] = $row['quantity'] * $row['rate'];
    
    // Get customer purchase history for this SPECIFIC inventory item entry
    // This ensures each separate inventory entry shows only its own data
    $sql_customer_history = "SELECT 
                            SUM(cii.quantity) as total_quantity,
                            SUM(cii.weight) as total_weight,
                            SUM(cii.amount) as total_amount,
                            CASE 
                                WHEN SUM(cii.weight) > 0 THEN SUM(cii.amount) / SUM(cii.weight)
                                WHEN SUM(cii.quantity) > 0 THEN SUM(cii.amount) / SUM(cii.quantity)
                                ELSE 0
                            END as avg_rate
                            FROM customer_invoice_items cii
                            JOIN customer_invoices ci ON cii.invoice_id = ci.id
                            WHERE cii.inventory_item_id = ?";
    
    $stmt_history = $conn->prepare($sql_customer_history);
    $stmt_history->bind_param('i', $row['inventory_item_id']);
    $stmt_history->execute();
    $customer_history = $stmt_history->get_result()->fetch_assoc();
    
    // Add customer purchase data to the item
    if ($customer_history && $customer_history['total_quantity'] > 0) {
        $row['customer_quantity'] = $customer_history['total_quantity'];
        $row['customer_weight'] = $customer_history['total_weight'];
        $row['customer_rate'] = $customer_history['avg_rate'];
        $row['customer_amount'] = $customer_history['total_amount'];
    } else {
        $row['customer_quantity'] = 0;
        $row['customer_weight'] = 0;
        $row['customer_rate'] = 0;
        $row['customer_amount'] = 0;
    }
    
    $items[] = $row;
}

// Generate sequential watak number starting from 1
$next_number = getNextWatakNumber($conn);
$watak_number = formatWatakNumber($next_number);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_watak'])) {
            // Debug: Log the POST data
        error_log("POST data received: " . print_r($_POST, true));
        
        // Additional debugging
        if (isset($_POST['items'])) {
            error_log("Items count: " . count($_POST['items']));
            foreach ($_POST['items'] as $index => $item) {
                error_log("Item $index: " . print_r($item, true));
            }
        } else {
            error_log("No items array found in POST data");
        }
    
    try {
        $conn->begin_transaction();

        // Insert watak
        $sql = "INSERT INTO vendor_watak (
                vendor_id, watak_number, date, inventory_date, vehicle_no, chalan_no,
                vehicle_charges, bardan, other_charges, 
                total_amount, total_commission, net_payable
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        $watak_number = $_POST['watak_number'] ?? formatWatakNumber(getNextWatakNumber($conn));
        $date = $_POST['date'];
        $vehicle_no = $_POST['vehicle_no'];
        $chalan_no = $_POST['chalan_no'] ?? '';
        $vehicle_charges = floatval($_POST['vehicle_charges']);
        $bardan = floatval($_POST['bardan']);
        $other_charges = floatval($_POST['other_charges']);
        $total_amount = 0;
        $total_commission = 0;
        $net_payable = 0;

        $stmt->bind_param('isssssdsdddd', 
            $vendor_id, $watak_number, $date, $inventory_date, $vehicle_no, $chalan_no,
            $vehicle_charges, $bardan, $other_charges,
            $total_amount, $total_commission, $net_payable
        );
        $stmt->execute();
        $watak_id = $conn->insert_id;

        // Insert watak items
        $sql = "INSERT INTO watak_items (
                watak_id, item_name, quantity, weight,
                rate, commission_percent, labor, amount
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        
        $total_amount = 0;
        
        // Check if items array exists and is not empty
        if (!isset($_POST['items']) || empty($_POST['items'])) {
            throw new Exception("No items selected for watak. Please add at least one item.");
        }
        
        // Calculate commission percentage
        $commission_percent = floatval($_POST['commission_percent'] ?? ($vendor['type'] === 'Local' ? 10 : 6));
        
        // Calculate labor rate
        $labor_rate = floatval($_POST['labor_rate'] ?? 1);
        
        foreach ($_POST['items'] as $item) {
            // Validate item data - allow 0 quantity but require name and rate
            if (empty($item['name']) || !isset($item['quantity']) || empty($item['rate'])) {
                continue; // Skip invalid items
            }
            
            $quantity = floatval($item['quantity']);
            $weight = floatval($item['weight'] ?? 0);
            $rate = floatval($item['rate']);
            
            // Calculate amount based on weight vs quantity logic
            $amount = 0;
            if ($weight > 0) {
                // If weight is provided, use weight × rate
                $amount = $weight * $rate;
            } else if ($quantity > 0) {
                // If weight is not provided but quantity is, use quantity × rate
                $amount = $quantity * $rate;
            }
            
            // If we have customer sales data, use the actual customer amount instead of calculated amount
            if (isset($item['customer_amount']) && $item['customer_amount'] > 0) {
                $amount = $item['customer_amount'];
            }
            
            $total_amount += $amount;
            
            // Calculate labor for this item
            $item_labor = 0;
            $item_name_lower = strtolower($item['name']);
            if ($item_name_lower !== 'krade') {
                $item_labor = $quantity * $labor_rate;
            }

            $stmt->bind_param('isdddddd',
                $watak_id,
                $item['name'],
                $item['quantity'],
                $item['weight'],
                $item['rate'],
                $commission_percent,
                $item_labor,
                $amount
            );
            $stmt->execute();
        }
        
        // Check if any valid items were processed
        if ($total_amount == 0) {
            throw new Exception("No valid items found. Please ensure all items have name, quantity, and rate.");
        }

        // Calculate commission as percentage of total amount based on vendor type
        $total_commission = ($total_amount * $commission_percent) / 100;
        
        // Calculate total labor from stored item labor values
        $total_labor = 0;
        $labor_sql = "SELECT SUM(labor) as total_labor FROM watak_items WHERE watak_id = ?";
        $labor_stmt = $conn->prepare($labor_sql);
        $labor_stmt->bind_param('i', $watak_id);
        $labor_stmt->execute();
        $labor_result = $labor_stmt->get_result();
        $labor_row = $labor_result->fetch_assoc();
        $total_labor = $labor_row['total_labor'] ?? 0;

        // Apply rounding logic
        // 1. Expenses: Remove all decimals (round down)
        $total_commission = floor($total_commission);
        $total_labor = floor($total_labor);
        $vehicle_charges = floor($vehicle_charges);
        $other_charges = floor($other_charges);
        $bardan = floor($bardan);
        
        // 2. Goods Sale Proceeds: If decimal >= 0.5, round up by 1 rupee; if < 0.5, keep current amount and remove decimal
        $goods_sale_proceeds = $total_amount;
        $decimal_part = $goods_sale_proceeds - floor($goods_sale_proceeds);
        if ($decimal_part >= 0.5) {
            $goods_sale_proceeds = ceil($goods_sale_proceeds);
        } else {
            $goods_sale_proceeds = floor($goods_sale_proceeds);
        }
        
        // 3. Net Amount: Remove all decimals (round down)
        $net_payable = $goods_sale_proceeds - $total_commission - $total_labor - $vehicle_charges - $other_charges - $bardan;
        $net_payable = floor($net_payable);
        $sql = "UPDATE vendor_watak SET 
                total_amount = ?,
                total_commission = ?,
                net_payable = ?
                WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('dddi', $goods_sale_proceeds, $total_commission, $net_payable, $watak_id);
        $stmt->execute();

        // Update vendor balance (add watak amount to vendor balance)
        $update_vendor_sql = "UPDATE vendors SET balance = balance + ? WHERE id = ?";
        $update_vendor_stmt = $conn->prepare($update_vendor_sql);
        $update_vendor_stmt->bind_param('di', $net_payable, $vendor_id);
        $update_vendor_stmt->execute();

        $conn->commit();
        
        // Show success message and redirect
        $_SESSION['success_message'] = "Watak created successfully! Watak ID: " . $watak_id . ", Date: " . $date . ". Vendor balance updated with ₹" . number_format($net_payable, 2);
        header("Location: ../../views/watak/index.php?filter_date=" . $date);
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error creating watak: " . $e->getMessage();
        error_log("Watak creation error: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
    }
}
?>

<div class="main-content">
    <div class="container-fluid mt-4">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Add Watak</h3>
                <button type="button" class="close" aria-label="Close" onclick="window.location.href='../../views/inventory/vendor_report.php?date=<?php echo $inventory_date; ?>'">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="card-body">
                <?php if (isset($error_message)): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>

                <form method="post" id="watakForm">
                    <input type="hidden" name="vendor_id" value="<?php echo $vendor_id; ?>">
                    <input type="hidden" name="inventory_date" value="<?php echo $inventory_date; ?>">
                    <input type="hidden" name="watak_number" value="<?php echo $watak_number; ?>">
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label>Vendor</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($vendor['name']); ?>" readonly>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label>Date <span class="text-danger">*</span></label>
                            <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            <small class="form-text text-muted">Watak date (today's date when creating the watak)</small>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label>Vehicle No.</label>
                            <input type="text" name="vehicle_no" class="form-control" 
                                   value="<?php echo !empty($items) ? htmlspecialchars($items[0]['vehicle_no']) : ''; ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label>Chalan No.</label>
                            <input type="text" name="chalan_no" class="form-control" placeholder="Enter chalan number">
                        </div>
                    </div>

                    <div class="mt-4 mb-3" id="watak_items_container">
                        <?php if (empty($items)): ?>
                        <div id="empty_watak_row" class="text-center text-muted py-4 border rounded">
                            Click "Add Item" to add items to the watak
                        </div>
                        <?php else: ?>
                            <?php foreach ($items as $index => $item): ?>
                            <div class="watak-item-card border rounded p-3 mb-3 position-relative" data-index="<?php echo $index; ?>">
                                <button type="button" class="btn btn-sm btn-danger delete-watak-item position-absolute top-0 end-0 m-2">
                                    <i class="fas fa-times"></i>
                                </button>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Item Name <span class="text-danger">*</span></label>
                                        <input type="text" name="items[<?php echo $index; ?>][name]" class="form-control" 
                                               value="<?php echo htmlspecialchars($item['item_name']); ?>" required>
                                        <?php if ($item['customer_quantity'] > 0): ?>
                                        <small class="text-muted d-block mt-1">Customer purchases: <?php echo $item['customer_quantity']; ?> units</small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Quantity <span class="text-danger">*</span></label>
                                        <input type="number" name="items[<?php echo $index; ?>][quantity]" class="form-control quantity" 
                                               value="<?php echo $item['customer_quantity']; ?>" step="0.01" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Weight (Optional)</label>
                                        <input type="number" name="items[<?php echo $index; ?>][weight]" class="form-control weight" 
                                               value="<?php echo $item['customer_weight'] > 0 ? $item['customer_weight'] : 0; ?>" step="0.01">
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <label class="form-label">Rate <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text">₹</span>
                                            <input type="text" name="items[<?php echo $index; ?>][rate]" class="form-control rate" 
                                                   value="<?php echo $item['customer_rate'] > 0 ? $item['customer_rate'] : $item['rate']; ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Total Amount</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₹</span>
                                            <input type="number" class="form-control amount" 
                                                   value="<?php echo $item['customer_amount']; ?>" readonly>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <style>
                    /* Mobile responsive styles for watak creation from inventory */
                    @media (max-width: 768px) {
                        /* Hide table headers on mobile */
                        #itemsTable thead {
                            display: none !important;
                        }
                        
                        /* Convert table rows to card-like layout on mobile */
                        #itemsTable tbody tr {
                            display: block !important;
                            border: 1px solid #dee2e6 !important;
                            margin-bottom: 15px !important;
                            padding: 15px !important;
                            border-radius: 8px !important;
                            background-color: #f8f9fa !important;
                        }
                        
                        #itemsTable tbody tr td {
                            display: block !important;
                            border: none !important;
                            padding: 8px 0 !important;
                            text-align: left !important;
                        }
                        
                        /* Add labels for each field on mobile */
                        #itemsTable tbody tr td:nth-child(1)::before {
                            content: "Item Name: ";
                            font-weight: bold;
                            color: #495057;
                        }
                        
                        #itemsTable tbody tr td:nth-child(2)::before {
                            content: "Quantity: ";
                            font-weight: bold;
                            color: #495057;
                        }
                        
                        #itemsTable tbody tr td:nth-child(3)::before {
                            content: "Weight: ";
                            font-weight: bold;
                            color: #495057;
                        }
                        
                        #itemsTable tbody tr td:nth-child(4)::before {
                            content: "Rate: ";
                            font-weight: bold;
                            color: #495057;
                        }
                        
                        #itemsTable tbody tr td:nth-child(5)::before {
                            content: "Total: ";
                            font-weight: bold;
                            color: #495057;
                        }
                        
                        /* Style form controls on mobile */
                        #itemsTable tbody tr td input,
                        #itemsTable tbody tr td select {
                            width: 100% !important;
                            margin-top: 5px !important;
                            border-radius: 4px !important;
                            border: 1px solid #ced4da !important;
                            padding: 8px 12px !important;
                        }
                        
                        /* Style the delete button */
                        #itemsTable tbody tr td:last-child {
                            text-align: center !important;
                            padding-top: 15px !important;
                        }
                        
                        #itemsTable tbody tr td:last-child::before {
                            content: "" !important;
                        }
                        
                        #itemsTable tbody tr td:last-child .btn {
                            width: 100% !important;
                            padding: 10px !important;
                        }
                        
                        /* Empty row styling */
                        #itemsTable tbody tr td[colspan="6"] {
                            text-align: center !important;
                            padding: 30px !important;
                            color: #6c757d !important;
                            border: none !important;
                            background: none !important;
                        }
                        
                        #itemsTable tbody tr td[colspan="6"]::before {
                            content: "" !important;
                        }
                        
                        /* Customer purchases info styling */
                        #itemsTable tbody tr td small.text-muted {
                            margin-top: 5px !important;
                            display: block !important;
                            font-size: 12px !important;
                            color: #6c757d !important;
                        }
                        
                        /* Make form fields more mobile-friendly */
                        .form-control {
                            font-size: 16px !important; /* Prevents zoom on iOS */
                        }
                        
                        /* Improve button spacing */
                        .btn {
                            margin-bottom: 10px !important;
                        }
                        
                        /* Make summary tables responsive */
                        .table-sm {
                            font-size: 14px !important;
                        }
                        
                        .table-sm td {
                            padding: 8px 4px !important;
                        }
                    }
                    </style>
                    
                    <!-- Summary rows -->
                    <div class="row">
                        <div class="col-md-8 offset-md-4">
                            <table class="table table-sm">
                                <tr>
                                    <td class="text-right"><strong>Sub Total:</strong></td>
                                    <td width="150" class="text-right">₹<span id="subTotal">0.00</span></td>
                                </tr>
                                <tr>
                                    <td class="text-right"><strong>Total Commission:</strong></td>
                                    <td class="text-right">₹<span id="totalCommission">0.00</span></td>
                                </tr>
                                <tr>
                                    <td class="text-right"><strong>Total Labor:</strong></td>
                                    <td class="text-right">₹<span id="totalLabor">0.00</span></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <div class="text-left mt-3 mb-4">
                        <button type="button" class="btn btn-primary" id="addRow">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
                    </div>

                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label>Commission %</label>
                            <div class="input-group">
                                <input type="number" name="commission_percent" id="commissionPercent" class="form-control" 
                                       value="<?php echo $vendor['type'] === 'Local' ? 10 : 6; ?>" step="0.01" min="0" max="100">
                                <div class="input-group-append">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <small class="form-text text-muted">
                                <?php echo $vendor['type'] === 'Local' ? 'Local vendor (10%)' : 'Outsider vendor (6%)'; ?>
                            </small>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label>Labor Rate</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text">₹</span>
                                </div>
                                <input type="number" name="labor_rate" id="laborRate" class="form-control" 
                                       value="<?php echo $vendor['type'] === 'Local' ? 1 : 2; ?>" step="0.01" min="0">
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label>Vehicle Charges</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text">₹</span>
                                </div>
                                <input type="number" name="vehicle_charges" id="vehicleCharges" class="form-control" 
                                       value="<?php echo !empty($items) ? $items[0]['vehicle_charges'] : 0; ?>" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label>Bardan</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text">₹</span>
                                </div>
                                <input type="number" name="bardan" id="bardan" class="form-control" 
                                       value="<?php echo !empty($items) ? $items[0]['bardan'] : 0; ?>" step="0.01">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label>Other Charges</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text">₹</span>
                                </div>
                                <input type="number" name="other_charges" id="otherCharges" class="form-control" value="0" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-9 mb-3">
                            <label>&nbsp;</label>
                            <div class="form-text">
                                <strong>Note:</strong> Commission is calculated as a percentage of subtotal. Labor charges are automatically calculated as (Labor Rate × Quantity) for all items except "Krade".
                            </div>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-8 offset-md-4">
                            <table class="table table-sm">
                                <tr>
                                    <td class="text-right"><strong>Grand Total:</strong></td>
                                    <td width="150" class="text-right">₹<span id="grandTotal">0.00</span></td>
                                </tr>
                                <tr>
                                    <td class="text-right"><strong>Total Commission:</strong></td>
                                    <td class="text-right">₹<span id="commissionTotal">0.00</span></td>
                                </tr>
                                <tr>
                                    <td class="text-right"><strong>Net Amount:</strong></td>
                                    <td class="text-right">₹<span id="netAmount">0.00</span></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Commission and labor rates are now visible input fields above -->

                    <div class="row mt-4">
                        <div class="col-md-12 text-right">
                            <button type="button" class="btn btn-secondary" onclick="window.location.href='../../views/inventory/vendor_report.php?date=<?php echo $inventory_date; ?>'">
                                Cancel
                            </button>
                            <button type="submit" name="save_watak" class="btn btn-primary">
                                Create Watak
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.table th, .table td {
    vertical-align: middle;
}
.input-group-text {
    background-color: #f8f9fa;
}
</style>

<script>
// Vendor type for rate input step control
const vendorType = '<?php echo $vendor['type']; ?>';

document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM fully loaded');
    
    // Debug function to log current values
    function logValues() {
        console.log('Logging current values:');
        let total = 0;
        $('.watak-item-card').each(function(index) {
            const quantity = parseFloat($(this).find('.quantity').val()) || 0;
            const weight = parseFloat($(this).find('.weight').val()) || 0;
            const rate = parseFloat($(this).find('.rate').val()) || 0;
            
            // Calculate amount based on weight vs quantity logic
            let amount = 0;
            if (weight > 0) {
                amount = weight * rate;
            } else if (quantity > 0) {
                amount = quantity * rate;
            }
            
            console.log(`Card ${index}: quantity=${quantity}, weight=${weight}, rate=${rate}, calculated amount=${amount}`);
            total += amount;
        });
        console.log(`Total calculated: ${total}`);
    }
    
    // Force calculation of card amount
    function calculateRowAmount(card) {
        const quantity = parseFloat(card.find('.quantity').val()) || 0;
        const weight = parseFloat(card.find('.weight').val()) || 0;
        const rate = parseFloat(card.find('.rate').val()) || 0;
        
        // Calculate amount based on weight vs quantity logic
        let amount = 0;
        if (weight > 0) {
            amount = weight * rate;
        } else if (quantity > 0) {
            amount = quantity * rate;
        }
        
        console.log(`Calculating card: quantity=${quantity}, weight=${weight}, rate=${rate}, amount=${amount}`);
        
        // Update the amount field
        card.find('.amount').val(amount.toFixed(2));
        
        // Always recalculate all totals after any change
        calculateAllTotals();
        
        return amount;
    }
    
    // Calculate all summary totals
    function calculateAllTotals() {
        let subTotal = 0;
        let totalLabor = 0;
        
        // Get labor rate from input field
        const laborRate = parseFloat($('#laborRate').val()) || 1;
        
        // Process each card to calculate subtotal and labor
        $('.watak-item-card').each(function() {
            const quantity = parseFloat($(this).find('.quantity').val()) || 0;
            const weight = parseFloat($(this).find('.weight').val()) || 0;
            const rate = parseFloat($(this).find('.rate').val()) || 0;
            
            // Calculate amount based on weight vs quantity logic
            let amount = 0;
            if (weight > 0) {
                amount = weight * rate;
            } else if (quantity > 0) {
                amount = quantity * rate;
            }
            
            $(this).find('.amount').val(amount.toFixed(2));
            
            // Add to subtotal
            subTotal += amount;
            
            // Calculate labor for this item (labor rate * quantity for all items except "Krade")
            const itemName = $(this).find('input[name^="items"][name$="[name]"]').val() || '';
            if (itemName.toLowerCase() !== 'krade') {
                totalLabor += quantity * laborRate;
            }
        });
        
        // Calculate commission as percentage of subtotal
        const commissionPercent = parseFloat($('#commissionPercent').val()) || (<?php echo json_encode($vendor['type']); ?> === 'Local' ? 10 : 6);
        const totalCommission = (subTotal * commissionPercent) / 100;
        
        // Get additional charges
        const vehicleCharges = parseFloat($('#vehicleCharges').val()) || 0;
        const otherCharges = parseFloat($('#otherCharges').val()) || 0;
        const bardan = parseFloat($('#bardan').val()) || 0;
        
        // Calculate final totals
        const grandTotal = subTotal;
        const netAmount = grandTotal - totalCommission - totalLabor - vehicleCharges - otherCharges - bardan;
        
        console.log(`Totals: subTotal=${subTotal}, commission=${totalCommission}, labor=${totalLabor}, net=${netAmount}`);
        
        // Update the displayed totals
        $('#subTotal').text(Math.round(subTotal));
        $('#totalCommission').text(Math.round(totalCommission));
        $('#totalLabor').text(Math.round(totalLabor));
        $('#grandTotal').text(Math.round(grandTotal));
        $('#commissionTotal').text(Math.round(totalCommission));
        $('#netAmount').text(Math.round(netAmount));
    }

    // Add new row
    $('#addRow').click(function() {
        const container = $('#watak_items_container');
        const emptyRow = $('#empty_watak_row');
        if (emptyRow.length) {
            emptyRow.hide();
        }
        
        const index = container.find('.watak-item-card').length;
        
        const newItem = `
            <div class="watak-item-card border rounded p-3 mb-3 position-relative" data-index="${index}">
                <button type="button" class="btn btn-sm btn-danger delete-watak-item position-absolute top-0 end-0 m-2">
                    <i class="fas fa-times"></i>
                </button>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Item Name <span class="text-danger">*</span></label>
                        <input type="text" name="items[${index}][name]" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Quantity <span class="text-danger">*</span></label>
                        <input type="number" name="items[${index}][quantity]" class="form-control quantity" value="0" step="0.01" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Weight (Optional)</label>
                        <input type="number" name="items[${index}][weight]" class="form-control weight" value="0" step="0.01">
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <label class="form-label">Rate <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="text" name="items[${index}][rate]" class="form-control rate" value="0" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Total Amount</label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" class="form-control amount" value="0" readonly>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        container.append(newItem);
        calculateAllTotals();
    });

    // Delete row
    $(document).on('click', '.delete-watak-item', function() {
        $(this).closest('.watak-item-card').remove();
        
        const container = $('#watak_items_container');
        if (container.find('.watak-item-card').length === 0) {
            const emptyRow = $('#empty_watak_row');
            if (emptyRow.length) {
                emptyRow.show();
            } else {
                container.prepend('<div id="empty_watak_row" class="text-center text-muted py-4 border rounded">Click "Add Item" to add items to the watak</div>');
            }
        }
        
        calculateAllTotals();
    });

    // Attach event handlers to form inputs
    $(document).on('input change keyup blur', '.watak-item-card .quantity, .watak-item-card .weight, .watak-item-card .rate', function() {
        calculateRowAmount($(this).closest('.watak-item-card'));
    });
    
    // Handle item name changes to recalculate totals
    $(document).on('input change keyup blur', '.watak-item-card input[name^="items"][name$="[name]"]', function() {
        calculateAllTotals();
    });
    
    // Handle vehicle charges, bardan, and other charges changes
    $(document).on('input change keyup blur', '#vehicleCharges, #bardan, #otherCharges', function() {
        calculateAllTotals();
    });
    
    // Handle commission percentage and labor rate changes
    $(document).on('input change keyup blur', '#commissionPercent, #laborRate', function() {
        calculateAllTotals();
    });
    
    // Initialize calculations on page load with multiple approaches
    
    // Approach 1: Immediate calculation
    calculateAllTotals();
    
    // Approach 2: Delayed calculation to ensure DOM is ready
    setTimeout(function() {
        console.log('Running delayed calculation');
        // Force calculation for each card
        $('.watak-item-card').each(function() {
            calculateRowAmount($(this));
        });
        calculateAllTotals();
        logValues();
    }, 500);
    
    // Approach 3: After all resources are loaded
    $(window).on('load', function() {
        console.log('Window fully loaded');
        calculateAllTotals();
    });
    
    // Log initial state
    logValues();
});

// Add a global function that can be called from browser console for debugging
function forceCalculate() {
    console.log('Manual calculation triggered');
    $('#itemsTable tbody tr').each(function() {
        if ($(this).find('.quantity').length > 0) {
            const quantity = parseFloat($(this).find('.quantity').val()) || 0;
            const weight = parseFloat($(this).find('.weight').val()) || 0;
            const rate = parseFloat($(this).find('.rate').val()) || 0;
            
            // Calculate amount based on weight vs quantity logic
            let amount = 0;
            if (weight > 0) {
                // If weight is provided, use weight × rate
                amount = weight * rate;
            } else if (quantity > 0) {
                // If weight is not provided but quantity is, use quantity × rate
                amount = quantity * rate;
            }
            
            $(this).find('.amount').val(amount.toFixed(2));
            
            // Auto-set labor to 1 * quantity for all items except "Krade"
            const itemName = $(this).find('input[name^="items"][name$="[name]"]').val() || '';
            if (itemName.toLowerCase() !== 'krade') {
                $(this).find('.labor').val(quantity.toFixed(2));
            }
        }
    });
    
    let subTotal = 0;
    $('#itemsTable tbody tr').each(function() {
        if ($(this).find('.amount').length > 0) {
            subTotal += parseFloat($(this).find('.amount').val()) || 0;
        }
    });
    
    $('#subTotal').text(subTotal.toFixed(2));
    $('#grandTotal').text(subTotal.toFixed(2));
    
    // Calculate other totals
    let totalCommission = 0;
    let totalLabor = 0;
    
    $('#itemsTable tbody tr').each(function() {
        if ($(this).find('.quantity').length > 0) {
            const amount = parseFloat($(this).find('.amount').val()) || 0;
            const commission = parseFloat($(this).find('.commission').val()) || 0;
            const labor = parseFloat($(this).find('.labor').val()) || 0;
            
            totalCommission += (amount * commission) / 100;
            totalLabor += labor;
        }
    });
    
    const vehicleCharges = parseFloat($('#vehicleCharges').val()) || 0;
    const otherCharges = parseFloat($('#otherCharges').val()) || 0;
    const bardan = parseFloat($('#bardan').val()) || 0;
    const netAmount = subTotal - totalCommission - totalLabor - vehicleCharges - otherCharges - bardan;
    
    $('#totalCommission').text(totalCommission.toFixed(2));
    $('#commissionTotal').text(totalCommission.toFixed(2));
    $('#totalLabor').text(totalLabor.toFixed(2));
    $('#netAmount').text(netAmount.toFixed(2));
    
    console.log({
        subTotal,
        totalCommission,
        totalLabor,
        vehicleCharges,
        otherCharges,
        bardan,
        netAmount
    });
    
    return "Calculation complete";
}

// Add global calculation function and initialization
</script>

<script>
// Check if jQuery is loaded properly
console.log('Checking jQuery status:', typeof jQuery !== 'undefined' ? 'jQuery is loaded' : 'jQuery is NOT loaded');

// Create a global variable to track if calculations have been performed
window.calculationsPerformed = false;

// Define a function to force calculations that can be called from anywhere
window.runWatakCalculations = function() {
    if (window.calculationsPerformed) {
        console.log('Calculations already performed, skipping');
        return;
    }
    
    console.log('Running global calculation function');
    
    // Calculate amounts for each row
    $('#itemsTable tbody tr').each(function() {
        if ($(this).find('.quantity').length > 0) {
            const quantity = parseFloat($(this).find('.quantity').val()) || 0;
            const weight = parseFloat($(this).find('.weight').val()) || 0;
            const rate = parseFloat($(this).find('.rate').val()) || 0;
            
            // Calculate amount based on weight vs quantity logic
            let amount = 0;
            if (weight > 0) {
                // If weight is provided, use weight × rate
                amount = weight * rate;
            } else if (quantity > 0) {
                // If weight is not provided but quantity is, use quantity × rate
                amount = quantity * rate;
            }
            
            $(this).find('.amount').val(amount.toFixed(2));
            
            // Auto-set labor to 1 * quantity for all items except "Krade"
            const itemName = $(this).find('input[name^="items"][name$="[name]"]').val() || '';
            if (itemName.toLowerCase() !== 'krade') {
                $(this).find('.labor').val(quantity.toFixed(2));
            }
            
            console.log(`Row calculated: quantity=${quantity}, weight=${weight}, rate=${rate}, amount=${amount}`);
        }
    });
    
    // Calculate all totals
    let subTotal = 0;
    let totalLabor = 0;
    let totalCommission = 0;
    
    $('#itemsTable tbody tr').each(function() {
        if ($(this).find('.quantity').length > 0) {
            const amount = parseFloat($(this).find('.amount').val()) || 0;
            const commission = parseFloat($(this).find('.commission').val()) || 0;
            const labor = parseFloat($(this).find('.labor').val()) || 0;
            
            subTotal += amount;
            totalLabor += labor;
            totalCommission += (amount * commission) / 100;
        }
    });
    
    const vehicleCharges = parseFloat($('#vehicleCharges').val()) || 0;
    const otherCharges = parseFloat($('#otherCharges').val()) || 0;
    const bardan = parseFloat($('#bardan').val()) || 0;
    const grandTotal = subTotal;
    const netAmount = grandTotal - totalCommission - totalLabor - vehicleCharges - otherCharges - bardan;
    
    // Update displayed totals
    $('#subTotal').text(subTotal.toFixed(2));
    $('#totalCommission').text(totalCommission.toFixed(2));
    $('#totalLabor').text(totalLabor.toFixed(2));
    $('#grandTotal').text(grandTotal.toFixed(2));
    $('#commissionTotal').text(totalCommission.toFixed(2));
    $('#netAmount').text(netAmount.toFixed(2));
    
    console.log('Global calculation complete with values:', {
        subTotal,
        totalCommission,
        totalLabor,
        vehicleCharges,
        otherCharges,
        bardan,
        netAmount
    });
    
    window.calculationsPerformed = true;
};

// Set up multiple triggers to ensure calculations happen
setTimeout(window.runWatakCalculations, 500);
setTimeout(window.runWatakCalculations, 1000);
setTimeout(window.runWatakCalculations, 2000);
</script>

<?php require_once __DIR__ . '/../../views/layout/footer.php'; ?> 

<!-- Inline script to force immediate calculation on page load -->
<script>
// Force immediate calculation as soon as this script runs
(function() {
    console.log('Inline script executing');
    
    // Run the global calculation function
    if (typeof window.runWatakCalculations === 'function') {
        window.runWatakCalculations();
    } else {
        console.error('Global calculation function not found, using fallback');
        
        // Fallback calculation
        $('#itemsTable tbody tr').each(function() {
            if ($(this).find('.quantity').length > 0) {
                const quantity = parseFloat($(this).find('.quantity').val()) || 0;
                const weight = parseFloat($(this).find('.weight').val()) || 0;
                const rate = parseFloat($(this).find('.rate').val()) || 0;
                
                // Calculate amount based on weight vs quantity logic
                let amount = 0;
                if (weight > 0) {
                    // If weight is provided, use weight × rate
                    amount = weight * rate;
                } else if (quantity > 0) {
                    // If weight is not provided but quantity is, use quantity × rate
                    amount = quantity * rate;
                }
                
                $(this).find('.amount').val(amount.toFixed(2));
                
                // Auto-set labor to 1 * quantity for all items except "Krade"
                const itemName = $(this).find('input[name^="items"][name$="[name]"]').val() || '';
                if (itemName.toLowerCase() !== 'krade') {
                    $(this).find('.labor').val(quantity.toFixed(2));
                }
            }
        });
        
        // Calculate all totals
        let subTotal = 0;
        $('#itemsTable tbody tr').each(function() {
            if ($(this).find('.amount').length > 0) {
                subTotal += parseFloat($(this).find('.amount').val()) || 0;
            }
        });
        
        // Update displayed totals
        $('#subTotal').text(subTotal.toFixed(2));
        $('#grandTotal').text(subTotal.toFixed(2));
    }
})();

// Also add a button to manually trigger calculations if needed
document.addEventListener('DOMContentLoaded', function() {
    const buttonContainer = document.createElement('div');
    buttonContainer.style.position = 'fixed';
    buttonContainer.style.bottom = '10px';
    buttonContainer.style.right = '10px';
    buttonContainer.style.zIndex = '9999';
    
    const calcButton = document.createElement('button');
    calcButton.textContent = 'Calculate Totals';
    calcButton.className = 'btn btn-sm btn-warning';
    calcButton.onclick = function() {
        if (typeof window.runWatakCalculations === 'function') {
            window.calculationsPerformed = false; // Reset flag to force calculation
            window.runWatakCalculations();
        } else if (typeof forceCalculate === 'function') {
            forceCalculate();
        }
    };
    
    buttonContainer.appendChild(calcButton);
    document.body.appendChild(buttonContainer);
});
</script>
</body>
</html>

<?php
// Flush the output buffer
ob_end_flush();
?> 