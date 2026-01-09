<?php
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../layout/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Determine if we're viewing a single invoice or multiple invoices for a vendor on a specific date
$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$vendor_id = isset($_GET['vendor_id']) ? intval($_GET['vendor_id']) : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';
$is_combined_view = ($vendor_id > 0 && !empty($date));

// If neither invoice_id nor (vendor_id and date) provided, redirect to invoice list
if (!$invoice_id && !$is_combined_view) {
    $_SESSION['error_message'] = "No invoice specified.";
    header('Location: index.php');
    exit();
}

if ($invoice_id) {
    // Single invoice view
    $sql = "SELECT vi.*, v.name as vendor_name, v.contact as vendor_contact, 
                   v.type as vendor_type, v.vendor_category
            FROM vendor_invoices vi
            JOIN vendors v ON vi.vendor_id = v.id
            WHERE vi.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$invoice) {
        // Invoice not found, redirect to invoice list
        $_SESSION['error_message'] = "Invoice not found.";
        header('Location: index.php');
        exit();
    }

    // Get invoice items
    $sql = "SELECT vii.*, i.name as item_name
            FROM vendor_invoice_items vii
            JOIN items i ON vii.item_id = i.id
            WHERE vii.invoice_id = ?
            ORDER BY vii.id";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $invoice_items = $stmt->get_result();
    $stmt->close();
    
    $page_title = "Purchase Invoice #" . htmlspecialchars($invoice['invoice_number']);
    $is_single_invoice = true;
    
} else {
    // Multiple invoices view for a vendor on a specific date
    $sql = "SELECT v.* 
            FROM vendors v
            WHERE v.id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $vendor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$vendor) {
        // Vendor not found, redirect to invoice list
        $_SESSION['error_message'] = "Vendor not found.";
        header('Location: index.php');
        exit();
    }
    
    // Get all invoices for this vendor on this date
    $sql = "SELECT vi.*
            FROM vendor_invoices vi
            WHERE vi.vendor_id = ? AND DATE(vi.invoice_date) = ?
            ORDER BY CAST(vi.invoice_number AS UNSIGNED)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $vendor_id, $date);
    $stmt->execute();
    $invoices_result = $stmt->get_result();
    $invoices = [];
    $total_invoice_amount = 0;
    
    while ($row = $invoices_result->fetch_assoc()) {
        $invoices[] = $row;
        $total_invoice_amount += $row['total_amount'];
    }
    $stmt->close();
    
    if (empty($invoices)) {
        // No invoices found, redirect to invoice list
        $_SESSION['error_message'] = "No invoices found for this vendor on the selected date.";
        header('Location: index.php');
        exit();
    }
    
    // Get all invoice items
    $invoice_ids = array_column($invoices, 'id');
    $invoice_ids_str = implode(',', $invoice_ids);
    
    $sql = "SELECT vii.*, vi.invoice_number, i.name as item_name
            FROM vendor_invoice_items vii
            JOIN vendor_invoices vi ON vii.invoice_id = vi.id
            JOIN items i ON vii.item_id = i.id
            WHERE vii.invoice_id IN ($invoice_ids_str)
            ORDER BY vi.invoice_number, vii.id";
    
    $result = $conn->query($sql);
    $all_items = [];
    
    while ($row = $result->fetch_assoc()) {
        $all_items[] = $row;
    }
    
    $page_title = "All Purchase Invoices for " . htmlspecialchars($vendor['name']) . " on " . date('d/m/Y', strtotime($date));
    $is_single_invoice = false;
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
    <section class="content">
        <div class="container-fluid mt-4">
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

    <?php if ($is_single_invoice): ?>
        <!-- Single Invoice View -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title">Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?> for <?php echo htmlspecialchars($invoice['vendor_name']); ?> on <?php echo date('d/m/Y', strtotime($invoice['invoice_date'])); ?></h3>
                    <div>
                            <a href="index.php?filter_date=<?php echo date('Y-m-d', strtotime($invoice['invoice_date'])); ?>" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Invoices
                            </a>
                            <a href="../../print/vendors/invoice.php?id=<?php echo $invoice_id; ?>" class="btn btn-info" target="_blank">
                                <i class="fas fa-print"></i> Print
                            </a>
                            <a href="../../handlers/vendors/download_invoice.php?id=<?php echo $invoice_id; ?>" class="btn btn-primary">
                                <i class="fas fa-download"></i> Download
                            </a>
                            <a href="edit_invoice.php?id=<?php echo $invoice_id; ?>" class="btn btn-warning">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <?php 
                            // Only show delete button for invoices from today
                            $invoice_date = date('Y-m-d', strtotime($invoice['invoice_date']));
                            $today = date('Y-m-d');
                            if ($invoice_date === $today): 
                            ?>
                                <a href="../../handlers/vendors/delete_invoice.php?id=<?php echo $invoice_id; ?>" class="btn btn-danger" 
                                   onclick="return confirm('Are you sure you want to delete this invoice? Any associated payments will also be deleted. This action cannot be undone.')">
                                    <i class="fas fa-trash"></i> Delete
                                </a>
                            <?php endif; ?>
                </div>
            </div>

                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h5 class="mb-2">Vendor Information</h5>
                                <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($invoice['vendor_name']); ?></p>
                    <?php if (!empty($invoice['vendor_contact'])): ?>
                                    <p class="mb-1"><strong>Contact:</strong> <?php echo htmlspecialchars($invoice['vendor_contact']); ?></p>
                    <?php endif; ?>
                                <p class="mb-1"><strong>Type:</strong> <?php echo htmlspecialchars($invoice['vendor_type']); ?></p>
                                <p class="mb-1"><strong>Category:</strong> <?php echo htmlspecialchars($invoice['vendor_category']); ?></p>
                </div>
                            <div class="col-md-6 text-md-right">
                                <h5 class="mb-2">Invoice Details</h5>
                                <p class="mb-1"><strong>Date:</strong> <?php echo date('d/m/Y', strtotime($invoice['invoice_date'])); ?></p>
                                <p class="mb-1"><strong>Invoice Number:</strong> <?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                                <p class="mb-1"><strong>Total Amount:</strong> ₹<?php echo number_format($invoice['total_amount'], 2); ?></p>
                </div>
            </div>

            <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Item</th>
                                        <th>Quantity</th>
                                        <th>Weight (kg)</th>
                                        <th>Rate (₹)</th>
                                        <th>Amount (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $counter = 1;
                        $total_quantity = 0;
                        $total_weight = 0;
                        
                        while ($item = $invoice_items->fetch_assoc()):
                            $total_quantity += $item['quantity'];
                            $total_weight += $item['weight'];
                        ?>
                                        <tr>
                                <td><?php echo $counter++; ?></td>
                                            <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                            <td><?php echo number_format($item['quantity'], 2); ?></td>
                                            <td><?php echo $item['weight'] > 0 ? number_format($item['weight'], 2) : 'N/A'; ?></td>
                                            <td>₹<?php echo number_format($item['rate'], 2); ?></td>
                                            <td>₹<?php echo number_format($item['amount'], 2); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                    <tfoot>
                                    <tr>
                                        <th colspan="2" class="text-right">Total:</th>
                                        <th><?php echo number_format($total_quantity, 2); ?></th>
                                        <th><?php echo number_format($total_weight, 2); ?> kg</th>
                            <th></th>
                                        <th>₹<?php echo number_format($invoice['total_amount'], 2); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Multiple Invoices View -->
                <div class="card">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title">All Invoices for <?php echo htmlspecialchars($vendor['name']); ?> on <?php echo date('d/m/Y', strtotime($date)); ?></h3>
                    <div>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Invoices
                        </a>
                        <a href="../../print/vendors/invoice.php?vendor_id=<?php echo $vendor_id; ?>&date=<?php echo $date; ?>" class="btn btn-info" target="_blank">
                            <i class="fas fa-print"></i> Print All
                        </a>
                        <a href="../../handlers/vendors/download_invoice.php?vendor_id=<?php echo $vendor_id; ?>&date=<?php echo $date; ?>" class="btn btn-primary">
                            <i class="fas fa-download"></i> Download All
                        </a>
                        <a href="edit_combined.php?vendor_id=<?php echo $vendor_id; ?>&date=<?php echo $date; ?>" class="btn btn-warning">
                            <i class="fas fa-edit"></i> Edit All
                </a>
                <?php 
                        // Only show delete button for invoices from today
                        $invoice_date = date('Y-m-d', strtotime($date));
                $today = date('Y-m-d');
                if ($invoice_date === $today): 
                ?>
                            <a href="../../handlers/vendors/delete_combined.php?vendor_id=<?php echo $vendor_id; ?>&date=<?php echo $date; ?>" class="btn btn-danger" 
                               onclick="return confirm('Are you sure you want to delete ALL invoices for this vendor on this date? Any associated payments will also be deleted. This action cannot be undone.')">
                                <i class="fas fa-trash"></i> Delete All
                    </a>
                <?php endif; ?>
                </div>
            </div>

                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h5 class="mb-2">Vendor Information</h5>
                                <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($vendor['name']); ?></p>
                    <?php if (!empty($vendor['contact'])): ?>
                                    <p class="mb-1"><strong>Contact:</strong> <?php echo htmlspecialchars($vendor['contact']); ?></p>
                    <?php endif; ?>
                                <p class="mb-1"><strong>Type:</strong> <?php echo htmlspecialchars($vendor['type']); ?></p>
                                <p class="mb-1"><strong>Category:</strong> <?php echo htmlspecialchars($vendor['vendor_category'] ?? 'N/A'); ?></p>
                </div>
                            <div class="col-md-6 text-md-right">
                                <h5 class="mb-2">Summary</h5>
                                <p class="mb-1"><strong>Date:</strong> <?php echo date('d/m/Y', strtotime($date)); ?></p>
                                <p class="mb-1"><strong>Total Invoices:</strong> <?php echo count($invoices); ?></p>
                                <p class="mb-1"><strong>Total Amount:</strong> ₹<?php echo number_format($total_invoice_amount, 2); ?></p>
                </div>
            </div>

            <?php foreach ($invoices as $index => $inv): ?>
                            <div class="card mb-4">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Invoice #<?php echo htmlspecialchars($inv['invoice_number']); ?></h5>
                                    <div>
                                        <a href="view_invoice.php?id=<?php echo $inv['id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i> View Details
                                        </a>
                                        <a href="edit_invoice.php?id=<?php echo $inv['id']; ?>" class="btn btn-sm btn-outline-warning">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <?php
                                        // Show delete button for all invoices in combined view
                                        $created_date = date('Y-m-d', strtotime($inv['created_at'] ?? $today));
                                        if ($created_date === $today): 
                                        ?>
                                            <a href="../../handlers/vendors/delete_invoice.php?id=<?php echo $inv['id']; ?>&redirect=combined" 
                                               class="btn btn-sm btn-outline-danger"
                                               onclick="return confirm('Are you sure you want to delete invoice #<?php echo htmlspecialchars($inv['invoice_number']); ?>? Any associated payments will also be deleted. This action cannot be undone.')">
                                                <i class="fas fa-trash"></i> Delete
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" disabled title="Cannot delete invoices created on previous days">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body">
                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Item</th>
                                                    <th>Quantity</th>
                                                    <th>Weight (kg)</th>
                                                    <th>Rate (₹)</th>
                                                    <th>Amount (₹)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $counter = 1;
                                $invoice_total_quantity = 0;
                                $invoice_total_weight = 0;
                                $invoice_total_amount = 0;
                                
                                foreach ($all_items as $item):
                                    if ($item['invoice_id'] == $inv['id']):
                                        $invoice_total_quantity += $item['quantity'];
                                        $invoice_total_weight += $item['weight'];
                                        $invoice_total_amount += $item['amount'];
                                ?>
                                                    <tr>
                                        <td><?php echo $counter++; ?></td>
                                                        <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                                        <td><?php echo number_format($item['quantity'], 2); ?></td>
                                                        <td><?php echo $item['weight'] > 0 ? number_format($item['weight'], 2) : 'N/A'; ?></td>
                                                        <td>₹<?php echo number_format($item['rate'], 2); ?></td>
                                                        <td>₹<?php echo number_format($item['amount'], 2); ?></td>
                                    </tr>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </tbody>
                            <tfoot>
                                                <tr>
                                                    <th colspan="2" class="text-right">Subtotal:</th>
                                                    <th><?php echo number_format($invoice_total_quantity, 2); ?></th>
                                                    <th><?php echo number_format($invoice_total_weight, 2); ?> kg</th>
                                    <th></th>
                                                    <th>₹<?php echo number_format($invoice_total_amount, 2); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    </div>
                </div>
            <?php endforeach; ?>

                        <div class="card bg-light">
                            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                                        <h5 class="mb-0">Total Invoices: <?php echo count($invoices); ?></h5>
                                    </div>
                                    <div class="col-md-6 text-right">
                                        <h5 class="mb-0">Grand Total: ₹<?php echo number_format($total_invoice_amount, 2); ?></h5>
                    </div>
                    </div>
                </div>
            </div>
            </div>
        </div>
    <?php endif; ?>
</div>
    </section>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
