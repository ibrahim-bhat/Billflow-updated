<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../layout/header.php';

$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$invoice_id) {
    header('Location: ../invoices/index.php');
    exit();
}

// Load single invoice header
$invoice_sql = "SELECT ci.*, c.name as customer_name, c.contact as customer_contact,
                       c.location as customer_location, c.balance as customer_balance
                FROM customer_invoices ci
                JOIN customers c ON ci.customer_id = c.id
                WHERE ci.id = ?";
$stmt = $conn->prepare($invoice_sql);
$stmt->bind_param('i', $invoice_id);
$stmt->execute();
$invoice_rs = $stmt->get_result();
if ($invoice_rs->num_rows === 0) {
    header('Location: ../invoices/index.php');
    exit();
}
$invoice = $invoice_rs->fetch_assoc();

$customer = [
    'id' => $invoice['customer_id'],
    'name' => $invoice['customer_name'],
    'contact' => $invoice['customer_contact'],
    'location' => $invoice['customer_location'],
    'balance' => $invoice['customer_balance']
];

// Prefer display_date for showing
$date_for_view = $invoice['display_date'] ?: $invoice['date'];
$formatted_date = date('d/m/Y', strtotime($date_for_view));

// Load items
$items_sql = "SELECT cii.*, i.name as item_name, v.name as vendor_name
              FROM customer_invoice_items cii
              JOIN items i ON cii.item_id = i.id
              LEFT JOIN vendors v ON cii.vendor_id = v.id
              WHERE cii.invoice_id = ?
              ORDER BY cii.id";
$items_stmt = $conn->prepare($items_sql);
$items_stmt->bind_param('i', $invoice_id);
$items_stmt->execute();
$items_rs = $items_stmt->get_result();

$total_amount = $invoice['total_amount'];
?>

<div class="main-content">
    <section class="content">
        <div class="container-fluid mt-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title">Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?> for <?php echo htmlspecialchars($customer['name']); ?> on <?php echo $formatted_date; ?></h3>
                    <div>
                        <a href="../invoices/index.php?filter_date=<?php echo date('Y-m-d', strtotime($invoice['date'])); ?>" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Invoices
                        </a>
                        <a href="../../print/vendors/combined_invoices.php?id=<?php echo $invoice_id; ?>" class="btn btn-info" target="_blank">
                            <i class="fas fa-print"></i> Print
                        </a>
                        <a href="../../handlers/invoices/download_combined.php?id=<?php echo $invoice_id; ?>" class="btn btn-primary">
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
                            <p class="mb-1"><strong>Invoice Number:</strong> <?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                            <p class="mb-1"><strong>Total Amount:</strong> ₹<?php echo number_format($total_amount, 2); ?></p>
                        </div>
                    </div>

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
                                <?php $counter = 1; $sum = 0; while ($item = $items_rs->fetch_assoc()): $sum += $item['amount']; ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                    <td><?php echo htmlspecialchars($item['vendor_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo number_format($item['quantity'], 2); ?></td>
                                    <td><?php echo $item['weight'] > 0 ? number_format($item['weight'], 2) : 'N/A'; ?></td>
                                    <td>₹<?php echo number_format($item['rate'], 2); ?></td>
                                    <td>₹<?php echo number_format($item['amount'], 2); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="6" class="text-right">Total:</th>
                                    <th>₹<?php echo number_format($total_amount ?: $sum, 2); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
