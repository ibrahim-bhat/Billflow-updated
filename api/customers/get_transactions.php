<?php
// Include database configuration
require_once __DIR__ . '/../../config/config.php';

// Set content type to JSON
header('Content-Type: application/json');

// Initialize response array
$response = array('success' => false, 'transactions' => array());

// Check if customer ID is provided
if (isset($_GET['customer_id']) && !empty($_GET['customer_id'])) {
    $customer_id = intval($_GET['customer_id']);
    
    // Get customer details
    $customer_sql = "SELECT name, balance FROM customers WHERE id = ?";
    $customer_stmt = $conn->prepare($customer_sql);
    $customer_stmt->bind_param("i", $customer_id);
    $customer_stmt->execute();
    $customer_result = $customer_stmt->get_result();
    $customer = $customer_result->fetch_assoc();
    $customer_stmt->close();
    
    if ($customer) {
        $response['customer'] = $customer;
        
        // Get payments for this customer
        $sql = "SELECT p.id, p.date, p.amount, p.discount, p.payment_mode, p.receipt_no
                FROM customer_payments p
                WHERE p.customer_id = ?
                ORDER BY p.date DESC, p.id DESC";
        
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("i", $customer_id);
            
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                
                // Fetch all payments
                while ($row = $result->fetch_assoc()) {
                    $response['transactions'][] = array(
                        'id' => $row['id'],
                        'date' => $row['date'],
                        'amount' => $row['amount'],
                        'discount' => $row['discount'],
                        'payment_mode' => $row['payment_mode'],
                        'receipt_no' => $row['receipt_no']
                    );
                }
                
                $response['success'] = true;
            } else {
                $response['error'] = "Query execution failed: " . $stmt->error;
            }
            
            $stmt->close();
        } else {
            $response['error'] = "Query preparation failed: " . $conn->error;
        }
    } else {
        $response['error'] = "Customer not found";
    }
} else {
    $response['error'] = "Customer ID is required";
}

// Return JSON response
echo json_encode($response);
?> 