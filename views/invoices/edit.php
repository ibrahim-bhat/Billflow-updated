<?php
// Start output buffering to prevent headers already sent error
ob_start();

require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Environment detection for database connection

require_once __DIR__ . '/../layout/header.php';
require_once __DIR__ . '/../../core/helpers/numbering_helper.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Get invoice ID from URL
$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$invoice_id) {
    header('Location: index.php');
    exit();
}

// Get invoice details
$sql = "SELECT ci.*, c.name as customer_name, c.id as customer_id 
        FROM customer_invoices ci 
        JOIN customers c ON ci.customer_id = c.id 
        WHERE ci.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $invoice_id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();

if (!$invoice) {
    header('Location: index.php');
    exit();
}

// Date restriction removed - invoices can now be edited from any date
// This allows editing invoices from yesterday, today, or any other date

// Get invoice items
$sql = "SELECT cii.*, i.name as item_name, v.name as vendor_name 
        FROM customer_invoice_items cii 
        JOIN items i ON cii.item_id = i.id 
        JOIN vendors v ON cii.vendor_id = v.id 
        WHERE cii.invoice_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $invoice_id);
$stmt->execute();
$invoice_items = $stmt->get_result();

// Check if we got any results
if ($invoice_items->num_rows == 0) {
    // No items found - this shouldn't happen for a valid invoice
}

// Check inventory availability for informational purposes only (non-blocking)
$unavailable_items = [];
$available_inventory_items = [];

// Reset the result pointer to check each item
mysqli_data_seek($invoice_items, 0);

while ($item = $invoice_items->fetch_assoc()) {
    // Check if the inventory item still exists (deleted or not)
    $check_sql = "SELECT ii.id, i.name as item_name 
                  FROM inventory_items ii 
                  JOIN items i ON ii.item_id = i.id 
                  WHERE ii.id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param('i', $item['inventory_item_id']);
    $check_stmt->execute();
    $inventory_item = $check_stmt->get_result()->fetch_assoc();
    
    if (!$inventory_item) {
        // Inventory item doesn't exist anymore - note for informational purposes
        $unavailable_items[] = $item['item_name'] . " (inventory item deleted)";
    } else {
        // Track available inventory items for conditional updates
        $available_inventory_items[$item['inventory_item_id']] = true;
    }
}

// Show informational message about unavailable inventory items (but don't block editing)
if (!empty($unavailable_items)) {
    $_SESSION['info_message'] = "Note: Inventory will not be updated for the following items (deleted from inventory): " . implode(", ", $unavailable_items);
}

// Reset the result pointer for form display
mysqli_data_seek($invoice_items, 0);

// Process Update Invoice form
if (isset($_POST['update_invoice'])) {
    $customer_id = sanitizeInput($_POST['customer_id']);
    
    // Dual-date system (system date vs display date)
// - System date: stored in customer_invoices.date (always today's date for transaction/day grouping)
// - Display date: optional user-provided date shown on the invoice (stored in customer_invoices.display_date)
$use_custom_date = isset($_POST['use_custom_date']) && $_POST['use_custom_date'] == 'on';
$display_date = ($use_custom_date && !empty($_POST['date']))
    ? sanitizeInput($_POST['date'])
    : date('Y-m-d');
$date = date('Y-m-d'); // Always store today's date as the system/transaction date
    
    $total_amount = sanitizeInput($_POST['total_amount']);
    
    // Get item details from form
    $item_ids = $_POST['item_id'] ?? [];
    $vendor_ids = $_POST['vendor_id'] ?? [];
    $inventory_item_ids = $_POST['inventory_item_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $weights = $_POST['weight'] ?? [];
    $rates = $_POST['rate'] ?? [];
    
    if (!empty($customer_id) && !empty($item_ids) && count($item_ids) > 0) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // First, get the original inventory items to calculate available stock
            $original_items_sql = "SELECT * FROM customer_invoice_items WHERE invoice_id = ?";
            $original_stmt = $conn->prepare($original_items_sql);
            $original_stmt->bind_param('i', $invoice_id);
            $original_stmt->execute();
            $original_items = $original_stmt->get_result();
            
            // Create a map of original quantities for each inventory item
            $original_quantities = [];
            while ($item = $original_items->fetch_assoc()) {
                $inventory_item_id = $item['inventory_item_id'];
                if (isset($original_quantities[$inventory_item_id])) {
                    $original_quantities[$inventory_item_id] += $item['quantity'];
                } else {
                    $original_quantities[$inventory_item_id] = $item['quantity'];
                }
            }
            
            // For editing invoices, we don't need to pre-validate stock since we're restoring original quantities first
            // The stock validation will happen after we restore the original inventory
            
            // Now restore the original inventory (only for items that still exist)
            $inventory_restored_items = [];
            foreach ($original_quantities as $inventory_item_id => $quantity) {
                // Check if inventory item still exists before restoring
                $exists_sql = "SELECT id FROM inventory_items WHERE id = ?";
                $exists_stmt = $conn->prepare($exists_sql);
                $exists_stmt->bind_param('i', $inventory_item_id);
                $exists_stmt->execute();
                $exists_result = $exists_stmt->get_result();
                
                if ($exists_result->num_rows > 0) {
                    // Inventory item exists, restore it
                    $restore_sql = "UPDATE inventory_items SET remaining_stock = remaining_stock + ? WHERE id = ?";
                    $restore_stmt = $conn->prepare($restore_sql);
                    $restore_stmt->bind_param('di', $quantity, $inventory_item_id);
                    $restore_stmt->execute();
                    $inventory_restored_items[$inventory_item_id] = true;
                }
            }
            
            // Validate stock availability for new quantities after restoring original stock (only for items with inventory)
            $insufficient_stock_items = [];
            for ($i = 0; $i < count($item_ids); $i++) {
                if (!empty($item_ids[$i]) && !empty($vendor_ids[$i]) && !empty($inventory_item_ids[$i])) {
                    $inventory_item_id = $inventory_item_ids[$i];
                    $qty = $quantities[$i];
                    
                    // Only check stock if inventory item exists
                    $stock_check_sql = "SELECT remaining_stock, i.name as item_name 
                                       FROM inventory_items ii 
                                       JOIN items i ON ii.item_id = i.id 
                                       WHERE ii.id = ?";
                    $stock_check_stmt = $conn->prepare($stock_check_sql);
                    $stock_check_stmt->bind_param('i', $inventory_item_id);
                    $stock_check_stmt->execute();
                    $stock_result = $stock_check_stmt->get_result();
                    $stock_info = $stock_result->fetch_assoc();
                    
                    // Only validate stock if inventory item exists
                    if ($stock_info && $stock_info['remaining_stock'] < $qty) {
                        $insufficient_stock_items[] = $stock_info['item_name'] . " (Available: " . $stock_info['remaining_stock'] . ", Required: " . $qty . ")";
                    }
                    // If inventory item doesn't exist, we skip stock validation (will update invoice only)
                }
            }
            
            // If insufficient stock, rollback and show error (only for items with available inventory)
            if (!empty($insufficient_stock_items)) {
                throw new Exception("Insufficient stock for the following items: " . implode(", ", $insufficient_stock_items));
            }
            
            // Restore customer balance
            $restore_balance_sql = "UPDATE customers SET balance = balance - ? WHERE id = ?";
            $restore_balance_stmt = $conn->prepare($restore_balance_sql);
            $restore_balance_stmt->bind_param('di', $invoice['total_amount'], $invoice['customer_id']);
            $restore_balance_stmt->execute();
            
            // Update invoice header
            $update_sql = "UPDATE customer_invoices SET customer_id = ?, date = ?, display_date = ?, total_amount = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("issdi", $customer_id, $date, $display_date, $total_amount, $invoice_id);
            $update_stmt->execute();
            
            // Delete existing invoice items
            $delete_sql = "DELETE FROM customer_invoice_items WHERE invoice_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param('i', $invoice_id);
            $delete_stmt->execute();
            
            // Insert new invoice items
            $item_sql = "INSERT INTO customer_invoice_items (invoice_id, item_id, vendor_id, inventory_item_id, quantity, weight, rate, amount) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $item_stmt = $conn->prepare($item_sql);
            
            for ($i = 0; $i < count($item_ids); $i++) {
                if (!empty($item_ids[$i]) && !empty($vendor_ids[$i]) && !empty($inventory_item_ids[$i])) {
                    $vendor_id = $vendor_ids[$i];
                    $inventory_item_id = $inventory_item_ids[$i];
                    $qty = $quantities[$i];
                    $weight = $weights[$i] ?: 0;
                    $rate = $rates[$i];
                    
                    // Calculation logic
                    if ($weight > 0) {
                        $amount = $weight * $rate;
                    } else if ($qty > 0) {
                        $amount = $qty * $rate;
                    } else {
                        $amount = 0;
                    }
                    
                    $item_stmt->bind_param("iiiidddd", $invoice_id, $item_ids[$i], $vendor_id, $inventory_item_id, $qty, $weight, $rate, $amount);
                    $item_stmt->execute();
                    
                    // Update inventory only if the inventory item exists
                    $check_inventory_sql = "SELECT id FROM inventory_items WHERE id = ?";
                    $check_inventory_stmt = $conn->prepare($check_inventory_sql);
                    $check_inventory_stmt->bind_param("i", $inventory_item_id);
                    $check_inventory_stmt->execute();
                    $inventory_exists = $check_inventory_stmt->get_result();
                    
                    if ($inventory_exists->num_rows > 0) {
                        // Inventory item exists, update it
                        $update_inventory_sql = "UPDATE inventory_items 
                                               SET remaining_stock = remaining_stock - ? 
                                               WHERE id = ?";
                        $update_inventory_stmt = $conn->prepare($update_inventory_sql);
                        $update_inventory_stmt->bind_param("di", $qty, $inventory_item_id);
                        $update_inventory_stmt->execute();
                    }
                    // If inventory doesn't exist, we skip inventory update but continue with invoice update
                }
            }
            
            // Update customer balance
            $update_balance_sql = "UPDATE customers SET balance = balance + ? WHERE id = ?";
            $update_balance_stmt = $conn->prepare($update_balance_sql);
            $update_balance_stmt->bind_param("di", $total_amount, $customer_id);
            $update_balance_stmt->execute();
            
            // Commit transaction
            $conn->commit();
            $_SESSION['success_message'] = "Invoice updated successfully! Invoice #" . $invoice['invoice_number'];
            header('Location: index.php');
            exit();
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error_message = "Error updating invoice: " . $e->getMessage();
        }
    } else {
        $error_message = "All required fields must be filled!";
    }
}

// Get all customers for dropdown
$customers_sql = "SELECT * FROM customers ORDER BY name";
$customers_result = $conn->query($customers_sql);
$customers = [];
while ($row = $customers_result->fetch_assoc()) {
    $customers[] = $row;
}

// Get vendors with stock for dropdown (filtered)
$vendors_sql = "SELECT
                v.id,
                v.name,
                COALESCE(COUNT(DISTINCT ii.item_id), 0) as total_items,
                COALESCE(SUM(ii.remaining_stock), 0) as total_stock
                FROM vendors v
                INNER JOIN inventory inv ON v.id = inv.vendor_id
                INNER JOIN inventory_items ii ON inv.id = ii.inventory_id
                WHERE ii.remaining_stock > 0
                GROUP BY v.id
                HAVING total_stock > 0
                ORDER BY v.name";
$vendors_result = $conn->query($vendors_sql);
$vendors = [];
while ($row = $vendors_result->fetch_assoc()) {
    $vendors[] = $row;
}

// Get the original vendor IDs from the invoice items to ensure they're included
$original_vendor_ids = [];
$invoice_items_temp = $invoice_items;
mysqli_data_seek($invoice_items_temp, 0);
while ($item = $invoice_items_temp->fetch_assoc()) {
    $original_vendor_ids[] = $item['vendor_id'];
}
$original_vendor_ids = array_unique($original_vendor_ids);

// Add original vendors if they're not already in the list
if (!empty($original_vendor_ids)) {
    $placeholders = str_repeat('?,', count($original_vendor_ids) - 1) . '?';
    $original_vendors_sql = "SELECT
                            v.id,
                            v.name,
                            COALESCE(COUNT(DISTINCT ii.item_id), 0) as total_items,
                            COALESCE(SUM(ii.remaining_stock), 0) as total_stock
                            FROM vendors v
                            LEFT JOIN inventory inv ON v.id = inv.vendor_id
                            LEFT JOIN inventory_items ii ON inv.id = ii.inventory_id
                            WHERE v.id IN ($placeholders)
                            GROUP BY v.id
                            ORDER BY v.name";
    $stmt = $conn->prepare($original_vendors_sql);
    $stmt->bind_param(str_repeat('i', count($original_vendor_ids)), ...$original_vendor_ids);
    $stmt->execute();
    $original_vendors_result = $stmt->get_result();
    
    while ($row = $original_vendors_result->fetch_assoc()) {
        // Check if this vendor is already in the list
        $exists = false;
        foreach ($vendors as $existing_vendor) {
            if ($existing_vendor['id'] == $row['id']) {
                $exists = true;
                break;
            }
        }
        
        // If not in the list, add it (even with zero stock)
        if (!$exists) {
            $vendors[] = $row;
        }
    }
}

// Reset the invoice_items result pointer for later use
mysqli_data_seek($invoice_items, 0);
?>

<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1>Edit Invoice #<?php echo $invoice['invoice_number']; ?></h1>
            <p>Edit invoice details and items</p>
        </div>
        <a href="index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Invoices
        </a>
    </div>

    <!-- Display Messages -->
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['info_message'])): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <?php echo $_SESSION['info_message']; unset($_SESSION['info_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="post" action="edit.php?id=<?php echo $invoice_id; ?>" id="editInvoiceForm">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="customer_id" class="form-label">Customer <span class="text-danger">*</span></label>
                        <select class="form-select" id="customer_id" name="customer_id" required>
                            <option value="">Select Customer</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>" 
                                        <?php echo $customer['id'] == $invoice['customer_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($customer['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="use_custom_date" name="use_custom_date">
                            <label class="form-check-label" for="use_custom_date">
                                Use Custom Date
                            </label>
                        </div>
                        <label for="invoice_date" class="form-label">Date</label>
                        <input type="date" class="form-control" id="invoice_date" name="date" 
                               value="<?php echo $invoice['display_date'] ?? $invoice['date']; ?>" disabled>
                    </div>
                </div>

                <div class="table-responsive mb-3">
                    <table class="table table-bordered" id="invoice_items_table">
                        <thead>
                            <tr>
                                <th>Item Name</th>
                                <th>Vendor</th>
                                <th>Qty</th>
                                <th>Weight</th>
                                <th>Rate</th>
                                <th>Amount</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $item_count = 0;
                            while ($item = $invoice_items->fetch_assoc()): 
                                $item_count++;
                                // Debug: Log the item data
                                error_log("DEBUG: Item $item_count - ID: {$item['item_id']}, Name: {$item['item_name']}, Vendor: {$item['vendor_name']}");
                            ?>
                            <tr class="item-row">
                                <td>
                                    <select name="item_id[]" class="form-select item-select" required>
                                        <option value="<?php echo $item['item_id']; ?>" selected
                                                data-inventory-item-id="<?php echo $item['inventory_item_id']; ?>"
                                                data-rate="<?php echo $item['rate']; ?>"
                                                data-stock="0">
                                            <?php echo htmlspecialchars($item['item_name']); ?>
                                        </option>
                                        <!-- Additional items will be loaded via AJAX when vendor changes -->
                                    </select>
                                    <input type="hidden" name="inventory_item_id[]" class="inventory-item-id" 
                                           value="<?php echo $item['inventory_item_id']; ?>">
                                    <input type="hidden" class="original-item-id" value="<?php echo $item['item_id']; ?>">
                                </td>
                                <td>
                                    <select name="vendor_id[]" class="form-select vendor-select" required>
                                        <option value="">Select Vendor</option>
                                        <?php foreach ($vendors as $vendor): ?>
                                            <option value="<?php echo $vendor['id']; ?>" 
                                                    <?php echo $vendor['id'] == $item['vendor_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($vendor['name']); ?> (<?php echo $vendor['total_items']; ?> items, <?php echo $vendor['total_stock']; ?> stock)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" name="quantity[]" class="form-control quantity" 
                                           value="<?php echo $item['quantity']; ?>" step="0.01" min="0.01" required>
                                </td>
                                <td>
                                    <input type="number" name="weight[]" class="form-control weight" 
                                           value="<?php echo $item['weight']; ?>" step="0.01" min="0">
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">₹</span>
                                        <input type="number" name="rate[]" class="form-control rate" 
                                               value="<?php echo $item['rate']; ?>" step="0.01" min="0.01" required>
                                    </div>
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">₹</span>
                                        <input type="number" name="amount[]" class="form-control amount" 
                                               value="<?php echo $item['amount']; ?>" step="0.01" readonly>
                                    </div>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-danger remove-row">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5" class="text-end"><strong>Subtotal:</strong></td>
                                <td><span id="invoice_subtotal">₹<?php echo number_format($invoice['total_amount'], 2); ?></span></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td colspan="5" class="text-end"><strong>Total Amount:</strong></td>
                                <td><span id="invoice_total">₹<?php echo number_format($invoice['total_amount'], 2); ?></span></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <button type="button" id="add_item_button" class="btn btn-sm btn-outline-secondary mb-3">
                    <i class="fas fa-plus"></i> Add Item
                </button>
                
                <input type="hidden" name="total_amount" id="total_amount" value="<?php echo $invoice['total_amount']; ?>">
                
                <div class="d-flex gap-2">
                    <button type="submit" name="update_invoice" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Invoice
                    </button>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Function to show user-friendly alerts
function showAlert(message, type = 'info') {
    // Create alert element
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    // Add to page
    document.body.appendChild(alertDiv);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.remove();
        }
    }, 5000);
}

// Function to load vendors with inventory
function loadVendorsWithInventory(vendorSelect) {
    vendorSelect.innerHTML = '<option value="">Loading vendors...</option>';
    
    fetch('../../api/vendors/get_with_stock.php')
        .then(response => response.json())
        .then(data => {
            let optionsHtml = '<option value="">Select Vendor</option>';
            
            if (data.vendors && data.vendors.length > 0) {
                data.vendors.forEach(vendor => {
                    optionsHtml += `<option value="${vendor.id}">${vendor.name} (${vendor.total_items} items, ${vendor.total_stock} stock)</option>`;
                });
                vendorSelect.innerHTML = optionsHtml;
            } else {
                vendorSelect.innerHTML = '<option value="">No vendors with inventory found</option>';
            }
        })
        .catch(error => {
            console.error('Error loading vendors:', error);
            vendorSelect.innerHTML = '<option value="">Error loading vendors</option>';
        });
}

// Function to load vendor items via AJAX
function loadVendorItems(vendorId, itemSelect, selectedItemId = null, preserveOriginalItem = false) {
    if (!vendorId) {
        itemSelect.innerHTML = '<option value="">Select Item</option>';
        return Promise.resolve();
    }

    // Store original item information if we need to preserve it
    let originalItemOption = null;
    if (preserveOriginalItem && itemSelect.options.length > 0) {
        // Find the currently selected option (original item)
        for (let i = 0; i < itemSelect.options.length; i++) {
            if (itemSelect.options[i].selected && itemSelect.options[i].value) {
                originalItemOption = {
                    value: itemSelect.options[i].value,
                    text: itemSelect.options[i].textContent,
                    inventoryItemId: itemSelect.options[i].getAttribute('data-inventory-item-id') || '',
                    stock: itemSelect.options[i].getAttribute('data-stock') || '0',
                    rate: itemSelect.options[i].getAttribute('data-rate') || ''
                };
                break;
            }
        }
    }

    itemSelect.innerHTML = '<option value="">Loading items...</option>';

    // Add edit_mode=true parameter to include items with zero stock
    return fetch('../../api/vendors/get_items.php?vendor_id=' + vendorId + '&edit_mode=true')
        .then(response => response.json())
        .then(data => {
            let optionsHtml = '<option value="">Select Item</option>';
            let foundSelected = false;

            let originalInventoryItemFound = false;
            if (data.items && data.items.length > 0) {
                data.items.forEach(item => {
                    const isSelected = selectedItemId && String(item.id) === String(selectedItemId);
                    if (isSelected) foundSelected = true;
                    
                    // Check if this is the same inventory item as the original (by inventory_item_id)
                    if (originalItemOption && String(item.inventory_item_id) === String(originalItemOption.inventoryItemId)) {
                        originalInventoryItemFound = true;
                        // If the original inventory item still exists, select it
                        optionsHtml += `<option value="${item.id}"
                            selected
                            data-stock="${item.available_stock}"
                            data-rate="${item.last_rate || ''}"
                            data-inventory-item-id="${item.inventory_item_id}"
                            data-date-received="${item.date_received}">
                            ${item.name} (Stock: ${item.available_stock})
                        </option>`;
                        foundSelected = true;
                    } else {
                        optionsHtml += `<option value="${item.id}"
                            ${isSelected ? 'selected' : ''}
                            data-stock="${item.available_stock}"
                            data-rate="${item.last_rate || ''}"
                            data-inventory-item-id="${item.inventory_item_id}"
                            data-date-received="${item.date_received}">
                            ${item.name} (Stock: ${item.available_stock})
                        </option>`;
                    }
                });
            }

            // Only show the original deleted item if its specific inventory item wasn't found
            // This prevents duplicates while preserving deleted inventory items
            if (originalItemOption && !originalInventoryItemFound && preserveOriginalItem) {
                optionsHtml += `<option value="${originalItemOption.value}"
                    selected
                    data-stock="${originalItemOption.stock}"
                    data-rate="${originalItemOption.rate}"
                    data-inventory-item-id="${originalItemOption.inventoryItemId}"
                    class="note-text-warning">
                    ${originalItemOption.text} (Inventory Deleted)
                </option>`;
                foundSelected = true;
            }

            // If no items available and no original item to preserve
            if (data.items.length === 0 && !originalItemOption) {
                optionsHtml = '<option value="">No items available</option>';
            }

            itemSelect.innerHTML = optionsHtml;
        })
        .catch(error => {
            console.error('Error loading items:', error);
            itemSelect.innerHTML = '<option value="">Error loading items</option>';
        });
}

// Initialize existing rows
document.addEventListener('DOMContentLoaded', function() {
    const existingRows = document.querySelectorAll('.item-row');
    
    existingRows.forEach((row, index) => {
        const vendorSelect = row.querySelector('.vendor-select');
        const itemSelect = row.querySelector('.item-select');
        const originalItemId = row.querySelector('.original-item-id').value;
        
        // For existing rows, load vendor items on page load but preserve original item even if inventory is deleted
        if (vendorSelect.value) {
            // Store the original inventory item ID
            const originalInventoryItemId = row.querySelector('.inventory-item-id').value;
            
            // Add a small delay to ensure DOM is ready, then load items but preserve original item
            setTimeout(() => {
                loadVendorItems(vendorSelect.value, itemSelect, originalItemId, true).then(() => {
                    // After loading items, find and select the option that matches the original inventory item ID
                    const options = itemSelect.options;
                    let foundOriginal = false;
                    
                    for (let i = 0; i < options.length; i++) {
                        const option = options[i];
                        const optionInventoryItemId = option.getAttribute('data-inventory-item-id');
                        
                        if (optionInventoryItemId === originalInventoryItemId) {
                            itemSelect.selectedIndex = i;
                            foundOriginal = true;
                            break;
                        }
                    }
                    
                    // The original item should now be preserved even if inventory is deleted
                    if (!foundOriginal) {
                        console.log('Original item preserved even though inventory may be deleted');
                    }
                });
            }, 100);
        }
        
        // Add vendor selection functionality for manual changes
        vendorSelect.addEventListener('change', function() {
            const selectedVendorId = this.value;
            if (selectedVendorId) {
                console.log("Vendor changed to: " + selectedVendorId);
                
                // When vendor changes, load that vendor's items but select the same item name if available
                loadVendorItems(selectedVendorId, itemSelect, originalItemId).then(() => {
                    // After loading items, select the first option with matching item ID
                    const options = itemSelect.options;
                    
                    // If we have options and the first one isn't the placeholder
                    if (options.length > 1) {
                        // Select the first item option (index 1, after the placeholder)
                        itemSelect.selectedIndex = 1;
                        
                        // Trigger the change event to update inventory item ID
                        const changeEvent = new Event('change');
                        itemSelect.dispatchEvent(changeEvent);
                        
                        console.log("Selected first item from new vendor: " + options[1].text);
                        console.log("New inventory item ID: " + options[1].getAttribute('data-inventory-item-id'));
                    }
                });
            } else {
                itemSelect.innerHTML = '<option value="">Select Item</option>';
                const inventoryItemIdField = row.querySelector('.inventory-item-id');
                if (inventoryItemIdField) inventoryItemIdField.value = '';
            }
        });
        
        // Add calculation functionality
        const qtyInput = row.querySelector('.quantity');
        const weightInput = row.querySelector('.weight');
        const rateInput = row.querySelector('.rate');
        const amountInput = row.querySelector('.amount');
        
        function updateRowAmount() {
            const qty = parseFloat(qtyInput.value) || 0;
            const weight = parseFloat(weightInput.value) || 0;
            const rate = parseFloat(rateInput.value) || 0;
            
            let amount = 0;
            
            if (weight > 0) {
                amount = weight * rate;
            } else if (qty > 0) {
                amount = qty * rate;
            }
            
            amountInput.value = amount.toFixed(2);
            updateTotals();
        }
        
        qtyInput.addEventListener('input', function() {
            const selectedOption = itemSelect.options[itemSelect.selectedIndex];
            if (selectedOption.value) {
                const availableStock = parseFloat(selectedOption.getAttribute('data-stock'));
                const enteredQty = parseFloat(this.value) || 0;
                
                // For existing rows, show warning but don't prevent editing
                if (enteredQty > availableStock) {
                    // Don't reset the value, just show a visual indicator
                    this.style.borderColor = '#dc3545';
                    this.title = `Warning: Available stock is ${availableStock}`;
                } else {
                    this.style.borderColor = '';
                    this.title = '';
                }
            }
            updateRowAmount();
        });
        weightInput.addEventListener('input', updateRowAmount);
        rateInput.addEventListener('input', updateRowAmount);
        
        // Item selection handler
        itemSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const inventoryItemIdField = row.querySelector('.inventory-item-id');
            
            if (selectedOption.value) {
                const availableStock = parseFloat(selectedOption.getAttribute('data-stock'));
                const lastRate = selectedOption.getAttribute('data-rate');
                const inventoryItemId = selectedOption.getAttribute('data-inventory-item-id');
                
                // Check if this is a different item than the original (changing item)
                const originalItemId = row.querySelector('.original-item-id').value;
                const isChangingItem = selectedOption.value !== originalItemId;
                
                // If changing to a different item and it's out of stock, show alert
                if (isChangingItem && availableStock <= 0) {
                    showAlert('This item is out of stock', 'warning');
                    // Reset to original item
                    loadVendorItems(vendorSelect.value, itemSelect, originalItemId);
                    return;
                }
                
                // Update the inventory item ID - this is critical for vendor switching
                if (inventoryItemId) {
                    inventoryItemIdField.value = inventoryItemId;
                    console.log("Updated inventory item ID to: " + inventoryItemId);
                } else {
                    console.warn("No inventory item ID found in selected option");
                }
                
                // For editing, don't restrict quantity to current stock
                // The original quantity might be higher than current available stock
                qtyInput.placeholder = `Available: ${availableStock}`;
                
                if (lastRate) {
                    rateInput.value = lastRate;
                }
                
                updateRowAmount();
            } else {
                inventoryItemIdField.value = '';
                rateInput.value = '';
                updateTotals();
            }
        });
        
        // Remove row handler
        const removeBtn = row.querySelector('.remove-row');
        removeBtn.addEventListener('click', function() {
            row.remove();
            updateTotals();
        });
    });
});

// Add item row functionality
document.getElementById('add_item_button').addEventListener('click', function() {
    const tbody = document.querySelector('#invoice_items_table tbody');
    
    const newRow = document.createElement('tr');
    newRow.classList.add('item-row');
    newRow.innerHTML = `
        <td>
            <select name="item_id[]" class="form-select item-select" required>
                <option value="">Select Item</option>
            </select>
            <input type="hidden" name="inventory_item_id[]" class="inventory-item-id">
        </td>
        <td>
            <select name="vendor_id[]" class="form-select vendor-select" required>
                <option value="">Select Vendor</option>
            </select>
        </td>
        <td>
            <input type="number" name="quantity[]" class="form-control quantity" value="" step="0.01" min="0.01" required>
        </td>
        <td>
            <input type="number" name="weight[]" class="form-control weight" value="" step="0.01" min="0">
        </td>
        <td>
            <div class="input-group input-group-sm">
                <span class="input-group-text">₹</span>
                <input type="number" name="rate[]" class="form-control rate" value="" step="0.01" min="0.01" required>
            </div>
        </td>
        <td>
            <div class="input-group input-group-sm">
                <span class="input-group-text">₹</span>
                <input type="number" name="amount[]" class="form-control amount" value="0" step="0.01" readonly>
            </div>
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-danger remove-row">
                <i class="fas fa-trash"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(newRow);
    
    const vendorSelect = newRow.querySelector('.vendor-select');
    const itemSelect = newRow.querySelector('.item-select');
    
    loadVendorsWithInventory(vendorSelect);
    
    vendorSelect.addEventListener('change', function() {
        const selectedVendorId = this.value;
        if (selectedVendorId) {
            loadVendorItems(selectedVendorId, itemSelect);
        } else {
            itemSelect.innerHTML = '<option value="">Select Item</option>';
        }
    });
    
    const qtyInput = newRow.querySelector('.quantity');
    const weightInput = newRow.querySelector('.weight');
    const rateInput = newRow.querySelector('.rate');
    const amountInput = newRow.querySelector('.amount');
    const removeBtn = newRow.querySelector('.remove-row');
    
    function updateRowAmount() {
        const qty = parseFloat(qtyInput.value) || 0;
        const weight = parseFloat(weightInput.value) || 0;
        const rate = parseFloat(rateInput.value) || 0;
        
        let amount = 0;
        
        if (weight > 0) {
            amount = weight * rate;
        } else if (qty > 0) {
            amount = qty * rate;
        }
        
        amountInput.value = amount.toFixed(2);
        updateTotals();
    }
    
    qtyInput.addEventListener('input', updateRowAmount);
    weightInput.addEventListener('input', updateRowAmount);
    rateInput.addEventListener('input', updateRowAmount);
    
    itemSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const inventoryItemIdField = newRow.querySelector('.inventory-item-id');
        
        if (selectedOption.value) {
            const availableStock = parseFloat(selectedOption.getAttribute('data-stock'));
            const lastRate = selectedOption.getAttribute('data-rate');
            const inventoryItemId = selectedOption.getAttribute('data-inventory-item-id');
            
            inventoryItemIdField.value = inventoryItemId;
            
            // Check if item is out of stock for newly added items
            if (availableStock <= 0) {
                showAlert('This item is out of stock', 'warning');
                // Reset the selection
                this.value = '';
                inventoryItemIdField.value = '';
                rateInput.value = '';
                qtyInput.value = '';
                weightInput.value = '';
                amountInput.value = '';
                updateTotals();
                return;
            }
            
            qtyInput.max = availableStock;
            qtyInput.placeholder = `Max: ${availableStock}`;
            
            if (lastRate) {
                rateInput.value = lastRate;
            }
            
            if (!qtyInput.value) qtyInput.value = "1";
            
            updateRowAmount();
            qtyInput.focus();
        } else {
            inventoryItemIdField.value = '';
            rateInput.value = '';
            qtyInput.value = '';
            weightInput.value = '';
            amountInput.value = '';
            updateTotals();
        }
    });
    
    qtyInput.addEventListener('input', function() {
        const selectedOption = itemSelect.options[itemSelect.selectedIndex];
        if (selectedOption.value) {
            const availableStock = parseFloat(selectedOption.getAttribute('data-stock'));
            const enteredQty = parseFloat(this.value) || 0;
            
            if (enteredQty > availableStock) {
                showAlert(`Maximum available stock is ${availableStock}`, 'warning');
                this.value = availableStock;
            }
        }
        updateRowAmount();
    });
    
    removeBtn.addEventListener('click', function() {
        newRow.remove();
        updateTotals();
    });
});

// Function to update totals
function updateTotals() {
    let subtotal = 0;
    const amounts = document.querySelectorAll('#invoice_items_table .amount');
    
    amounts.forEach(input => {
        subtotal += parseFloat(input.value) || 0;
    });
    
    document.getElementById('invoice_subtotal').innerText = '₹' + subtotal.toFixed(2);
    document.getElementById('invoice_total').innerText = '₹' + subtotal.toFixed(2);
    document.getElementById('total_amount').value = subtotal.toFixed(2);
}

// Form validation
document.getElementById('editInvoiceForm').addEventListener('submit', function(event) {
    const items = document.querySelectorAll('#invoice_items_table tbody tr');
    if (items.length === 0) {
        event.preventDefault();
        alert('Please add at least one item to the invoice.');
        return false;
    }
    
    const total = parseFloat(document.getElementById('total_amount').value) || 0;
    if (total <= 0) {
        event.preventDefault();
        alert('Total amount must be greater than zero.');
        return false;
    }
    
    let isValid = true;
    items.forEach(row => {
        const itemSelect = row.querySelector('.item-select');
        const vendorSelect = row.querySelector('.vendor-select');
        
        if (!itemSelect.value || !vendorSelect.value) {
            isValid = false;
            event.preventDefault();
            alert('Please select both a vendor and an item for each row.');
            return false;
        }
    });
    
    return isValid;
});

// Handle custom date checkbox functionality
document.getElementById('use_custom_date').addEventListener('change', function() {
    const dateInput = document.getElementById('invoice_date');
    if (this.checked) {
        dateInput.disabled = false;
        dateInput.required = true;
    } else {
        dateInput.disabled = true;
        dateInput.required = false;
        dateInput.value = '<?php echo date('Y-m-d'); ?>';
    }
});

// Initialize date field state
document.addEventListener('DOMContentLoaded', function() {
    const dateCheckbox = document.getElementById('use_custom_date');
    const dateInput = document.getElementById('invoice_date');
    
    // Check if display date exists and is different from today's date
    const displayDate = '<?php echo $invoice['display_date'] ?? date('Y-m-d'); ?>';
    const currentDate = '<?php echo date('Y-m-d'); ?>';
    
    if (displayDate !== currentDate) {
        dateCheckbox.checked = true;
        dateInput.disabled = false;
        dateInput.required = true;
    }
    

});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>

<?php
// Flush the output buffer
ob_end_flush();
?> 