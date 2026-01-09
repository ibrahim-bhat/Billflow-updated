<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../views/layout/header.php';

// Check if a date is provided
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Format for display
$display_date = date('d/m/Y', strtotime($date));

// Get vendors with inventory on this date
$vendor_sql = "SELECT DISTINCT 
                v.id as vendor_id,
                v.name as vendor_name
               FROM inventory inv
               JOIN vendors v ON inv.vendor_id = v.id
               WHERE DATE(inv.date_received) = ?
               ORDER BY v.name";

$vendor_stmt = $conn->prepare($vendor_sql);
$vendor_stmt->bind_param("s", $date);
$vendor_stmt->execute();
$vendors_result = $vendor_stmt->get_result();

// Check if we have any inventory for this date
$has_inventory = ($vendors_result->num_rows > 0);

// Prepare summary data
$total_qty = 0;
$total_weight = 0;
$total_amount = 0;
$rate_sum = 0;
$rate_count = 0;

// Function to get item purchase history
function getItemPurchaseHistory($conn, $item_id, $vendor_id) {
    $customer_history_sql = "SELECT 
        DATE_FORMAT(ci.date, '%d/%m/%Y') as date,
        cii.quantity,
        cii.weight,
        cii.rate,
        cii.amount
        FROM customer_invoice_items cii
        JOIN customer_invoices ci ON cii.invoice_id = ci.id
        WHERE cii.item_id = ? AND cii.vendor_id = ?
        ORDER BY ci.date DESC";

    $customer_history_stmt = $conn->prepare($customer_history_sql);
    $customer_history_stmt->bind_param('ii', $item_id, $vendor_id);
    $customer_history_stmt->execute();
    $customer_history_result = $customer_history_stmt->get_result();

    // Calculate totals
    $total_customer_qty = 0;
    $total_customer_weight = 0;
    $total_customer_amount = 0;
    $customer_data = [];

    while ($row = $customer_history_result->fetch_assoc()) {
        $total_customer_qty += $row['quantity'];
        $total_customer_weight += ($row['weight'] ?? 0);
        $total_customer_amount += $row['amount'];
        $customer_data[] = $row;
    }

    // Calculate average rate correctly
    $average_rate = 0;
    if ($total_customer_weight > 0) {
        $average_rate = $total_customer_amount / $total_customer_weight;
    } elseif ($total_customer_qty > 0) {
        $average_rate = $total_customer_amount / $total_customer_qty;
    }

    return [
        'history' => $customer_data,
        'total_qty' => $total_customer_qty,
        'total_weight' => $total_customer_weight,
        'total_amount' => $total_customer_amount,
        'average_rate' => $average_rate
    ];
}
?>

<div class="main-content">
    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Vendor Report for <?php echo $display_date; ?></h1>
            <a href="../../views/inventory/index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Inventory
            </a>
        </div>

        <?php if (!$has_inventory): ?>
            <div class="alert alert-info">No inventory records found for this date</div>
        <?php else: ?>
            <?php
            // Summary section - Get aggregate data first
            $summary_sql = "SELECT 
                          COUNT(DISTINCT ii.id) as total_items,
                          SUM(ii.quantity_received) as total_qty
                         FROM inventory inv
                         JOIN inventory_items ii ON inv.id = ii.inventory_id
                         WHERE DATE(inv.date_received) = ?";
            
            $summary_stmt = $conn->prepare($summary_sql);
            $summary_stmt->bind_param("s", $date);
            $summary_stmt->execute();
            $summary = $summary_stmt->get_result()->fetch_assoc();
            
            // Display summary in left-aligned format like the screenshot
            echo '<div class="report-summary">';
            
            // Get individual entries for the summary part
            $entries_sql = "SELECT 
             ii.quantity_received as qty, 
             i.id as item_id,
             i.name as item_name
            FROM inventory inv
            JOIN inventory_items ii ON inv.id = ii.inventory_id
            JOIN items i ON ii.item_id = i.id
            WHERE DATE(inv.date_received) = ?
            ORDER BY ii.id";
            
            $entries_stmt = $conn->prepare($entries_sql);
            $entries_stmt->bind_param("s", $date);
            $entries_stmt->execute();
            $entries_result = $entries_stmt->get_result();
            
            while ($entry = $entries_result->fetch_assoc()) {
                echo '<div class="summary-entry">';
                echo $entry['qty'] . ' - ' . $entry['item_id'];
                echo '</div>';
            }
            
            echo '<hr class="summary-divider">';
            echo '<div class="summary-total">';
            echo 'Total Qty: ' . number_format($summary['total_items'], 0);
            echo '</div>';
            echo '</div>';
            
            // Display vendor sections
            while ($vendor = $vendors_result->fetch_assoc()) {
                echo '<hr class="vendor-separator">';
                echo '<div class="vendor-header">' . strtoupper($vendor['vendor_name']) . '</div>';
                
                // Get items for this vendor
                $items_sql = "SELECT 
                            i.id as item_id,
                            i.name as item_name,
                            ii.quantity_received as received_qty,
                            ii.remaining_stock as remaining_qty
                            FROM inventory inv
                            JOIN inventory_items ii ON inv.id = ii.inventory_id
                            JOIN items i ON ii.item_id = i.id
                            WHERE DATE(inv.date_received) = ? AND inv.vendor_id = ?
        ORDER BY ii.id";

                $items_stmt = $conn->prepare($items_sql);
                $items_stmt->bind_param("si", $date, $vendor['vendor_id']);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                
                // Display vendor summary
                $vendor_summary_sql = "SELECT 
                                    COUNT(DISTINCT ii.id) as total_items,
                                    SUM(ii.quantity_received) as total_qty
                                    FROM inventory inv
                                    JOIN inventory_items ii ON inv.id = ii.inventory_id
                                    WHERE DATE(inv.date_received) = ? AND inv.vendor_id = ?";
                
                $vendor_summary_stmt = $conn->prepare($vendor_summary_sql);
                $vendor_summary_stmt->bind_param("si", $date, $vendor['vendor_id']);
                $vendor_summary_stmt->execute();
                $vendor_summary = $vendor_summary_stmt->get_result()->fetch_assoc();
                
                // Display items in a grid layout
                echo '<div class="items-grid">';
                
                while ($item = $items_result->fetch_assoc()) {
                    // Determine item name color based on remaining stock
                    $item_name_class = $item['remaining_qty'] > 0 ? 'item-name-red' : 'item-name-green';
                    
                    echo '<div class="item-container">';
                    
                    // Item header with checkmark and clickable name
                    echo '<div class="item-header">';
                    echo '<i class="fas fa-check-circle text-success"></i> ';
                    echo '<strong class="' . $item_name_class . ' clickable-item" 
                              data-item-id="' . $item['item_id'] . '" 
                              data-vendor-id="' . $vendor['vendor_id'] . '" 
                              data-item-name="' . htmlspecialchars($item['item_name']) . '">' . 
                              htmlspecialchars($item['item_name']) . '</strong>';
                    echo '</div>';
                    
                    // Item details
                    echo '<div class="item-info">';
                    echo 'Received Date: ' . $display_date . '<br>';
                    echo 'Received Qty: ' . number_format($item['received_qty'], 2) . '<br>';
                    echo 'Remaining Qty: ' . number_format($item['remaining_qty'], 2) . '<br>';
                    echo '</div>';
                    
                    // Purchase history
                    echo '<div class="purchase-history">';
                    echo '<strong>Purchase History:</strong><br>';
                    
                    // Get purchase history entries
                    $history_sql = "SELECT 
                                 ii.quantity_received as qty
                                 FROM inventory_items ii
                                 JOIN inventory inv ON ii.inventory_id = inv.id
                                 WHERE inv.vendor_id = ? AND ii.item_id = ?
                                 ORDER BY inv.date_received DESC
                                 LIMIT 5";
                                 
                    $history_stmt = $conn->prepare($history_sql);
                    $history_stmt->bind_param("ii", $vendor['vendor_id'], $item['item_id']);
                    $history_stmt->execute();
                    $history_result = $history_stmt->get_result();
                    
                    while ($history = $history_result->fetch_assoc()) {
                        echo $history['qty'] . '<br>';
                    }
                    
                    echo '</div>'; // End purchase-history
                    
                    echo '</div>'; // End item-container
                }
                
                echo '</div>'; // End items-grid
                
                // Vendor totals
                echo '<div class="vendor-totals">';
                echo '<div><strong>Total Qty:</strong> ' . number_format($vendor_summary['total_qty'], 2) . '</div>';
                echo '</div>';
            }
            ?>
        <?php endif; ?>
    </div>
</div>

<!-- Purchase History Modal -->
<div class="modal fade" id="purchaseHistoryModal" tabindex="-1" aria-labelledby="purchaseHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="purchaseHistoryModalLabel">Purchase History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="purchaseHistoryModalBody">
                <!-- Content will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
    .report-summary {
        background: #f9f9f9;
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 4px;
        max-width: 230px;
        font-family: monospace;
        font-size: 14px;
    }
    
    .summary-entry {
        margin-bottom: 3px;
        white-space: nowrap;
    }
    
    .summary-divider {
        margin: 10px 0;
        border-top: 1px solid #ddd;
    }
    
    .summary-total {
        font-weight: bold;
        margin-top: 3px;
    }
    
    .vendor-separator {
        margin: 30px 0 20px;
        border-top: 1px solid #ccc;
    }
    
    .vendor-header {
        font-size: 20px;
        font-weight: bold;
        margin-bottom: 15px;
        background: #f0f0f0;
        padding: 8px 15px;
        border-radius: 4px;
    }
    
    .items-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }
    
    .item-container {
        background: white;
        border: 1px solid #eee;
        border-radius: 4px;
        padding: 15px;
    }
    
    .item-header {
        margin-bottom: 10px;
        border-bottom: 1px solid #f0f0f0;
        padding-bottom: 8px;
    }
    
    .item-name-red {
        color: #dc3545 !important;
        cursor: pointer;
        text-decoration: underline;
    }
    
    .item-name-green {
        color: #28a745 !important;
        cursor: pointer;
        text-decoration: underline;
    }
    
    .clickable-item:hover {
        opacity: 0.8;
    }
    
    .item-info {
        margin-bottom: 10px;
        font-size: 14px;
    }
    
    .purchase-history {
        font-size: 13px;
        color: #555;
    }
    
    .vendor-totals {
        background: #f9f9f9;
        padding: 15px;
        margin: 20px 0;
        border-radius: 4px;
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
        font-weight: 500;
    }
    
    .modal-lg {
        max-width: 800px;
    }
    
    .purchase-history-table {
        font-size: 14px;
    }
    
    .purchase-history-table th {
        background-color: #f8f9fa;
        font-weight: 600;
    }
    
    .summary-box {
        padding: 15px;
        background-color: #f8f9fa;
        border-radius: 4px;
        margin-bottom: 15px;
        text-align: center;
    }
    .summary-box .title {
        font-size: 14px;
        color: #6c757d;
        margin-bottom: 5px;
    }
    .summary-box .value {
        font-size: 20px;
        font-weight: bold;
        color: #212529;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, setting up click handlers...');
    
    // Handle click on item names
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('clickable-item')) {
            var itemId = e.target.getAttribute('data-item-id');
            var vendorId = e.target.getAttribute('data-vendor-id');
            var itemName = e.target.getAttribute('data-item-name');
            
            console.log('Clickable item clicked!', itemId, vendorId, itemName);
            
            // Show loading in modal
            document.getElementById('purchaseHistoryModalLabel').textContent = 'Purchase History - ' + itemName;
            document.getElementById('purchaseHistoryModalBody').innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            
            // Show modal using Bootstrap 5
            var modal = new bootstrap.Modal(document.getElementById('purchaseHistoryModal'));
            modal.show();
            
            // Load purchase history via AJAX
            var url = 'get_item_purchase_history.php?item_id=' + itemId + '&vendor_id=' + vendorId;
            console.log('Fetching from URL:', url);
            
            fetch(url)
                .then(response => {
                    console.log('Response received:', response);
                    return response.json();
                })
                .then(data => {
                    console.log('Data received:', data);
                    if (data.success) {
                        var html = '';
                        
                        // Add summary boxes first (only once)
                        if (data.summary) {
                            html += '<div class="row mb-3">';
                            html += '<div class="col-md-3"><div class="summary-box"><div class="title">Total Quantity</div><div class="value">' + data.summary.total_qty + '</div></div></div>';
                            html += '<div class="col-md-3"><div class="summary-box"><div class="title">Total Weight</div><div class="value">' + data.summary.total_weight + ' kg</div></div></div>';
                            html += '<div class="col-md-3"><div class="summary-box"><div class="title">Total Amount</div><div class="value">₹' + data.summary.total_amount + '</div></div></div>';
                            html += '<div class="col-md-3"><div class="summary-box"><div class="title">Average Rate</div><div class="value">₹' + data.summary.avg_rate + '</div></div></div>';
                            html += '</div>';
                        }
                        
                        // Add detailed purchase history table
                        html += '<h5 class="mb-3">Purchase History Details</h5>';
                        html += '<div class="table-responsive">';
                        html += '<table class="table table-bordered table-striped purchase-history-table">';
                        html += '<thead><tr>';
                        html += '<th>Purchase Date</th>';
                        html += '<th>Quantity</th>';
                        html += '<th>Weight</th>';
                        html += '<th>Rate</th>';
                        html += '<th>Total Amount</th>';
                        html += '</tr></thead>';
                        html += '<tbody>';
                        
                        if (data.history && data.history.length > 0) {
                            data.history.forEach(function(purchase) {
                                html += '<tr>';
                                html += '<td>' + purchase.date + '</td>';
                                html += '<td>' + purchase.quantity + '</td>';
                                html += '<td>' + purchase.weight + '</td>';
                                html += '<td>₹' + purchase.rate + '</td>';
                                html += '<td>₹' + purchase.amount + '</td>';
                                html += '</tr>';
                            });
                        } else {
                            html += '<tr><td colspan="5" class="text-center">No purchase history found</td></tr>';
                        }
                        
                        html += '</tbody></table></div>';
                        
                        document.getElementById('purchaseHistoryModalBody').innerHTML = html;
                    } else {
                        document.getElementById('purchaseHistoryModalBody').innerHTML = '<div class="alert alert-danger">Error loading purchase history: ' + (data.message || 'Unknown error') + '</div>';
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    document.getElementById('purchaseHistoryModalBody').innerHTML = '<div class="alert alert-danger">Error loading purchase history. Please try again.</div>';
                });
        }
    });
    
    // Also check if clickable items exist
    var clickableItems = document.querySelectorAll('.clickable-item');
    console.log('Found clickable items:', clickableItems.length);
});
</script>

<?php require_once __DIR__ . '/../../views/layout/footer.php'; ?> 