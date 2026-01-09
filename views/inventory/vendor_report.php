<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../layout/header.php';
require_once __DIR__ . '/../../core/helpers/numbering_helper.php';

// Get date parameter, default to today
$report_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Get all vendors who received items on this date
$sql = "SELECT DISTINCT v.id, v.name 
        FROM vendors v
        JOIN inventory inv ON v.id = inv.vendor_id
        WHERE DATE(inv.date_received) = ?
        ORDER BY v.name";

$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $report_date);
$stmt->execute();
$vendors_result = $stmt->get_result();

// Handle watak creation if requested
if (isset($_POST['create_watak'])) {
    $vendor_id = intval($_POST['vendor_id']);
    $inventory_date = $_POST['inventory_date'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Create watak entry
        $sql = "INSERT INTO vendor_watak (vendor_id, watak_number, date, total_amount, net_payable) 
                VALUES (?, ?, ?, 0, 0)";
        $stmt = $conn->prepare($sql);
        $next_number = getNextWatakNumber($conn);
        $watak_number = formatWatakNumber($next_number);
        $stmt->bind_param('iss', $vendor_id, $watak_number, $inventory_date);
        $stmt->execute();
        $watak_id = $conn->insert_id;

        // Get inventory items for this vendor and date
        $sql = "SELECT 
                i.name as item_name,
                ii.quantity_received as quantity,
                COALESCE(
                    (SELECT vbi.rate 
                     FROM vendor_bill_items vbi 
                     JOIN vendor_bills vb ON vbi.bill_id = vb.id 
                     WHERE vbi.item_id = i.id 
                     AND vb.vendor_id = inv.vendor_id
                     AND DATE(vb.date) = DATE(inv.date_received)
                     LIMIT 1), 
                    i.default_rate
                ) as rate
                FROM inventory inv
                JOIN inventory_items ii ON inv.id = ii.inventory_id
                JOIN items i ON ii.item_id = i.id
                WHERE inv.vendor_id = ? AND DATE(inv.date_received) = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('is', $vendor_id, $inventory_date);
        $stmt->execute();
        $items_result = $stmt->get_result();

        $total_amount = 0;
        
        // Insert watak items
        while ($item = $items_result->fetch_assoc()) {
            $amount = $item['quantity'] * $item['rate'];
            $total_amount += $amount;
            
            $sql = "INSERT INTO watak_items (watak_id, item_name, quantity, rate, amount) 
                    VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('isddd', $watak_id, $item['item_name'], $item['quantity'], $item['rate'], $amount);
            $stmt->execute();
        }

        // Update watak totals with rounding logic
        // Apply rounding logic (same as other watak creation)
        // 1. Goods Sale Proceeds: If decimal >= 0.5, round up by 1 rupee; if < 0.5, keep current amount and remove decimal
        $goods_sale_proceeds = $total_amount;
        $decimal_part = $goods_sale_proceeds - floor($goods_sale_proceeds);
        if ($decimal_part >= 0.5) {
            $goods_sale_proceeds = ceil($goods_sale_proceeds);
        } else {
            $goods_sale_proceeds = floor($goods_sale_proceeds);
        }
        
        // 2. Net Amount: Remove all decimals (round down) - for this simple case, net_payable = goods_sale_proceeds
        $net_payable = floor($goods_sale_proceeds);
        
        $sql = "UPDATE vendor_watak SET total_amount = ?, net_payable = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ddi', $goods_sale_proceeds, $net_payable, $watak_id);
        $stmt->execute();

        // Commit transaction
        $conn->commit();
        
        // Set success message
        $_SESSION['success_message'] = "Watak created successfully! Watak ID: " . $watak_id . ", Date: " . $inventory_date;
        
        // Redirect to the watak listing page with the correct date filter
        header("Location: ../watak/index.php?filter_date=" . $inventory_date);
        exit;
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $error_message = "Error creating watak: " . $e->getMessage();
    }
}
?>

<!-- Main content -->
<div class="main-content">
    <section class="content">
        <div class="container-fluid mt-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2>Vendor Report for <?php echo date('d/m/Y', strtotime($report_date)); ?></h2>
                </div>
                <div class="d-flex gap-2">
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Inventory
                    </a>
                    <form class="d-flex">
                        <input type="date" class="form-control" name="date" value="<?php echo $report_date; ?>" onchange="this.form.submit()">
                    </form>
                </div>
            </div>

            <?php if (isset($error_message)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
            <?php endif; ?>

            <?php if ($vendors_result->num_rows === 0): ?>
            <div class="alert alert-info">
                No inventory records found for this date.
            </div>
            <?php endif; ?>

            <?php while ($vendor = $vendors_result->fetch_assoc()): 
                // Get items received by this vendor on this date
                $sql = "SELECT 
                        i.name as item_name,
                        ii.quantity_received,
                        ii.remaining_stock,
                        COALESCE(
                            (SELECT vii.rate 
                             FROM vendor_invoice_items vii 
                             JOIN vendor_invoices vi ON vii.invoice_id = vi.id 
                             WHERE vii.item_id = i.id 
                             AND vi.vendor_id = inv.vendor_id
                             AND DATE(vi.date) = DATE(inv.date_received)
                             LIMIT 1), 
                            i.default_rate
                        ) as rate,
                        inv.date_received,
                        (ii.quantity_received * COALESCE(
                            (SELECT vii.rate 
                             FROM vendor_invoice_items vii 
                             JOIN vendor_invoices vi ON vii.invoice_id = vi.id 
                             WHERE vii.item_id = i.id 
                             AND vi.vendor_id = inv.vendor_id
                             AND DATE(vi.date) = DATE(inv.date_received)
                             LIMIT 1), 
                            i.default_rate
                        )) as total_amount
                        FROM inventory inv
                        JOIN inventory_items ii ON inv.id = ii.inventory_id
                        JOIN items i ON ii.item_id = i.id
                        WHERE inv.vendor_id = ? AND DATE(inv.date_received) = ?
                        ORDER BY ii.id";
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('is', $vendor['id'], $report_date);
                $stmt->execute();
                $items_result = $stmt->get_result();
            ?>
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><?php echo htmlspecialchars($vendor['name']); ?></h5>
                    <form method="post" action="../../handlers/inventory/create_watak.php" class="d-inline">
                        <input type="hidden" name="vendor_id" value="<?php echo $vendor['id']; ?>">
                        <input type="hidden" name="inventory_date" value="<?php echo $report_date; ?>">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-file-invoice"></i> Create Watak
                        </button>
                    </form>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped vendorTable">
                            <thead>
                                <tr>
                                    <th>Item Name</th>
                                    <th>Received Qty</th>
                                    <th>Remaining Qty</th>
                                    <th>Rate</th>
                                    <th>Total Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total_amount = 0;
                                while ($item = $items_result->fetch_assoc()): 
                                    $total_amount += $item['total_amount'];
                                    $status = $item['remaining_stock'] > 0 ? 
                                        '<span class="badge bg-success">✓</span>' : 
                                        '<span class="badge bg-danger">▼</span>';
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                        <td><?php echo number_format($item['quantity_received'], 2); ?></td>
                                        <td><?php echo number_format($item['remaining_stock'], 2); ?></td>
                                        <td>₹<?php echo number_format($item['rate'] ?? 0, 2); ?></td>
                                        <td>₹<?php echo number_format($item['total_amount'] ?? 0, 2); ?></td>
                                        <td><?php echo $status; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="4" class="text-end"><strong>Total:</strong></td>
                                    <td><strong>₹<?php echo number_format($total_amount, 2); ?></strong></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    </section>
</div>

<script>
$(document).ready(function() {
    $('.vendorTable').DataTable({
        "order": [[0, "asc"]],
        "pageLength": 25
    });
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?> 