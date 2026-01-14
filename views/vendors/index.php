<?php
// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/helpers/feature_helper.php';
require_once __DIR__ . '/../layout/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Check if category column should be shown
$show_category = show_category_column();
$features = get_feature_settings();

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed: " . ($conn->connect_error ?? 'Unknown error'));
}

// Process Add Vendor form
if (isset($_POST['add_vendor'])) {
    $name = $_POST['name'] ?? '';
    $balance = $_POST['balance'] ?? 0;
    $type = $_POST['type'] ?? '';
    $vendor_category = $_POST['vendor_category'] ?? '';
    $shortcut_code = $_POST['shortcut_code'] ?? '';
    
    if (!empty($name)) {
        // Sanitize and validate input
        $name = trim(htmlspecialchars($name));
        $balance = floatval($balance);
        $type = trim(htmlspecialchars($type));
        $vendor_category = trim(htmlspecialchars($vendor_category));
        $shortcut_code = trim(strtolower(htmlspecialchars($shortcut_code)));
        
        // Include optional shortcut_code if provided
        if (!empty($shortcut_code)) {
            $sql = "INSERT INTO vendors (name, balance, type, vendor_category, shortcut_code) VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sdsss", $name, $balance, $type, $vendor_category, $shortcut_code);
        } else {
            $sql = "INSERT INTO vendors (name, balance, type, vendor_category) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sdss", $name, $balance, $type, $vendor_category);
        }
        
        if ($stmt->execute()) {
            $success_message = "Vendor added successfully!";
        } else {
            $error_message = "Error adding vendor: " . $conn->error;
        }
        
        $stmt->close();
    } else {
        $error_message = "Vendor name is required!";
    }
}

// Process Invoice Creation
if (isset($_POST['create_invoice']) && isset($_POST['action']) && $_POST['action'] === 'create_invoice') {
    $vendor_id = $_POST['vendor_id'] ?? '';
    $invoice_number = $_POST['invoice_number'] ?? '';
    $invoice_date = $_POST['invoice_date'] ?? '';
    $item_ids = $_POST['item_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $weights = $_POST['weight'] ?? [];
    $rates = $_POST['rate'] ?? [];
    
    if (!empty($vendor_id) && !empty($invoice_number) && !empty($invoice_date) && !empty($item_ids)) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Sanitize inputs
            $vendor_id = intval($vendor_id);
            $invoice_number = trim(htmlspecialchars($invoice_number));
            $invoice_date = trim(htmlspecialchars($invoice_date));
            
            // Check if invoice number already exists
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
            
            // Insert invoice record
            $sql = "INSERT INTO vendor_invoices (vendor_id, invoice_number, invoice_date, total_amount, payment_status) VALUES (?, ?, ?, 0, 'pending')";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iss", $vendor_id, $invoice_number, $invoice_date);
            $stmt->execute();
            $invoice_id = $conn->insert_id;
            $stmt->close();
            
            // Insert invoice items and calculate total amount
            $total_amount = 0;
            
            foreach ($item_ids as $key => $item_id) {
                if (empty($item_id)) continue; // Skip empty items
                
                $item_id = intval($item_id);
                $quantity = floatval($quantities[$key]);
                $weight = floatval($weights[$key]);
                $rate = floatval($rates[$key]);
                
                // Calculate amount based on weight or quantity
                if ($weight > 0) {
                    $amount = $weight * $rate;
                } else {
                    $amount = $quantity * $rate;
                }
                
                $total_amount += $amount;
                
                // Insert invoice item
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
            
            // Update vendor balance
            $sql = "UPDATE vendors SET balance = balance + ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("di", $total_amount, $vendor_id);
            $stmt->execute();
            $stmt->close();
            
            // Commit transaction
            $conn->commit();
            $success_message = "Purchase invoice created successfully!";
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error_message = "Error creating invoice: " . $e->getMessage();
        } catch (Error $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error_message = "Error creating invoice: " . $e->getMessage();
        }
    } else {
        $error_message = "All required fields must be filled!";
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

    $vendor_id = $_POST['vendor_id'] ?? '';
    $amount = $_POST['amount'] ?? '';
    $discount = $_POST['discount'] ?? 0;
    $payment_mode = $_POST['payment_mode'] ?? '';
    $receipt_no = $_POST['receipt_no'] ?? '';
    $selected_invoices = $_POST['selected_invoices'] ?? '';
    $payment_type = $_POST['payment_type'] ?? 'general';
    $date = date('Y-m-d');
    
    if (!empty($vendor_id) && !empty($amount)) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            $invoice_ids = [];
            if ($payment_type === 'specific' && !empty($selected_invoices)) {
                $invoice_ids = explode(',', $selected_invoices);
                $invoice_ids = array_filter(array_map('intval', $invoice_ids));
            }
            
            // Sanitize and validate input
            $vendor_id = intval($vendor_id);
            $amount = floatval($amount);
            $discount = floatval($discount);
            $payment_mode = trim(htmlspecialchars($payment_mode));
            $receipt_no = trim(htmlspecialchars($receipt_no));
            $date = trim(htmlspecialchars($date));
            
            // Insert payment record
            if (!empty($invoice_ids)) {
                // If specific invoices are selected, create separate payment records for each
                $payment_amount_per_invoice = ($amount + $discount) / count($invoice_ids);
                
                foreach ($invoice_ids as $invoice_id) {
                    $sql = "INSERT INTO vendor_payments (vendor_id, amount, discount, payment_mode, receipt_no, date) 
                            VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $zero_discount = 0.00;
                    $stmt->bind_param("iddsss", $vendor_id, $payment_amount_per_invoice, $zero_discount, $payment_mode, $receipt_no, $date);
                    $stmt->execute();
                    
                    // Mark invoice as paid
                    $sql = "UPDATE vendor_invoices SET payment_status = 'paid' WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $invoice_id);
                    $stmt->execute();
                }
            } else {
                // General payment - no specific invoice
            $sql = "INSERT INTO vendor_payments (vendor_id, amount, discount, payment_mode, receipt_no, date) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iddsss", $vendor_id, $amount, $discount, $payment_mode, $receipt_no, $date);
            $stmt->execute();
            }
            
            // Update vendor balance
            $sql = "UPDATE vendors SET balance = balance - ? - ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ddi", $amount, $discount, $vendor_id);
            $stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            $success_message = "Payment recorded successfully!";
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error_message = "Error recording payment: " . $e->getMessage();
        } catch (Error $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error_message = "Error recording payment: " . $e->getMessage();
        }
        
        if (isset($stmt)) {
            $stmt->close();
        }
    } else {
        $error_message = "All required fields must be filled!";
    }
    
    payment_end:
}

// Get all vendors
$sql = "SELECT * FROM vendors ORDER BY name";
$result = $conn->query($sql);

// Get totals for dashboard cards
$sql_count = "SELECT COUNT(*) AS total_vendors FROM vendors";
$count_result = $conn->query($sql_count);
$total_vendors = $count_result->fetch_assoc()['total_vendors'];

$sql_balance = "SELECT SUM(balance) AS total_balance FROM vendors";
$balance_result = $conn->query($sql_balance);
$total_balance = $balance_result->fetch_assoc()['total_balance'] ?? 0;

// Get pre-selected item if any
$selected_item = isset($_GET['select_item']) ? intval($_GET['select_item']) : 0;

// Function to get unpaid invoices for a vendor
function getUnpaidInvoices($conn, $vendor_id) {
    $sql = "SELECT id, invoice_number, invoice_date, total_amount, 
            COALESCE(paid_amount, 0) as paid_amount,
            (total_amount - COALESCE(paid_amount, 0)) as remaining_amount
            FROM vendor_invoices 
            WHERE vendor_id = ? 
            AND (total_amount - COALESCE(paid_amount, 0)) > 0
            ORDER BY invoice_date ASC, id ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    return $stmt->get_result();
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
            <h1>Vendors</h1>
            <p>Manage your vendor information and transactions</p>
        </div>
        <button type="button" class="btn-new-invoice" data-bs-toggle="modal" data-bs-target="#addVendorModal">
            <i class="fas fa-plus"></i> Add Vendor
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

    <!-- Dashboard Cards -->
    <!-- <div class="row mb-4">
        <div class="col-md-6">
            <div class="dashboard-card">
                <h5>Total Vendors</h5>
                <div class="value"><?php echo number_format($total_vendors); ?></div>   
            </div>
        </div>
        <div class="col-md-6">
            <div class="dashboard-card">
                <h5>Total Balance</h5>
                <div class="value">₹<?php 
    $balance = $total_balance;
    $decimal_part = $balance - floor($balance);
    if ($decimal_part >= 0.5) {
        $balance = ceil($balance);
    } else {
        $balance = floor($balance);
    }
    echo number_format($balance, 0); 
?></div>
            </div>
        </div>
    </div> -->

    <!-- Vendors Table -->
    <div class="dashboard-card mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
            <h5 class="mb-2 mb-md-0">Vendor List</h5>
            <div class="search-container">
                <input type="text" id="vendorSearch" class="form-control" placeholder="Search vendors...">
            </div>
        </div>

    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>
                        <div class="d-flex align-items-center">
                            <span>Type</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary ms-2" id="typeSortBtn" title="Sort by Type">
                                <i class="fas fa-sort"></i>
                            </button>
                        </div>
                    </th>
                    <?php if ($show_category): ?>
                    <th>
                        <div class="d-flex align-items-center">
                            <span>Category</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary ms-2" id="categorySortBtn" title="Sort by Category">
                                <i class="fas fa-sort"></i>
                            </button>
                        </div>
                    </th>
                    <?php endif; ?>
                    <th>Balance</th>
                    <th class="actions-column">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($vendor = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($vendor['name']); ?></td>
                            <td><?php echo htmlspecialchars($vendor['type']); ?></td>
                            <?php if ($show_category): ?>
                            <td><?php echo htmlspecialchars($vendor['vendor_category'] ?? 'Local'); ?></td>
                            <?php endif; ?>
                            <td>₹<?php 
    $balance = $vendor['balance'];
    $decimal_part = $balance - floor($balance);
    if ($decimal_part >= 0.5) {
        $balance = ceil($balance);
    } else {
        $balance = floor($balance);
    }
    echo number_format($balance, 0); 
?></td>
                            <td>
                                <div class="d-flex flex-row gap-2 action-buttons align-items-center">
                                    <!-- Primary Actions -->
                                    <?php if ($vendor['vendor_category'] === 'Purchase Based'): ?>
                                    <button type="button" class="btn btn-primary btn-sm" title="Create Invoice" 
                                            data-bs-toggle="modal" data-bs-target="#createInvoiceModal"
                                            data-vendor-id="<?php echo $vendor['id']; ?>"
                                            data-vendor-name="<?php echo htmlspecialchars($vendor['name']); ?>"
                                            data-vendor-category="<?php echo htmlspecialchars($vendor['vendor_category']); ?>">
                                        <i class="fas fa-file-invoice"></i>
                                    </button>
                                    <?php else: ?>
                                    <button type="button" class="btn btn-primary btn-sm" title="Create Watak" 
                                            data-bs-toggle="modal" data-bs-target="#watakModal"
                                            data-vendor-id="<?php echo $vendor['id']; ?>"
                                            data-vendor-name="<?php echo htmlspecialchars($vendor['name']); ?>"
                                            data-vendor-type="<?php echo htmlspecialchars($vendor['type']); ?>">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <button type="button" class="btn btn-success btn-sm" title="Make Payment" 
                                            data-bs-toggle="modal" data-bs-target="#paymentModal" 
                                            data-vendor-id="<?php echo $vendor['id']; ?>" 
                                            data-vendor-name="<?php echo htmlspecialchars($vendor['name']); ?>"
                                            data-vendor-balance="<?php echo $vendor['balance']; ?>"
                                            data-vendor-category="<?php echo htmlspecialchars($vendor['vendor_category'] ?? 'Commission Based'); ?>">
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
                                                   onclick="window.open('../../handlers/vendors/download_ledger.php?vendor_id=<?php echo $vendor['id']; ?>', '_blank'); return false;">
                                                    <i class="fas fa-book me-2"></i> View Ledger
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="#" 
                                                   data-bs-toggle="modal" 
                                                   data-bs-target="<?php echo $vendor['vendor_category'] === 'Purchase Based' ? '#downloadInvoicesModal' : '#downloadWataksModal'; ?>" 
                                                   data-vendor-id="<?php echo $vendor['id']; ?>" 
                                                   data-vendor-name="<?php echo htmlspecialchars($vendor['name']); ?>">
                                                    <i class="fas fa-download me-2"></i> Download All <?php echo $vendor['vendor_category'] === 'Purchase Based' ? 'Invoices' : 'Wataks'; ?>
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item" href="#" 
                                                   data-bs-toggle="modal" data-bs-target="#editVendorModal"
                                                   data-vendor-id="<?php echo $vendor['id']; ?>"
                                                   data-vendor-name="<?php echo htmlspecialchars($vendor['name']); ?>"
                                                   data-vendor-type="<?php echo htmlspecialchars($vendor['type']); ?>"
                                                   data-vendor-category="<?php echo htmlspecialchars($vendor['vendor_category'] ?? 'Local'); ?>"
                                                   data-vendor-balance="<?php echo $vendor['balance']; ?>">
                                                    <i class="fas fa-edit me-2"></i> Edit Vendor
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="payments.php?id=<?php echo $vendor['id']; ?>">
                                                    <i class="fas fa-cog me-2"></i> Manage Payments
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" 
                                                   data-bs-toggle="modal" data-bs-target="#deleteVendorModal"
                                                   data-vendor-id="<?php echo $vendor['id']; ?>"
                                                   data-vendor-name="<?php echo htmlspecialchars($vendor['name']); ?>">
                                                    <i class="fas fa-trash me-2"></i> Delete Vendor
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
                        <td colspan="5" class="text-center">No vendors found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Vendor Modal -->
<div class="modal fade" id="addVendorModal" tabindex="-1" aria-labelledby="addVendorModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addVendorModalLabel">Add New Vendor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="index.php">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Vendor Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="shortcut_code" class="form-label">Shortcut Code</label>
                        <input type="text" class="form-control" id="shortcut_code" name="shortcut_code" placeholder="e.g., ib for Ibrahim, ar for Abdul Rehman">
                        <div class="form-text">Lowercase, no spaces. Used by AI to map vendor codes (ib, ar, etc.).</div>
                    </div>
                    <div class="mb-3">
                        <label for="type" class="form-label">Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="type" name="type" required>
                            <option value="Local">Local</option>
                            <option value="Outsider">Outsider</option>
                        </select>
                    </div>
                    <?php if ($show_category): ?>
                    <div class="mb-3">
                         <label for="vendor_category" class="form-label">Category <span class="text-danger">*</span></label>
                         <select class="form-select" id="vendor_category" name="vendor_category" required>
                             <?php if ($features['commission']): ?>
                             <option value="Commission Based">Commission Based</option>
                             <?php endif; ?>
                             <?php if ($features['purchase']): ?>
                             <option value="Purchase Based">Purchase Based</option>
                             <?php endif; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label for="balance" class="form-label">Opening Balance</label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" class="form-control" id="balance" name="balance" step="0.01" value="0.00">
                        </div>
                        <div class="form-text">Enter positive value if you owe money to the vendor, negative if vendor owes you money</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_vendor" class="btn btn-primary">Add Vendor</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Make Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="paymentModalLabel">Make Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="index.php">
                <div class="modal-body">
                    <input type="hidden" name="vendor_id" id="payment_vendor_id">
                    <input type="hidden" name="vendor_category" id="payment_vendor_category">
                    <input type="hidden" name="selected_invoices" id="selected_invoices">
                    <input type="hidden" name="payment_type" id="payment_type" value="specific">
                    
                    <div class="row">
                        <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Vendor</label>
                        <input type="text" class="form-control" id="payment_vendor_name" readonly>
                    </div>
                    <div class="mb-3">
                        <label for="ledger_balance" class="form-label">Ledger Balance</label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="text" class="form-control" id="ledger_balance" readonly>
                        </div>
                    </div>
                        </div>
                        <div class="col-md-6">
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
                        </div>
                    </div>
                    
                    <!-- Invoice Selection for Purchase Based Vendors -->
                    <div id="invoiceSelectionSection" class="collapsible-content">
                        <hr>
                        <h6>Select Invoice(s) to Pay</h6>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="payment_type" id="paySpecificInvoice" value="specific" checked>
                                <label class="form-check-label" for="paySpecificInvoice">
                                    Pay Specific Invoice(s)
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="payment_type" id="payGeneral" value="general">
                                <label class="form-check-label" for="payGeneral">
                                    General Payment (No specific invoice)
                                </label>
                            </div>
                        </div>
                        
                        <div id="invoiceListSection">
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Select</th>
                                            <th>Invoice #</th>
                                            <th>Date</th>
                                            <th>Total Amount</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="unpaidInvoicesList">
                                        <!-- Invoices will be loaded here dynamically -->
                                    </tbody>
                                </table>
                            </div>
                            <div class="alert alert-info">
                                <small>Select the invoice(s) you want to pay. The total selected amount should match your payment amount.</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                    <div class="mb-3">
                        <label for="payment_mode" class="form-label">Transaction Mode <span class="text-danger">*</span></label>
                        <select class="form-select" id="payment_mode" name="payment_mode" required>
                            <option value="Cash">Cash</option>
                            <option value="Bank">Account Transfer</option>
                        </select>
                    </div>
                        </div>
                        <div class="col-md-6">
                    <div class="mb-3">
                        <label for="receipt_no" class="form-label">Receipt No.</label>
                        <input type="text" class="form-control" id="receipt_no" name="receipt_no">
                            </div>
                        </div>
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

<?php require_once __DIR__ . '/../layout/footer.php'; ?>

<!-- Edit Vendor Modal -->
<div class="modal fade" id="editVendorModal" tabindex="-1" aria-labelledby="editVendorModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editVendorModalLabel">Edit Vendor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="../../handlers/vendors/process.php">
                <div class="modal-body">
                    <input type="hidden" name="vendor_id" id="edit_vendor_id">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Vendor Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_type" class="form-label">Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="edit_type" name="type" required>
                            <option value="Local">Local</option>
                            <option value="Outsider">Outsider</option>
                        </select>
                    </div>
                    <?php if ($show_category): ?>
                    <div class="mb-3">
                         <label for="edit_vendor_category" class="form-label">Category <span class="text-danger">*</span></label>
                         <select class="form-select" id="edit_vendor_category" name="vendor_category" required>
                             <?php if ($features['commission']): ?>
                             <option value="Commission Based">Commission Based</option>
                             <?php endif; ?>
                             <?php if ($features['purchase']): ?>
                             <option value="Purchase Based">Purchase Based</option>
                             <?php endif; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label for="edit_balance" class="form-label">Balance</label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" class="form-control" id="edit_balance" name="balance" step="0.01" readonly>
                        </div>
                        <div class="form-text">Balance can only be changed through transactions</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_vendor" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../components/watak_modal.php'; ?>

<!-- Create Invoice Modal for Purchase Based Vendors -->
<div class="modal fade" id="createInvoiceModal" tabindex="-1" aria-labelledby="createInvoiceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createInvoiceModalLabel">Create Purchase Invoice</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="index.php" id="createInvoiceForm">
                <div class="modal-body">
                    <input type="hidden" name="vendor_id" id="invoice_vendor_id">
                    <input type="hidden" name="action" value="create_invoice">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Vendor</label>
                                <input type="text" class="form-control" id="invoice_vendor_name" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="invoice_number" class="form-label">Invoice Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="invoice_number" name="invoice_number" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="invoice_date" class="form-label">Invoice Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="invoice_date" name="invoice_date" value="<?php echo date('Y-m-d'); ?>" required>
                                <small class="text-muted">This date will appear on the invoice. The creation date is tracked automatically.</small>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    <h6>Invoice Items</h6>
                    
                    <div class="table-responsive">
                        <table class="table table-bordered" id="invoice_items_table">
                            <thead class="table-light">
                                <tr>
                                    <th>Item</th>
                                    <th>Quantity</th>
                                    <th>Weight (kg)</th>
                                    <th>Rate (₹)</th>
                                    <th>Amount (₹)</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="invoice_items_body">
                                <!-- Items will be added here dynamically -->
                            </tbody>
                        </table>
                    </div>
                    
                    <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="add_invoice_item">
                        <i class="fas fa-plus"></i> Add Item
                    </button>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <h6>Total Weight: <span id="invoice_total_weight">0.00</span> kg</h6>
                        </div>
                        <div class="col-md-6 text-end">
                            <h6>Total Amount: ₹<span id="invoice_total_amount">0.00</span></h6>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_invoice" class="btn btn-primary">Create Invoice</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Download All Wataks Modal -->
<div class="modal fade" id="downloadWataksModal" tabindex="-1" aria-labelledby="downloadWataksModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="downloadWataksModalLabel">Download All Wataks</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="download_wataks_vendor_id">
                <div class="mb-3">
                    <label class="form-label">Vendor</label>
                    <input type="text" class="form-control" id="download_wataks_vendor_name" readonly>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="watak_start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="watak_start_date" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="watak_end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="watak_end_date" required>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="download_wataks_btn" class="btn btn-primary">Download Wataks</button>
            </div>
        </div>
    </div>
</div>

<!-- Download All Invoices Modal -->
<div class="modal fade" id="downloadInvoicesModal" tabindex="-1" aria-labelledby="downloadInvoicesModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="downloadInvoicesModalLabel">Download All Invoices</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="download_invoices_vendor_id">
                <div class="mb-3">
                    <label class="form-label">Vendor</label>
                    <input type="text" class="form-control" id="download_invoices_vendor_name" readonly>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="invoice_start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="invoice_start_date" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="invoice_end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="invoice_end_date" required>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="download_invoices_btn" class="btn btn-primary">Download Invoices</button>
            </div>
        </div>
    </div>
</div>

<script>
    // Handle dropdown menu items that trigger modals
    document.addEventListener('click', function(e) {
        const dropdownItem = e.target.closest('.dropdown-item[data-bs-toggle="modal"]');
        if (dropdownItem) {
            e.preventDefault();
        }
    });

    // Vendor search functionality
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('vendorSearch');
        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const searchValue = this.value.toLowerCase().trim();
                const tableRows = document.querySelectorAll('.table tbody tr');
                
                tableRows.forEach(row => {
                    const vendorName = row.cells[0].textContent.toLowerCase();
                    const vendorType = row.cells[1].textContent.toLowerCase();
                    const vendorCategory = row.cells[2].textContent.toLowerCase();
                    
                    if (vendorName.includes(searchValue) || 
                        vendorType.includes(searchValue) ||
                        vendorCategory.includes(searchValue)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        }

        // Type column sorting functionality
        let typeSortDirection = 'asc'; // Track current sort direction
        
        const typeSortBtn = document.getElementById('typeSortBtn');
        if (typeSortBtn) {
            typeSortBtn.addEventListener('click', function() {
                // Toggle sort direction
                typeSortDirection = typeSortDirection === 'asc' ? 'desc' : 'asc';
                
                // Update button icon
                const icon = this.querySelector('i');
                if (typeSortDirection === 'asc') {
                    icon.className = 'fas fa-sort-up';
                    this.title = 'Sort by Type (Ascending)';
                } else {
                    icon.className = 'fas fa-sort-down';
                    this.title = 'Sort by Type (Descending)';
                }
                
                // Get all table rows
                const tbody = document.querySelector('.table tbody');
                const rows = Array.from(tbody.querySelectorAll('tr'));
                
                // Sort rows based on type column
                rows.sort((a, b) => {
                    const aType = a.cells[1].textContent.trim();
                    const bType = b.cells[1].textContent.trim();
                    
                    if (typeSortDirection === 'asc') {
                        return aType.localeCompare(bType);
                    } else {
                        return bType.localeCompare(aType);
                    }
                });
                
                // Re-append sorted rows
                rows.forEach(row => {
                    tbody.appendChild(row);
                });
            });
        }

        // Category column sorting functionality
        let categorySortDirection = 'asc'; // Track current sort direction
        
        const categorySortBtn = document.getElementById('categorySortBtn');
        if (categorySortBtn) {
            categorySortBtn.addEventListener('click', function() {
                // Toggle sort direction
                categorySortDirection = categorySortDirection === 'asc' ? 'desc' : 'asc';
                
                // Update button icon
                const icon = this.querySelector('i');
                if (categorySortDirection === 'asc') {
                    icon.className = 'fas fa-sort-up';
                    this.title = 'Sort by Category (Ascending)';
                } else {
                    icon.className = 'fas fa-sort-down';
                    this.title = 'Sort by Category (Descending)';
                }
                
                // Get all table rows
                const tbody = document.querySelector('.table tbody');
                const rows = Array.from(tbody.querySelectorAll('tr'));
                
                // Sort rows based on category column
                rows.sort((a, b) => {
                    const aCategory = a.cells[2].textContent.trim();
                    const bCategory = b.cells[2].textContent.trim();
                    
                    if (categorySortDirection === 'asc') {
                        return aCategory.localeCompare(bCategory);
                    } else {
                        return bCategory.localeCompare(aCategory);
                    }
                });
                
                // Re-append sorted rows
                rows.forEach(row => {
                    tbody.appendChild(row);
                });
            });
        }
    });

// Edit Vendor Modal
document.getElementById('editVendorModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const vendorId = button.getAttribute('data-vendor-id');
    const vendorName = button.getAttribute('data-vendor-name');
    const vendorType = button.getAttribute('data-vendor-type');
    const vendorCategory = button.getAttribute('data-vendor-category');
    const vendorBalance = button.getAttribute('data-vendor-balance');
    
    document.getElementById('edit_vendor_id').value = vendorId;
    document.getElementById('edit_name').value = vendorName;
    document.getElementById('edit_type').value = vendorType;
    document.getElementById('edit_vendor_category').value = vendorCategory || 'Local';
    document.getElementById('edit_balance').value = vendorBalance;
});

// Payment Modal
document.getElementById('paymentModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const vendorId = button.getAttribute('data-vendor-id');
    const vendorName = button.getAttribute('data-vendor-name');
    const vendorBalance = button.getAttribute('data-vendor-balance');
    const vendorCategory = button.getAttribute('data-vendor-category');
    
    document.getElementById('payment_vendor_id').value = vendorId;
    document.getElementById('payment_vendor_name').value = vendorName;
    
    // Apply rounding logic to balance display
    let balance = parseFloat(vendorBalance);
    let decimalPart = balance - Math.floor(balance);
    if (decimalPart >= 0.5) {
        balance = Math.ceil(balance);
    } else {
        balance = Math.floor(balance);
    }
    document.getElementById('ledger_balance').value = '₹' + balance.toFixed(0);
    document.getElementById('payment_vendor_category').value = vendorCategory;
    
    // Show/hide invoice selection section based on vendor category
    const invoiceSection = document.getElementById('invoiceSelectionSection');
    const paymentTypeField = document.getElementById('payment_type');
    
    if (vendorCategory === 'Purchase Based') {
        invoiceSection.style.display = 'block';
        loadUnpaidInvoices(vendorId);
        paymentTypeField.value = 'specific'; // Default to specific for Purchase Based
    } else {
        invoiceSection.style.display = 'none';
        paymentTypeField.value = 'general'; // Default to general for Commission Based
    }
});

// Function to load unpaid invoices for a vendor
function loadUnpaidInvoices(vendorId) {
    fetch(`../../api/vendors/get_unpaid_invoices.php?vendor_id=${vendorId}`)
        .then(response => response.json())
        .then(data => {
            const tbody = document.getElementById('unpaidInvoicesList');
            tbody.innerHTML = '';
            
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center">No unpaid invoices found</td></tr>';
                return;
            }
            
            data.forEach(invoice => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>
                        <input type="checkbox" class="form-check-input invoice-checkbox" 
                               value="${invoice.id}" data-amount="${invoice.total_amount}">
                    </td>
                    <td>${invoice.invoice_number}</td>
                    <td>${invoice.invoice_date}</td>
                    <td>₹${parseFloat(invoice.total_amount).toFixed(2)}</td>
                    <td><span class="badge bg-warning">Unpaid</span></td>
                `;
                tbody.appendChild(row);
            });
        })
        .catch(error => {
            console.error('Error loading invoices:', error);
            document.getElementById('unpaidInvoicesList').innerHTML = 
                '<tr><td colspan="6" class="text-center text-danger">Error loading invoices</td></tr>';
        });
}

// Handle invoice selection and payment type changes
document.addEventListener('DOMContentLoaded', function() {
    const paySpecificInvoice = document.getElementById('paySpecificInvoice');
    const payGeneral = document.getElementById('payGeneral');
    const invoiceListSection = document.getElementById('invoiceListSection');
    
    if (paySpecificInvoice && payGeneral) {
        paySpecificInvoice.addEventListener('change', function() {
            invoiceListSection.style.display = this.checked ? 'block' : 'none';
            document.getElementById('payment_type').value = 'specific';
        });
        
        payGeneral.addEventListener('change', function() {
            invoiceListSection.style.display = this.checked ? 'none' : 'block';
            document.getElementById('payment_type').value = 'general';
        });
    }
    
    // Handle invoice checkbox changes to calculate total
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('invoice-checkbox')) {
            calculateSelectedInvoiceTotal();
        }
    });
});

// Calculate total of selected invoices
function calculateSelectedInvoiceTotal() {
    const checkboxes = document.querySelectorAll('.invoice-checkbox:checked');
    let total = 0;
    const selectedInvoices = [];
    
    checkboxes.forEach(checkbox => {
        total += parseFloat(checkbox.dataset.amount);
        selectedInvoices.push(checkbox.value);
    });
    
    // Update amount field with total
    const amountField = document.getElementById('amount');
    if (amountField) {
        amountField.value = total.toFixed(2);
    }
    
    // Update hidden field with selected invoice IDs
    const selectedInvoicesField = document.getElementById('selected_invoices');
    if (selectedInvoicesField) {
        selectedInvoicesField.value = selectedInvoices.join(',');
    }
}

// Handle form submission
document.addEventListener('DOMContentLoaded', function() {
    const paymentForm = document.querySelector('#paymentModal form');
    if (paymentForm) {
        paymentForm.addEventListener('submit', function(e) {
            // Get vendor category
            const vendorCategory = document.getElementById('payment_vendor_category').value;
            
            // Only validate invoice selection for Purchase Based vendors
            if (vendorCategory === 'Purchase Based') {
                const paymentType = document.querySelector('input[name="payment_type"]:checked');
                const selectedInvoices = document.getElementById('selected_invoices');
                
                if (paymentType && paymentType.value === 'specific') {
                    if (!selectedInvoices.value.trim()) {
                        e.preventDefault();
                        alert('Please select at least one invoice to pay.');
                        return false;
                    }
                }
            }
        });
    }
});

// Add this at the end of the file
document.addEventListener('DOMContentLoaded', function() {
    // Handle pre-selected item for watak
    const selectedItem = <?php echo $selected_item; ?>;
    if (selectedItem > 0) {
        // Show watak modal automatically
        const watakModal = new bootstrap.Modal(document.getElementById('watakModal'));
        watakModal.show();
        
        // Wait for the modal to be shown and items to be loaded
        document.getElementById('watakModal').addEventListener('shown.bs.modal', function() {
            // Add a new item row
            document.getElementById('add_watak_item').click();
            
            // Wait for items to be loaded
            const checkItemsLoaded = setInterval(function() {
                const itemSelect = document.querySelector('#watak_items_table .item-select');
                if (itemSelect && itemSelect.options.length > 1) {
                    clearInterval(checkItemsLoaded);
                    
                    // Select the pre-selected item
                    for (let i = 0; i < itemSelect.options.length; i++) {
                        if (itemSelect.options[i].value == selectedItem) {
                            itemSelect.selectedIndex = i;
                            itemSelect.dispatchEvent(new Event('change'));
                            break;
                        }
                    }
                }
            }, 100);
        });
    }
});

// Create Invoice Modal Functionality
document.getElementById('createInvoiceModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const vendorId = button.getAttribute('data-vendor-id');
    const vendorName = button.getAttribute('data-vendor-name');
    
    document.getElementById('invoice_vendor_id').value = vendorId;
    document.getElementById('invoice_vendor_name').value = vendorName;
    
    // Clear previous items
    document.getElementById('invoice_items_body').innerHTML = '';
    document.getElementById('invoice_total_weight').textContent = '0.00';
    document.getElementById('invoice_total_amount').textContent = '0.00';
    
    // Add first row
    addInvoiceItemRow();
    
    // Get next invoice number via AJAX
    fetch(`../../core/helpers/numbering_helper_vendor.php?action=get_next_invoice_number`)
        .then(response => response.text())
        .then(data => {
            document.getElementById('invoice_number').value = data.trim();
        })
        .catch(error => {
            console.error('Error fetching invoice number:', error);
        });
});

// Add item row to invoice
function addInvoiceItemRow() {
    const tbody = document.getElementById('invoice_items_body');
    const rowCount = tbody.rows.length;
    const newRow = document.createElement('tr');
    
    newRow.innerHTML = `
        <td>
            <select class="form-select item-select" name="item_id[]" required>
                <option value="">Select Item</option>
                <!-- Items will be loaded via AJAX -->
            </select>
        </td>
        <td>
            <input type="number" class="form-control invoice-quantity" name="quantity[]" 
                   value="" min="0" step="0.01" placeholder="Enter quantity" required>
        </td>
        <td>
            <input type="number" class="form-control invoice-weight" name="weight[]" 
                   value="" min="0" step="0.01" placeholder="Enter weight" required>
        </td>
        <td>
            <input type="number" class="form-control invoice-rate" name="rate[]" 
                   value="" min="0" step="0.01" placeholder="Enter rate" required>
        </td>
        <td>
            <input type="text" class="form-control invoice-amount" name="amount[]" 
                   value="" readonly>
        </td>
        <td>
            <button type="button" class="btn btn-danger btn-sm remove-item">
                <i class="fas fa-trash"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(newRow);
    
    // Load items via AJAX
    const selectElement = newRow.querySelector('.item-select');
    loadItems(selectElement);
    
    // Add event listeners for calculation
    const quantityInput = newRow.querySelector('.invoice-quantity');
    const weightInput = newRow.querySelector('.invoice-weight');
    const rateInput = newRow.querySelector('.invoice-rate');
    
    quantityInput.addEventListener('input', calculateRowAmount);
    weightInput.addEventListener('input', calculateRowAmount);
    rateInput.addEventListener('input', calculateRowAmount);
    
    // Add event listener for remove button
    const removeButton = newRow.querySelector('.remove-item');
    removeButton.addEventListener('click', function() {
        if (tbody.rows.length > 1) {
            tbody.removeChild(newRow);
            calculateInvoiceTotals();
        }
    });
}

// Load items via AJAX
function loadItems(selectElement) {
    // Show loading state
    selectElement.innerHTML = '<option value="">Loading items...</option>';
    
    fetch('../../api/inventory/get_items_simple.php')
        .then(response => response.json())
        .then(data => {
            // Clear loading option
            selectElement.innerHTML = '<option value="">Select Item</option>';
            
            if (data && data.success && data.items && data.items.length > 0) {
                data.items.forEach(item => {
                    const option = document.createElement('option');
                    option.value = item.id;
                    option.textContent = item.name;
                    selectElement.appendChild(option);
                });
            } else {
                console.error('No items returned or invalid format:', data);
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
}

// Calculate row amount
function calculateRowAmount(event) {
    const row = event.target.closest('tr');
    const quantity = parseFloat(row.querySelector('.invoice-quantity').value) || 0;
    const weight = parseFloat(row.querySelector('.invoice-weight').value) || 0;
    const rate = parseFloat(row.querySelector('.invoice-rate').value) || 0;
    
    let amount = 0;
    if (weight > 0) {
        amount = weight * rate;
    } else {
        amount = quantity * rate;
    }
    
    row.querySelector('.invoice-amount').value = amount.toFixed(2);
    calculateInvoiceTotals();
}

// Calculate invoice totals
function calculateInvoiceTotals() {
    const rows = document.querySelectorAll('#invoice_items_body tr');
    let totalAmount = 0;
    let totalWeight = 0;
    
    rows.forEach(row => {
        const weight = parseFloat(row.querySelector('.invoice-weight').value) || 0;
        const amount = parseFloat(row.querySelector('.invoice-amount').value) || 0;
        
        totalWeight += weight;
        totalAmount += amount;
    });
    
    document.getElementById('invoice_total_weight').textContent = totalWeight.toFixed(2);
    document.getElementById('invoice_total_amount').textContent = totalAmount.toFixed(2);
}

// Add event listener for add item button
document.addEventListener('DOMContentLoaded', function() {
    const addItemButton = document.getElementById('add_invoice_item');
    if (addItemButton) {
        addItemButton.addEventListener('click', addInvoiceItemRow);
    }
    
    // Form validation
    const invoiceForm = document.getElementById('createInvoiceForm');
    if (invoiceForm) {
        invoiceForm.addEventListener('submit', function(e) {
            const itemSelects = document.querySelectorAll('.item-select');
            let valid = true;
            
            // Check if at least one item is selected
            let hasSelectedItem = false;
            itemSelects.forEach(select => {
                if (select.value) {
                    hasSelectedItem = true;
                }
            });
            
            if (!hasSelectedItem) {
                e.preventDefault();
                alert('Please select at least one item for the invoice.');
                valid = false;
            }
            
            // Check if total amount is greater than 0
            const totalAmount = parseFloat(document.getElementById('invoice_total_amount').textContent) || 0;
            if (totalAmount <= 0) {
                e.preventDefault();
                alert('Invoice total amount must be greater than 0.');
                valid = false;
            }
            
            return valid;
        });
    }
});

// Download Wataks Modal Functionality
document.getElementById('downloadWataksModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const vendorId = button.getAttribute('data-vendor-id');
    const vendorName = button.getAttribute('data-vendor-name');
    
    document.getElementById('download_wataks_vendor_id').value = vendorId;
    document.getElementById('download_wataks_vendor_name').value = vendorName;
    
    // Set default date range (last 30 days)
    const today = new Date();
    const thirtyDaysAgo = new Date();
    thirtyDaysAgo.setDate(today.getDate() - 30);
    
    document.getElementById('watak_end_date').value = today.toISOString().split('T')[0];
    document.getElementById('watak_start_date').value = thirtyDaysAgo.toISOString().split('T')[0];
});

// Handle Download Wataks Button
document.getElementById('download_wataks_btn').addEventListener('click', function() {
    const vendorId = document.getElementById('download_wataks_vendor_id').value;
    const startDate = document.getElementById('watak_start_date').value;
    const endDate = document.getElementById('watak_end_date').value;
    
    if (!vendorId || !startDate || !endDate) {
        alert('Please fill in all required fields.');
        return;
    }
    
    const url = `../../handlers/vendors/download_wataks.php?vendor_id=${vendorId}&start_date=${startDate}&end_date=${endDate}`;
    window.open(url, '_blank');
    
    // Close the modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('downloadWataksModal'));
    modal.hide();
});

// Download Invoices Modal Functionality
document.getElementById('downloadInvoicesModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const vendorId = button.getAttribute('data-vendor-id');
    const vendorName = button.getAttribute('data-vendor-name');
    
    document.getElementById('download_invoices_vendor_id').value = vendorId;
    document.getElementById('download_invoices_vendor_name').value = vendorName;
    
    // Set default date range (last 30 days)
    const today = new Date();
    const thirtyDaysAgo = new Date();
    thirtyDaysAgo.setDate(today.getDate() - 30);
    
    document.getElementById('invoice_end_date').value = today.toISOString().split('T')[0];
    document.getElementById('invoice_start_date').value = thirtyDaysAgo.toISOString().split('T')[0];
});

// Handle Download Invoices Button
document.getElementById('download_invoices_btn').addEventListener('click', function() {
    const vendorId = document.getElementById('download_invoices_vendor_id').value;
    const startDate = document.getElementById('invoice_start_date').value;
    const endDate = document.getElementById('invoice_end_date').value;
    
    if (!vendorId || !startDate || !endDate) {
        alert('Please fill in all required fields.');
        return;
    }
    
    const url = `../../handlers/vendors/download_invoice.php?vendor_id=${vendorId}&start_date=${startDate}&end_date=${endDate}`;
    window.open(url, '_blank');
    
    // Close the modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('downloadInvoicesModal'));
    modal.hide();
});
</script> 

<!-- Delete Vendor Modal -->
<div class="modal fade" id="deleteVendorModal" tabindex="-1" aria-labelledby="deleteVendorModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteVendorModalLabel">Delete Vendor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-danger fw-bold">This will permanently delete the vendor and ALL related data (wataks, invoices, items, payments, inventory). This action cannot be undone.</p>
                <div class="mb-3">
                    <label class="form-label">Vendor</label>
                    <input type="text" class="form-control" id="delete_vendor_name" readonly>
                    <input type="hidden" id="delete_vendor_id">
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="confirm_delete_vendor">
                    <label class="form-check-label" for="confirm_delete_vendor">
                        I understand this action is irreversible
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="delete_vendor_btn" class="btn btn-danger" disabled>Delete</button>
            </div>
        </div>
    </div>
    </div>

<script>
// Wire delete vendor modal
document.getElementById('deleteVendorModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const vendorId = button.getAttribute('data-vendor-id');
    const vendorName = button.getAttribute('data-vendor-name');
    document.getElementById('delete_vendor_id').value = vendorId;
    document.getElementById('delete_vendor_name').value = vendorName;
    document.getElementById('confirm_delete_vendor').checked = false;
    document.getElementById('delete_vendor_btn').disabled = true;
});

document.getElementById('confirm_delete_vendor').addEventListener('change', function() {
    document.getElementById('delete_vendor_btn').disabled = !this.checked;
});

document.getElementById('delete_vendor_btn').addEventListener('click', function() {
    const vendorId = document.getElementById('delete_vendor_id').value;
    const confirmed = document.getElementById('confirm_delete_vendor').checked;
    if (!confirmed) { return; }
    fetch('../../handlers/vendors/delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'vendor_id=' + encodeURIComponent(vendorId)
    })
    .then(r => r.json())
    .then(data => {
        if (data && data.success) {
            location.reload();
        } else {
            alert('Failed to delete vendor: ' + (data && data.error ? data.error : 'Unknown error'));
        }
    })
    .catch(err => {
        alert('Failed to delete vendor: ' + err);
    });
});
</script>