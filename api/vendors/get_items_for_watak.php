<?php
// API endpoint - no HTML headers
require_once __DIR__ . '/../../config/config.php';

// Set JSON header first
header('Content-Type: application/json');

try {
    // Check if required parameters are provided
    if (!isset($_GET['vendor_id'])) {
        echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
        exit;
    }

    $vendor_id = (int)$_GET['vendor_id'];
    $date = isset($_GET['date']) ? $_GET['date'] : null;

    // Validate date format if provided
    if ($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = null;
    }

    // Query to get items for this vendor, optionally filtered by date
    // Shows all inventory items with remaining stock, with dates in item names
    if ($date) {
        // Filter by specific date
        $sql = "SELECT 
                i.id,
                i.name,
                inv.date_received,
                ii.remaining_stock as available_stock,
                ii.id as inventory_item_id,
                COALESCE(
                    (SELECT vii.rate 
                     FROM vendor_invoice_items vii 
                     JOIN vendor_invoices vi ON vii.invoice_id = vi.id 
                     WHERE vii.item_id = i.id 
                     AND vi.vendor_id = ?
                     AND DATE(vi.date) = DATE(inv.date_received)
                     ORDER BY vi.date DESC
                     LIMIT 1), 
                    i.default_rate,
                    0
                ) as last_rate
                FROM items i
                JOIN inventory_items ii ON i.id = ii.item_id
                JOIN inventory inv ON ii.inventory_id = inv.id AND inv.vendor_id = ?
                WHERE inv.vendor_id = ? 
                AND DATE(inv.date_received) = ?
                AND ii.remaining_stock > 0
                ORDER BY inv.date_received DESC, i.name";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiis", $vendor_id, $vendor_id, $vendor_id, $date);
    } else {
        // Show all items from vendor regardless of date
        $sql = "SELECT 
                i.id,
                i.name,
                inv.date_received,
                ii.remaining_stock as available_stock,
                ii.id as inventory_item_id,
                COALESCE(
                    (SELECT vii.rate 
                     FROM vendor_invoice_items vii 
                     JOIN vendor_invoices vi ON vii.invoice_id = vi.id 
                     WHERE vii.item_id = i.id 
                     AND vi.vendor_id = ?
                     AND DATE(vi.date) = DATE(inv.date_received)
                     ORDER BY vi.date DESC
                     LIMIT 1), 
                    i.default_rate,
                    0
                ) as last_rate
                FROM items i
                JOIN inventory_items ii ON i.id = ii.item_id
                JOIN inventory inv ON ii.inventory_id = inv.id AND inv.vendor_id = ?
                WHERE inv.vendor_id = ? 
                AND ii.remaining_stock > 0
                ORDER BY inv.date_received DESC, i.name";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $vendor_id, $vendor_id, $vendor_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    while ($row = $result->fetch_assoc()) {
        // Format the item name to include date (like in customers.php)
        $formatted_name = $row['name'] . ' (' . date('d/m/Y', strtotime($row['date_received'])) . ')';
        
        $items[] = [
            'id' => $row['id'],
            'name' => $formatted_name,
            'original_name' => $row['name'],
            'date_received' => $row['date_received'],
            'available_stock' => floatval($row['available_stock']),
            'inventory_item_id' => $row['inventory_item_id'],
            'last_rate' => floatval($row['last_rate'])
        ];
    }

    echo json_encode([
        'success' => true, 
        'items' => $items, 
        'date' => $date,
        'vendor_id' => $vendor_id
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}
?> 