<?php
// Include database configuration
require_once __DIR__ . '/../../config/config.php';

// Set content type to JSON
header('Content-Type: application/json');

// Initialize response array
$response = array('success' => false, 'ledger' => array());

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
        
        // Create a union query to get all transactions for this customer
        $sql = "
            (
                -- Invoices (add to balance)
                SELECT 
                    ci.display_date AS date,
                    CONCAT('Invoice #', ci.invoice_number) AS description,
                    ci.total_amount AS debit,
                    0 AS credit,
                    1 AS transaction_type,
                    ci.id AS transaction_id
                FROM customer_invoices ci
                WHERE ci.customer_id = ?
            )
            UNION
            (
                -- Payments (subtract from balance)
                SELECT 
                    cp.date AS date,
                    CONCAT('Payment (', cp.payment_mode, ')', 
                           CASE WHEN cp.receipt_no IS NOT NULL AND cp.receipt_no != '' 
                                THEN CONCAT(' - Receipt #', cp.receipt_no) 
                                ELSE '' END) AS description,
                    0 AS debit,
                    cp.amount + cp.discount AS credit,
                    2 AS transaction_type,
                    cp.id AS transaction_id
                FROM customer_payments cp
                WHERE cp.customer_id = ?
            )
            ORDER BY date ASC, transaction_type ASC, transaction_id ASC
        ";
        
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("ii", $customer_id, $customer_id);
            
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                
                // Calculate opening balance by reversing all transactions
                $opening_balance = $customer['balance']; // Start with current balance
                
                // Reverse all transactions to get the original opening balance
                while ($row = $result->fetch_assoc()) {
                    // Reverse the transaction: subtract invoices, add payments
                    $opening_balance -= $row['debit'] - $row['credit'];
                }
                
                // Reset result pointer to beginning
                $result->data_seek(0);
                
                // Calculate running balance starting from opening balance
                $balance = $opening_balance;
                $ledger = array();
                
                // Add opening balance entry
                $ledger[] = array(
                    'date' => 'Opening Balance',
                    'description' => 'Opening Balance',
                    'debit' => '',
                    'credit' => '',
                    'balance' => number_format(abs($opening_balance), 2),
                    'balance_type' => $opening_balance > 0 ? 'Receivable from Customer' : 'Payable to Customer'
                );
                
                while ($row = $result->fetch_assoc()) {
                    // Update running balance
                    $balance += $row['debit'] - $row['credit'];
                    
                    $ledger[] = array(
                        'date' => $row['date'],
                        'description' => $row['description'],
                        'debit' => $row['debit'] > 0 ? number_format($row['debit'], 2) : '',
                        'credit' => $row['credit'] > 0 ? number_format($row['credit'], 2) : '',
                        'balance' => number_format(abs($balance), 2),
                        'balance_type' => $balance > 0 ? 'Receivable from Customer' : 'Payable to Customer'
                    );
                }
                
                $response['ledger'] = $ledger;
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