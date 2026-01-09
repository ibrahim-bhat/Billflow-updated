<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');

// Check if required parameters are provided
if (!isset($_GET['vendor_id']) || !isset($_GET['date'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

// Validate parameters
$vendor_id = (int)$_GET['vendor_id'];
$date = $_GET['date'];

if ($vendor_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid vendor ID']);
    exit;
}

if (empty($date)) {
    echo json_encode(['success' => false, 'error' => 'Invalid date']);
    exit;
}

try {
    // Test database connection
    if (!$conn) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        exit;
    }
    
    // First, get all inventory items from this vendor on the specific date with their remaining stock
    // Note: We're now getting inventory_item_id to properly separate entries
    $items_sql = "SELECT 
        ii.id as inventory_item_id,
        i.id as item_id,
        i.name as item_name,
        ii.quantity_received,
        ii.remaining_stock
        FROM inventory_items ii
        JOIN inventory inv ON ii.inventory_id = inv.id
        JOIN items i ON ii.item_id = i.id
        WHERE inv.vendor_id = ? AND DATE(inv.date_received) = ?
        ORDER BY ii.id";

    $items_stmt = $conn->prepare($items_sql);
    $items_stmt->bind_param('is', $vendor_id, $date);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();

    $items_data = [];
    
    while ($item = $items_result->fetch_assoc()) {
        $inventory_item_id = $item['inventory_item_id'];
        $item_id = $item['item_id'];
        
        // Get purchase history for this SPECIFIC inventory item entry
        // This ensures each separate inventory entry shows only its own data
        $item_history_sql = "SELECT 
            DATE_FORMAT(ci.date, '%d/%m/%Y') as date,
            cii.quantity,
            cii.weight,
            cii.rate,
            cii.amount
            FROM customer_invoice_items cii
            JOIN customer_invoices ci ON cii.invoice_id = ci.id
            WHERE cii.inventory_item_id = ? 
            ORDER BY ci.date ASC, ci.id ASC";

        $item_history_stmt = $conn->prepare($item_history_sql);
        $item_history_stmt->bind_param('i', $inventory_item_id);
        $item_history_stmt->execute();
        $item_history_result = $item_history_stmt->get_result();

        $item_history = [];
        $item_total_quantity = 0;
        $item_total_weight = 0;
        $item_total_amount = 0;

        while ($history_row = $item_history_result->fetch_assoc()) {
            $item_history[] = [
                'date' => $history_row['date'],
                'quantity' => number_format($history_row['quantity'], 2),
                'weight' => number_format($history_row['weight'] ?? 0, 2) . ' kg',
                'rate' => number_format($history_row['rate'], 2),
                'amount' => number_format($history_row['amount'], 2)
            ];
            
            $item_total_quantity += $history_row['quantity'];
            $item_total_weight += ($history_row['weight'] ?? 0);
            $item_total_amount += $history_row['amount'];
        }

        // Calculate average rate for this specific inventory item
        $item_average_rate = 0;
        if ($item_total_weight > 0) {
            $item_average_rate = $item_total_amount / $item_total_weight;
        } elseif ($item_total_quantity > 0) {
            $item_average_rate = $item_total_amount / $item_total_quantity;
        }

        // Determine if item is sold out or has remaining stock
        $is_sold_out = $item['remaining_stock'] == 0;
        $item_status = $is_sold_out ? 'sold_out' : 'has_stock';

        $items_data[] = [
            'inventory_item_id' => $inventory_item_id,
            'item_id' => $item_id,
            'item_name' => $item['item_name'],
            'quantity_received' => number_format($item['quantity_received'], 2),
            'remaining_stock' => number_format($item['remaining_stock'], 2),
            'purchase_rate' => '0.00', // Rate is not stored in inventory_items, will be calculated from sales
            'is_sold_out' => $is_sold_out,
            'item_status' => $item_status,
            'history' => $item_history,
            'summary' => [
                'total_quantity' => number_format($item_total_quantity, 2),
                'total_weight' => number_format($item_total_weight, 2),
                'total_amount' => number_format($item_total_amount, 2),
                'average_rate' => number_format($item_average_rate, 2)
            ]
        ];
    }

    // Calculate overall summary across all inventory items
    $overall_total_quantity = 0;
    $overall_total_weight = 0;
    $overall_total_amount = 0;

    foreach ($items_data as $item) {
        $overall_total_quantity += floatval(str_replace(',', '', $item['summary']['total_quantity']));
        $overall_total_weight += floatval(str_replace(',', '', $item['summary']['total_weight']));
        $overall_total_amount += floatval(str_replace(',', '', $item['summary']['total_amount']));
    }

    $overall_average_rate = 0;
    if ($overall_total_weight > 0) {
        $overall_average_rate = $overall_total_amount / $overall_total_weight;
    } elseif ($overall_total_quantity > 0) {
        $overall_average_rate = $overall_total_amount / $overall_total_quantity;
    }

    $overall_summary = [
        'total_quantity' => number_format($overall_total_quantity, 2),
        'total_weight' => number_format($overall_total_weight, 2),
        'total_amount' => number_format($overall_total_amount, 2),
        'average_rate' => number_format($overall_average_rate, 2)
    ];

    echo json_encode([
        'success' => true,
        'items' => $items_data,
        'overall_summary' => $overall_summary
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?> 