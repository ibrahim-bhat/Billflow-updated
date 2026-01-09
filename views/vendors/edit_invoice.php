<?php
// Start output buffering to prevent headers already sent error
ob_start();

require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../layout/header.php';
require_once __DIR__ . '/../../core/helpers/numbering_helper_vendor.php';

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
$sql = "SELECT vi.*, v.name as vendor_name, v.id as vendor_id, v.type as vendor_type, v.vendor_category
        FROM vendor_invoices vi 
        JOIN vendors v ON vi.vendor_id = v.id 
        WHERE vi.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $invoice_id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();

if (!$invoice) {
    header('Location: index.php');
    exit();
}

// Get invoice date for reference (no same day restriction)
$invoice_date = date('Y-m-d', strtotime($invoice['invoice_date']));
$today = date('Y-m-d');

// Get invoice items
$sql = "SELECT vii.*, i.name as item_name 
        FROM vendor_invoice_items vii 
        JOIN items i ON vii.item_id = i.id 
        WHERE vii.invoice_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $invoice_id);
$stmt->execute();
$invoice_items = $stmt->get_result();

// Check if we got any results
if ($invoice_items->num_rows == 0) {
    // No items found - this shouldn't happen for a valid invoice
    $_SESSION['error_message'] = "No items found for this invoice.";
    header('Location: index.php');
    exit();
}

// Process Update Invoice form
if (isset($_POST['update_invoice'])) {
    $vendor_id = intval($_POST['vendor_id']);
    
    // Handle optional date - use current date if custom date is not selected
    $use_custom_date = isset($_POST['use_custom_date']) && $_POST['use_custom_date'] == 'on';
    if ($use_custom_date && !empty($_POST['date'])) {
        $date = trim(htmlspecialchars($_POST['date']));
    } else {
        $date = date('Y-m-d'); // Use current date as default
    }
    
    $total_amount = floatval($_POST['total_amount']);
    
    // Get item details from form
    $item_ids = $_POST['item_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $weights = $_POST['weight'] ?? [];
    $rates = $_POST['rate'] ?? [];
    
    if (!empty($vendor_id) && !empty($item_ids) && count($item_ids) > 0) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Update invoice header
            $update_sql = "UPDATE vendor_invoices SET vendor_id = ?, invoice_date = ?, total_amount = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("isdi", $vendor_id, $date, $total_amount, $invoice_id);
            $update_stmt->execute();
            
            // Delete existing invoice items
            $delete_sql = "DELETE FROM vendor_invoice_items WHERE invoice_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param('i', $invoice_id);
            $delete_stmt->execute();
            
            // Insert new invoice items
            $item_sql = "INSERT INTO vendor_invoice_items (invoice_id, item_id, quantity, weight, rate, amount) 
                        VALUES (?, ?, ?, ?, ?, ?)";
            $item_stmt = $conn->prepare($item_sql);
            
            for ($i = 0; $i < count($item_ids); $i++) {
                if (!empty($item_ids[$i])) {
                    $qty = floatval($quantities[$i]);
                    $weight = floatval($weights[$i]);
                    $rate = floatval($rates[$i]);
                    
                    // Calculation logic
                    if ($weight > 0) {
                        $amount = $weight * $rate;
                    } else if ($qty > 0) {
                        $amount = $qty * $rate;
                    } else {
                        $amount = 0;
                    }
                    
                    $item_stmt->bind_param("iidddd", $invoice_id, $item_ids[$i], $qty, $weight, $rate, $amount);
                    $item_stmt->execute();
                }
            }
            
            // Update vendor balance
            $update_balance_sql = "UPDATE vendors SET balance = balance + ? - ? WHERE id = ?";
            $update_balance_stmt = $conn->prepare($update_balance_sql);
            $update_balance_stmt->bind_param("ddi", $total_amount, $invoice['total_amount'], $vendor_id);
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

// Get all vendors for dropdown
$vendors_sql = "SELECT * FROM vendors ORDER BY name";
$vendors_result = $conn->query($vendors_sql);
$vendors = [];
while ($row = $vendors_result->fetch_assoc()) {
    $vendors[] = $row;
}

// Get all items for dropdown
$items_sql = "SELECT * FROM items ORDER BY name";
$items_result = $conn->query($items_sql);
$items = [];
while ($row = $items_result->fetch_assoc()) {
    $items[] = $row;
}

// Reset the invoice_items result pointer for later use
mysqli_data_seek($invoice_items, 0);
?>

<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1>Edit Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?></h1>
            <p>Edit invoice details and items</p>
        </div>
        <a href="index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Invoices
        </a>
    </div>

    <!-- Display Messages -->
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="post" action="edit_invoice.php?id=<?php echo $invoice_id; ?>" id="editInvoiceForm">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label for="vendor_id" class="form-label">Vendor <span class="text-danger">*</span></label>
                        <select class="form-select" id="vendor_id" name="vendor_id" required>
                            <option value="">Select Vendor</option>
                            <?php foreach ($vendors as $vendor): ?>
                                <option value="<?php echo $vendor['id']; ?>" 
                                        <?php echo $vendor['id'] == $invoice['vendor_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($vendor['name']); ?> 
                                    (<?php echo htmlspecialchars($vendor['vendor_category']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="invoice_number" class="form-label">Invoice Number</label>
                        <input type="text" class="form-control" id="invoice_number" value="<?php echo htmlspecialchars($invoice['invoice_number']); ?>" readonly>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="use_custom_date" name="use_custom_date">
                            <label class="form-check-label" for="use_custom_date">
                                Use Custom Date
                            </label>
                        </div>
                        <label for="invoice_date" class="form-label">Date</label>
                        <input type="date" class="form-control" id="invoice_date" name="date" 
                               value="<?php echo $invoice['invoice_date']; ?>" disabled>
                    </div>
                </div>

                <div class="table-responsive mb-3">
                    <table class="table table-bordered" id="invoice_items_table">
                        <thead>
                            <tr>
                                <th>Item Name</th>
                                <th>Qty</th>
                                <th>Weight (kg)</th>
                                <th>Rate (₹)</th>
                                <th>Amount (₹)</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $item_count = 0;
                            while ($item = $invoice_items->fetch_assoc()): 
                                $item_count++;
                            ?>
                            <tr class="item-row">
                                <td>
                                    <select name="item_id[]" class="form-select item-select" required>
                                        <option value="">Select Item</option>
                                        <?php foreach ($items as $i): ?>
                                            <option value="<?php echo $i['id']; ?>" 
                                                    <?php echo $i['id'] == $item['item_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($i['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" name="quantity[]" class="form-control quantity" 
                                           value="<?php echo $item['quantity']; ?>" step="0.01" min="0.01" placeholder="Quantity" required>
                                </td>
                                <td>
                                    <input type="number" name="weight[]" class="form-control weight" 
                                           value="<?php echo $item['weight']; ?>" step="0.01" min="0" placeholder="Weight">
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">₹</span>
                                        <input type="number" name="rate[]" class="form-control rate" 
                                               value="<?php echo $item['rate']; ?>" step="0.01" min="0.01" placeholder="Rate" required>
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
                                <td colspan="4" class="text-end"><strong>Subtotal:</strong></td>
                                <td><span id="invoice_subtotal">₹<?php echo number_format($invoice['total_amount'], 2); ?></span></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end"><strong>Total Amount:</strong></td>
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

// Function to load items via AJAX
function loadItems(itemSelect, selectedItemId = null) {
    itemSelect.innerHTML = '<option value="">Loading items...</option>';

    return fetch('../../api/inventory/get_items_simple.php')
        .then(response => response.json())
        .then(data => {
            let optionsHtml = '<option value="">Select Item</option>';

            if (data.items && data.items.length > 0) {
                data.items.forEach(item => {
                    const isSelected = selectedItemId && String(item.id) === String(selectedItemId);
                    optionsHtml += `<option value="${item.id}" ${isSelected ? 'selected' : ''}>
                        ${item.name}
                    </option>`;
                });
                itemSelect.innerHTML = optionsHtml;
            } else {
                itemSelect.innerHTML = '<option value="">No items available</option>';
            }
        })
        .catch(error => {
            console.error('Error loading items:', error);
            itemSelect.innerHTML = '<option value="">Error loading items</option>';
        });
}

// Initialize existing rows
document.addEventListener('DOMContentLoaded', function() {
    const existingRows = document.querySelectorAll('.item-row');
    
    existingRows.forEach((row) => {
        const itemSelect = row.querySelector('.item-select');
        
        // Add calculation functionality
        const qtyInput = row.querySelector('.quantity');
        const weightInput = row.querySelector('.weight');
        const rateInput = row.querySelector('.rate');
        const amountInput = row.querySelector('.amount');
        
        function updateRowAmount() {
            const qty = parseFloat(qtyInput.value) || 0;
            const weight = parseFloat(weightInput.value) || 0;
            const rate = parseFloat(rateInput.value) || 0;
            
            // Use AJAX to calculate the amount
            const formData = new FormData();
            formData.append('quantity', qty);
            formData.append('weight', weight);
            formData.append('rate', rate);
            
            fetch('../../api/ajax/calculate_vendor_invoice_row.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                amountInput.value = data.amount;
                
                // Highlight the amount field
                amountInput.classList.add('bg-light');
                setTimeout(() => {
                    amountInput.classList.remove('bg-light');
                }, 500);
                
                updateTotals();
            })
            .catch(error => {
                console.error('Error calculating row amount:', error);
                // Fallback calculation if AJAX fails
                let amount = 0;
                if (weight > 0) {
                    amount = weight * rate;
                } else if (qty > 0) {
                    amount = qty * rate;
                }
                amountInput.value = amount.toFixed(2);
                updateTotals();
            });
        }
        
        qtyInput.addEventListener('input', updateRowAmount);
        weightInput.addEventListener('input', updateRowAmount);
        rateInput.addEventListener('input', updateRowAmount);
        
        // Remove row handler
        const removeBtn = row.querySelector('.remove-row');
        removeBtn.addEventListener('click', function() {
            if (document.querySelectorAll('.item-row').length > 1) {
                row.remove();
                updateTotals();
            } else {
                showAlert('Cannot remove the last item row. At least one item is required.', 'warning');
            }
        });
    });
    
    // Calculate initial totals
    updateTotals();
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
                <?php foreach ($items as $i): ?>
                <option value="<?php echo $i['id']; ?>"><?php echo htmlspecialchars($i['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="number" name="quantity[]" class="form-control quantity" value="" step="0.01" min="0.01" placeholder="Quantity" required>
        </td>
        <td>
            <input type="number" name="weight[]" class="form-control weight" value="" step="0.01" min="0" placeholder="Weight">
        </td>
        <td>
            <div class="input-group input-group-sm">
                <span class="input-group-text">₹</span>
                <input type="number" name="rate[]" class="form-control rate" value="" step="0.01" min="0.01" placeholder="Rate" required>
            </div>
        </td>
        <td>
            <div class="input-group input-group-sm">
                <span class="input-group-text">₹</span>
                <input type="number" name="amount[]" class="form-control amount" value="" step="0.01" readonly>
            </div>
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-danger remove-row">
                <i class="fas fa-trash"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(newRow);
    
    const itemSelect = newRow.querySelector('.item-select');
    const qtyInput = newRow.querySelector('.quantity');
    const weightInput = newRow.querySelector('.weight');
    const rateInput = newRow.querySelector('.rate');
    const amountInput = newRow.querySelector('.amount');
    const removeBtn = newRow.querySelector('.remove-row');
    
    function updateRowAmount() {
        const qty = parseFloat(qtyInput.value) || 0;
        const weight = parseFloat(weightInput.value) || 0;
        const rate = parseFloat(rateInput.value) || 0;
        
        // Use AJAX to calculate the amount
        const formData = new FormData();
        formData.append('quantity', qty);
        formData.append('weight', weight);
        formData.append('rate', rate);
        
        fetch('../../api/ajax/calculate_vendor_invoice_row.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            amountInput.value = data.amount;
            
            // Highlight the amount field
            amountInput.classList.add('bg-light');
            setTimeout(() => {
                amountInput.classList.remove('bg-light');
            }, 500);
            
            updateTotals();
        })
        .catch(error => {
            console.error('Error calculating row amount:', error);
            // Fallback calculation if AJAX fails
            let amount = 0;
            if (weight > 0) {
                amount = weight * rate;
            } else if (qty > 0) {
                amount = qty * rate;
            }
            amountInput.value = amount.toFixed(2);
            updateTotals();
        });
    }
    
    qtyInput.addEventListener('input', updateRowAmount);
    weightInput.addEventListener('input', updateRowAmount);
    rateInput.addEventListener('input', updateRowAmount);
    
    itemSelect.addEventListener('change', function() {
        if (this.value) {
            qtyInput.focus();
        }
    });
    
    removeBtn.addEventListener('click', function() {
        newRow.remove();
        updateTotals();
    });
    
    // Focus on the item select
    itemSelect.focus();
});

// Function to update totals
function updateTotals() {
    const amounts = document.querySelectorAll('#invoice_items_table .amount');
    const amountValues = [];
    
    amounts.forEach(input => {
        amountValues.push(parseFloat(input.value) || 0);
    });
    
    const subtotalElement = document.getElementById('invoice_subtotal');
    const totalElement = document.getElementById('invoice_total');
    const totalAmountInput = document.getElementById('total_amount');
    const oldTotal = parseFloat(totalAmountInput.value) || 0;
    
    // Use AJAX to calculate totals
    const formData = new FormData();
    formData.append('amounts', JSON.stringify(amountValues));
    
    fetch('../../api/ajax/calculate_vendor_invoice_totals.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        // Highlight the totals when they change
        if (oldTotal !== data.total) {
            subtotalElement.classList.add('highlight');
            totalElement.classList.add('highlight');
            
            setTimeout(() => {
                subtotalElement.classList.remove('highlight');
                totalElement.classList.remove('highlight');
            }, 1000);
        }
        
        subtotalElement.innerText = data.formatted_subtotal;
        totalElement.innerText = data.formatted_total;
        totalAmountInput.value = data.total;
    })
    .catch(error => {
        console.error('Error calculating totals:', error);
        // Fallback calculation if AJAX fails
        let subtotal = 0;
        amounts.forEach(input => {
            subtotal += parseFloat(input.value) || 0;
        });
        
        subtotalElement.innerText = '₹' + subtotal.toFixed(2);
        totalElement.innerText = '₹' + subtotal.toFixed(2);
        totalAmountInput.value = subtotal.toFixed(2);
    });
}

// Form validation
document.getElementById('editInvoiceForm').addEventListener('submit', function(event) {
    const items = document.querySelectorAll('#invoice_items_table tbody tr');
    if (items.length === 0) {
        event.preventDefault();
        showAlert('Please add at least one item to the invoice.', 'danger');
        return false;
    }
    
    const total = parseFloat(document.getElementById('total_amount').value) || 0;
    if (total <= 0) {
        event.preventDefault();
        showAlert('Total amount must be greater than zero.', 'danger');
        return false;
    }
    
    let isValid = true;
    items.forEach(row => {
        const itemSelect = row.querySelector('.item-select');
        const qtyInput = row.querySelector('.quantity');
        const rateInput = row.querySelector('.rate');
        
        if (!itemSelect.value) {
            isValid = false;
            itemSelect.classList.add('is-invalid');
        } else {
            itemSelect.classList.remove('is-invalid');
        }
        
        if (!qtyInput.value || parseFloat(qtyInput.value) <= 0) {
            isValid = false;
            qtyInput.classList.add('is-invalid');
        } else {
            qtyInput.classList.remove('is-invalid');
        }
        
        if (!rateInput.value || parseFloat(rateInput.value) <= 0) {
            isValid = false;
            rateInput.classList.add('is-invalid');
        } else {
            rateInput.classList.remove('is-invalid');
        }
    });
    
    if (!isValid) {
        event.preventDefault();
        showAlert('Please fill in all required fields correctly.', 'danger');
        return false;
    }
    
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
    
    // Check if current date is different from invoice date
    const invoiceDate = '<?php echo $invoice['invoice_date']; ?>';
    const currentDate = '<?php echo date('Y-m-d'); ?>';
    
    if (invoiceDate !== currentDate) {
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
