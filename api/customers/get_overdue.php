<?php
// Include database configuration
require_once __DIR__ . '/../../config/config.php';

// Set content type to JSON
header('Content-Type: application/json');

// Initialize response array
$response = array('success' => false, 'customers' => array());

try {
    // Get customers with balance > 50,000 who haven't made any payment in the last 12 days
    $sql = "
        SELECT 
            c.id,
            c.name,
            c.location,
            c.balance,
            CASE 
                WHEN MAX(cp.date) IS NULL THEN 
                    999 -- If no payments at all, show as very overdue
                ELSE 
                    DATEDIFF(CURDATE(), MAX(cp.date))
            END as days_overdue
        FROM customers c
        LEFT JOIN customer_payments cp ON c.id = cp.customer_id
        WHERE c.name != 'Cash' -- Exclude Cash customer
        AND c.balance > 50000 -- Only customers with balance more than 50,000
        GROUP BY c.id, c.name, c.location, c.balance
        HAVING 
            MAX(cp.date) IS NULL OR -- No payments at all
            DATEDIFF(CURDATE(), MAX(cp.date)) > 12 -- Last payment more than 12 days ago
        ORDER BY c.balance DESC, c.name ASC
    ";
    
    $result = $conn->query($sql);
    
    if ($result) {
        $customers = array();
        
        while ($row = $result->fetch_assoc()) {
            $customers[] = array(
                'id' => $row['id'],
                'name' => htmlspecialchars($row['name']),
                'location' => htmlspecialchars($row['location']),
                'balance' => floatval($row['balance']),
                'days_overdue' => intval($row['days_overdue'])
            );
        }
        
        $response['customers'] = $customers;
        $response['success'] = true;
    } else {
        $response['error'] = "Database query failed: " . $conn->error;
    }
    
} catch (Exception $e) {
    $response['error'] = "Error: " . $e->getMessage();
}

// Return JSON response
echo json_encode($response);
?> 