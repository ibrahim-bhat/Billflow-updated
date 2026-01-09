<?php
// Start output buffering to prevent headers already sent error
ob_start();

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../layout/header.php';
require_once __DIR__ . '/../../core/helpers/numbering_helper.php';

// Get watak ID from GET parameter
$watak_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Validate input
if (!$watak_id) {
    echo "<div class='alert alert-danger'>Invalid request. Please provide a valid watak ID.</div>";
    echo "<a href='index.php' class='btn btn-primary mt-3'>Back to Watak</a>";
    exit;
}

// Get watak details
$sql = "SELECT w.*, v.name as vendor_name, v.type as vendor_type 
        FROM vendor_watak w 
        JOIN vendors v ON w.vendor_id = v.id 
        WHERE w.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $watak_id);
$stmt->execute();
$watak = $stmt->get_result()->fetch_assoc();

if (!$watak) {
    echo "<div class='alert alert-danger'>Watak not found</div>";
    echo "<a href='index.php' class='btn btn-primary mt-3'>Back to Watak</a>";
    exit;
}

// Note: Date restriction removed - wataks from any date can now be edited
// Only deletion is restricted to today's wataks

// Get watak items
$sql = "SELECT * FROM watak_items WHERE watak_id = ? ORDER BY id";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $watak_id);
$stmt->execute();
$watak_items_result = $stmt->get_result();
$watak_items = [];

while ($row = $watak_items_result->fetch_assoc()) {
    $watak_items[] = $row;
}

// Handle form submission for updating watak
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_watak'])) {
    try {
        $conn->begin_transaction();

        // Get the original watak amount to update vendor balance correctly
        $original_net_payable = $watak['net_payable'];

        // Update watak header
        $sql = "UPDATE vendor_watak SET 
                date = ?, 
                vehicle_no = ?, 
                vehicle_charges = ?, 
                bardan = ?, 
                other_charges = ?
                WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        $date = $_POST['date'];
        $vehicle_no = $_POST['vehicle_no'];
        $vehicle_charges = floatval($_POST['vehicle_charges']);
        $bardan = floatval($_POST['bardan']);
        $other_charges = floatval($_POST['other_charges']);

        $stmt->bind_param('ssdddi', 
            $date, $vehicle_no, $vehicle_charges, $bardan, $other_charges, $watak_id
        );
        $stmt->execute();

        // Delete existing watak items
        $sql = "DELETE FROM watak_items WHERE watak_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $watak_id);
        $stmt->execute();

        // Insert updated watak items
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
        
        // Calculate commission percentage and labor rate
        $commission_percent = floatval($_POST['commission_percent'] ?? ($watak['vendor_type'] === 'Local' ? 10 : 6));
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

        // Apply rounding logic (same as watak creation)
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
        
        // Update watak totals
        $sql = "UPDATE vendor_watak SET 
                total_amount = ?,
                total_commission = ?,
                net_payable = ?
                WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('dddi', $goods_sale_proceeds, $total_commission, $net_payable, $watak_id);
        $stmt->execute();

        // Update vendor balance (remove old amount and add new amount)
        $balance_change = $net_payable - $original_net_payable;
        $update_vendor_sql = "UPDATE vendors SET balance = balance + ? WHERE id = ?";
        $update_vendor_stmt = $conn->prepare($update_vendor_sql);
        $update_vendor_stmt->bind_param('di', $balance_change, $watak['vendor_id']);
        $update_vendor_stmt->execute();

        $conn->commit();
        
        // Show success message and redirect
        $_SESSION['success_message'] = "Watak updated successfully! Watak ID: " . $watak_id . ", Date: " . $date . ". Vendor balance updated with ₹" . number_format($balance_change, 2);
        header("Location: index.php?filter_date=" . $date);
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error updating watak: " . $e->getMessage();
        error_log("Watak update error: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
    }
}
?>

<div class="main-content">
    <div class="container-fluid mt-4">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Edit Watak</h3>
                <button type="button" class="close" aria-label="Close" onclick="window.location.href='index.php?filter_date=<?php echo $watak_date; ?>'">
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
                    <input type="hidden" name="watak_id" value="<?php echo $watak_id; ?>">
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label>Vendor</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($watak['vendor_name']); ?>" readonly>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label>Watak Number</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($watak['watak_number']); ?>" readonly>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label>Date <span class="text-danger">*</span></label>
                            <input type="date" name="date" class="form-control" value="<?php echo $watak['date']; ?>" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label>Vehicle No.</label>
                            <input type="text" name="vehicle_no" class="form-control" 
                                   value="<?php echo htmlspecialchars($watak['vehicle_no']); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label>Vehicle Charges</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text">₹</span>
                                </div>
                                <input type="number" name="vehicle_charges" id="vehicleCharges" class="form-control" 
                                       value="<?php echo $watak['vehicle_charges']; ?>" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label>Bardan</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text">₹</span>
                                </div>
                                <input type="number" name="bardan" id="bardan" class="form-control" 
                                       value="<?php echo $watak['bardan']; ?>" step="0.01">
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 mb-3" id="watak_items_container">
                        <?php if (empty($watak_items)): ?>
                        <div id="empty_watak_row" class="text-center text-muted py-4 border rounded">
                            Click "Add Item" to add items to the watak
                        </div>
                        <?php else: ?>
                            <?php foreach ($watak_items as $index => $item): ?>
                            <div class="watak-item-card border rounded p-3 mb-3 position-relative" data-index="<?php echo $index; ?>">
                                <button type="button" class="btn btn-sm btn-danger delete-watak-item position-absolute top-0 end-0 m-2">
                                    <i class="fas fa-times"></i>
                                </button>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Item Name <span class="text-danger">*</span></label>
                                        <input type="text" name="items[<?php echo $index; ?>][name]" class="form-control" 
                                               value="<?php echo htmlspecialchars($item['item_name']); ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Quantity <span class="text-danger">*</span></label>
                                        <input type="number" name="items[<?php echo $index; ?>][quantity]" class="form-control quantity" 
                                               value="<?php echo $item['quantity']; ?>" step="0.01" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Weight (Optional)</label>
                                        <input type="number" name="items[<?php echo $index; ?>][weight]" class="form-control weight" 
                                               value="<?php echo $item['weight']; ?>" step="0.01">
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <label class="form-label">Rate <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text">₹</span>
                                            <input type="text" name="items[<?php echo $index; ?>][rate]" class="form-control rate" 
                                                   value="<?php echo $item['rate']; ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Total Amount</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₹</span>
                                            <input type="number" class="form-control amount" 
                                                   value="<?php echo $item['amount']; ?>" readonly>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
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
                                       value="<?php echo $watak['vendor_type'] === 'Local' ? 10 : 6; ?>" step="0.01" min="0" max="100">
                                <div class="input-group-append">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <small class="form-text text-muted">
                                <?php echo $watak['vendor_type'] === 'Local' ? 'Local vendor (10%)' : 'Outsider vendor (6%)'; ?>
                            </small>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label>Labor Rate</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text">₹</span>
                                </div>
                                <input type="number" name="labor_rate" id="laborRate" class="form-control" 
                                       value="<?php echo $watak['vendor_type'] === 'Local' ? 1 : 2; ?>" step="0.01" min="0">
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label>Other Charges</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text">₹</span>
                                </div>
                                <input type="number" name="other_charges" id="otherCharges" class="form-control" 
                                       value="<?php echo $watak['other_charges']; ?>" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
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

                    <div class="row mt-4">
                        <div class="col-md-12 text-right">
                            <button type="button" class="btn btn-secondary" onclick="window.location.href='index.php?filter_date=<?php echo $watak_date; ?>'">
                                Cancel
                            </button>
                            <button type="submit" name="update_watak" class="btn btn-primary">
                                Update Watak
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Vendor type for rate input step control
const vendorType = '<?php echo $watak['vendor_type']; ?>';

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
    
    // Force calculation of row amount
    function calculateRowAmount(card) {
        const quantity = parseFloat(card.find('.quantity').val()) || 0;
        const weight = parseFloat(card.find('.weight').val()) || 0;
        const rate = parseFloat(card.find('.rate').val()) || 0;
        
        // Calculate amount based on weight vs quantity logic
        let amount = 0;
        if (weight > 0) {
            // If weight is provided, use weight × rate
            amount = weight * rate;
        } else if (quantity > 0) {
            // If weight is not provided but quantity is, use quantity × rate
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
                // If weight is provided, use weight × rate
                amount = weight * rate;
            } else if (quantity > 0) {
                // If weight is not provided but quantity is, use quantity × rate
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
        const commissionPercent = parseFloat($('#commissionPercent').val()) || (<?php echo json_encode($watak['vendor_type']); ?> === 'Local' ? 10 : 6);
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
    });
    
    let subTotal = 0;
    $('.watak-item-card').each(function() {
        subTotal += parseFloat($(this).find('.amount').val()) || 0;
    });
    
    $('#subTotal').text(subTotal.toFixed(2));
    $('#grandTotal').text(subTotal.toFixed(2));
    
    // Calculate other totals
    const commissionPercent = parseFloat($('#commissionPercent').val()) || 10;
    const totalCommission = (subTotal * commissionPercent) / 100;
    const laborRate = parseFloat($('#laborRate').val()) || 1;
    let totalLabor = 0;
    
    $('.watak-item-card').each(function() {
        const quantity = parseFloat($(this).find('.quantity').val()) || 0;
        const itemName = $(this).find('input[name^="items"][name$="[name]"]').val() || '';
        if (itemName.toLowerCase() !== 'krade') {
            totalLabor += quantity * laborRate;
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

<?php require_once __DIR__ . '/../layout/footer.php'; ?> 

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