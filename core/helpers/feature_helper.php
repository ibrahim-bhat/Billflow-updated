<?php
/**
 * Feature Helper
 * Manages feature flags for single tenant system
 */

/**
 * Get all feature settings
 * @return array Feature settings or defaults
 */
function get_feature_settings() {
    global $conn;
    
    // Check if settings exist in session
    if (isset($_SESSION['features'])) {
        return $_SESSION['features'];
    }
    
    // Get from database
    $query = "SELECT enable_commission, enable_purchase, enable_ai FROM feature_settings WHERE id = 1 LIMIT 1";
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $settings = mysqli_fetch_assoc($result);
        
        // Store in session
        $_SESSION['features'] = [
            'commission' => (bool)$settings['enable_commission'],
            'purchase' => (bool)$settings['enable_purchase'],
            'ai' => (bool)$settings['enable_ai']
        ];
        
        return $_SESSION['features'];
    }
    
    // Default fallback (all enabled)
    $_SESSION['features'] = [
        'commission' => true,
        'purchase' => true,
        'ai' => true
    ];
    
    return $_SESSION['features'];
}

/**
 * Check if a specific feature is enabled
 * @param string $feature_name Feature name: 'commission', 'purchase', or 'ai'
 * @return bool True if enabled
 */
function is_feature_enabled($feature_name) {
    $features = get_feature_settings();
    
    if (!isset($features[$feature_name])) {
        return false;
    }
    
    return $features[$feature_name];
}

/**
 * Update feature settings
 * @param array $features Associative array of features
 * @return bool Success status
 */
function update_feature_settings($features) {
    global $conn;
    
    $enable_commission = isset($features['commission']) ? (int)$features['commission'] : 1;
    $enable_purchase = isset($features['purchase']) ? (int)$features['purchase'] : 1;
    $enable_ai = isset($features['ai']) ? (int)$features['ai'] : 1;
    
    $query = "INSERT INTO feature_settings (id, enable_commission, enable_purchase, enable_ai) 
              VALUES (1, ?, ?, ?)
              ON DUPLICATE KEY UPDATE 
              enable_commission = VALUES(enable_commission),
              enable_purchase = VALUES(enable_purchase),
              enable_ai = VALUES(enable_ai)";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 'iii', $enable_commission, $enable_purchase, $enable_ai);
    $result = mysqli_stmt_execute($stmt);
    
    if ($result) {
        // Update session
        $_SESSION['features'] = [
            'commission' => (bool)$enable_commission,
            'purchase' => (bool)$enable_purchase,
            'ai' => (bool)$enable_ai
        ];
    }
    
    return $result;
}

/**
 * Check if category column should be shown
 * Category is shown when at least one system (commission OR purchase) is enabled
 * @return bool True if category should be displayed
 */
function show_category_column() {
    return is_feature_enabled('commission') || is_feature_enabled('purchase');
}

/**
 * Refresh feature settings from database
 * Useful after updating settings
 */
function refresh_feature_settings() {
    unset($_SESSION['features']);
    return get_feature_settings();
}

/**
 * Require feature access or redirect
 * @param string $feature_name Feature name required
 * @param string $redirect_url URL to redirect if access denied (default: dashboard)
 */
function require_feature($feature_name, $redirect_url = '/dashboard/') {
    if (!is_feature_enabled($feature_name)) {
        $_SESSION['error'] = "This feature is not enabled. Please contact administrator.";
        header("Location: " . $redirect_url);
        exit;
    }
}
