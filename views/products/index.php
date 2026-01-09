<?php
// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../layout/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Process Add Item form
if (isset($_POST['add_item'])) {
    $name = sanitizeInput($_POST['name']);
    
    if (!empty($name)) {
        $sql = "INSERT INTO items (name) VALUES (?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $name);
        
        if ($stmt->execute()) {
            $success_message = "Item added successfully!";
        } else {
            $error_message = "Error adding item: " . $conn->error;
        }
        
        $stmt->close();
    } else {
        $error_message = "Item name is required!";
    }
}

// Get all items with their total and remaining stock
$sql = "SELECT i.*, 
        COALESCE(SUM(ii.quantity_received), 0) as total_stock,
        COALESCE(SUM(ii.remaining_stock), 0) as current_stock
        FROM items i
        LEFT JOIN inventory_items ii ON i.id = ii.item_id
        GROUP BY i.id
        ORDER BY i.name";
$result = $conn->query($sql);

// Get totals for dashboard cards
$sql_items = "SELECT COUNT(*) as total_items FROM items";
$items_result = $conn->query($sql_items);
$total_items = $items_result->fetch_assoc()['total_items'];

$sql_stock = "SELECT COALESCE(SUM(remaining_stock), 0) as total_stock FROM inventory_items";
$stock_result = $conn->query($sql_stock);
$total_stock = $stock_result->fetch_assoc()['total_stock'];

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

<div class="main-content" >
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1>Items</h1>
            <p>Manage your items and inventory</p>
        </div>
        <div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="fas fa-plus"></i> Add Item
            </button>
        </div>
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
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="dashboard-card">
                <h5>Total Items</h5>
                <div class="value"><?php echo number_format($total_items); ?></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="dashboard-card">
                <h5>Total Stock</h5>
                <div class="value"><?php echo number_format($total_stock, 2); ?></div>
            </div>
        </div>
    </div>

    <!-- Items Table -->
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($item = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                            
                            <td>
                                <div class="btn-group btn-group-sm">
                                   
                                    <button type="button" class="btn btn-outline-warning" title="Edit" 
                                            data-bs-toggle="modal" data-bs-target="#editItemModal" 
                                            data-item-id="<?php echo $item['id']; ?>"
                                            data-item-name="<?php echo htmlspecialchars($item['name']); ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="text-center">No items found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1" aria-labelledby="addItemModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addItemModalLabel">Add New Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Item Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_item" class="btn btn-primary">Add Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Item Modal -->
<div class="modal fade" id="editItemModal" tabindex="-1" aria-labelledby="editItemModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editItemModalLabel">Edit Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="../../handlers/inventory/process_item.php">
                <div class="modal-body">
                    <input type="hidden" name="item_id" id="edit_item_id">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Item Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_item" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Edit Item Modal
document.getElementById('editItemModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const itemId = button.getAttribute('data-item-id');
    const itemName = button.getAttribute('data-item-name');
    
    document.getElementById('edit_item_id').value = itemId;
    document.getElementById('edit_name').value = itemName;
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?> 