<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../layout/header.php';

// Check if we're viewing a single invoice or combined invoices
$is_single_invoice = isset($_GET['id']);

if ($is_single_invoice) {
    // Get single invoice details
    $invoice_id = intval($_GET['id']);
    
    // Get invoice details
    $invoice_sql = "SELECT ci.*, c.name as customer_name, c.contact as customer_contact, 
                   c.location as customer_location, c.balance as customer_balance
                   FROM customer_invoices ci
                   JOIN customers c ON ci.customer_id = c.id
                   WHERE ci.id = ?";
    $invoice_stmt = $conn->prepare($invoice_sql);
    $invoice_stmt->bind_param("i", $invoice_id);
    $invoice_stmt->execute();
    $invoice_result = $invoice_stmt->get_result();
    
    if ($invoice_result->num_rows === 0) {
        header('Location: index.php');
        exit();
    }
    
    $invoice = $invoice_result->fetch_assoc();
    $customer_id = $invoice['customer_id'];
    // Prefer display_date for showing the invoice date; fallback to system date
    $date = $invoice['display_date'] ?: $invoice['date'];
    $formatted_date = date('d/m/Y', strtotime($date));
    $customer = [
        'id' => $customer_id,
        'name' => $invoice['customer_name'],
        'contact' => $invoice['customer_contact'],
        'location' => $invoice['customer_location'],
        'balance' => $invoice['customer_balance']
    ];
    $total_amount = $invoice['total_amount'];
    $invoice_numbers = [$invoice['invoice_number']];
    $invoice_ids = [$invoice_id];
    
    // Get invoice items
    $items_sql = "SELECT cii.*, i.name as item_name, v.name as vendor_name 
                 FROM customer_invoice_items cii
                 JOIN items i ON cii.item_id = i.id
                 LEFT JOIN vendors v ON cii.vendor_id = v.id
                 WHERE cii.invoice_id = ?
                 ORDER BY cii.id";
    $items_stmt = $conn->prepare($items_sql);
    $items_stmt->bind_param("i", $invoice_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();
    
    // Set up invoices array for the individual invoices table
    $invoices = [$invoice];
    
} else {
    // Handle combined invoices
    if (!isset($_GET['date']) || !isset($_GET['customer_id'])) {
        header('Location: index.php');
        exit();
    }
    
    // The filter date keeps using system date grouping
    $date = sanitizeInput($_GET['date']);
    $customer_id = intval($_GET['customer_id']);
    
    // Get customer information
    $customer_sql = "SELECT * FROM customers WHERE id = ?";
    $customer_stmt = $conn->prepare($customer_sql);
    $customer_stmt->bind_param("i", $customer_id);
    $customer_stmt->execute();
    $customer_result = $customer_stmt->get_result();
    
    if ($customer_result->num_rows === 0) {
        header('Location: index.php');
        exit();
    }
    
    $customer = $customer_result->fetch_assoc();
    
    // Get all invoices for this customer on this system date
    $invoices_sql = "SELECT * FROM customer_invoices 
                    WHERE customer_id = ? AND DATE(date) = ? 
                    ORDER BY CAST(invoice_number AS UNSIGNED)";
    $invoices_stmt = $conn->prepare($invoices_sql);
    $invoices_stmt->bind_param("is", $customer_id, $date);
    $invoices_stmt->execute();
    $invoices_result = $invoices_stmt->get_result();
    
    // Get all invoice items for these invoices
    $invoice_ids = [];
    $invoices = [];
    $invoice_numbers = [];
    $total_amount = 0;
    // Track display dates to decide whether we can combine
    $display_dates = [];
    $common_display_date = null;
    $all_same_display_date = true;
    
    while ($invoice = $invoices_result->fetch_assoc()) {
        $invoice_ids[] = $invoice['id'];
        $invoices[] = $invoice;
        $invoice_numbers[] = $invoice['invoice_number'];
        $total_amount += $invoice['total_amount'];
        $curr_disp = $invoice['display_date'];
        if ($common_display_date === null) {
            $common_display_date = $curr_disp;
        } else {
            if ($common_display_date !== $curr_disp) {
                $all_same_display_date = false;
            }
        }
        if (!empty($curr_disp)) {
            $display_dates[$curr_disp] = true;
        }
    }
    
    if (empty($invoice_ids)) {
        header('Location: index.php');
        exit();
    }
    
    // Determine if we should combine:
    // Combine only if all invoices share the same display_date (or no display_date set)
    $can_combine = $all_same_display_date;
    if ($can_combine) {
        // Load combined items only when combinable
        $items_sql = "SELECT cii.*, i.name as item_name, v.name as vendor_name 
                     FROM customer_invoice_items cii
                     JOIN items i ON cii.item_id = i.id
                     LEFT JOIN vendors v ON cii.vendor_id = v.id
                     WHERE cii.invoice_id IN (" . implode(',', $invoice_ids) . ")
                     ORDER BY cii.id";
        $items_result = $conn->query($items_sql);
    } else {
        $items_result = null;
    }
    
    // Format the date for display
    if ($all_same_display_date && !empty($common_display_date)) {
        $formatted_date = date('d/m/Y', strtotime($common_display_date));
    } else if ($can_combine) {
        $formatted_date = date('d/m/Y', strtotime($date));
    } else {
        $formatted_date = 'Multiple Dates';
    }
}

// Determine if we're viewing a single invoice or multiple
$is_multiple = count($invoice_ids) > 1;
$page_title = ($is_multiple && $can_combine) ? "Combined Invoices" : ($is_multiple ? "Invoices" : ("Invoice #" . $invoice_numbers[0]));
?>

<div class="main-content">
    <section class="content">
        <div class="container-fluid mt-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title"><?php echo $page_title; ?> for <?php echo htmlspecialchars($customer['name']); ?> on <?php echo $formatted_date; ?></h3>
                    <div>
                        <a href="index.php?filter_date=<?php echo $date; ?>" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Invoices
                        </a>
                        <a href="../../print/vendors/combined_invoices.php?<?php echo $is_single_invoice ? 'id=' . $invoice_id : 'date=' . $date . '&customer_id=' . $customer_id; ?>" class="btn btn-info" target="_blank">
                            <i class="fas fa-print"></i> Print
                        </a>
                        <a href="../../handlers/invoices/download_combined.php?<?php echo $is_single_invoice ? 'id=' . $invoice_id : 'date=' . $date . '&customer_id=' . $customer_id; ?>" class="btn btn-primary">
                            <i class="fas fa-download"></i> Download
                        </a>
                    </div>
                </div>
                
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h5 class="mb-2">Customer Information</h5>
                            <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($customer['name']); ?></p>
                            <?php if (!empty($customer['contact'])): ?>
                            <p class="mb-1"><strong>Contact:</strong> <?php echo htmlspecialchars($customer['contact']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($customer['location'])): ?>
                            <p class="mb-1"><strong>Location:</strong> <?php echo htmlspecialchars($customer['location']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 text-md-right">
                            <h5 class="mb-2">Invoice Details</h5>
                            <p class="mb-1"><strong>Date:</strong> <?php echo $formatted_date; ?></p>
                            <p class="mb-1"><strong>Invoice <?php echo $is_multiple ? 'Numbers' : 'Number'; ?>:</strong> 
                                <?php echo implode(', ', $invoice_numbers); ?>
                            </p>
                            <p class="mb-1"><strong>Total Amount:</strong> ₹<?php echo number_format($total_amount, 2); ?></p>
                        </div>
                    </div>
                    
                    <?php if ($is_single_invoice || (isset($can_combine) && $can_combine)): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Item</th>
                                    <th>Vendor</th>
                                    <th>Quantity</th>
                                    <th>Weight</th>
                                    <th>Rate</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $counter = 1;
                                $total_items_amount = 0;
                                if ($items_result) {
                                    mysqli_data_seek($items_result, 0);
                                    while ($item = $items_result->fetch_assoc()): 
                                        $total_items_amount += $item['amount'];
                                ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                    <td><?php echo htmlspecialchars($item['vendor_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo number_format($item['quantity'], 2); ?></td>
                                    <td><?php echo $item['weight'] > 0 ? number_format($item['weight'], 2) : 'N/A'; ?></td>
                                    <td>₹<?php echo number_format($item['rate'], 2); ?></td>
                                    <td>₹<?php echo number_format($item['amount'], 2); ?></td>
                                </tr>
                                <?php endwhile; } ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="6" class="text-right">Total:</th>
                                    <th>₹<?php echo number_format($total_items_amount, 2); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php else: ?>
                        <?php 
                        // Render each invoice separately with its own items and totals
                        foreach ($invoices as $inv):
                            $inv_id = (int)$inv['id'];
                            $inv_date = $inv['display_date'] ?: $inv['date'];
                            $inv_date_fmt = date('d/m/Y', strtotime($inv_date));
                            $inv_total = $inv['total_amount'];
                            $items_sql_sep = "SELECT cii.*, i.name as item_name, v.name as vendor_name 
                                              FROM customer_invoice_items cii
                                              JOIN items i ON cii.item_id = i.id
                                              LEFT JOIN vendors v ON cii.vendor_id = v.id
                                              WHERE cii.invoice_id = ?
                                              ORDER BY cii.id";
                            $stmt_sep = $conn->prepare($items_sql_sep);
                            $stmt_sep->bind_param('i', $inv_id);
                            $stmt_sep->execute();
                            $items_sep = $stmt_sep->get_result();
                            $row_no = 1;
                            $items_sum = 0;
                        ?>
                        <div class="card mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>Invoice #<?php echo htmlspecialchars($inv['invoice_number']); ?></strong>
                                    <span class="ms-2">Date: <?php echo $inv_date_fmt; ?></span>
                                </div>
                                <div class="btn-group">
                                    <a href="../invoices/view.php?id=<?php echo $inv_id; ?>" class="btn btn-info btn-sm">View</a>
                                    <a href="../invoices/edit.php?id=<?php echo $inv_id; ?>" class="btn btn-primary btn-sm">Edit</a>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Item</th>
                                                <th>Vendor</th>
                                                <th>Quantity</th>
                                                <th>Weight</th>
                                                <th>Rate</th>
                                                <th>Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($it = $items_sep->fetch_assoc()): $items_sum += $it['amount']; ?>
                                            <tr>
                                                <td><?php echo $row_no++; ?></td>
                                                <td><?php echo htmlspecialchars($it['item_name']); ?></td>
                                                <td><?php echo htmlspecialchars($it['vendor_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo number_format($it['quantity'], 2); ?></td>
                                                <td><?php echo $it['weight'] > 0 ? number_format($it['weight'], 2) : 'N/A'; ?></td>
                                                <td>₹<?php echo number_format($it['rate'], 2); ?></td>
                                                <td>₹<?php echo number_format($it['amount'], 2); ?></td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th colspan="6" class="text-right">Total:</th>
                                                <th>₹<?php echo number_format($inv_total ?: $items_sum, 2); ?></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <?php if ($is_multiple): ?>
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <h5>Individual Invoices</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Invoice #</th>
                                            <th>Date</th>
                                            <th>Amount</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($invoices as $invoice): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                                            <td><?php echo date('d/m/Y', strtotime($invoice['date'])); ?></td>
                                            <td>₹<?php echo number_format($invoice['total_amount'], 2); ?></td>
                                            <td>
                                                <div class="btn-group">
                                                    <a href="../invoices/view.php?id=<?php echo $invoice['id']; ?>" class="btn btn-info btn-sm">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                    <a href="../invoices/edit.php?id=<?php echo $invoice['id']; ?>" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </a>
                                                    <button type="button" 
                                                            class="btn btn-danger btn-sm delete-invoice" 
                                                            data-invoice-id="<?php echo $invoice['id']; ?>"
                                                            data-invoice-number="<?php echo htmlspecialchars($invoice['invoice_number']); ?>"
                                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                                            title="Delete">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Invoice</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete invoice <span id="deleteInvoiceNumber"></span>?</p>
                <p><strong>Customer:</strong> <span id="deleteCustomerName"></span></p>
                <p><strong>Note:</strong> This will restore items to inventory and update customer balance.</p>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Warning:</strong> This action cannot be undone!
                </div>
            </div>
            <div class="modal-footer">
                <form method="post" action="index.php">
                    <input type="hidden" name="invoice_id" id="deleteInvoiceId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_invoice" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Wait for DOM to be loaded
document.addEventListener('DOMContentLoaded', function() {
    // Handle delete button click
    document.querySelectorAll('.delete-invoice').forEach(function(button) {
        button.addEventListener('click', function() {
            console.log('Delete button clicked');
            var invoiceId = this.getAttribute('data-invoice-id');
            var invoiceNumber = this.getAttribute('data-invoice-number');
            var customerName = this.getAttribute('data-customer-name');
            
            console.log('Invoice ID:', invoiceId);
            console.log('Invoice Number:', invoiceNumber);
            console.log('Customer Name:', customerName);
            
            document.getElementById('deleteInvoiceId').value = invoiceId;
            document.getElementById('deleteInvoiceNumber').textContent = invoiceNumber;
            document.getElementById('deleteCustomerName').textContent = customerName;
            
            // Use Bootstrap 5 modal
            var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            deleteModal.show();
        });
    });
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?> 