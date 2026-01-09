<?php
// Force JSON output and suppress HTML error output to avoid breaking JSON
header('Content-Type: application/json');
ini_set('display_errors', 0);

// Ensure all uncaught exceptions become JSON responses,
// including those thrown during includes/DB connection
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit();
});

// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Configure mysqli to throw exceptions for better JSON error handling (after DB is available)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Return JSON error if not logged in (do not redirect in API)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Initialize response array
$response = array('success' => false, 'ledger' => array());

try {
    if (isset($_GET['vendor_id'])) {
        $vendor_id = intval($_GET['vendor_id']);
        if ($vendor_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid vendor id']);
            exit();
        }

        // Get vendor's opening balance
        $sql = "SELECT balance FROM vendors WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $vendor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $vendor = $result ? $result->fetch_assoc() : null;

        if ($vendor) {
        // Calculate opening balance by reversing all transactions
        $opening_balance = $vendor['balance']; // Start with current balance
        
        // Get all transactions first to calculate opening balance
        $sql = "SELECT 
                CAST('watak' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci as type,
                COALESCE(inventory_date, date) as txn_date,
                CAST(CONCAT('Watak #', watak_number) AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci as description,
                net_payable as debit,
                NULL as credit
                FROM vendor_watak 
                WHERE vendor_id = ?
                
                UNION ALL
                
                SELECT 
                CAST('purchase_invoice' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci as type,
                invoice_date as txn_date,
                CAST(CONCAT('Purchase Invoice #', invoice_number) AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci as description,
                total_amount as debit,
                NULL as credit
                FROM vendor_invoices
                WHERE vendor_id = ?
                
                UNION ALL
                
                SELECT 
                CAST('payment' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci as type,
                date as txn_date,
                CAST(CONCAT('Payment - ', 
                    CASE payment_mode 
                        WHEN 'Cash' THEN 'Cash Payment'
                        WHEN 'Bank' THEN 'Bank Transfer'
                    END,
                    CASE 
                        WHEN receipt_no IS NOT NULL AND receipt_no != '' 
                        THEN CONCAT(' (Receipt #', receipt_no, ')')
                        ELSE ''
                    END
                ) AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci as description,
                NULL as debit,
                amount + discount as credit
                FROM vendor_payments
                WHERE vendor_id = ?
                
                ORDER BY txn_date ASC";
        
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to prepare ledger query']);
            exit();
        }
        if (!$stmt->bind_param("iii", $vendor_id, $vendor_id, $vendor_id)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to bind parameters']);
            exit();
        }
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to execute ledger query']);
            exit();
        }
        $result = $stmt->get_result();
        if (!$result) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to fetch ledger rows']);
            exit();
        }
        
        // Reverse all transactions to get the original opening balance
        while ($row = $result->fetch_assoc()) {
            // Reverse the transaction: subtract wataks, add payments
            if ($row['debit']) {
                $opening_balance -= $row['debit'];
            }
            if ($row['credit']) {
                $opening_balance += $row['credit'];
            }
        }
        
        // Reset result pointer to beginning
        $result->data_seek(0);
        
        // Calculate running balance starting from opening balance
        $running_balance = $opening_balance;
        $ledger = array();
        
        // Add opening balance entry
        $ledger[] = array(
            'date' => 'Opening Balance',
            'description' => 'Opening Balance',
            'debit' => null,
            'credit' => null,
            'balance' => number_format(abs(($opening_balance - floor($opening_balance)) >= 0.5 ? ceil($opening_balance) : floor($opening_balance)), 0),
            'balance_type' => $opening_balance > 0 ? 'Payable to Vendor' : 'Receivable from Vendor'
        );
        
        while ($row = $result->fetch_assoc()) {
            if ($row['debit']) {
                $running_balance += $row['debit'];
            }
            if ($row['credit']) {
                $running_balance -= $row['credit'];
            }
            
            $ledger[] = array(
                'date' => date('d-m-Y', strtotime($row['txn_date'])),
                'description' => $row['description'],
                'debit' => $row['debit'] ? number_format(($row['debit'] - floor($row['debit'])) >= 0.5 ? ceil($row['debit']) : floor($row['debit']), 0) : null,
                'credit' => $row['credit'] ? number_format(($row['credit'] - floor($row['credit'])) >= 0.5 ? ceil($row['credit']) : floor($row['credit']), 0) : null,
                'balance' => number_format(abs(($running_balance - floor($running_balance)) >= 0.5 ? ceil($running_balance) : floor($running_balance)), 0),
                'balance_type' => $running_balance > 0 ? 'Payable to Vendor' : 'Receivable from Vendor'
            );
        }
        
            $response['success'] = true;
            $response['ledger'] = $ledger;
        } else {
            echo json_encode(['success' => false, 'error' => 'Vendor not found']);
            exit();
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Missing vendor id']);
        exit();
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit();
}

// Return JSON response
echo json_encode($response);