<?php
// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Add Inventory
if (isset($_POST['add_inventory'])) {
    $vendor_id = sanitizeInput($_POST['vendor_id']);
    $date_received = sanitizeInput($_POST['date_received']);
    $vehicle_no = sanitizeInput($_POST['vehicle_no']);
    $vehicle_charges = sanitizeInput($_POST['vehicle_charges']);
    $bardan = sanitizeInput($_POST['bardan']);
    
    // Validate required fields
    if (empty($vendor_id) || empty($date_received) || !isset($_POST['item_id']) || empty($_POST['item_id'])) {
        $_SESSION['error_message'] = "Required fields are missing!";
        header('Location: ../../views/inventory/index.php');
        exit();
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Insert inventory record
        $sql = "INSERT INTO inventory (vendor_id, date_received, vehicle_no, vehicle_charges, bardan) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("issds", $vendor_id, $date_received, $vehicle_no, $vehicle_charges, $bardan);
        $stmt->execute();
        $inventory_id = $conn->insert_id;
        $stmt->close();
        
        // Insert inventory items
        $sql = "INSERT INTO inventory_items (inventory_id, item_id, quantity_received, remaining_stock) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        foreach ($_POST['item_id'] as $key => $item_id) {
            $quantity = floatval($_POST['quantity'][$key]);
            
            if ($quantity > 0) {
                $stmt->bind_param("iidd", $inventory_id, $item_id, $quantity, $quantity);
                $stmt->execute();
            }
        }
        
        $stmt->close();
        
        // Commit transaction
        $conn->commit();
        $_SESSION['success_message'] = "Inventory added successfully!";
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $_SESSION['error_message'] = "Error adding inventory: " . $e->getMessage();
    }
    
    header('Location: ../../views/products/index.php');
    exit();
}
?> 