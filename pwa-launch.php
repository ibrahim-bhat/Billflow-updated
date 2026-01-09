<?php
// Include session configuration
require_once __DIR__ . '/config/session_config.php';
require_once __DIR__ . '/config/config.php';

// Get company settings for PWA
$company_name = 'BillFlow'; // Default fallback
$company_logo = 'assets/images/kichlooandco-logo.png'; // Default fallback

try {
    $sql = "SELECT company_name, logo_path FROM company_settings LIMIT 1";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $settings = $result->fetch_assoc();
        if (!empty($settings['company_name'])) {
            $company_name = $settings['company_name'];
        }
        if (!empty($settings['logo_path']) && file_exists($settings['logo_path'])) {
            $company_logo = $settings['logo_path'];
        }
    }
} catch (Exception $e) {
    error_log("Error fetching company settings for PWA: " . $e->getMessage());
}

// Check if user is logged in
if (isset($_SESSION['user_id'])) {
    // User is logged in, redirect to dashboard
    header("Location: views/dashboard/index.php");
    exit;
} else {
    // User is not logged in, redirect to login page
    header("Location: index.php");
    exit;
}
?> 