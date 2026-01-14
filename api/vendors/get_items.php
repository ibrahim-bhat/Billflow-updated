<?php
require_once __DIR__ . '/../../config/config.php';

// Check if required parameters are provided
if (!isset($_GET['vendor_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing required parameters']);
    exit;
}

$vendor_id = (int)$_GET['vendor_id'];
$date = $_GET['date'] ?? date('Y-m-d'); // Default to today if no date provided
$format = $_GET['format'] ?? 'json'; // Default to JSON format for customer invoice

// Check if we're in edit mode (for invoice editing)
$edit_mode = isset($_GET['edit_mode']) && $_GET['edit_mode'] === 'true';

// Query to get items for this vendor, separated by date
// In edit mode, include items with zero stock to show original invoice items
$stock_condition = $edit_mode ? "" : "AND ii.remaining_stock > 0";

$sql = "SELECT 
        i.id,
        i.name,
        inv.date_received,
        ii.remaining_stock as available_stock,
        ii.id as inventory_item_id,
        (
            SELECT vii.rate
            FROM vendor_invoice_items vii
            JOIN vendor_invoices vi ON vii.invoice_id = vi.id
            WHERE vii.item_id = i.id AND vi.vendor_id = ?
            AND DATE(vi.invoice_date) = DATE(inv.date_received)
            ORDER BY vi.invoice_date DESC
            LIMIT 1
        ) as last_rate
        FROM items i
        JOIN inventory_items ii ON i.id = ii.item_id
        JOIN inventory inv ON ii.inventory_id = inv.id AND inv.vendor_id = ?
        WHERE inv.vendor_id = ? $stock_condition
        ORDER BY inv.date_received DESC, i.name";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $vendor_id, $vendor_id, $vendor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($format === 'json') {
    // Return JSON format
    $items = [];
    while ($row = $result->fetch_assoc()) {
        // Format the item name to include date
        $formatted_name = $row['name'] . ' (' . date('d/m/Y', strtotime($row['date_received'])) . ')';
        
        $items[] = [
            'id' => $row['id'],
            'name' => $formatted_name,
            'original_name' => $row['name'],
            'date_received' => $row['date_received'],
            'available_stock' => $row['available_stock'],
            'inventory_item_id' => $row['inventory_item_id'],
            'last_rate' => $row['last_rate']
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'items' => $items]);
    exit;
} else {
    // Return HTML format
    if ($result->num_rows === 0) {
        echo '<div class="alert alert-info">No items found for this vendor on this date</div>';
        exit;
    }
    
    echo '<div class="vendor-items-list">';
    
    while ($row = $result->fetch_assoc()) {
        echo '<div class="item-row">';
        
        // Item name with checkmark and date
        echo '<div class="item-name">';
        echo '<i class="fas fa-check-circle"></i>';
        echo htmlspecialchars($row['name']) . ' (' . date('d/m/Y', strtotime($row['date_received'])) . ')';
        echo '</div>';
        
        // Item details
        echo '<div class="item-details">';
        echo '<span>Available Qty: ' . number_format($row['available_stock'], 2) . '</span>';
        
        if ($row['last_rate']) {
            echo '<span>Rate: ₹' . number_format($row['last_rate'], 2) . '</span>';
        } else {
            echo '<span>Rate: N/A</span>';
        }
        
        echo '</div>'; // End item-details
        
        echo '</div>'; // End item-row
    }
    
    echo '</div>'; // End vendor-items-list
}
?> 