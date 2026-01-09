<?php
// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/helpers/numbering_helper.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

if (isset($_POST['save_watak'])) {
    // Debug: Log the POST data
    error_log("POST data received: " . print_r($_POST, true));
    
    // Additional debugging
    if (isset($_POST['items'])) {
        error_log("Items count: " . count($_POST['items']));
        foreach ($_POST['items'] as $index => $item) {
            error_log("Item $index: " . print_r($item, true));
        }
    } else {
        error_log("No items array found in POST data");
    }

    $vendor_id = sanitizeInput($_POST['vendor_id']);
    $date = sanitizeInput($_POST['date']);
    $inventory_date = sanitizeInput($_POST['inventory_date'] ?? $date); // Use date as fallback
    $vehicle_no = sanitizeInput($_POST['vehicle_no']);
    $vehicle_charges = sanitizeInput($_POST['vehicle_charges']) ?: 0;
    $bardan = sanitizeInput($_POST['bardan']) ?: 0;
    $other_charges = sanitizeInput($_POST['other_charges']) ?: 0;
    $commission_percent = sanitizeInput($_POST['commission_percent']) ?: 0;
    $labor_rate = sanitizeInput($_POST['labor_rate']) ?: 0;
    
    // Get item details from form
    $items = $_POST['items'] ?? [];
    
    // Validate input
    if (!$vendor_id || !$date || !$inventory_date) {
        $_SESSION['error_message'] = "Invalid request. Please provide vendor, date, and inventory date.";
        header('Location: ../../views/vendors/index.php');
        exit();
    }

    // Get vendor details
    $sql = "SELECT * FROM vendors WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $vendor_id);
    $stmt->execute();
    $vendor = $stmt->get_result()->fetch_assoc();

    if (!$vendor) {
        $_SESSION['error_message'] = "Vendor not found";
        header('Location: ../../views/vendors/index.php');
        exit();
    }
    
    if (!empty($items) && count($items) > 0) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Generate sequential watak number starting from 1
            $next_number = getNextWatakNumber($conn);
            $watak_number = formatWatakNumber($next_number);

            // Insert watak
            $sql = "INSERT INTO vendor_watak (
                    vendor_id, watak_number, date, inventory_date, vehicle_no, 
                    vehicle_charges, bardan, other_charges, 
                    total_amount, total_commission, net_payable
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($sql);
            $total_amount = 0;
            $total_commission = 0;
            $net_payable = 0;

            $stmt->bind_param('issssdsdddd', 
                $vendor_id, $watak_number, $date, $inventory_date, $vehicle_no,
                $vehicle_charges, $bardan, $other_charges,
                $total_amount, $total_commission, $net_payable
            );
            $stmt->execute();
            $watak_id = $conn->insert_id;

            // Insert watak items
            $sql = "INSERT INTO watak_items (
                    watak_id, item_name, quantity, weight,
                    rate, commission_percent, labor, amount
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($sql);
            
            $total_amount = 0;
            
            // Check if items array exists and is not empty
            if (!isset($_POST['items']) || empty($_POST['items'])) {
                throw new Exception("No items selected for watak. Please add at least one item.");
            }
            
            // Calculate commission percentage
            $commission_percent = floatval($_POST['commission_percent'] ?? ($vendor['type'] === 'Local' ? 10 : 6));
            
            // Calculate labor rate
            $labor_rate = floatval($_POST['labor_rate'] ?? 1);
            
            foreach ($_POST['items'] as $item) {
                // Validate item data - allow 0 quantity but require name and rate
                if (empty($item['name']) || !isset($item['quantity']) || empty($item['rate'])) {
                    continue; // Skip invalid items
                }
                
                $quantity = floatval($item['quantity']);
                $weight = floatval($item['weight'] ?? 0);
                $rate = floatval($item['rate']);
                
                // Calculate amount based on weight vs quantity logic
                $amount = 0;
                if ($weight > 0) {
                    // If weight is provided, use weight × rate
                    $amount = $weight * $rate;
                } else if ($quantity > 0) {
                    // If weight is not provided but quantity is, use quantity × rate
                    $amount = $quantity * $rate;
                }
                
                $total_amount += $amount;
                
                // Calculate labor for this item
                $item_labor = 0;
                $item_name_lower = strtolower($item['name']);
                if ($item_name_lower !== 'krade') {
                    $item_labor = $quantity * $labor_rate;
                }

                $stmt->bind_param('isdddddd',
                    $watak_id,
                    $item['name'],
                    $item['quantity'],
                    $item['weight'],
                    $item['rate'],
                    $commission_percent,
                    $item_labor,
                    $amount
                );
                $stmt->execute();
            }
            
            // Check if any valid items were processed
            if ($total_amount == 0) {
                throw new Exception("No valid items found. Please ensure all items have name, quantity, and rate.");
            }

            // Calculate commission as percentage of total amount based on vendor type
            $total_commission = ($total_amount * $commission_percent) / 100;
            
            // Calculate total labor from stored item labor values
            $total_labor = 0;
            $labor_sql = "SELECT SUM(labor) as total_labor FROM watak_items WHERE watak_id = ?";
            $labor_stmt = $conn->prepare($labor_sql);
            $labor_stmt->bind_param('i', $watak_id);
            $labor_stmt->execute();
            $labor_result = $labor_stmt->get_result();
            $labor_row = $labor_result->fetch_assoc();
            $total_labor = $labor_row['total_labor'] ?? 0;

            // Apply rounding logic
            // 1. Expenses: Remove all decimals (round down)
            $total_commission = floor($total_commission);
            $total_labor = floor($total_labor);
            $vehicle_charges = floor($vehicle_charges);
            $other_charges = floor($other_charges);
            $bardan = floor($bardan);
            
            // 2. Goods Sale Proceeds: If decimal >= 0.5, round up by 1 rupee; if < 0.5, keep current amount and remove decimal
            $goods_sale_proceeds = $total_amount;
            $decimal_part = $goods_sale_proceeds - floor($goods_sale_proceeds);
            if ($decimal_part >= 0.5) {
                $goods_sale_proceeds = ceil($goods_sale_proceeds);
            } else {
                $goods_sale_proceeds = floor($goods_sale_proceeds);
            }
            
            // 3. Net Amount: Remove all decimals (round down)
            $net_payable = $goods_sale_proceeds - $total_commission - $total_labor - $vehicle_charges - $other_charges - $bardan;
            $net_payable = floor($net_payable);
            
            $sql = "UPDATE vendor_watak SET 
                    total_amount = ?,
                    total_commission = ?,
                    net_payable = ?
                    WHERE id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('dddi', $goods_sale_proceeds, $total_commission, $net_payable, $watak_id);
            $stmt->execute();

            // Update vendor balance (add watak amount to vendor balance)
            $update_vendor_sql = "UPDATE vendors SET balance = balance + ? WHERE id = ?";
            $update_vendor_stmt = $conn->prepare($update_vendor_sql);
            $update_vendor_stmt->bind_param('di', $net_payable, $vendor_id);
            $update_vendor_stmt->execute();

            $conn->commit();
            
            // Show success message and redirect
            $_SESSION['success_message'] = "Watak created successfully! Watak ID: " . $watak_id . ", Date: " . $date . ". Vendor balance updated with ₹" . number_format($net_payable, 2);
            header("Location: ../../views/watak/index.php?filter_date=" . $date);
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Error creating watak: " . $e->getMessage();
            error_log("Watak creation error: " . $e->getMessage());
            error_log("Error trace: " . $e->getTraceAsString());
            $_SESSION['error_message'] = $error_message;
        }
    } else {
        $_SESSION['error_message'] = "All required fields must be filled!";
    }
}

header('Location: ../../views/vendors/index.php');
exit(); 