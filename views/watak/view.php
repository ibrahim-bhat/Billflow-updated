<?php
// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../layout/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

if (!isset($_GET['id'])) {
    die('Watak ID not provided');
}

$watak_id = sanitizeInput($_GET['id']);

// Get company settings
$sql = "SELECT * FROM company_settings LIMIT 1";
$company = $conn->query($sql)->fetch_assoc();

// Get watak details
$sql = "SELECT w.*, v.name as vendor_name, v.contact as vendor_contact, v.type as vendor_type, v.balance as vendor_balance
        FROM vendor_watak w
        JOIN vendors v ON w.vendor_id = v.id
        WHERE w.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $watak_id);
$stmt->execute();
$result = $stmt->get_result();
$watak = $result->fetch_assoc();

if (!$watak) {
    die('Watak not found');
}

// Get watak items
$sql = "SELECT * FROM watak_items WHERE watak_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $watak_id);
$stmt->execute();
$items_result = $stmt->get_result();
?>

<!-- Main content -->
<div class="main-content">
    <section class="content">
        <div class="container-fluid mt-4">
            <div class="card">
                <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                    <h3 class="card-title mb-2 mb-md-0">Watak #<?php echo htmlspecialchars($watak['watak_number']); ?></h3>
                    <div class="btn-group action-buttons">
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> <span class="d-none d-md-inline">Back</span>
                        </a>
                        <a href="edit.php?id=<?php echo $watak_id; ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> <span class="d-none d-md-inline">Edit</span>
                        </a>
                        <a href="../../print/watak/watak.php?id=<?php echo $watak_id; ?>" class="btn btn-info" target="_blank">
                            <i class="fas fa-print"></i> <span class="d-none d-md-inline">Print</span>
                        </a>
                        <a href="../../handlers/watak/download.php?id=<?php echo $watak_id; ?>" class="btn btn-primary">
                            <i class="fas fa-download"></i> <span class="d-none d-md-inline">Download</span>
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h5>Vendor Details</h5>
                            <p><strong>Name:</strong> <?php echo htmlspecialchars($watak['vendor_name']); ?></p>
                            <p><strong>Contact:</strong> <?php echo htmlspecialchars($watak['vendor_contact'] ?? 'N/A'); ?></p>
                            <p><strong>Type:</strong> <?php echo htmlspecialchars($watak['vendor_type'] ?? 'N/A'); ?></p>
                        </div>
                        <div class="col-md-6 text-md-right">
                            <h5>Watak Details</h5>
                            <p><strong>Date:</strong> <?php echo date('d/m/Y', strtotime($watak['date'])); ?></p>
                            <?php if (!empty($watak['vehicle_no'])): ?>
                            <p><strong>Vehicle:</strong> <?php echo htmlspecialchars($watak['vehicle_no']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Quantity</th>
                                    <th class="d-none d-md-table-cell">Weight (kg)</th>
                                    <th>Rate</th>
                                    <th class="d-none d-md-table-cell">Commission %</th>
                                    <th>Labor</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total_labor = 0;
                                $total_amount = 0;
                                
                                while ($item = $items_result->fetch_assoc()): 
                                    // Use stored labor value instead of recalculating
                                    $item_labor = $item['labor'];
                                    $total_labor += $item_labor;
                                    $total_amount += $item['amount'];
                                    
                                    // Calculate commission percentage
                                    $commission_percent = ($watak['total_amount'] > 0) ? ($watak['total_commission'] / $watak['total_amount']) * 100 : 0;
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                    <td><?php echo number_format($item['quantity'], 2); ?></td>
                                    <td class="d-none d-md-table-cell"><?php echo $item['weight'] ? number_format($item['weight'], 2) : '-'; ?></td>
                                    <td>₹<?php echo number_format($item['rate'], 2); ?></td>
                                    <td class="d-none d-md-table-cell"><?php echo number_format($commission_percent, 2); ?>%</td>
                                    <td>₹<?php echo number_format($item_labor, 2); ?></td>
                                    <td>₹<?php echo number_format($item['amount'], 2); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="5" class="text-right"><strong>Total:</strong></td>
                                    <td><strong>₹<?php echo number_format($total_labor, 2); ?></strong></td>
                                    <td><strong>₹<?php echo number_format($total_amount, 2); ?></strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="row mt-4">
                        <div class="col-md-6">
                            <div class="card mb-4">
                                <div class="card-header bg-light">
                                    <h5 class="mb-0">Additional Details</h5>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm">
                                        <tr>
                                            <th>Vehicle Charges:</th>
                                            <td class="text-right">₹<?php echo number_format(floor($watak['vehicle_charges']), 0); ?></td>
                                        </tr>
                                        <?php if (!empty($watak['bardan'])): ?>
                                        <tr>
                                            <th>Bardan:</th>
                                            <td class="text-right">₹<?php echo number_format(floor($watak['bardan']), 0); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if (!empty($watak['other_charges'])): ?>
                                        <tr>
                                            <th>Other Charges:</th>
                                            <td class="text-right">₹<?php echo number_format(floor($watak['other_charges']), 0); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h5 class="mb-0">Summary</h5>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm">
                                        <?php 
                                        // Apply rounding logic for Goods Sale Proceeds
                                        $goods_sale_proceeds = $watak['total_amount'];
                                        $decimal_part = $goods_sale_proceeds - floor($goods_sale_proceeds);
                                        if ($decimal_part >= 0.5) {
                                            $goods_sale_proceeds = ceil($goods_sale_proceeds);
                                        } else {
                                            $goods_sale_proceeds = floor($goods_sale_proceeds);
                                        }
                                        
                                        // Calculate expenses with no decimals
                                        $total_expenses_rounded = floor($watak['total_commission']) + floor($total_labor) + floor($watak['vehicle_charges']) + floor($watak['other_charges']) + floor($watak['bardan'] ?? 0);
                                        ?>
                                        <tr>
                                            <th>Total Amount:</th>
                                            <td class="text-right">₹<?php echo number_format($goods_sale_proceeds, 0); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Commission Amount:</th>
                                            <td class="text-right">₹<?php echo number_format(floor($watak['total_commission']), 0); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Net Payable:</th>
                                            <td class="text-right"><strong>₹<?php echo number_format(floor($goods_sale_proceeds - $total_expenses_rounded), 0); ?></strong></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?> 