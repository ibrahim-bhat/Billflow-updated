<?php
// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

if (isset($_GET['id'])) {
    $watak_id = sanitizeInput($_GET['id']);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get the watak details for vendor balance adjustment
        $sql = "SELECT vendor_id, net_payable FROM vendor_watak WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $watak_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $watak = $result->fetch_assoc();
        
        if (!$watak) {
            throw new Exception("Watak not found!");
        }
        
        $vendor_id = $watak['vendor_id'];
        $net_payable = $watak['net_payable'];
        
        // Delete watak items
        $sql = "DELETE FROM watak_items WHERE watak_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $watak_id);
        $stmt->execute();
        
        // Delete watak header
        $sql = "DELETE FROM vendor_watak WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $watak_id);
        $stmt->execute();
        
        // Update vendor balance
        $sql = "UPDATE vendors SET balance = balance - ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("di", $net_payable, $vendor_id);
        $stmt->execute();
        
        // Commit transaction
        $conn->commit();
        $_SESSION['success_message'] = "Watak deleted successfully!";
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $_SESSION['error_message'] = "Error deleting watak: " . $e->getMessage();
    }
}

// Redirect back to vendors page
header('Location: ../../views/vendors/index.php');
exit(); 