<?php
// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/helpers/numbering_helper.php';

if (isset($_POST['create_invoice'])) {
    $customer_id = sanitizeInput($_POST['customer_id']);
    $vendor_id = sanitizeInput($_POST['vendor_id']);
    $date = sanitizeInput($_POST['date']);
    $total_amount = sanitizeInput($_POST['total_amount']);
    
    // Get item details from form
    $item_ids = $_POST['item_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $weights = $_POST['weight'] ?? [];
    $rates = $_POST['rate'] ?? [];
    
    if (!empty($customer_id) && !empty($vendor_id) && !empty($item_ids) && count($item_ids) > 0) {
        // Generate sequential invoice number starting from 1
        $next_number = getNextInvoiceNumber($conn);
        $invoice_number = formatInvoiceNumber($next_number);
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Insert invoice header
            $sql = "INSERT INTO customer_invoices (customer_id, vendor_id, invoice_number, date, total_amount) 
                    VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iissd", $customer_id, $vendor_id, $invoice_number, $date, $total_amount);
            $stmt->execute();
            $invoice_id = $conn->insert_id;
            
            // Insert invoice items and update stock
            $item_sql = "INSERT INTO invoice_items (invoice_id, item_id, quantity, weight, rate, amount) 
                        VALUES (?, ?, ?, ?, ?, ?)";
            $item_stmt = $conn->prepare($item_sql);
            
            // Prepare stock update statement
            $stock_sql = "UPDATE inventory_items 
                         SET remaining_stock = remaining_stock - ? 
                         WHERE item_id = ? 
                         AND remaining_stock > 0 
                         AND inventory_id IN (
                             SELECT id FROM inventory WHERE vendor_id = ?
                         )
                         ORDER BY created_at ASC
                         LIMIT 1";
            $stock_stmt = $conn->prepare($stock_sql);
            
            for ($i = 0; $i < count($item_ids); $i++) {
                if (!empty($item_ids[$i])) {
                    $item_id = $item_ids[$i];
                    $qty = floatval($quantities[$i]);
                    $weight = floatval($weights[$i] ?: 0);
                    $rate = floatval($rates[$i]);
                    $amount = $qty * $rate;
                    
                    // Check available stock
                    $check_sql = "SELECT COALESCE(SUM(remaining_stock), 0) as available_stock 
                                FROM inventory_items ii 
                                JOIN inventory i ON ii.inventory_id = i.id 
                                WHERE ii.item_id = ? AND i.vendor_id = ?";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("ii", $item_id, $vendor_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    $available_stock = $check_result->fetch_assoc()['available_stock'];
                    
                    if ($available_stock < $qty) {
                        throw new Exception("Insufficient stock for item ID: $item_id. Available: $available_stock, Requested: $qty");
                    }
                    
                    // Add invoice item
                    $item_stmt->bind_param("iidddd", $invoice_id, $item_id, $qty, $weight, $rate, $amount);
                    $item_stmt->execute();
                    
                    // Update stock
                    $stock_stmt->bind_param("dii", $qty, $item_id, $vendor_id);
                    $stock_stmt->execute();
                    
                    if ($stock_stmt->affected_rows == 0) {
                        throw new Exception("Failed to update stock for item ID: $item_id");
                    }
                }
            }
            
            $conn->commit();
            $_SESSION['success_message'] = "Invoice #$invoice_number created successfully!";
            header('Location: ../../views/customers/index.php');
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Error creating invoice: " . $e->getMessage();
            header('Location: ../../views/customers/index.php');
            exit();
        }
    } else {
        $_SESSION['error_message'] = "Required fields are missing!";
        header('Location: ../../views/customers/index.php');
        exit();
    }
}

header('Location: ../../views/customers/index.php');
exit(); 