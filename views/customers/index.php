<?php
// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../layout/header.php';
require_once __DIR__ . '/../../core/helpers/numbering_helper.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Process Add Customer form
if (isset($_POST['add_customer'])) {
    $name = sanitizeInput($_POST['name']);
    $contact = sanitizeInput($_POST['contact']);
    $location = sanitizeInput($_POST['location']);
    $balance = sanitizeInput($_POST['balance']);
    
    if (!empty($name)) {
        $sql = "INSERT INTO customers (name, contact, location, balance) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssd", $name, $contact, $location, $balance);
        
        if ($stmt->execute()) {
            $success_message = "Customer added successfully!";
        } else {
            $error_message = "Error adding customer: " . $conn->error;
        }
        
        $stmt->close();
    } else {
        $error_message = "Customer name is required!";
    }
}

// Process Payment form
if (isset($_POST['make_payment'])) {
    // Check payment code
    if (!isset($_POST['payment_code']) || empty($_POST['payment_code'])) {
        $error_message = "Payment code is required!";
        goto payment_end;
    }

    // Verify code
    $code_sql = "SELECT payment_secret_code FROM company_settings LIMIT 1";
    $code_result = $conn->query($code_sql);
    $settings = $code_result->fetch_assoc();
    
    if ($_POST['payment_code'] !== $settings['payment_secret_code']) {
        $error_message = "Wrong payment code!";
        goto payment_end;
    }

    $customer_id = sanitizeInput($_POST['customer_id']);
    $amount = sanitizeInput($_POST['amount']);
    $discount = sanitizeInput($_POST['discount']) ?: 0;
    $payment_mode = sanitizeInput($_POST['payment_mode']);
    $receipt_no = sanitizeInput($_POST['receipt_no']);
    $date = date('Y-m-d');
    
    if (!empty($customer_id) && !empty($amount)) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Insert payment record
            $sql = "INSERT INTO customer_payments (customer_id, amount, discount, payment_mode, receipt_no, date) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iddsss", $customer_id, $amount, $discount, $payment_mode, $receipt_no, $date);
            $stmt->execute();
            
            // Update customer balance
            $sql = "UPDATE customers SET balance = balance - ? - ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ddi", $amount, $discount, $customer_id);
            $stmt->execute();
            
            // Commit transaction
            $conn->commit();
            $success_message = "Payment recorded successfully!";
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error_message = "Error recording payment: " . $e->getMessage();
        }
        
        $stmt->close();
    } else {
        $error_message = "All required fields must be filled!";
    }
    
    payment_end:
}

// Process Delete Customer
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $customer_id = sanitizeInput($_GET['delete']);
    
    // Check for related records before deleting
    $check_sql = "SELECT COUNT(*) AS count FROM customer_invoices WHERE customer_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $customer_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $row = $result->fetch_assoc();
    
    if ($row['count'] > 0) {
        $error_message = "Cannot delete customer. There are related invoices.";
    } else {
        $delete_sql = "DELETE FROM customers WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $customer_id);
        
        if ($delete_stmt->execute()) {
            $success_message = "Customer deleted successfully!";
        } else {
            $error_message = "Error deleting customer: " . $conn->error;
        }
        
        $delete_stmt->close();
    }
    $check_stmt->close();
}

// Process Create Invoice form
if (isset($_POST['create_invoice'])) {
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
    $vendor_ids = $_POST['vendor_id'] ?? []; // New array for vendor IDs per item
    $inventory_item_ids = $_POST['inventory_item_id'] ?? []; // New array for inventory item IDs
    $quantities = $_POST['quantity'] ?? [];
    $weights = $_POST['weight'] ?? [];
    $rates = $_POST['rate'] ?? [];
    
    if (!empty($customer_id) && !empty($item_ids) && count($item_ids) > 0) {
        // Generate sequential invoice number starting from 1
        $next_number = getNextInvoiceNumber($conn);
        $invoice_number = formatInvoiceNumber($next_number);
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Insert invoice header with system date and display date
            $sql = "INSERT INTO customer_invoices (customer_id, invoice_number, date, display_date, total_amount) 
                    VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isssd", $customer_id, $invoice_number, $date, $display_date, $total_amount);
            $stmt->execute();
            $invoice_id = $conn->insert_id;
            
            // Store items in customer_invoice_items table
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
                    
                    // New calculation logic:
                    // 1. If weight is provided, use weight × rate (regardless of quantity)
                    // 2. If weight is not provided but quantity is, use quantity × rate
                    if ($weight > 0) {
                        $amount = $weight * $rate;
                    } else if ($qty > 0) {
                        $amount = $qty * $rate;
                    } else {
                        $amount = 0;
                    }
                    
                    $item_stmt->bind_param("iiiidddd", $invoice_id, $item_ids[$i], $vendor_id, $inventory_item_id, $qty, $weight, $rate, $amount);
                    $item_stmt->execute();
                    
                    // Update the specific inventory item - decrease the remaining_stock
                    $update_inventory_sql = "UPDATE inventory_items 
                                           SET remaining_stock = remaining_stock - ? 
                                           WHERE id = ? AND remaining_stock >= ?";
                    $update_inventory_stmt = $conn->prepare($update_inventory_sql);
                    $update_inventory_stmt->bind_param("dii", $qty, $inventory_item_id, $qty);
                    $update_inventory_stmt->execute();
                    
                    // Check if any rows were affected
                    if ($update_inventory_stmt->affected_rows == 0) {
                        throw new Exception("Not enough stock available for the selected inventory item");
                    }
                }
            }
            
            // Update customer balance (add to balance)
            $update_sql = "UPDATE customers SET balance = balance + ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("di", $total_amount, $customer_id);
            $update_stmt->execute();
            
            // Commit transaction
            $conn->commit();
            $success_message = "Invoice created successfully! Invoice #$invoice_number";
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error_message = "Error creating invoice: " . $e->getMessage();
        }
    } else {
        $error_message = "All required fields must be filled!";
    }
}

// Process Edit Customer form
if (isset($_POST['edit_customer'])) {
    $customer_id = sanitizeInput($_POST['customer_id']);
    $name = sanitizeInput($_POST['name']);
    $contact = sanitizeInput($_POST['contact']);
    $location = sanitizeInput($_POST['location']);
    $balance = sanitizeInput($_POST['balance']);
    
    if (!empty($name) && !empty($customer_id)) {
        $sql = "UPDATE customers SET name = ?, contact = ?, location = ?, balance = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssdi", $name, $contact, $location, $balance, $customer_id);
        
        if ($stmt->execute()) {
            $success_message = "Customer updated successfully!";
        } else {
            $error_message = "Error updating customer: " . $conn->error;
        }
        
        $stmt->close();
    } else {
        $error_message = "Customer ID and name are required!";
    }
}

// Get all customers
$sql = "SELECT * FROM customers ORDER BY name";
$result = $conn->query($sql);

// Get totals for dashboard cards
$sql_count = "SELECT COUNT(*) AS total_customers FROM customers";
$count_result = $conn->query($sql_count);
$total_customers = $count_result->fetch_assoc()['total_customers'];

$sql_balance = "SELECT SUM(balance) AS total_balance FROM customers WHERE name != 'Cash'";
$balance_result = $conn->query($sql_balance);
$total_balance = $balance_result->fetch_assoc()['total_balance'] ?? 0;

// Get all vendors for invoice form
$vendors_sql = "SELECT * FROM vendors ORDER BY name";
$vendors_result = $conn->query($vendors_sql);
$vendors = [];
while ($row = $vendors_result->fetch_assoc()) {
    $vendors[] = $row;
}
?>

<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1>Customers</h1>
            <p>Manage your customer information and transactions</p>
        </div>
        <button type="button" class="btn-new-invoice" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
            <i class="fas fa-plus"></i> Add Customer
        </button>
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

    <!-- Stats Cards -->
    <!-- <div class="stats-row">
        <div class="dashboard-card">
            <h5>Total Customers</h5>
            <div class="value"><?php echo $total_customers; ?></div>
            <div class="subtitle"><?php echo $total_customers; ?> Customers</div>
        </div>
        <div class="dashboard-card">
            <h5>Total Balance</h5>
            <div class="value">₹<?php echo number_format($total_balance, 0); ?></div>
            <div class="subtitle">Account Balance</div>
        </div>
    </div> -->

    <!-- Customer List -->
    <div class="dashboard-card mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
            <h5 class="mb-2 mb-md-0">Customer List</h5>
            <div class="search-container">
                <input type="text" id="customerSearch" class="form-control" placeholder="Search customers...">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Name</th>
                        <!-- <th>Contact</th>
                        <th>Location</th> -->
                        <th>
                            <div class="d-flex align-items-center">
                                <span>Balance</span>
                                <button type="button" class="btn btn-sm btn-outline-secondary ms-2" id="balanceSortBtn" title="Sort by Balance">
                                    <i class="fas fa-sort"></i>
                                </button>
                            </div>
                        </th>
                        <th class="actions-column">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($customer = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($customer['name']); ?></td>
                                <!-- <td><?php echo htmlspecialchars($customer['contact'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($customer['location'] ?? 'N/A'); ?></td> -->
                                <td class="<?php echo $customer['balance'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                    ₹<?php echo number_format(abs($customer['balance']), 2); ?>
                                </td>
                                <td>
                                    <div class="d-flex flex-row gap-2 action-buttons align-items-center">
                                        <!-- Primary Actions -->
                                        <button type="button" class="btn btn-primary btn-sm" title="Create Invoice" 
                                                data-bs-toggle="modal" data-bs-target="#invoiceModal" 
                                                data-customer-id="<?php echo $customer['id']; ?>" 
                                                data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>">
                                            <i class="fas fa-file-invoice"></i>
                                        </button>
                                        <button type="button" class="btn btn-success btn-sm" title="Make Payment" 
                                                data-bs-toggle="modal" data-bs-target="#paymentModal" 
                                                data-customer-id="<?php echo $customer['id']; ?>" 
                                                data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                                data-customer-balance="<?php echo $customer['balance']; ?>">
                                            <i class="fas fa-money-bill-wave"></i>
                                        </button>
                                        
                                        <!-- More Options Dropdown -->
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary btn-sm dropdown-toggle-custom" type="button" 
                                                    data-bs-toggle="dropdown" aria-expanded="false" title="More Options">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <a class="dropdown-item" href="#" 
                                                       data-bs-toggle="modal" data-bs-target="#transactionsModal"
                                                       data-customer-id="<?php echo $customer['id']; ?>" 
                                                       data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>">
                                                        <i class="fas fa-history me-2"></i> View Transactions
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item" href="#" 
                                                       data-bs-toggle="modal" data-bs-target="#ledgerModal"
                                                       data-customer-id="<?php echo $customer['id']; ?>" 
                                                       data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>">
                                                        <i class="fas fa-book me-2"></i> View Ledger
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item" href="#" 
                                                       data-bs-toggle="modal" data-bs-target="#printBillsModal"
                                                       data-customer-id="<?php echo $customer['id']; ?>" 
                                                       data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>">
                                                        <i class="fas fa-download me-2"></i> Download All Bills
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item" href="#" 
                                                       data-bs-toggle="modal" data-bs-target="#editCustomerModal"
                                                       data-customer-id="<?php echo $customer['id']; ?>"
                                                       data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                                       data-customer-contact="<?php echo htmlspecialchars($customer['contact'] ?? ''); ?>"
                                                       data-customer-location="<?php echo htmlspecialchars($customer['location'] ?? ''); ?>"
                                                       data-customer-balance="<?php echo $customer['balance']; ?>">
                                                        <i class="fas fa-edit me-2"></i> Edit Customer
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item" href="payments.php?id=<?php echo $customer['id']; ?>">
                                                        <i class="fas fa-cog me-2"></i> Manage Payments
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item text-danger" href="#" 
                                                       data-bs-toggle="modal" data-bs-target="#deleteCustomerModal"
                                                       data-customer-id="<?php echo $customer['id']; ?>"
                                                       data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>">
                                                        <i class="fas fa-trash me-2"></i> Delete Customer
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center">No customers found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-labelledby="addCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addCustomerModalLabel">Add New Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="index.php">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Customer Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="contact" class="form-label">Contact Number</label>
                        <input type="text" class="form-control" id="contact" name="contact">
                    </div>
                    <div class="mb-3">
                        <label for="location" class="form-label">Location</label>
                        <input type="text" class="form-control" id="location" name="location">
                    </div>
                    <div class="mb-3">
                        <label for="balance" class="form-label">Opening Balance</label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" class="form-control" id="balance" name="balance" step="0.01" value="0.00">
                        </div>
                        <div class="form-text">Enter positive value if customer owes you money, negative if you owe money to the customer</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_customer" class="btn btn-primary">Add Customer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Customer Modal -->
<div class="modal fade" id="editCustomerModal" tabindex="-1" aria-labelledby="editCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editCustomerModalLabel">Edit Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="index.php">
                <div class="modal-body">
                    <input type="hidden" name="customer_id" id="edit_customer_id">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Customer Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_contact" class="form-label">Contact Number</label>
                        <input type="text" class="form-control" id="edit_contact" name="contact">
                    </div>
                    <div class="mb-3">
                        <label for="edit_location" class="form-label">Location</label>
                        <input type="text" class="form-control" id="edit_location" name="location">
                    </div>
                    <div class="mb-3">
                        <label for="edit_balance" class="form-label">Balance</label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" class="form-control" id="edit_balance" name="balance" step="0.01">
                        </div>
                        <div class="form-text">Enter positive value if customer owes you money, negative if you owe money to the customer</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_customer" class="btn btn-primary">Update Customer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Customer Modal -->
<div class="modal fade" id="deleteCustomerModal" tabindex="-1" aria-labelledby="deleteCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteCustomerModalLabel">Delete Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-danger fw-bold">This will permanently delete the customer and ALL related data (bills, items, payments). This action cannot be undone.</p>
                <div class="mb-3">
                    <label class="form-label">Customer</label>
                    <input type="text" class="form-control" id="delete_customer_name" readonly>
                    <input type="hidden" id="delete_customer_id">
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="confirm_delete_customer">
                    <label class="form-check-label" for="confirm_delete_customer">
                        I understand this action is irreversible
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="delete_customer_btn" class="btn btn-danger" disabled>Delete</button>
            </div>
        </div>
    </div>
</div>

<script>
// Wire delete customer modal
document.getElementById('deleteCustomerModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const customerId = button.getAttribute('data-customer-id');
    const customerName = button.getAttribute('data-customer-name');
    document.getElementById('delete_customer_id').value = customerId;
    document.getElementById('delete_customer_name').value = customerName;
    document.getElementById('confirm_delete_customer').checked = false;
    document.getElementById('delete_customer_btn').disabled = true;
});

document.getElementById('confirm_delete_customer').addEventListener('change', function() {
    document.getElementById('delete_customer_btn').disabled = !this.checked;
});

document.getElementById('delete_customer_btn').addEventListener('click', function() {
    const customerId = document.getElementById('delete_customer_id').value;
    const confirmed = document.getElementById('confirm_delete_customer').checked;
    if (!confirmed) { return; }
    fetch('../../handlers/customers/delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'customer_id=' + encodeURIComponent(customerId)
    })
    .then(r => r.json())
    .then(data => {
        if (data && data.success) {
            location.reload();
        } else {
            alert('Failed to delete customer: ' + (data && data.error ? data.error : 'Unknown error'));
        }
    })
    .catch(err => {
        alert('Failed to delete customer: ' + err);
    });
});

// Handle dropdown menu items that trigger modals
document.addEventListener('click', function(e) {
    const dropdownItem = e.target.closest('.dropdown-item[data-bs-toggle="modal"]');
    if (dropdownItem) {
        e.preventDefault();
    }
});
</script>

<!-- Make Invoice Modal -->
<div class="modal fade" id="invoiceModal" tabindex="-1" aria-labelledby="invoiceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="invoiceModalLabel">Create Invoice</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="index.php" id="invoiceForm">
                <div class="modal-body">
                    <input type="hidden" name="customer_id" id="invoice_customer_id">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Customer</label>
                            <input type="text" class="form-control" id="invoice_customer_name" readonly>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="use_custom_date" name="use_custom_date">
                                <label class="form-check-label" for="use_custom_date">
                                    Use Custom Date
                                </label>
                            </div>
                            <label for="invoice_date" class="form-label">Date</label>
                            <input type="date" class="form-control" id="invoice_date" name="date" value="<?php echo date('Y-m-d'); ?>" disabled>
                        </div>
                    </div>
                    <div class="mb-3" id="invoice_items_container">
                        <!-- Items will be added dynamically -->
                        <div id="empty_row" class="text-center text-muted py-4 border rounded">
                            Click "Add Item" to start creating your invoice
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-8 offset-md-4">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td class="text-end"><strong>Subtotal:</strong></td>
                                    <td width="150"><span id="invoice_subtotal">₹0.00</span></td>
                                </tr>
                                <tr>
                                    <td class="text-end"><strong>Total Amount:</strong></td>
                                    <td><span id="invoice_total">₹0.00</span></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    <button type="button" id="add_item_button" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-plus"></i> Add Item
                    </button>
                    <input type="hidden" name="total_amount" id="total_amount" value="0">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_invoice" class="btn btn-primary">Create Invoice</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Make Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="paymentModalLabel">Make Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="index.php">
                <div class="modal-body">
                    <input type="hidden" name="customer_id" id="payment_customer_id">
                    <div class="mb-3">
                        <label class="form-label">Customer</label>
                        <input type="text" class="form-control" id="payment_customer_name" readonly>
                    </div>
                    <div class="mb-3">
                        <label for="ledger_balance" class="form-label">Ledger Balance</label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="text" class="form-control" id="ledger_balance" readonly>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="amount" class="form-label">Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" class="form-control" id="amount" name="amount" step="0.01" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="discount" class="form-label">Discount</label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" class="form-control" id="discount" name="discount" step="0.01" value="0.00">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="payment_mode" class="form-label">Transaction Mode <span class="text-danger">*</span></label>
                        <select class="form-select" id="payment_mode" name="payment_mode" required>
                            <option value="Cash">Cash</option>
                            <option value="Bank">Account Transfer</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="receipt_no" class="form-label">Receipt No.</label>
                        <input type="text" class="form-control" id="receipt_no" name="receipt_no">
                    </div>
                    <div class="mb-3">
                        <label for="payment_code" class="form-label">Payment Security Code <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="payment_code" name="payment_code" required>
                        <div class="form-text">Enter the security code to authorize this payment</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="make_payment" class="btn btn-primary">Submit Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Transactions Modal -->
<div class="modal fade" id="transactionsModal" tabindex="-1" aria-labelledby="transactionsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="transactionsModalLabel">Customer Transactions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="transactions_content">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- View Ledger Modal -->
<div class="modal fade" id="ledgerModal" tabindex="-1" aria-labelledby="ledgerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="ledgerModalLabel">Customer Ledger</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="ledger_content">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a id="export_customer_ledger_pdf" href="#" target="_blank" class="btn btn-primary">
                    <i class="fas fa-file-pdf"></i> Save as PDF
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Print All Bills Modal -->
<div class="modal fade" id="printBillsModal" tabindex="-1" aria-labelledby="printBillsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="printBillsModalLabel">Download All Bills</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="print_bills_customer_id">
                <div class="mb-3">
                    <label class="form-label">Customer</label>
                    <input type="text" class="form-control" id="print_bills_customer_name" readonly>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" required>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="print_bills_btn" class="btn btn-primary">Download Bills</button>
            </div>
        </div>
    </div>
</div>

<script>
    // Customer search functionality
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('customerSearch');
        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const searchValue = this.value.toLowerCase().trim();
                const tableRows = document.querySelectorAll('.table tbody tr');
                
                tableRows.forEach(row => {
                    const customerName = row.cells[0].textContent.toLowerCase();
                    const customerContact = row.cells[1].textContent.toLowerCase();
                    const customerLocation = row.cells[2].textContent.toLowerCase();
                    
                    if (customerName.includes(searchValue) || 
                        customerContact.includes(searchValue) || 
                        customerLocation.includes(searchValue)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        }

        // Balance column sorting functionality
        let balanceSortDirection = 'asc'; // Track current sort direction
        
        const balanceSortBtn = document.getElementById('balanceSortBtn');
        if (balanceSortBtn) {
            balanceSortBtn.addEventListener('click', function() {
                // Toggle sort direction
                balanceSortDirection = balanceSortDirection === 'asc' ? 'desc' : 'asc';
                
                // Update button icon
                const icon = this.querySelector('i');
                if (balanceSortDirection === 'asc') {
                    icon.className = 'fas fa-sort-up';
                    this.title = 'Sort by Balance (Ascending)';
                } else {
                    icon.className = 'fas fa-sort-down';
                    this.title = 'Sort by Balance (Descending)';
                }
                
                // Get all table rows
                const tbody = document.querySelector('.table tbody');
                const rows = Array.from(tbody.querySelectorAll('tr'));
                
                // Sort rows based on balance column
                rows.sort((a, b) => {
                    const aBalanceText = a.cells[1].textContent.trim(); // Balance is in second column (index 1)
                    const bBalanceText = b.cells[1].textContent.trim();
                    
                    // Extract numeric value from balance text (remove ₹ and commas)
                    const aBalance = parseFloat(aBalanceText.replace(/[₹,\s]/g, '')) || 0;
                    const bBalance = parseFloat(bBalanceText.replace(/[₹,\s]/g, '')) || 0;
                    
                    if (balanceSortDirection === 'asc') {
                        return aBalance - bBalance;
                    } else {
                        return bBalance - aBalance;
                    }
                });
                
                // Re-append sorted rows
                rows.forEach(row => {
                    tbody.appendChild(row);
                });
            });
        }
    });

    // Edit Customer Modal Functionality
    document.getElementById('editCustomerModal').addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        const customerId = button.getAttribute('data-customer-id');
        const customerName = button.getAttribute('data-customer-name');
        const customerContact = button.getAttribute('data-customer-contact');
        const customerLocation = button.getAttribute('data-customer-location');
        const customerBalance = button.getAttribute('data-customer-balance');
        
        document.getElementById('edit_customer_id').value = customerId;
        document.getElementById('edit_name').value = customerName;
        document.getElementById('edit_contact').value = customerContact;
        document.getElementById('edit_location').value = customerLocation;
        document.getElementById('edit_balance').value = customerBalance;
    });

    // Payment Modal Functionality
    document.getElementById('paymentModal').addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        const customerId = button.getAttribute('data-customer-id');
        const customerName = button.getAttribute('data-customer-name');
        const customerBalance = button.getAttribute('data-customer-balance');
        
        document.getElementById('payment_customer_id').value = customerId;
        document.getElementById('payment_customer_name').value = customerName;
        
        // Format the balance display (show absolute value with decimal points)
        const balanceValue = Math.abs(parseFloat(customerBalance)).toFixed(2);
        const balanceText = '₹' + balanceValue;
        document.getElementById('ledger_balance').value = balanceText;
    });

    // Invoice Modal Functionality
    document.getElementById('invoiceModal').addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        const customerId = button.getAttribute('data-customer-id');
        const customerName = button.getAttribute('data-customer-name');
        
        document.getElementById('invoice_customer_id').value = customerId;
        document.getElementById('invoice_customer_name').value = customerName;
        
        document.getElementById('empty_row').style.display = '';
        
        // Clear any previous items
        const rows = document.querySelectorAll('#invoice_items_table tbody tr:not(#empty_row)');
        rows.forEach(row => row.remove());
        
        // Reset total
        document.getElementById('invoice_total').innerText = '₹0.00';
        document.getElementById('total_amount').value = '0';
    });

    // Function to load vendors with inventory
    function loadVendorsWithInventory(vendorSelect, previousVendorId = null) {
        // Show loading state
        vendorSelect.innerHTML = '<option value="">Loading vendors...</option>';
        
        // Load vendors with inventory via AJAX
        fetch('../../api/vendors/get_with_stock.php')
            .then(response => response.json())
            .then(data => {
                let optionsHtml = '<option value="">Select Vendor</option>';
                
                if (data.vendors && data.vendors.length > 0) {
                    data.vendors.forEach(vendor => {
                        optionsHtml += `<option value="${vendor.id}">${vendor.name} (${vendor.total_items} items, ${vendor.total_stock} stock)</option>`;
                    });
                    vendorSelect.innerHTML = optionsHtml;
                    
                    // Auto-select previous vendor if provided and exists in the list
                    if (previousVendorId) {
                        console.log('Attempting to auto-select vendor:', previousVendorId);
                        const option = vendorSelect.querySelector(`option[value="${previousVendorId}"]`);
                        if (option) {
                            console.log('Found vendor option, setting value and triggering change event');
                            vendorSelect.value = previousVendorId;
                            
                            // Trigger the change event to load items for this vendor
                            const changeEvent = new Event('change', { bubbles: true });
                            vendorSelect.dispatchEvent(changeEvent);
                        } else {
                            console.log('Vendor option not found in dropdown');
                        }
                    }
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
function loadVendorItems(vendorId, itemSelect) {
    if (!vendorId) return;
    
    // Show loading state
    itemSelect.innerHTML = '<option value="">Loading items...</option>';
            
            // Load items for this vendor via AJAX
            fetch('../../api/vendors/get_items.php?vendor_id=' + vendorId)
                .then(response => response.json())
                .then(data => {
            let optionsHtml = '<option value="">Select Item</option>';
            
            if (data.items && data.items.length > 0) {
                data.items.forEach(item => {
                    optionsHtml += `<option value="${item.id}" 
                        data-stock="${item.available_stock}"
                        data-rate="${item.last_rate || ''}"
                        data-inventory-item-id="${item.inventory_item_id}"
                        data-date-received="${item.date_received}"
                    >${item.name} (Stock: ${item.available_stock})</option>`;
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

    // Add item row in invoice
    document.getElementById('add_item_button').addEventListener('click', function() {
        document.getElementById('empty_row').style.display = 'none';
        
        const container = document.getElementById('invoice_items_container');
        
        const newItem = document.createElement('div');
        newItem.classList.add('invoice-item-card', 'border', 'rounded', 'p-3', 'mb-3', 'position-relative');
        newItem.innerHTML = `
            <button type="button" class="btn btn-sm btn-danger remove-item position-absolute top-0 end-0 m-2">
                <i class="fas fa-times"></i>
            </button>
            
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Item Name <span class="text-danger">*</span></label>
                    <select name="item_id[]" class="form-select item-select" required>
                        <option value="">Select Item</option>
                    </select>
                    <input type="hidden" name="inventory_item_id[]" class="inventory-item-id">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Vendor <span class="text-danger">*</span></label>
                    <select name="vendor_id[]" class="form-select vendor-select" required>
                        <option value="">Select Vendor</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Quantity <span class="text-danger">*</span></label>
                    <input type="number" name="quantity[]" class="form-control quantity" value="" step="0.01" min="0.01" required>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-4">
                    <label class="form-label">Weight (Optional)</label>
                    <input type="number" name="weight[]" class="form-control weight" value="" step="0.01" min="0">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Rate <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">₹</span>
                        <input type="number" name="rate[]" class="form-control rate" value="" step="0.01" min="0.01" required>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Amount</label>
                    <div class="input-group">
                        <span class="input-group-text">₹</span>
                        <input type="number" name="amount[]" class="form-control amount" value="0" step="0.01" readonly>
                    </div>
                </div>
            </div>
        `;
        
        container.appendChild(newItem);
        
        // Get reference to the vendor select and item select
        const vendorSelect = newItem.querySelector('.vendor-select');
        const itemSelect = newItem.querySelector('.item-select');
        
        // Auto-select vendor from previous row if available
        const existingItems = container.querySelectorAll('.invoice-item-card');
        let previousVendorId = null;
        if (existingItems.length > 1) {
            const previousItem = existingItems[existingItems.length - 2];
            const previousVendorSelect = previousItem.querySelector('.vendor-select');
            if (previousVendorSelect && previousVendorSelect.value) {
                previousVendorId = previousVendorSelect.value;
                console.log('Found previous vendor ID:', previousVendorId);
            }
        }
        
        // Load vendors with inventory and auto-select if needed
        loadVendorsWithInventory(vendorSelect, previousVendorId);
        
        // Add vendor selection functionality
        vendorSelect.addEventListener('change', function() {
            const selectedVendorId = this.value;
            console.log('Vendor selection changed to:', selectedVendorId);
            if (selectedVendorId) {
                loadVendorItems(selectedVendorId, itemSelect);
            } else {
                itemSelect.innerHTML = '<option value="">Select Item</option>';
            }
        });
        
        // Get references to all inputs in this card
        const qtyInput = newItem.querySelector('.quantity');
        const weightInput = newItem.querySelector('.weight');
        const rateInput = newItem.querySelector('.rate');
        const amountInput = newItem.querySelector('.amount');
        const removeBtn = newItem.querySelector('.remove-item');
        
        // Function to calculate row amount
        function updateRowAmount() {
            const qty = parseFloat(qtyInput.value) || 0;
            const weight = parseFloat(weightInput.value) || 0;
            const rate = parseFloat(rateInput.value) || 0;
            
            let amount = 0;
            
            // If weight is provided, use weight × rate
            // If weight is not provided but quantity is, use quantity × rate
            if (weight > 0) {
                amount = weight * rate;
            } else if (qty > 0) {
                amount = qty * rate;
            }
            
            amountInput.value = amount.toFixed(2);
            
            // Update totals
            updateTotals();
        }
        
        // Add event listeners to all inputs
        qtyInput.addEventListener('input', updateRowAmount);
        weightInput.addEventListener('input', updateRowAmount);
        rateInput.addEventListener('input', updateRowAmount);
        
        // Item selection handler
        itemSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const inventoryItemIdField = newItem.querySelector('.inventory-item-id');
            
            if (selectedOption.value) {
                const availableStock = parseFloat(selectedOption.getAttribute('data-stock'));
                const lastRate = selectedOption.getAttribute('data-rate');
                const inventoryItemId = selectedOption.getAttribute('data-inventory-item-id');
                
                // Set the inventory item ID in hidden field
                inventoryItemIdField.value = inventoryItemId;
                
                // Set max quantity to available stock
                qtyInput.max = availableStock;
                qtyInput.placeholder = `Max: ${availableStock}`;
                
                // Set last rate if available
                if (lastRate) {
                    rateInput.value = lastRate;
                }
                
                // Default value for quantity if empty
                if (!qtyInput.value) qtyInput.value = "1";
                
                // Calculate initial amount
                updateRowAmount();
                
                // Focus on quantity field for easy input
                qtyInput.focus();
            } else {
                // Clear fields if no item is selected
                inventoryItemIdField.value = '';
                rateInput.value = '';
                qtyInput.value = '';
                weightInput.value = '';
                amountInput.value = '';
                updateTotals();
            }
        });
        
        // Validate quantity against available stock
        qtyInput.addEventListener('input', function() {
            const selectedOption = itemSelect.options[itemSelect.selectedIndex];
            if (selectedOption.value) {
                const availableStock = parseFloat(selectedOption.getAttribute('data-stock'));
                const enteredQty = parseFloat(this.value) || 0;
                
                if (enteredQty > availableStock) {
                    alert(`Maximum available stock is ${availableStock}`);
                    this.value = availableStock;
                }
            }
            updateRowAmount();
        });
        
        // Remove item handler
        removeBtn.addEventListener('click', function() {
            newItem.remove();
            updateTotals();
            
            const remainingItems = container.querySelectorAll('.invoice-item-card');
            if (remainingItems.length === 0) {
                document.getElementById('empty_row').style.display = '';
            }
        });
    });

    // Function to update totals
    function updateTotals() {
        let subtotal = 0;
        const amounts = document.querySelectorAll('.invoice-item-card .amount');
        
        amounts.forEach(input => {
            subtotal += parseFloat(input.value) || 0;
        });
        
        // Update subtotal display
        document.getElementById('invoice_subtotal').innerText = '₹' + subtotal.toFixed(2);
        
        // For now, total is same as subtotal
        const total = subtotal;
        
        document.getElementById('invoice_total').innerText = '₹' + total.toFixed(2);
        document.getElementById('total_amount').value = total.toFixed(2);
    }

    // Load transactions data
    document.getElementById('transactionsModal').addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        const customerId = button.getAttribute('data-customer-id');
        const customerName = button.getAttribute('data-customer-name');
        
        this.querySelector('.modal-title').textContent = `Transactions - ${customerName}`;
        
        // Load transactions via AJAX
        fetch('../../api/customers/get_transactions.php?customer_id=' + customerId)
            .then(response => response.json())
            .then(data => {
                let html = `
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Discount</th>
                                    <th>Mode</th>
                                    <th>Receipt No</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                if (data.success && data.transactions.length > 0) {
                    data.transactions.forEach(transaction => {
                        html += `
                            <tr>
                                <td>${transaction.date}</td>
                                <td>₹${parseFloat(transaction.amount).toFixed(2)}</td>
                                <td>₹${parseFloat(transaction.discount).toFixed(2)}</td>
                                <td>${transaction.payment_mode}</td>
                                <td>${transaction.receipt_no || '-'}</td>
                            </tr>
                        `;
                    });
                } else {
                    html += `<tr><td colspan="5" class="text-center">No transactions found</td></tr>`;
                }
                
                html += `
                            </tbody>
                        </table>
                    </div>
                `;
                
                document.getElementById('transactions_content').innerHTML = html;
            })
            .catch(error => {
                console.error('Error loading transactions:', error);
                document.getElementById('transactions_content').innerHTML = `
                    <div class="alert alert-danger">
                        Failed to load transactions. Please try again.
                    </div>
                `;
            });
    });

    // Load ledger data
    document.getElementById('ledgerModal').addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        const customerId = button.getAttribute('data-customer-id');
        const customerName = button.getAttribute('data-customer-name');
        
        this.querySelector('.modal-title').textContent = `Ledger - ${customerName}`;
        
        // Save customer ID for PDF export
        this.setAttribute('data-customer-id', customerId);
        
        // Load ledger via AJAX
        fetch('../../api/customers/get_ledger.php?customer_id=' + customerId)
            .then(response => response.json())
            .then(data => {
                let html = `
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th>Debit</th>
                                    <th>Credit</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                if (data.success && data.ledger.length > 0) {
                    data.ledger.forEach(entry => {
                        html += `
                            <tr>
                                <td>${entry.date}</td>
                                <td>${entry.description}</td>
                                <td class="text-end">${entry.debit ? '₹' + entry.debit : ''}</td>
                                <td class="text-end">${entry.credit ? '₹' + entry.credit : ''}</td>
                                <td class="text-end">₹${entry.balance}</td>
                                <td class="${entry.balance_type === 'Receivable from Customer' ? 'text-success' : 'text-danger'}">${entry.balance_type}</td>
                            </tr>
                        `;
                    });
                } else {
                    html += `<tr><td colspan="6" class="text-center">No ledger entries found</td></tr>`;
                }
                
                html += `
                            </tbody>
                        </table>
                    </div>
                `;
                
                document.getElementById('ledger_content').innerHTML = html;
            })
            .catch(error => {
                console.error('Error loading ledger:', error);
                document.getElementById('ledger_content').innerHTML = `
                    <div class="alert alert-danger">
                        Failed to load ledger. Please try again.
                    </div>
                `;
            });
    });
    
    // Handle Customer Ledger PDF Export
    document.getElementById('export_customer_ledger_pdf').addEventListener('click', function(e) {
        e.preventDefault();
        const customerId = document.getElementById('ledgerModal').getAttribute('data-customer-id');
        if (customerId) {
            const url = `../../handlers/customers/download_ledger.php?customer_id=${customerId}`;
            window.open(url, '_blank');
        } else {
            alert('Customer information is missing. Please try again.');
        }
    });
    
    // Print Bills Modal Functionality
    document.getElementById('printBillsModal').addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        const customerId = button.getAttribute('data-customer-id');
        const customerName = button.getAttribute('data-customer-name');
        
        document.getElementById('print_bills_customer_id').value = customerId;
        document.getElementById('print_bills_customer_name').value = customerName;
        
        // Set default date range (last 30 days)
        const today = new Date();
        const thirtyDaysAgo = new Date();
        thirtyDaysAgo.setDate(today.getDate() - 30);
        
        document.getElementById('end_date').value = today.toISOString().split('T')[0];
        document.getElementById('start_date').value = thirtyDaysAgo.toISOString().split('T')[0];
    });
    
    // Handle Print Bills Button
    document.getElementById('print_bills_btn').addEventListener('click', function() {
        const customerId = document.getElementById('print_bills_customer_id').value;
        const startDate = document.getElementById('start_date').value;
        const endDate = document.getElementById('end_date').value;
        
        if (!customerId || !startDate || !endDate) {
            alert('Please fill in all required fields.');
            return;
        }
        
        const url = `../../handlers/customers/download_bills.php?customer_id=${customerId}&start_date=${startDate}&end_date=${endDate}`;
        window.open(url, '_blank');
        
        // Close the modal
        const modal = bootstrap.Modal.getInstance(document.getElementById('printBillsModal'));
        modal.hide();
    });

    // Form validation for invoice submission
    document.getElementById('invoiceForm').addEventListener('submit', function(event) {
        const items = document.querySelectorAll('#invoice_items_table tbody tr:not(#empty_row)');
        if (items.length === 0) {
            event.preventDefault();
            alert('Please add at least one item to the invoice.');
            return false;
        }
        
        // Check total amount
        const total = parseFloat(document.getElementById('total_amount').value) || 0;
        if (total <= 0) {
            event.preventDefault();
            alert('Total amount must be greater than zero.');
            return false;
        }
        
    // Check that all items have a vendor and item selected
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
        dateInput.value = '<?php echo date('Y-m-d'); ?>'; // Reset to current date
    }
});

// Initialize date field state when modal opens
document.getElementById('invoiceModal').addEventListener('show.bs.modal', function() {
    const dateCheckbox = document.getElementById('use_custom_date');
    const dateInput = document.getElementById('invoice_date');
    
    // Reset checkbox and date field
    dateCheckbox.checked = false;
    dateInput.disabled = true;
    dateInput.required = false;
    dateInput.value = '<?php echo date('Y-m-d'); ?>';
    });
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>