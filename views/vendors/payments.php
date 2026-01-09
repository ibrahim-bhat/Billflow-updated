<?php
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$vendor_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Get vendor details
$sql = "SELECT * FROM vendors WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();

if (!$vendor) {
    $_SESSION['error_message'] = "Vendor not found!";
    header('Location: index.php');
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->begin_transaction();
        
        if (isset($_POST['edit_payment'])) {
            $payment_id = intval($_POST['payment_id']);
            $old_amount = floatval($_POST['old_amount']);
            $new_amount = floatval($_POST['new_amount']);
            $new_discount = floatval($_POST['new_discount']);
            $new_payment_mode = sanitizeInput($_POST['new_payment_mode']);
            $new_receipt_no = sanitizeInput($_POST['new_receipt_no']);
            $new_date = sanitizeInput($_POST['new_date']);
            
            // Calculate totals
            $old_total = $old_amount;
            $new_total = $new_amount + $new_discount;
            
            // Update the payment
            $sql = "UPDATE vendor_payments SET 
                    amount = ?, 
                    discount = ?, 
                    payment_mode = ?, 
                    receipt_no = ?, 
                    date = ?
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ddsssi", $new_amount, $new_discount, $new_payment_mode, $new_receipt_no, $new_date, $payment_id);
            $stmt->execute();
            
            // Update vendor balance: First reverse the old payment, then apply the new payment
            // This ensures correct balance adjustment
            $balance_adjustment = $old_total - $new_total; // Positive if payment reduced, negative if increased
            $sql = "UPDATE vendors SET balance = balance + ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("di", $balance_adjustment, $vendor_id);
            $stmt->execute();
            
            $_SESSION['success_message'] = "Payment updated successfully!";
            
        } elseif (isset($_POST['delete_payment'])) {
            $payment_id = intval($_POST['payment_id']);
            $payment_amount = floatval($_POST['payment_amount']);
            $payment_discount = floatval($_POST['payment_discount']);
            // Get payment details for logging
            $sql = "SELECT * FROM vendor_payments WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $payment_id);
            $stmt->execute();
            $payment_details = $stmt->get_result()->fetch_assoc();
            
            // Delete the payment
            $sql = "DELETE FROM vendor_payments WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $payment_id);
            $stmt->execute();
            
            // Update vendor balance (reverse the payment)
            $total_payment = $payment_amount + $payment_discount;
            $sql = "UPDATE vendors SET balance = balance + ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("di", $total_payment, $vendor_id);
            $stmt->execute();
            
            $_SESSION['success_message'] = "Payment deleted successfully!";
        }
        
        $conn->commit();
        header("Location: payments.php?id=" . $vendor_id);
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
}

// Get all payments for this vendor
$sql = "SELECT vp.*, v.name as vendor_name 
        FROM vendor_payments vp 
        JOIN vendors v ON vp.vendor_id = v.id 
        WHERE vp.vendor_id = ? 
        ORDER BY vp.date DESC, vp.id DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$payments_result = $stmt->get_result();

// Include header
require_once __DIR__ . '/../layout/header.php';
?>

<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1>Manage Vendor Payments</h1>
            <p>Edit and manage payment entries for <?php echo htmlspecialchars($vendor['name']); ?></p>
        </div>
        <a href="index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Vendors
        </a>
    </div>

    <!-- Vendor Information -->
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title">Vendor Information</h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <strong>Name:</strong> <?php echo htmlspecialchars($vendor['name']); ?>
                </div>
                <div class="col-md-3">
                    <strong>Contact:</strong> <?php echo htmlspecialchars($vendor['contact']); ?>
                </div>
                <div class="col-md-3">
                    <strong>Type:</strong> <?php echo htmlspecialchars($vendor['type']); ?>
                </div>
                <div class="col-md-3">
                    <strong>Current Balance:</strong> 
                    <span class="badge <?php echo $vendor['balance'] > 0 ? 'bg-danger' : 'bg-success'; ?>">
                        ₹<?php echo number_format(abs($vendor['balance']), 2); ?>
                        <?php echo $vendor['balance'] > 0 ? ' (Payable)' : ' (Receivable)'; ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment List -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Payment History</h3>
        </div>
        <div class="card-body">
            <?php if ($payments_result->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Discount</th>
                            <th>Total</th>
                            <th>Payment Mode</th>
                            <th>Receipt No.</th>

                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($payment = $payments_result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($payment['date'])); ?></td>
                            <td>₹<?php echo number_format($payment['amount'], 2); ?></td>
                            <td>₹<?php echo number_format($payment['discount'], 2); ?></td>
                            <td><strong>₹<?php echo number_format($payment['amount'] + $payment['discount'], 2); ?></strong></td>
                            <td><?php echo htmlspecialchars($payment['payment_mode']); ?></td>
                            <td><?php echo htmlspecialchars($payment['receipt_no'] ?? 'N/A'); ?></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-warning" 
                                            data-bs-toggle="modal" data-bs-target="#editPaymentModal"
                                            data-payment-id="<?php echo $payment['id']; ?>"
                                            data-payment-amount="<?php echo $payment['amount']; ?>"
                                            data-payment-discount="<?php echo $payment['discount']; ?>"
                                            data-payment-mode="<?php echo htmlspecialchars($payment['payment_mode']); ?>"
                                            data-payment-receipt="<?php echo htmlspecialchars($payment['receipt_no'] ?? ''); ?>"
                                            data-payment-date="<?php echo $payment['date']; ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger" 
                                            data-bs-toggle="modal" data-bs-target="#deletePaymentModal"
                                            data-payment-id="<?php echo $payment['id']; ?>"
                                            data-payment-amount="<?php echo $payment['amount']; ?>"
                                            data-payment-discount="<?php echo $payment['discount']; ?>"
                                            data-payment-date="<?php echo date('d/m/Y', strtotime($payment['date'])); ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="alert alert-info">
                No payment records found for this vendor.
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Edit Payment Modal -->
<div class="modal fade" id="editPaymentModal" tabindex="-1" aria-labelledby="editPaymentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editPaymentModalLabel">Edit Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="payment_id" id="edit_payment_id">
                    <input type="hidden" name="old_amount" id="edit_old_amount">
                    
                    <div class="form-group mb-3">
                        <label for="edit_payment_date">Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="edit_payment_date" name="new_date" required>
                    </div>
                    
                    <div class="form-group mb-3">
                        <label for="edit_payment_amount">Amount <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="edit_payment_amount" name="new_amount" step="0.01" min="0" required>
                    </div>
                    
                    <div class="form-group mb-3">
                        <label for="edit_payment_discount">Discount</label>
                        <input type="number" class="form-control" id="edit_payment_discount" name="new_discount" step="0.01" min="0" value="0">
                    </div>
                    
                    <div class="form-group mb-3">
                        <label for="edit_payment_mode">Payment Mode <span class="text-danger">*</span></label>
                        <select class="form-control" id="edit_payment_mode" name="new_payment_mode" required>
                            <option value="Cash">Cash</option>
                            <option value="Bank">Bank</option>
                        </select>
                    </div>
                    
                    <div class="form-group mb-3">
                        <label for="edit_payment_receipt">Receipt No.</label>
                        <input type="text" class="form-control" id="edit_payment_receipt" name="new_receipt_no">
                    </div>
                    

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_payment" class="btn btn-primary">Update Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Payment Modal -->
<div class="modal fade" id="deletePaymentModal" tabindex="-1" aria-labelledby="deletePaymentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deletePaymentModalLabel">Delete Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="payment_id" id="delete_payment_id">
                    <input type="hidden" name="payment_amount" id="delete_payment_amount">
                    <input type="hidden" name="payment_discount" id="delete_payment_discount">
                    
                    <div class="alert alert-warning">
                        <strong>Warning!</strong> This action will permanently delete the payment and adjust the vendor balance accordingly.
                    </div>
                    
                    <p><strong>Payment Date:</strong> <span id="delete_payment_date"></span></p>
                    <p><strong>Payment Amount:</strong> ₹<span id="delete_payment_total"></span></p>
                    

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_payment" class="btn btn-danger">Delete Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Edit Payment Modal
document.getElementById('editPaymentModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const paymentId = button.getAttribute('data-payment-id');
    const paymentAmount = button.getAttribute('data-payment-amount');
    const paymentDiscount = button.getAttribute('data-payment-discount');
    const paymentMode = button.getAttribute('data-payment-mode');
    const paymentReceipt = button.getAttribute('data-payment-receipt');
    const paymentDate = button.getAttribute('data-payment-date');
    
    document.getElementById('edit_payment_id').value = paymentId;
    document.getElementById('edit_old_amount').value = paymentAmount;
    document.getElementById('edit_payment_amount').value = paymentAmount;
    document.getElementById('edit_payment_discount').value = paymentDiscount;
    document.getElementById('edit_payment_mode').value = paymentMode;
    document.getElementById('edit_payment_receipt').value = paymentReceipt;
    document.getElementById('edit_payment_date').value = paymentDate;
});

// Delete Payment Modal
document.getElementById('deletePaymentModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const paymentId = button.getAttribute('data-payment-id');
    const paymentAmount = button.getAttribute('data-payment-amount');
    const paymentDiscount = button.getAttribute('data-payment-discount');
    const paymentDate = button.getAttribute('data-payment-date');
    const paymentTotal = parseFloat(paymentAmount) + parseFloat(paymentDiscount);
    
    document.getElementById('delete_payment_id').value = paymentId;
    document.getElementById('delete_payment_amount').value = paymentAmount;
    document.getElementById('delete_payment_discount').value = paymentDiscount;
    document.getElementById('delete_payment_date').textContent = paymentDate;
    document.getElementById('delete_payment_total').textContent = paymentTotal.toFixed(2);
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?> 