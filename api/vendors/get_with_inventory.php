<?php
require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');

// Get search and category parameters
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$category = isset($_GET['category']) ? sanitizeInput($_GET['category']) : '';

// Build the SQL query to get vendors with inventory information
if (!empty($search) && !empty($category)) {
    // Search for vendors by name and category
    $sql = "SELECT 
            v.id,
            v.name,
            v.type,
            v.vendor_category,
            COALESCE(COUNT(DISTINCT ii.item_id), 0) as total_items,
            COALESCE(SUM(ii.remaining_stock), 0) as total_stock
            FROM vendors v
            LEFT JOIN inventory inv ON v.id = inv.vendor_id
            LEFT JOIN inventory_items ii ON inv.id = ii.inventory_id
            WHERE v.name LIKE ? AND v.vendor_category = ?
            GROUP BY v.id
            ORDER BY v.name";
    $stmt = $conn->prepare($sql);
    $searchTerm = "%$search%";
    $stmt->bind_param("ss", $searchTerm, $category);
    $stmt->execute();
    $result = $stmt->get_result();
} elseif (!empty($category)) {
    // Get vendors by category only
    $sql = "SELECT 
            v.id,
            v.name,
            v.type,
            v.vendor_category,
            COALESCE(COUNT(DISTINCT ii.item_id), 0) as total_items,
            COALESCE(SUM(ii.remaining_stock), 0) as total_stock
            FROM vendors v
            LEFT JOIN inventory inv ON v.id = inv.vendor_id
            LEFT JOIN inventory_items ii ON inv.id = ii.inventory_id
            WHERE v.vendor_category = ?
            GROUP BY v.id
            ORDER BY v.name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $category);
    $stmt->execute();
    $result = $stmt->get_result();
} elseif (!empty($search)) {
    // Search for vendors by name only
    $sql = "SELECT 
            v.id,
            v.name,
            v.type,
            v.vendor_category,
            COALESCE(COUNT(DISTINCT ii.item_id), 0) as total_items,
            COALESCE(SUM(ii.remaining_stock), 0) as total_stock
            FROM vendors v
            LEFT JOIN inventory inv ON v.id = inv.vendor_id
            LEFT JOIN inventory_items ii ON inv.id = ii.inventory_id
            WHERE v.name LIKE ?
            GROUP BY v.id
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
            v.type,
            v.vendor_category,
            COALESCE(COUNT(DISTINCT ii.item_id), 0) as total_items,
            COALESCE(SUM(ii.remaining_stock), 0) as total_stock
            FROM vendors v
            LEFT JOIN inventory inv ON v.id = inv.vendor_id
            LEFT JOIN inventory_items ii ON inv.id = ii.inventory_id
            GROUP BY v.id
            ORDER BY v.name";
    $result = $conn->query($sql);
}

$vendors = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $vendors[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'type' => $row['type'],
            'vendor_category' => $row['vendor_category'],
            'total_items' => $row['total_items'],
            'total_stock' => $row['total_stock']
        ];
    }
}

echo json_encode(['success' => true, 'vendors' => $vendors]);
?> 