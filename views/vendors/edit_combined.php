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

// Get vendor ID and date from URL
$vendor_id = isset($_GET['vendor_id']) ? intval($_GET['vendor_id']) : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';

if (!$vendor_id || empty($date)) {
    $_SESSION['error_message'] = "Invalid request. Vendor ID and date are required.";
    header('Location: index.php');
    exit();
}

// Get vendor details
$vendor_sql = "SELECT * FROM vendors WHERE id = ?";
$vendor_stmt = $conn->prepare($vendor_sql);
$vendor_stmt->bind_param('i', $vendor_id);
$vendor_stmt->execute();
$vendor = $vendor_stmt->get_result()->fetch_assoc();
$vendor_stmt->close();

if (!$vendor) {
    $_SESSION['error_message'] = "Vendor not found.";
    header('Location: index.php');
    exit();
}

// Get all invoices for this vendor on this date
$invoices_sql = "SELECT * FROM vendor_invoices 
                WHERE vendor_id = ? AND DATE(invoice_date) = ? 
                ORDER BY CAST(invoice_number AS UNSIGNED)";
$invoices_stmt = $conn->prepare($invoices_sql);
$invoices_stmt->bind_param('is', $vendor_id, $date);
$invoices_stmt->execute();
$invoices_result = $invoices_stmt->get_result();
$invoices = [];
$total_invoice_amount = 0;

while ($row = $invoices_result->fetch_assoc()) {
    $invoices[] = $row;
    $total_invoice_amount += $row['total_amount'];
}
$invoices_stmt->close();

if (empty($invoices)) {
    $_SESSION['error_message'] = "No invoices found for this vendor on the selected date.";
    header('Location: index.php');
    exit();
}

// Get all invoice items
$invoice_ids = array_column($invoices, 'id');
$invoice_ids_str = implode(',', $invoice_ids);

$items_sql = "SELECT vii.*, vi.invoice_number, i.name as item_name, i.id as item_id
              FROM vendor_invoice_items vii
              JOIN vendor_invoices vi ON vii.invoice_id = vi.id
              JOIN items i ON vii.item_id = i.id
              WHERE vii.invoice_id IN ($invoice_ids_str)
              ORDER BY vi.invoice_number, vii.id";
$items_result = $conn->query($items_sql);
$all_items = [];

while ($row = $items_result->fetch_assoc()) {
    $all_items[] = $row;
}

// Process form submission
if (isset($_POST['update_invoices'])) {
    // Start transaction
    $conn->begin_transaction();
    
    try {
        $original_total = $total_invoice_amount;
        $new_total = 0;
        
        // Process each invoice
        foreach ($_POST['invoice'] as $invoice_id => $invoice_data) {
            $invoice_id = intval($invoice_id);
            
            // Update invoice date if changed
            if (isset($invoice_data['date']) && !empty($invoice_data['date'])) {
                $new_date = trim(htmlspecialchars($invoice_data['date']));
                $update_date_sql = "UPDATE vendor_invoices SET invoice_date = ? WHERE id = ?";
                $update_date_stmt = $conn->prepare($update_date_sql);
                $update_date_stmt->bind_param('si', $new_date, $invoice_id);
                $update_date_stmt->execute();
                $update_date_stmt->close();
            }
            
            // Process items for this invoice
            if (isset($invoice_data['items'])) {
                // Delete existing items for this invoice
                $delete_items_sql = "DELETE FROM vendor_invoice_items WHERE invoice_id = ?";
                $delete_items_stmt = $conn->prepare($delete_items_sql);
                $delete_items_stmt->bind_param('i', $invoice_id);
                $delete_items_stmt->execute();
                $delete_items_stmt->close();
                
                // Insert updated items
                $invoice_total = 0;
                $insert_item_sql = "INSERT INTO vendor_invoice_items (invoice_id, item_id, quantity, weight, rate, amount) 
                                   VALUES (?, ?, ?, ?, ?, ?)";
                $insert_item_stmt = $conn->prepare($insert_item_sql);
                
                foreach ($invoice_data['items'] as $item_id => $item) {
                    $item_id = intval($item_id);
                    $quantity = floatval($item['quantity']);
                    $weight = floatval($item['weight']);
                    $rate = floatval($item['rate']);
                    
                    // Calculate amount
                    if ($weight > 0) {
                        $amount = $weight * $rate;
                    } else {
                        $amount = $quantity * $rate;
                    }
                    
                    $invoice_total += $amount;
                    
                    $insert_item_stmt->bind_param('iidddd', $invoice_id, $item_id, $quantity, $weight, $rate, $amount);
                    $insert_item_stmt->execute();
                }
                $insert_item_stmt->close();
                
                // Update invoice total
                $update_total_sql = "UPDATE vendor_invoices SET total_amount = ? WHERE id = ?";
                $update_total_stmt = $conn->prepare($update_total_sql);
                $update_total_stmt->bind_param('di', $invoice_total, $invoice_id);
                $update_total_stmt->execute();
                $update_total_stmt->close();
                
                $new_total += $invoice_total;
            }
        }
        
        // Update vendor balance
        $balance_diff = $new_total - $original_total;
        if ($balance_diff != 0) {
            $update_balance_sql = "UPDATE vendors SET balance = balance + ? WHERE id = ?";
            $update_balance_stmt = $conn->prepare($update_balance_sql);
            $update_balance_stmt->bind_param('di', $balance_diff, $vendor_id);
            $update_balance_stmt->execute();
            $update_balance_stmt->close();
        }
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success_message'] = "All invoices updated successfully!";
        header("Location: view_invoice.php?vendor_id=$vendor_id&date=$date");
        exit();
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $error_message = "Error updating invoices: " . $e->getMessage();
    }
}

// Get all items for dropdowns
$all_items_sql = "SELECT * FROM items ORDER BY name";
$all_items_result = $conn->query($all_items_sql);
$items_list = [];
while ($row = $all_items_result->fetch_assoc()) {
    $items_list[] = $row;
}
?>

<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1>Edit Combined Invoices</h1>
            <p>Vendor: <?php echo htmlspecialchars($vendor['name']); ?> | Date: <?php echo date('d/m/Y', strtotime($date)); ?></p>
        </div>
        <a href="view_invoice.php?vendor_id=<?php echo $vendor_id; ?>&date=<?php echo $date; ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to View
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

    <form method="post" id="editCombinedForm">
        <?php foreach ($invoices as $invoice): ?>
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?></h5>
                    <div>
                        <div class="form-check form-switch">
                            <input class="form-check-input invoice-toggle" type="checkbox" 
                                   id="toggle_invoice_<?php echo $invoice['id']; ?>" 
                                   data-invoice-id="<?php echo $invoice['id']; ?>" checked>
                            <label class="form-check-label" for="toggle_invoice_<?php echo $invoice['id']; ?>">
                                Enable Editing
                            </label>
                        </div>
                    </div>
                </div>
                <div class="card-body invoice-section" id="invoice_section_<?php echo $invoice['id']; ?>">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="invoice_date_<?php echo $invoice['id']; ?>" class="form-label">Invoice Date</label>
                            <input type="date" class="form-control" 
                                   id="invoice_date_<?php echo $invoice['id']; ?>" 
                                   name="invoice[<?php echo $invoice['id']; ?>][date]" 
                                   value="<?php echo $invoice['invoice_date']; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Invoice Number</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($invoice['invoice_number']); ?>" readonly>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-bordered invoice-items-table" id="invoice_items_<?php echo $invoice['id']; ?>">
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
                                $invoice_items = array_filter($all_items, function($item) use ($invoice) {
                                    return $item['invoice_id'] == $invoice['id'];
                                });
                                
                                foreach ($invoice_items as $item): 
                                ?>
                                <tr class="item-row">
                                    <td>
                                        <select name="invoice[<?php echo $invoice['id']; ?>][items][<?php echo $item['item_id']; ?>][item_id]" 
                                                class="form-select item-select" required>
                                            <option value="">Select Item</option>
                                            <?php foreach ($items_list as $i): ?>
                                                <option value="<?php echo $i['id']; ?>" 
                                                        <?php echo $i['id'] == $item['item_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($i['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" 
                                               name="invoice[<?php echo $invoice['id']; ?>][items][<?php echo $item['item_id']; ?>][quantity]" 
                                               class="form-control quantity" 
                                               value="<?php echo $item['quantity']; ?>" 
                                               step="0.01" min="0.01" placeholder="Quantity" required>
                                    </td>
                                    <td>
                                        <input type="number" 
                                               name="invoice[<?php echo $invoice['id']; ?>][items][<?php echo $item['item_id']; ?>][weight]" 
                                               class="form-control weight" 
                                               value="<?php echo $item['weight']; ?>" 
                                               step="0.01" min="0" placeholder="Weight">
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text">₹</span>
                                            <input type="number" 
                                                   name="invoice[<?php echo $invoice['id']; ?>][items][<?php echo $item['item_id']; ?>][rate]" 
                                                   class="form-control rate" 
                                                   value="<?php echo $item['rate']; ?>" 
                                                   step="0.01" min="0.01" placeholder="Rate" required>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text">₹</span>
                                            <input type="number" class="form-control amount" 
                                                   value="<?php echo $item['amount']; ?>" 
                                                   step="0.01" readonly>
                                        </div>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-danger remove-row">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="4" class="text-end"><strong>Subtotal:</strong></td>
                                    <td colspan="2">
                                        <span class="invoice-subtotal">₹<?php echo number_format($invoice['total_amount'], 2); ?></span>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <button type="button" class="btn btn-sm btn-outline-secondary add-item-button mt-2" 
                            data-invoice-id="<?php echo $invoice['id']; ?>">
                        <i class="fas fa-plus"></i> Add Item
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
        
        <div class="card mb-4">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h5>Total Invoices: <?php echo count($invoices); ?></h5>
                    </div>
                    <div class="col-md-6 text-end">
                        <h5>Grand Total: <span id="grand_total">₹<?php echo number_format($total_invoice_amount, 2); ?></span></h5>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="d-flex gap-2 mb-4">
            <button type="submit" name="update_invoices" class="btn btn-primary">
                <i class="fas fa-save"></i> Update All Invoices
            </button>
            <a href="view_invoice.php?vendor_id=<?php echo $vendor_id; ?>&date=<?php echo $date; ?>" class="btn btn-secondary">
                <i class="fas fa-times"></i> Cancel
            </a>
        </div>
    </form>
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

// Initialize event handlers for existing rows
document.addEventListener('DOMContentLoaded', function() {
    // Enable/disable invoice sections
    document.querySelectorAll('.invoice-toggle').forEach(toggle => {
        toggle.addEventListener('change', function() {
            const invoiceId = this.getAttribute('data-invoice-id');
            const section = document.getElementById(`invoice_section_${invoiceId}`);
            
            if (this.checked) {
                section.classList.remove('disabled');
                section.querySelectorAll('input, select, button').forEach(el => {
                    el.disabled = false;
                });
            } else {
                section.classList.add('disabled');
                section.querySelectorAll('input, select, button').forEach(el => {
                    el.disabled = true;
                });
            }
        });
    });
    
    // Add item row buttons
    document.querySelectorAll('.add-item-button').forEach(button => {
        button.addEventListener('click', function() {
            const invoiceId = this.getAttribute('data-invoice-id');
            addItemRow(invoiceId);
        });
    });
    
    // Initialize existing rows
    document.querySelectorAll('.item-row').forEach(row => {
        initializeRowEvents(row);
    });
    
    // Calculate initial totals
    calculateAllTotals();
});

// Initialize events for a row
function initializeRowEvents(row) {
    const qtyInput = row.querySelector('.quantity');
    const weightInput = row.querySelector('.weight');
    const rateInput = row.querySelector('.rate');
    const amountInput = row.querySelector('.amount');
    const removeBtn = row.querySelector('.remove-row');
    
    // Update amount when inputs change
    qtyInput.addEventListener('input', () => updateRowAmount(row));
    weightInput.addEventListener('input', () => updateRowAmount(row));
    rateInput.addEventListener('input', () => updateRowAmount(row));
    
    // Remove row
    removeBtn.addEventListener('click', function() {
        const table = row.closest('table');
        const tbody = table.querySelector('tbody');
        
        if (tbody.querySelectorAll('tr').length > 1) {
            row.remove();
            calculateTableTotal(table);
            calculateGrandTotal();
        } else {
            showAlert('Cannot remove the last item row. At least one item is required.', 'warning');
        }
    });
}

// Add a new item row
function addItemRow(invoiceId) {
    const table = document.getElementById(`invoice_items_${invoiceId}`);
    const tbody = table.querySelector('tbody');
    
    const newRow = document.createElement('tr');
    newRow.classList.add('item-row');
    
    // Generate a unique temporary ID for the new item
    const tempItemId = 'new_' + Date.now();
    
    newRow.innerHTML = `
        <td>
            <select name="invoice[${invoiceId}][items][${tempItemId}][item_id]" class="form-select item-select" required>
                <option value="">Select Item</option>
                <?php foreach ($items_list as $i): ?>
                <option value="<?php echo $i['id']; ?>"><?php echo htmlspecialchars($i['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="number" name="invoice[${invoiceId}][items][${tempItemId}][quantity]" class="form-control quantity" 
                   value="" step="0.01" min="0.01" placeholder="Quantity" required>
        </td>
        <td>
            <input type="number" name="invoice[${invoiceId}][items][${tempItemId}][weight]" class="form-control weight" 
                   value="" step="0.01" min="0" placeholder="Weight">
        </td>
        <td>
            <div class="input-group input-group-sm">
                <span class="input-group-text">₹</span>
                <input type="number" name="invoice[${invoiceId}][items][${tempItemId}][rate]" class="form-control rate" 
                       value="" step="0.01" min="0.01" placeholder="Rate" required>
            </div>
        </td>
        <td>
            <div class="input-group input-group-sm">
                <span class="input-group-text">₹</span>
                <input type="number" class="form-control amount" value="" step="0.01" readonly>
            </div>
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-danger remove-row">
                <i class="fas fa-trash"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(newRow);
    initializeRowEvents(newRow);
    
    // Focus on the item select
    const itemSelect = newRow.querySelector('.item-select');
    itemSelect.focus();
}

// Calculate amount for a row
function updateRowAmount(row) {
    const qtyInput = row.querySelector('.quantity');
    const weightInput = row.querySelector('.weight');
    const rateInput = row.querySelector('.rate');
    const amountInput = row.querySelector('.amount');
    
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
    
    // Highlight the amount field
    amountInput.classList.add('bg-light');
    setTimeout(() => {
        amountInput.classList.remove('bg-light');
    }, 500);
    
    // Update totals
    const table = row.closest('table');
    calculateTableTotal(table);
    calculateGrandTotal();
}

// Calculate total for a table
function calculateTableTotal(table) {
    let total = 0;
    const rows = table.querySelectorAll('tbody tr');
    
    rows.forEach(row => {
        const amountInput = row.querySelector('.amount');
        total += parseFloat(amountInput.value) || 0;
    });
    
    const subtotalElement = table.querySelector('.invoice-subtotal');
    subtotalElement.textContent = '₹' + total.toFixed(2);
    
    // Highlight the subtotal
    subtotalElement.classList.add('highlight');
    setTimeout(() => {
        subtotalElement.classList.remove('highlight');
    }, 1000);
}

// Calculate grand total across all invoices
function calculateGrandTotal() {
    let grandTotal = 0;
    const subtotals = document.querySelectorAll('.invoice-subtotal');
    
    subtotals.forEach(element => {
        const value = element.textContent.replace('₹', '').replace(',', '');
        grandTotal += parseFloat(value) || 0;
    });
    
    const grandTotalElement = document.getElementById('grand_total');
    grandTotalElement.textContent = '₹' + grandTotal.toFixed(2);
    
    // Highlight the grand total
    grandTotalElement.classList.add('highlight');
    setTimeout(() => {
        grandTotalElement.classList.remove('highlight');
    }, 1000);
}

// Calculate all totals
function calculateAllTotals() {
    document.querySelectorAll('.invoice-items-table').forEach(table => {
        calculateTableTotal(table);
    });
    calculateGrandTotal();
}

// Form validation
document.getElementById('editCombinedForm').addEventListener('submit', function(event) {
    let isValid = true;
    let hasEnabledInvoice = false;
    
    // Check if at least one invoice is enabled
    document.querySelectorAll('.invoice-toggle').forEach(toggle => {
        if (toggle.checked) {
            hasEnabledInvoice = true;
        }
    });
    
    if (!hasEnabledInvoice) {
        event.preventDefault();
        showAlert('Please enable at least one invoice for editing.', 'danger');
        return false;
    }
    
    // Validate enabled invoices
    document.querySelectorAll('.invoice-toggle:checked').forEach(toggle => {
        const invoiceId = toggle.getAttribute('data-invoice-id');
        const section = document.getElementById(`invoice_section_${invoiceId}`);
        const rows = section.querySelectorAll('.item-row');
        
        if (rows.length === 0) {
            isValid = false;
            showAlert(`Invoice #${invoiceId} must have at least one item.`, 'danger');
        }
        
        rows.forEach(row => {
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
    });
    
    if (!isValid) {
        event.preventDefault();
        showAlert('Please fill in all required fields correctly.', 'danger');
        return false;
    }
    
    return true;
});

</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>

<?php
// Flush the output buffer
ob_end_flush();
?>
