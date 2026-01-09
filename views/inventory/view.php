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

// Get inventory details
if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$inventory_id = sanitizeInput($_GET['id']);

$sql = "SELECT i.*, v.name as vendor_name, v.type as vendor_type
        FROM inventory i
        JOIN vendors v ON i.vendor_id = v.id
        WHERE i.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $inventory_id);
$stmt->execute();
$inventory = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$inventory) {
    header('Location: index.php');
    exit();
}

// Get inventory items
$sql = "SELECT ii.*, i.name as item_name
        FROM inventory_items ii
        JOIN items i ON ii.item_id = i.id
        WHERE ii.inventory_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $inventory_id);
$stmt->execute();
$items_result = $stmt->get_result();
$stmt->close();
?>

<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1>View Inventory</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../products/index.php">Products</a></li>
                    <li class="breadcrumb-item active">View Inventory</li>
                </ol>
            </nav>
        </div>
        <div>
            <button type="button" class="btn btn-outline-primary" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-6">
                    <h5>Vendor Details</h5>
                    <table class="table table-borderless table-sm">
                        <tr>
                            <th width="150">Name:</th>
                            <td><?php echo htmlspecialchars($inventory['vendor_name']); ?></td>
                        </tr>
                        <tr>
                            <th>Type:</th>
                            <td><?php echo htmlspecialchars($inventory['vendor_type']); ?></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <h5>Inventory Details</h5>
                    <table class="table table-borderless table-sm">
                        <tr>
                            <th width="150">Date Received:</th>
                            <td><?php echo date('d M Y', strtotime($inventory['date_received'])); ?></td>
                        </tr>
                        <tr>
                            <th>Vehicle No:</th>
                            <td><?php echo htmlspecialchars($inventory['vehicle_no'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <th>Vehicle Charges:</th>
                            <td>₹<?php echo number_format($inventory['vehicle_charges'], 2); ?></td>
                        </tr>
                        <tr>
                            <th>Bardan:</th>
                            <td>₹<?php echo $inventory['bardan'] ? number_format($inventory['bardan'], 2) : '-'; ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <h5>Items</h5>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th class="text-end">Quantity Received</th>
                            <th class="text-end">Remaining Stock</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($item = $items_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                <td class="text-end"><?php echo number_format($item['quantity'], 2); ?></td>
                                <td class="text-end"><?php echo number_format($item['remaining_stock'], 2); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?> 