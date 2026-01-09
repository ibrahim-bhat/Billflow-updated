<?php
require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');

// Get search parameter
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Build the SQL query to get vendors with inventory information
// Only return vendors who have items with remaining stock > 0
if (!empty($search)) {
    // Search for vendors by name
    $sql = "SELECT 
            v.id,
            v.name,
            COALESCE(COUNT(DISTINCT ii.item_id), 0) as total_items,
            COALESCE(SUM(ii.remaining_stock), 0) as total_stock
            FROM vendors v
            INNER JOIN inventory inv ON v.id = inv.vendor_id
            INNER JOIN inventory_items ii ON inv.id = ii.inventory_id
            WHERE v.name LIKE ? AND ii.remaining_stock > 0
            GROUP BY v.id
            HAVING total_stock > 0
            ORDER BY v.name";
    $stmt = $conn->prepare($sql);
    $searchTerm = "%$search%";
    $stmt->bind_param("s", $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    // Get all vendors with inventory information
    $sql = "SELECT 
            v.id,
            v.name,
            COALESCE(COUNT(DISTINCT ii.item_id), 0) as total_items,
            COALESCE(SUM(ii.remaining_stock), 0) as total_stock
            FROM vendors v
            INNER JOIN inventory inv ON v.id = inv.vendor_id
            INNER JOIN inventory_items ii ON inv.id = ii.inventory_id
            WHERE ii.remaining_stock > 0
            GROUP BY v.id
            HAVING total_stock > 0
            ORDER BY v.name";
    $result = $conn->query($sql);
}

$vendors = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $vendors[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'total_items' => $row['total_items'],
            'total_stock' => $row['total_stock']
        ];
    }
}

echo json_encode(['success' => true, 'vendors' => $vendors]);
?> 