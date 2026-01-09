<?php
// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Edit Item
if (isset($_POST['edit_item'])) {
    $item_id = sanitizeInput($_POST['item_id']);
    $name = sanitizeInput($_POST['name']);
    
    if (!empty($name) && !empty($item_id)) {
        $sql = "UPDATE items SET name = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $name, $item_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Item updated successfully!";
        } else {
            $_SESSION['error_message'] = "Error updating item: " . $conn->error;
        }
        
        $stmt->close();
    } else {
        $_SESSION['error_message'] = "Item name is required!";
    }
}

header('Location: ../../views/products/index.php');
exit(); 