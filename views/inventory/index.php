<?php
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../layout/header.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Simple test to verify page is loading
echo "<!-- Page is loading... -->";

// Check database connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

echo "<!-- Database connected successfully -->";

// Function to get items for a specific vendor and date
function getVendorItems($conn, $vendorId, $date) {
    $sql = "SELECT 
            ii.id as inventory_item_id,
            inv.id as inventory_id,
            i.id as item_id,
            i.name as item_name,
            ii.quantity_received,
            ii.remaining_stock
            FROM inventory inv
            JOIN inventory_items ii ON inv.id = ii.inventory_id
            JOIN items i ON ii.item_id = i.id
            WHERE inv.vendor_id = ? AND DATE(inv.date_received) = ?
            ORDER BY ii.id";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("is", $vendorId, $date);
    $stmt->execute();
    return $stmt->get_result();
}

// Get all inventory grouped by date and vendor with stock status
$sql = "SELECT 
        inv.date_received,
        v.id as vendor_id,
        v.name as vendor_name,
        COUNT(DISTINCT ii.item_id) as item_count,
        SUM(CASE WHEN ii.remaining_stock > 0 THEN 1 ELSE 0 END) as items_with_stock,
        SUM(CASE WHEN ii.remaining_stock <= 0 THEN 1 ELSE 0 END) as items_sold_out,
        SUM(ii.remaining_stock) as total_remaining_stock
        FROM inventory inv
        JOIN vendors v ON inv.vendor_id = v.id
        JOIN inventory_items ii ON inv.id = ii.inventory_id
        GROUP BY inv.date_received, v.id, v.name
        ORDER BY inv.date_received DESC, v.name";

$result = $conn->query($sql);

// Check for SQL errors
if (!$result) {
    die("Query failed: " . $conn->error);
}

// Organize data by date
$inventoryByDate = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $date = $row['date_received'];
        if (!isset($inventoryByDate[$date])) {
            $inventoryByDate[$date] = [];
        }
        $inventoryByDate[$date][] = $row;
    }
}
?>

<!-- Main content -->
<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1>Inventory Management</h1>
            <p>Track and manage your inventory by date and vendor</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addInventoryModal">
                <i class="fas fa-plus-circle"></i> Add Inventory
            </button>
            <a href="vendor_report.php?date=<?php echo date('Y-m-d'); ?>" class="btn btn-warning">
                <i class="fas fa-file-alt"></i> Generate Report
            </a>
        </div>
    </div>

    <?php if (empty($inventoryByDate)): ?>
        <div class="dashboard-card text-center py-5">
            <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
            <h5 class="text-muted">No Inventory Records Found</h5>
            <p class="text-muted">Start by adding your first inventory entry</p>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addInventoryModal">
                <i class="fas fa-plus"></i> Add Inventory
            </button>
        </div>
    <?php else: ?>
        <?php foreach ($inventoryByDate as $date => $vendors): ?>
            <div class="dashboard-card mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="d-flex align-items-center gap-3">
                        <h5 class="mb-0">
                            <i class="fas fa-calendar-day text-primary me-2"></i>
                            <?php echo date('d F Y', strtotime($date)); ?>
                        </h5>
                        <span class="badge bg-info"><?php echo count($vendors); ?> Vendor(s)</span>
                    </div>
                    <button class="btn btn-outline-secondary btn-sm" 
                            onclick="toggleDateSection('<?php echo $date; ?>')" 
                            id="toggle-btn-<?php echo $date; ?>">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>
                
                <div class="collapsible-content" id="section-<?php echo $date; ?>" style="display: none;">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Vendor Name</th>
                                    <th>Status</th>
                                    <th>Items</th>
                                    <th>Stock</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($vendors as $vendor): ?>
                                <?php
                                $allItemsSoldOut = $vendor['items_with_stock'] == 0 && $vendor['item_count'] > 0;
                                $statusClass = $allItemsSoldOut ? 'bg-success' : 'bg-warning';
                                $statusText = $allItemsSoldOut ? 'All Sold' : 'In Stock';
                                $statusIcon = $allItemsSoldOut ? 'fa-check-circle' : 'fa-boxes';
                                $rowClass = $allItemsSoldOut ? 'table-success' : 'table-warning';
                                
                                // Get items for this vendor
                                $items = getVendorItems($conn, $vendor['vendor_id'], $date);
                                
                                // Get vendor category
                                $vendorCategoryQuery = "SELECT vendor_category FROM vendors WHERE id = ?";
                                $vendorCategoryStmt = $conn->prepare($vendorCategoryQuery);
                                $vendorCategoryStmt->bind_param("i", $vendor['vendor_id']);
                                $vendorCategoryStmt->execute();
                                $vendorCategoryResult = $vendorCategoryStmt->get_result();
                                $vendorCategory = $vendorCategoryResult->fetch_assoc();
                                $vendorCategoryStmt->close();
                                $isPurchaseBased = $vendorCategory && $vendorCategory['vendor_category'] === 'Purchase Based';
                                ?>
                                <tr class="<?php echo $rowClass; ?>">
                                    <td>
                                        <strong class="<?php echo $allItemsSoldOut ? 'text-success' : 'text-danger'; ?>">
                                            <i class="fas <?php echo $statusIcon; ?> me-2"></i>
                                            <?php echo htmlspecialchars($vendor['vendor_name']); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $statusClass; ?>">
                                            <?php echo $statusText; ?>
                                        </span>
                                    </td>
                                    <td class="<?php echo $allItemsSoldOut ? 'text-success' : 'text-danger'; ?>">
                                        <strong><?php echo $vendor['item_count']; ?></strong> item(s)
                                        <?php if (!$allItemsSoldOut): ?>
                                            <small class="text-muted">(<?php echo $vendor['items_with_stock']; ?> with stock)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $allItemsSoldOut ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo number_format($vendor['total_remaining_stock'], 0); ?> units
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1 justify-content-end align-items-center">
                                            <button class="btn btn-outline-info btn-sm" 
                                                    onclick="toggleVendorItems('<?php echo $date; ?>-<?php echo $vendor['vendor_id']; ?>')" 
                                                    title="View Items">
                                                <i class="fas fa-eye"></i>
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
                                                           onclick="showVendorHistory('<?php echo $vendor['vendor_id']; ?>', '<?php echo $date; ?>', '<?php echo htmlspecialchars($vendor['vendor_name']); ?>'); return false;">
                                                            <i class="fas fa-history me-2"></i> View History
                                                        </a>
                                                    </li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <?php if ($isPurchaseBased): ?>
                                                    <li>
                                                        <a class="dropdown-item" href="#" 
                                                           onclick="createPurchaseInvoice(<?php echo $vendor['vendor_id']; ?>, '<?php echo $date; ?>', '<?php echo htmlspecialchars($vendor['vendor_name']); ?>'); return false;">
                                                            <i class="fas fa-file-invoice me-2"></i> Create Invoice
                                                        </a>
                                                    </li>
                                                    <?php else: ?>
                                                    <li>
                                                        <a class="dropdown-item" href="../../handlers/inventory/create_watak.php?vendor_id=<?php echo $vendor['vendor_id']; ?>&date=<?php echo $date; ?>">
                                                            <i class="fas fa-plus me-2"></i> Create Watak
                                                        </a>
                                                    </li>
                                                    <?php endif; ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <a class="dropdown-item text-danger" href="#" 
                                                           onclick="deleteVendorItems(<?php echo $vendor['vendor_id']; ?>, '<?php echo htmlspecialchars($vendor['vendor_name']); ?>', '<?php echo $date; ?>'); return false;">
                                                            <i class="fas fa-trash me-2"></i> Delete All Items
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <!-- Items Row (Collapsible) -->
                                <tr class="collapsible-content" id="items-<?php echo $date; ?>-<?php echo $vendor['vendor_id']; ?>" style="display: none;">
                                    <td colspan="5" class="p-0">
                                        <div class="items-detail-container">
                                            <?php if ($items->num_rows > 0): ?>
                                                <table class="table table-sm mb-0">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Item Name</th>
                                                            <th>Received Qty</th>
                                                            <th>Remaining Qty</th>
                                                            <th>Status</th>
                                                            <th class="text-end">Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php while ($item = $items->fetch_assoc()): ?>
                                                        <?php
                                                        $isAllSold = $item['remaining_stock'] <= 0;
                                                        $itemStatusClass = $isAllSold ? 'success' : 'danger';
                                                        $itemStatusText = $isAllSold ? 'Sold Out' : 'Available';
                                                        ?>
                                                        <tr>
                                                            <td>
                                                                <i class="fas <?php echo $isAllSold ? 'fa-check-circle text-success' : 'fa-box text-danger'; ?> me-2"></i>
                                                                <?php echo htmlspecialchars($item['item_name']); ?>
                                                            </td>
                                                            <td><?php echo number_format($item['quantity_received'], 0); ?></td>
                                                            <td><?php echo number_format($item['remaining_stock'], 0); ?></td>
                                                            <td>
                                                                <span class="badge bg-<?php echo $itemStatusClass; ?>">
                                                                    <?php echo $itemStatusText; ?>
                                                                </span>
                                                            </td>
                                                            <td class="text-end">
                                                                <button class="btn btn-outline-danger btn-sm" 
                                                                        onclick="softDeleteItem(<?php echo $item['inventory_item_id']; ?>, <?php echo $item['inventory_id']; ?>, '<?php echo htmlspecialchars($item['item_name']); ?>')"
                                                                        title="Delete Item">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    <?php endwhile; ?>
                                                    </tbody>
                                                </table>
                                            <?php else: ?>
                                                <div class="p-3 text-center text-muted">
                                                    <i class="fas fa-info-circle me-2"></i>No items found
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Vendor History Modal -->
<div class="modal fade" id="vendorHistoryModal" tabindex="-1" aria-labelledby="vendorHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="vendorHistoryModalLabel">Vendor Purchase History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="vendorHistoryModalBody">
                <!-- Content will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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

function toggleDateSection(date) {
    const section = document.getElementById('section-' + date);
    const toggleBtn = document.getElementById('toggle-btn-' + date);
    const icon = toggleBtn.querySelector('i');
    
    if (section.style.display === 'none') {
        section.style.display = 'block';
        icon.className = 'fas fa-chevron-up';
    } else {
        section.style.display = 'none';
        icon.className = 'fas fa-chevron-down';
    }
}

function toggleVendorItems(vendorId) {
    const itemsRow = document.getElementById('items-' + vendorId);
    
    if (itemsRow.style.display === 'none') {
        itemsRow.style.display = 'table-row';
    } else {
        itemsRow.style.display = 'none';
    }
}

function softDeleteItem(inventoryItemId, inventoryId, itemName) {
    if (confirm('Are you sure you want to delete "' + itemName + '"? This item will be moved to deleted inventory.')) {
        // Mark item as deleted via AJAX
        fetch('../../handlers/inventory/soft_delete.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'inventory_item_id=' + inventoryItemId + '&inventory_id=' + inventoryId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Reload the page to reflect changes
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Failed to delete item'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error deleting item. Please try again.');
        });
    }
}

function showVendorHistory(vendorId, date, vendorName) {
    // Update modal title
    document.getElementById('vendorHistoryModalLabel').textContent = `Purchase History - ${vendorName} (${date})`;
    
    // Show loading state
    document.getElementById('vendorHistoryModalBody').innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading history...</div>';
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('vendorHistoryModal'));
    modal.show();
    
    // Fetch vendor history data
    fetch(`../../api/vendors/get_history.php?vendor_id=${vendorId}&date=${date}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '';
                
                // Add overall summary boxes
                /*
                if (data.overall_summary) {
                    html += '<div class="row mb-4">';
                    html += '<div class="col-md-3"><div class="summary-box"><div class="title">Total Quantity</div><div class="value">' + data.overall_summary.total_quantity + '</div></div></div>';
                    html += '<div class="col-md-3"><div class="summary-box"><div class="title">Total Weight</div><div class="value">' + data.overall_summary.total_weight + ' kg</div></div></div>';
                    html += '<div class="col-md-3"><div class="summary-box"><div class="title">Total Amount</div><div class="value">₹' + data.overall_summary.total_amount + '</div></div></div>';
                    html += '<div class="col-md-3"><div class="summary-box"><div class="title">Average Rate</div><div class="value">₹' + data.overall_summary.average_rate + '</div></div></div>';
                    html += '</div>';
                }
                */
                
                // Add items grouped by item
                if (data.items && data.items.length > 0) {
                    data.items.forEach((item, index) => {
                        // Item header with color coding
                        const itemColorClass = item.is_sold_out ? 'text-success' : 'text-danger';
                        const itemIcon = item.is_sold_out ? 'fa-check-circle' : 'fa-exclamation-circle';
                        
                        html += '<div class="item-section mb-4">';
                        html += '<div class="item-header">';
                        html += '<h5 class="' + itemColorClass + '">';
                        html += '<i class="fas ' + itemIcon + ' me-2"></i>';
                        html += item.item_name;
                        html += '</h5>';
                        html += '<div class="item-info">';
                        html += '<small class="text-muted">Received: ' + item.quantity_received + ' | Remaining: ' + item.remaining_stock + ' | Purchase Rate: ₹' + item.purchase_rate + '</small>';
                        html += '</div>';
                        html += '</div>';
                        
                        // Item summary boxes
                        html += '<div class="row mb-3">';
                        html += '<div class="col-md-3"><div class="summary-box item-summary"><div class="title">Total Quantity</div><div class="value">' + item.summary.total_quantity + '</div></div></div>';
                        html += '<div class="col-md-3"><div class="summary-box item-summary"><div class="title">Total Weight</div><div class="value">' + item.summary.total_weight + ' kg</div></div></div>';
                        html += '<div class="col-md-3"><div class="summary-box item-summary"><div class="title">Total Amount</div><div class="value">₹' + item.summary.total_amount + '</div></div></div>';
                        html += '<div class="col-md-3"><div class="summary-box item-summary"><div class="title">Average Rate</div><div class="value">₹' + item.summary.average_rate + '</div></div></div>';
                        html += '</div>';
                        
                        // Item purchase history table
                        if (item.history && item.history.length > 0) {
                            html += '<div class="table-responsive">';
                            html += '<table class="table table-bordered table-striped item-history-table">';
                            html += '<thead><tr>';
                            html += '<th>Purchase Date</th>';
                            html += '<th>Quantity</th>';
                            html += '<th>Weight</th>';
                            html += '<th>Rate</th>';
                            html += '<th>Total Amount</th>';
                            html += '</tr></thead>';
                            html += '<tbody>';
                            
                            item.history.forEach(function(purchase) {
                                html += '<tr>';
                                html += '<td>' + purchase.date + '</td>';
                                html += '<td>' + purchase.quantity + '</td>';
                                html += '<td>' + purchase.weight + '</td>';
                                html += '<td>₹' + purchase.rate + '</td>';
                                html += '<td>₹' + purchase.amount + '</td>';
                                html += '</tr>';
                            });
                            
                            html += '</tbody></table></div>';
                        } else {
                            html += '<div class="alert alert-info">No purchase history found for this item</div>';
                        }
                        
                        // Add separator line between items (except for the last item)
                        if (index < data.items.length - 1) {
                            html += '<hr class="item-separator">';
                        }
                        
                        html += '</div>'; // Close item-section
                    });
                } else {
                    html += '<div class="alert alert-info">No items found for this vendor on the selected date</div>';
                }
                
                document.getElementById('vendorHistoryModalBody').innerHTML = html;
            } else {
                document.getElementById('vendorHistoryModalBody').innerHTML = '<div class="alert alert-danger">Error loading vendor history: ' + (data.error || 'Unknown error') + '</div>';
            }
        })
        .catch(error => {
            console.error('Error loading vendor history:', error);
            document.getElementById('vendorHistoryModalBody').innerHTML = '<div class="alert alert-danger">Error loading vendor history: ' + error.message + '</div>';
        });
}




function createPurchaseInvoice(vendorId, date, vendorName) {
    // Redirect to purchase invoice creation page with parameters
    const url = `../vendors/index.php?action=create_invoice&vendor_id=${vendorId}&date=${date}&vendor_name=${encodeURIComponent(vendorName)}`;
    window.open(url, '_blank');
}

function deleteVendorItems(vendorId, vendorName, date) {
    if (confirm(`Delete all items for ${vendorName} from ${date}?`)) {
        // Show loading state
        const button = event.target.closest('button');
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> DELETING...';
        button.disabled = true;

        // Call the deletion script
        fetch('../../handlers/vendors/delete_items.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `vendor_id=${vendorId}&date=${date}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(`Deleted ${data.deleted_count} items successfully`);
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Failed to delete'));
                button.innerHTML = originalText;
                button.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error deleting items');
            button.innerHTML = originalText;
            button.disabled = false;
        });
    }
}

</script>

<?php require_once __DIR__ . '/../components/inventory_modal.php'; ?>

<?php require_once __DIR__ . '/../layout/footer.php'; ?> 