<?php
// Make sure there are no spaces, new lines or other characters before the opening <?php tag

require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/helpers/numbering_helper_vendor.php';

// Get vendor_id from GET or POST before processing the form
$vendor_id = isset($_GET['vendor_id']) ? intval($_GET['vendor_id']) : 0;
if (!$vendor_id && isset($_POST['vendor_id'])) {
    $vendor_id = intval($_POST['vendor_id']);
}

// Process form submission before any HTML output
if (isset($_POST['create_invoice'])) {
    $invoice_number = sanitizeInput($_POST['invoice_number']);
    $invoice_date = sanitizeInput($_POST['invoice_date']);
    
    if (!empty($invoice_number) && !empty($invoice_date) && $vendor_id > 0) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Check if this invoice number already exists globally (across all vendors) using helper function
            $check_sql = "SELECT COUNT(*) as count FROM vendor_invoices WHERE invoice_number = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("s", $invoice_number);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $check_row = $check_result->fetch_assoc();
            $check_stmt->close();
            
            if ($check_row['count'] > 0) {
                throw new Exception("Invoice number already exists. Please use a different number.");
            }
            
            // Insert invoice record - removed both notes and status fields
            $sql = "INSERT INTO vendor_invoices (vendor_id, invoice_number, invoice_date, total_amount) VALUES (?, ?, ?, 0)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iss", $vendor_id, $invoice_number, $invoice_date);
            $stmt->execute();
            $invoice_id = $conn->insert_id;
            $stmt->close();
            
            // Insert invoice items
            $total_amount = 0;
            foreach ($_POST['item_id'] as $key => $item_id) {
                $quantity = floatval($_POST['quantity'][$key]);
                $weight = floatval($_POST['weight'][$key]);
                $rate = floatval($_POST['rate'][$key]);
                
                // Updated calculation logic:
                // If weight is provided, use weight × rate
                // If only quantity is provided, use quantity × rate
                if ($weight > 0) {
                    $amount = $weight * $rate;
                } else {
                    $amount = $quantity * $rate;
                }
                
                $total_amount += $amount;
                
                $sql = "INSERT INTO vendor_invoice_items (invoice_id, item_id, quantity, weight, rate, amount) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iidddd", $invoice_id, $item_id, $quantity, $weight, $rate, $amount);
                $stmt->execute();
                $stmt->close();
            }
            
            // Update invoice total
            $sql = "UPDATE vendor_invoices SET total_amount = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("di", $total_amount, $invoice_id);
            $stmt->execute();
            $stmt->close();
            
            // Update vendor balance by adding the invoice total amount
            $sql = "UPDATE vendors SET balance = balance + ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("di", $total_amount, $vendor_id);
            $stmt->execute();
            $stmt->close();
            
            // Commit transaction
            $conn->commit();
            $_SESSION['success_message'] = "Purchase invoice created successfully!";
            
            // Redirect to avoid resubmission
            header('Location: index.php');
            exit();
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error_message = "Error creating invoice: " . $e->getMessage();
        }
    } else {
        $error_message = "Invoice number and date are required!";
    }
}

// Now include the header which will output HTML
require_once __DIR__ . '/../layout/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Get invoice data from GET parameters
$inventory_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$date = $inventory_date; // Use inventory date for the invoice_date field
$vendor_name = isset($_GET['vendor_name']) ? $_GET['vendor_name'] : '';

// If no vendor_id in URL, try to get from POST
if (!$vendor_id && isset($_POST['vendor_id'])) {
    $vendor_id = intval($_POST['vendor_id']);
}

// Get vendor details
$vendor = null;
if ($vendor_id) {
    $sql = "SELECT * FROM vendors WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $vendor = $result->fetch_assoc();
    $stmt->close();
}

// Auto-generate global invoice number using helper function
$next_number = getNextVendorInvoiceNumber($conn);
$next_invoice_number = formatVendorInvoiceNumber($next_number);

// Get inventory items for this vendor and date
$inventory_items = [];
if ($vendor_id) {
    // If inventory_date is provided, use that specific date
    // Otherwise, show all items from today's inventory
    if ($inventory_date) {
        $sql = "SELECT 
                ii.id as inventory_item_id,
                i.id as item_id,
                i.name as item_name,
                ii.quantity_received,
                ii.remaining_stock
                FROM inventory inv
                JOIN inventory_items ii ON inv.id = ii.inventory_id
                JOIN items i ON ii.item_id = i.id
                WHERE inv.vendor_id = ? AND DATE(inv.date_received) = ?
                ORDER BY i.name";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $vendor_id, $inventory_date);
    } else {
        // If no specific date, show today's inventory items
        $today = date('Y-m-d');
        $sql = "SELECT 
                ii.id as inventory_item_id,
                i.id as item_id,
                i.name as item_name,
                ii.quantity_received,
                ii.remaining_stock
                FROM inventory inv
                JOIN inventory_items ii ON inv.id = ii.inventory_id
                JOIN items i ON ii.item_id = i.id
                WHERE inv.vendor_id = ? AND DATE(inv.date_received) = ?
                ORDER BY i.name";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $vendor_id, $today);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $inventory_items[] = $row;
    }
    $stmt->close();
}

// Handle session messages
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>

<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1>Create Purchase Invoice</h1>
            <p>Create invoice for Purchase Based vendor</p>
        </div>
        <a href="../inventory/index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Inventory
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
    
    <?php if ($inventory_date): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <i class="fas fa-info-circle"></i> 
            Creating invoice with date <strong><?php echo date('d M Y', strtotime($inventory_date)); ?></strong>. 
            The invoice will show this date but will be recorded as created today.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php else: ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <i class="fas fa-info-circle"></i> 
            Creating invoice with today's date. Only inventory items received today will be shown.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($vendor): ?>
        <div class="dashboard-card mb-4">
            <h5>Vendor Information</h5>
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Name:</strong> <?php echo htmlspecialchars($vendor['name']); ?></p>
                    <p><strong>Type:</strong> <?php echo htmlspecialchars($vendor['type']); ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>Category:</strong> <?php echo htmlspecialchars($vendor['vendor_category']); ?></p>
                    <p><strong>Balance:</strong> ₹<?php echo number_format($vendor['balance'], 2); ?></p>
                </div>
            </div>
        </div>

        <form method="post" action="create_invoice.php">
            <input type="hidden" name="vendor_id" value="<?php echo $vendor_id; ?>">
            
            <div class="dashboard-card mb-4">
                <h5>Invoice Details</h5>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="invoice_number" class="form-label">Invoice Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="invoice_number" name="invoice_number" 
                                   value="<?php echo htmlspecialchars($next_invoice_number); ?>" 
                                   readonly required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="invoice_date" class="form-label">Invoice Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="invoice_date" name="invoice_date" value="<?php echo $date; ?>" required>
                            <?php if ($inventory_date): ?>
                                <small class="text-muted">This date will appear on the invoice: <?php echo date('d M Y', strtotime($inventory_date)); ?></small>
                            <?php else: ?>
                                <small class="text-muted">Today's date will appear on the invoice</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dashboard-card mb-4">
                <h5>Invoice Items</h5>
                <div class="table-responsive">
                    <table class="table table-bordered" id="invoice_items_table">
                        <thead>
                            <tr>
                                <th>Item Name</th>
                                <th>Available Quantity</th>
                                <th>Invoice Quantity</th>
                                <th>Weight (kg)</th>
                                <th>Rate (₹)</th>
                                <th>Amount (₹)</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($inventory_items)): ?>
                                <tr id="no-items-row">
                                    <td colspan="7" class="text-center">
                                        <div class="alert alert-info mb-0">
                                            <i class="fas fa-info-circle me-2"></i>
                                            No inventory items found for this vendor and date. 
                                            <button type="button" id="add-manual-item" class="btn btn-sm btn-primary ms-2">
                                                <i class="fas fa-plus"></i> Add Item Manually
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($inventory_items as $index => $item): ?>
                                    <tr class="item-row" data-item-id="<?php echo $item['item_id']; ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="flex-grow-1">
                                                    <?php echo htmlspecialchars($item['item_name']); ?>
                                                    <input type="hidden" name="item_id[]" value="<?php echo $item['item_id']; ?>">
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $item['remaining_stock']; ?></span>
                                        </td>
                                        <td>
                                            <input type="number" class="form-control invoice-quantity" name="quantity[]" 
                                                   value="" min="0" 
                                                   placeholder="Enter quantity" step="0.01" required>
                                        </td>
                                        <td>
                                            <div class="input-group">
                                                <input type="number" class="form-control invoice-weight" name="weight[]" 
                                                       value="" min="0" step="0.01" placeholder="Enter weight" required>
                                                <span class="input-group-text">kg</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="number" class="form-control invoice-rate" name="rate[]" 
                                                       value="" min="0" step="0.01" placeholder="Enter rate" required>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="text" class="form-control invoice-amount" name="amount[]" 
                                                       value="" readonly>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-danger btn-sm remove-item" title="Remove Item">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="d-flex justify-content-start mt-3">
                    <button type="button" id="add_new_item" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add New Item
                    </button>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-6">
                        <h5>Total Weight: <span id="total_weight">0.00</span> kg</h5>
                    </div>
                    <div class="col-md-6">
                        <h5>Total Amount: ₹<span id="total_amount">0.00</span></h5>
                    </div>
                </div>
            </div>

            <div class="text-center">
                <button type="submit" name="create_invoice" class="btn btn-primary btn-lg">
                    <i class="fas fa-save"></i> Create Purchase Invoice
                </button>
            </div>
        </form>
    <?php else: ?>
        <div class="alert alert-danger">
            <h5>Vendor Not Found</h5>
            <p>The specified vendor could not be found. Please go back to the inventory list and try again.</p>
            <a href="../inventory/index.php" class="btn btn-primary">Back to Inventory</a>
        </div>
    <?php endif; ?>
</div>

<script>
// Calculate amount for each row and totals automatically
document.addEventListener('DOMContentLoaded', function() {
    // Initialize event listeners
    initializeEventListeners();
    
    // Initial calculation
    calculateTotalsAjax();
    
    // Add new item button functionality
    const addNewItemBtn = document.getElementById('add_new_item');
    if (addNewItemBtn) {
        addNewItemBtn.addEventListener('click', addNewItemRow);
    }
    
    // Add manual item button functionality (when no items are found)
    const addManualItemBtn = document.getElementById('add-manual-item');
    if (addManualItemBtn) {
        addManualItemBtn.addEventListener('click', function() {
            // Remove the no-items-row
            const noItemsRow = document.getElementById('no-items-row');
            if (noItemsRow) {
                noItemsRow.remove();
            }
            
            // Add a new item row
            addNewItemRow();
        });
    }
});

// Initialize event listeners for all input elements and buttons
function initializeEventListeners() {
    // Initialize quantity inputs
    document.querySelectorAll('.invoice-quantity').forEach(input => {
        // Add placeholder
        input.placeholder = "Enter quantity";
    });
    
    // Remove item buttons
    document.querySelectorAll('.remove-item').forEach(button => {
        button.addEventListener('click', function() {
            const row = this.closest('tr');
            const tbody = row.parentElement;
            
            // Add confirmation if the row has values
            const hasValues = row.querySelector('.invoice-quantity').value > 0 || 
                             parseFloat(row.querySelector('.invoice-weight').value) > 0 ||
                             parseFloat(row.querySelector('.invoice-rate').value) > 0;
            
            if (hasValues) {
                if (confirm('Are you sure you want to remove this item?')) {
                    row.remove();
                    calculateTotalsAjax();
                    
                    // If no items left, show the no-items message
                    if (tbody.children.length === 0) {
                        const noItemsRow = document.createElement('tr');
                        noItemsRow.id = 'no-items-row';
                        noItemsRow.innerHTML = `
                            <td colspan="7" class="text-center">
                                <div class="alert alert-info mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    No items added yet. 
                                    <button type="button" id="add-manual-item" class="btn btn-sm btn-primary ms-2">
                                        <i class="fas fa-plus"></i> Add Item
                                    </button>
                                </div>
                            </td>
                        `;
                        tbody.appendChild(noItemsRow);
                        
                        // Add event listener to the new button
                        document.getElementById('add-manual-item').addEventListener('click', function() {
                            noItemsRow.remove();
                            addNewItemRow();
                        });
                    }
                }
            } else {
                row.remove();
                calculateTotalsAjax();
                
                // If no items left, show the no-items message
                if (tbody.children.length === 0) {
                    const noItemsRow = document.createElement('tr');
                    noItemsRow.id = 'no-items-row';
                    noItemsRow.innerHTML = `
                        <td colspan="7" class="text-center">
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle me-2"></i>
                                No items added yet. 
                                <button type="button" id="add-manual-item" class="btn btn-sm btn-primary ms-2">
                                    <i class="fas fa-plus"></i> Add Item
                                </button>
                            </div>
                        </td>
                    `;
                    tbody.appendChild(noItemsRow);
                    
                    // Add event listener to the new button
                    document.getElementById('add-manual-item').addEventListener('click', function() {
                        noItemsRow.remove();
                        addNewItemRow();
                    });
                }
            }
        });
    });
    
    // Input change event listeners
    const quantityInputs = document.querySelectorAll('.invoice-quantity');
    const weightInputs = document.querySelectorAll('.invoice-weight');
    const rateInputs = document.querySelectorAll('.invoice-rate');
    
    // Add event listeners for auto-calculation
    quantityInputs.forEach((input, index) => {
        input.addEventListener('input', () => {
            const row = input.closest('tr');
            const rowIndex = Array.from(row.parentElement.children).indexOf(row);
            calculateRowAmountAjax(rowIndex);
        });
    });
    
    weightInputs.forEach((input, index) => {
        input.addEventListener('input', () => {
            const row = input.closest('tr');
            const rowIndex = Array.from(row.parentElement.children).indexOf(row);
            calculateRowAmountAjax(rowIndex);
        });
    });
    
    rateInputs.forEach((input, index) => {
        input.addEventListener('input', () => {
            const row = input.closest('tr');
            const rowIndex = Array.from(row.parentElement.children).indexOf(row);
            calculateRowAmountAjax(rowIndex);
        });
    });
}

function calculateRowAmountAjax(rowIndex) {
    const row = document.querySelectorAll('#invoice_items_table tbody tr')[rowIndex];
    const quantity = parseFloat(row.querySelector('.invoice-quantity').value) || 0;
    const weight = parseFloat(row.querySelector('.invoice-weight').value) || 0;
    const rate = parseFloat(row.querySelector('.invoice-rate').value) || 0;
    
    // Show loading indicator
    row.querySelector('.invoice-amount').value = "Calculating...";
    
    // Create data object to send
    const data = {
        quantity: quantity,
        weight: weight,
        rate: rate
    };
    
    // Use fetch API for AJAX
    fetch('../../api/ajax/calculate_vendor_invoice_row.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            row.querySelector('.invoice-amount').value = data.amount.toFixed(2);
            calculateTotalsAjax();
        } else {
            row.querySelector('.invoice-amount').value = "Error";
            console.error('Calculation error:', data.message);
            
            // Fallback to client-side calculation
            calculateRowAmountFallback(rowIndex);
            calculateTotalsFallback();
        }
    })
    .catch(error => {
        console.error('AJAX error:', error);
        
        // Fallback to client-side calculation
        calculateRowAmountFallback(rowIndex);
        calculateTotalsFallback();
    });
}

// Function to add a new item row
function addNewItemRow() {
    // Get the table body
    const tbody = document.querySelector('#invoice_items_table tbody');
    
    // If there's a "no items" row, remove it
    const noItemsRow = document.getElementById('no-items-row');
    if (noItemsRow) {
        noItemsRow.remove();
    }
    
    // Create a new row
    const newRow = document.createElement('tr');
    newRow.className = 'item-row';
    
    // Generate a unique ID for the row
    const rowId = 'item-row-' + Date.now();
    newRow.id = rowId;
    
    // Add the row HTML
    newRow.innerHTML = `
        <td>
            <select class="form-select item-select" name="item_id[]" required>
                <option value="">Select Item</option>
                <!-- Items will be loaded dynamically -->
            </select>
        </td>
        <td>
            <span class="badge bg-secondary">N/A</span>
        </td>
        <td>
            <input type="number" class="form-control invoice-quantity" name="quantity[]" 
                   value="" min="0" step="0.01" required>
        </td>
        <td>
            <div class="input-group">
                <input type="number" class="form-control invoice-weight" name="weight[]" 
                       value="" min="0" step="0.01" required>
                <span class="input-group-text">kg</span>
            </div>
        </td>
        <td>
            <div class="input-group">
                <span class="input-group-text">₹</span>
                <input type="number" class="form-control invoice-rate" name="rate[]" 
                       value="" min="0" step="0.01" required>
            </div>
        </td>
        <td>
            <div class="input-group">
                <span class="input-group-text">₹</span>
                <input type="text" class="form-control invoice-amount" name="amount[]" 
                       value="" readonly>
            </div>
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-danger btn-sm remove-item" title="Remove Item">
                <i class="fas fa-trash"></i>
            </button>
        </td>
    `;
    
    // Add the row to the table
    tbody.appendChild(newRow);
    
    // Load items into the dropdown
    loadItemsForSelect(newRow.querySelector('.item-select'));
    
    // Add event listeners to the new row elements
    const quantityInput = newRow.querySelector('.invoice-quantity');
    const weightInput = newRow.querySelector('.invoice-weight');
    const rateInput = newRow.querySelector('.invoice-rate');
    const removeBtn = newRow.querySelector('.remove-item');
    
    // Add placeholders
    quantityInput.placeholder = "Enter quantity";
    weightInput.placeholder = "Enter weight";
    rateInput.placeholder = "Enter rate";
    
    // Remove item button
    removeBtn.addEventListener('click', function() {
        // Add confirmation if the row has values
        const hasValues = parseFloat(quantityInput.value) > 0 || 
                         parseFloat(weightInput.value) > 0 ||
                         parseFloat(rateInput.value) > 0;
        
        if (hasValues) {
            if (confirm('Are you sure you want to remove this item?')) {
                newRow.remove();
                calculateTotalsAjax();
                
                // If no items left, show the no-items message
                if (tbody.children.length === 0) {
                    const noItemsRow = document.createElement('tr');
                    noItemsRow.id = 'no-items-row';
                    noItemsRow.innerHTML = `
                        <td colspan="7" class="text-center">
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle me-2"></i>
                                No items added yet. 
                                <button type="button" id="add-manual-item" class="btn btn-sm btn-primary ms-2">
                                    <i class="fas fa-plus"></i> Add Item
                                </button>
                            </div>
                        </td>
                    `;
                    tbody.appendChild(noItemsRow);
                    
                    // Add event listener to the new button
                    document.getElementById('add-manual-item').addEventListener('click', function() {
                        noItemsRow.remove();
                        addNewItemRow();
                    });
                }
            }
        } else {
            newRow.remove();
            calculateTotalsAjax();
            
            // If no items left, show the no-items message
            if (tbody.children.length === 0) {
                const noItemsRow = document.createElement('tr');
                noItemsRow.id = 'no-items-row';
                noItemsRow.innerHTML = `
                    <td colspan="7" class="text-center">
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            No items added yet. 
                            <button type="button" id="add-manual-item" class="btn btn-sm btn-primary ms-2">
                                <i class="fas fa-plus"></i> Add Item
                            </button>
                        </div>
                    </td>
                `;
                tbody.appendChild(noItemsRow);
                
                // Add event listener to the new button
                document.getElementById('add-manual-item').addEventListener('click', function() {
                    noItemsRow.remove();
                    addNewItemRow();
                });
            }
        }
    });
    
    // Input change listeners
    quantityInput.addEventListener('input', function() {
        const rowIndex = Array.from(tbody.children).indexOf(newRow);
        calculateRowAmountAjax(rowIndex);
    });
    
    weightInput.addEventListener('input', function() {
        const rowIndex = Array.from(tbody.children).indexOf(newRow);
        calculateRowAmountAjax(rowIndex);
    });
    
    rateInput.addEventListener('input', function() {
        const rowIndex = Array.from(tbody.children).indexOf(newRow);
        calculateRowAmountAjax(rowIndex);
    });
    
    // Calculate initial amount
    const rowIndex = Array.from(tbody.children).indexOf(newRow);
    calculateRowAmountAjax(rowIndex);
    
    // Highlight the new row briefly
    newRow.style.backgroundColor = '#e8f4ff';
    setTimeout(() => {
        newRow.style.transition = 'background-color 1s';
        newRow.style.backgroundColor = '';
    }, 100);
    
    // Focus on the item select dropdown
    newRow.querySelector('.item-select').focus();
}

// Function to load items into a select dropdown
function loadItemsForSelect(selectElement) {
    // Show loading state
    selectElement.innerHTML = '<option value="">Loading items...</option>';
    
    // Fetch items from the server
    fetch('../../api/inventory/get_items_simple.php')
        .then(response => response.json())
        .then(data => {
            // Clear loading option
            selectElement.innerHTML = '<option value="">Select Item</option>';
            
            // Add items to the dropdown
            if (data && data.success && data.items && data.items.length > 0) {
                data.items.forEach(item => {
                    const option = document.createElement('option');
                    option.value = item.id;
                    option.textContent = item.name;
                    selectElement.appendChild(option);
                });
            } else {
                // No items found
                const option = document.createElement('option');
                option.value = "";
                option.textContent = "No items found";
                option.disabled = true;
                selectElement.appendChild(option);
            }
        })
        .catch(error => {
            console.error('Error loading items:', error);
            selectElement.innerHTML = '<option value="">Error loading items</option>';
        });
    
    // Add change event listener
    selectElement.addEventListener('change', function() {
        // If an item is selected, you could fetch additional details here
        // For example: rate, available quantity, etc.
    });
}

function calculateTotalsAjax() {
    // Gather all quantities, weights, and amounts
    const rows = document.querySelectorAll('#invoice_items_table tbody tr');
    const invoiceData = [];
    
    rows.forEach(row => {
        // Skip the no-items-row if present
        if (row.id === 'no-items-row') return;
        
        const quantity = parseFloat(row.querySelector('.invoice-quantity').value) || 0;
        const weight = parseFloat(row.querySelector('.invoice-weight').value) || 0;
        const amount = parseFloat(row.querySelector('.invoice-amount').value) || 0;
        
        invoiceData.push({
            quantity: quantity,
            weight: weight,
            amount: amount
        });
    });
    
    // Show loading indicators
    document.getElementById('total_amount').textContent = "Calculating...";
    document.getElementById('total_weight').textContent = "Calculating...";
    
    // Use fetch API for AJAX
    fetch('../../api/ajax/calculate_vendor_invoice_totals.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ items: invoiceData })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('total_amount').textContent = data.totalAmount.toFixed(2);
            document.getElementById('total_weight').textContent = data.totalWeight.toFixed(2);
        } else {
            document.getElementById('total_amount').textContent = "Error";
            document.getElementById('total_weight').textContent = "Error";
            console.error('Calculation error:', data.message);
            
            // Fallback to client-side calculation
            calculateTotalsFallback();
        }
    })
    .catch(error => {
        console.error('AJAX error:', error);
        
        // Fallback to client-side calculation
        calculateTotalsFallback();
    });
}

// Fallback functions in case AJAX fails
function calculateRowAmountFallback(rowIndex) {
    const rows = document.querySelectorAll('#invoice_items_table tbody tr');
    
    // Make sure the index is valid and not the no-items-row
    if (rowIndex >= 0 && rowIndex < rows.length && rows[rowIndex].id !== 'no-items-row') {
        const row = rows[rowIndex];
        const quantity = parseFloat(row.querySelector('.invoice-quantity').value) || 0;
        const weight = parseFloat(row.querySelector('.invoice-weight').value) || 0;
        const rate = parseFloat(row.querySelector('.invoice-rate').value) || 0;
        
        let amount = 0;
        if (weight > 0) {
            amount = weight * rate;
        } else {
            amount = quantity * rate;
        }
        
        const amountInput = row.querySelector('.invoice-amount');
        const oldAmount = parseFloat(amountInput.value) || 0;
        amountInput.value = amount.toFixed(2);
        
        // Highlight the amount if it changed
        if (amount !== oldAmount) {
            amountInput.classList.add('bg-light');
            setTimeout(() => {
                amountInput.classList.remove('bg-light');
            }, 500);
        }
    }
}

function calculateTotalsFallback() {
    const rows = document.querySelectorAll('#invoice_items_table tbody tr');
    let totalAmount = 0;
    let totalWeight = 0;
    let itemCount = 0;
    
    rows.forEach(row => {
        // Skip the no-items-row
        if (row.id === 'no-items-row') return;
        
        const weight = parseFloat(row.querySelector('.invoice-weight').value) || 0;
        const amount = parseFloat(row.querySelector('.invoice-amount').value) || 0;
        
        totalAmount += amount;
        totalWeight += weight;
        itemCount++;
    });
    
    // Update totals with animation
    const totalAmountElement = document.getElementById('total_amount');
    const oldTotalAmount = parseFloat(totalAmountElement.textContent) || 0;
    totalAmountElement.textContent = totalAmount.toFixed(2);
    
    if (totalAmount !== oldTotalAmount) {
        totalAmountElement.classList.add('highlight');
        setTimeout(() => {
            totalAmountElement.classList.remove('highlight');
        }, 500);
    }
    
    const totalWeightElement = document.getElementById('total_weight');
    const oldTotalWeight = parseFloat(totalWeightElement.textContent) || 0;
    totalWeightElement.textContent = totalWeight.toFixed(2);
    
    if (totalWeight !== oldTotalWeight) {
        totalWeightElement.classList.add('highlight');
        setTimeout(() => {
            totalWeightElement.classList.remove('highlight');
        }, 500);
    }
}
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>

