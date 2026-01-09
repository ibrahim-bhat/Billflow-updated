<?php
// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Initialize response array
$response = array('success' => false, 'watak' => array());

if (isset($_GET['vendor_id'])) {
    $vendor_id = sanitizeInput($_GET['vendor_id']);
    
    // Get all watak entries with item summary
    $sql = "SELECT 
            w.id,
            w.date,
            w.watak_number,
            w.vehicle_no,
            w.total_amount,
            w.total_commission,
            w.net_payable as net_amount,
            GROUP_CONCAT(
                CONCAT(
                    wi.item_name, 
                    ' (', wi.quantity, 
                    CASE 
                        WHEN wi.weight > 0 THEN CONCAT(', ', wi.weight, ' kg')
                        ELSE ''
                    END,
                    ')'
                ) 
                SEPARATOR ', '
            ) as items
            FROM vendor_watak w
            LEFT JOIN watak_items wi ON w.id = wi.watak_id
            WHERE w.vendor_id = ?
            GROUP BY w.id
            ORDER BY w.date DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $watak = array();
    while ($row = $result->fetch_assoc()) {
        $watak[] = array(
            'id' => $row['id'],
            'date' => date('d-m-Y', strtotime($row['date'])),
            'watak_number' => $row['watak_number'],
            'vehicle_no' => $row['vehicle_no'],
            'items' => $row['items'],
            'total_amount' => number_format($row['total_amount'], 2),
            'commission' => number_format($row['total_commission'], 2),
            'net_amount' => number_format($row['net_amount'], 2),
            'raw_id' => $row['id'] // Adding raw ID for edit/delete functionality
        );
    }
    
    $response['success'] = true;
    $response['watak'] = $watak;
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response); 