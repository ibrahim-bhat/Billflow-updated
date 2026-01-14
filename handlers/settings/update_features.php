<?php
require_once '../../config/config.php';
require_once '../../core/middleware/auth.php';
require_once '../../core/helpers/feature_helper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    // Get POST data
    $enable_commission = isset($_POST['enable_commission']) ? (int)$_POST['enable_commission'] : 0;
    $enable_purchase = isset($_POST['enable_purchase']) ? (int)$_POST['enable_purchase'] : 0;
    $enable_ai = isset($_POST['enable_ai']) ? (int)$_POST['enable_ai'] : 0;
    
    // Validate: At least one feature must be enabled
    if (!$enable_commission && !$enable_purchase && !$enable_ai) {
        echo json_encode([
            'success' => false, 
            'message' => 'At least one feature must be enabled'
        ]);
        exit;
    }
    
    // Update features
    $features = [
        'commission' => $enable_commission,
        'purchase' => $enable_purchase,
        'ai' => $enable_ai
    ];
    
    $result = update_feature_settings($features);
    
    if ($result) {
        // Refresh settings in session
        refresh_feature_settings();
        
        echo json_encode([
            'success' => true,
            'message' => 'Feature settings updated successfully',
            'features' => $_SESSION['features']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update feature settings'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
