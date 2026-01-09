<?php
// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Initialize response array
$response = array('success' => false, 'message' => '');

if (isset($_POST['edit_vendor'])) {
    $vendor_id = sanitizeInput($_POST['vendor_id']);
    $name = sanitizeInput($_POST['name']);
    $type = sanitizeInput($_POST['type']);
    
    if (!empty($vendor_id) && !empty($name)) {
        $sql = "UPDATE vendors SET name = ?, type = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssi", $name, $type, $vendor_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Vendor updated successfully!";
        } else {
            $_SESSION['error_message'] = "Error updating vendor: " . $conn->error;
        }
        
        $stmt->close();
    } else {
        $_SESSION['error_message'] = "All required fields must be filled!";
    }
    
    header('Location: ../../views/vendors/index.php');
    exit();
} 