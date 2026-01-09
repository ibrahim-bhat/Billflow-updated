<?php
// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit;
}

// Include config file
require_once __DIR__ . '/../../config/config.php';

// Get company settings for PWA icons
$company_logo = "assets/images/kichlooandco-logo.png"; // Default fallback
$company_name = "BillFlow"; // Default fallback
try {
    $sql = "SELECT logo_path, company_name FROM company_settings LIMIT 1";
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
    // Keep default values if there's an error
    error_log("Error fetching company settings for PWA: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($company_name); ?> - Business Management System</title>
    
    <!-- PWA Meta Tags for App-like Experience -->
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($company_name); ?>">
    <meta name="application-name" content="<?php echo htmlspecialchars($company_name); ?>">
    <meta name="theme-color" content="#ffffff">
    <meta name="msapplication-TileColor" content="#ffffff">
    <meta name="msapplication-tap-highlight" content="no">
    <meta name="format-detection" content="telephone=no">
    
    <!-- App Icons -->
    <link rel="icon" type="image/x-icon" href="<?php echo htmlspecialchars($company_logo); ?>">
    <link rel="shortcut icon" type="image/x-icon" href="<?php echo htmlspecialchars($company_logo); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo htmlspecialchars($company_logo); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo htmlspecialchars($company_logo); ?>">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars($company_logo); ?>">
    <link rel="apple-touch-icon" sizes="152x152" href="<?php echo htmlspecialchars($company_logo); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo htmlspecialchars($company_logo); ?>">
    <link rel="apple-touch-icon" sizes="167x167" href="<?php echo htmlspecialchars($company_logo); ?>">
    <link rel="apple-touch-icon" sizes="120x120" href="<?php echo htmlspecialchars($company_logo); ?>">
    <link rel="apple-touch-icon" sizes="76x76" href="<?php echo htmlspecialchars($company_logo); ?>">
    
    <!-- Manifest for PWA -->
    <?php
    // Determine the correct path to manifest and assets based on current file location
    $current_path = $_SERVER['PHP_SELF'];
    $path_parts = explode('/', trim($current_path, '/'));
    $depth = count($path_parts) - 2; // Subtract filename and base folder
    $base_path = $depth > 0 ? str_repeat('../', $depth) : '';
    ?>
    <link rel="manifest" href="<?php echo $base_path; ?>manifest-generator.php">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Core CSS - Variables, Reset, Base Styles -->
    <link rel="stylesheet" href="<?php echo $base_path; ?>assets/css/core.css">
    <!-- Components CSS - Reusable UI Components -->
    <link rel="stylesheet" href="<?php echo $base_path; ?>assets/css/components.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo $base_path; ?>assets/css/style.css">
    <meta property="og:image" content="<?php echo htmlspecialchars($company_logo); ?>" />
    
    <!-- Service Worker Registration for PWA -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                // Get the base path for service worker registration
                const currentPath = window.location.pathname;
                const pathSegments = currentPath.split('/').filter(segment => segment);
                
                // Determine how many levels deep we are
                let levelsDeep = 0;
                const fileName = pathSegments[pathSegments.length - 1];
                if (fileName && fileName.includes('.php')) {
                    levelsDeep = pathSegments.length - 2; // Subtract filename and base folder
                } else {
                    levelsDeep = pathSegments.length - 1;
                }
                
                // Build relative path to root
                const basePath = levelsDeep > 0 ? '../'.repeat(levelsDeep) : './';
                const swPath = basePath + 'sw.js';
                const scopePath = basePath;
                
                navigator.serviceWorker.register(swPath, { scope: scopePath })
                    .then((registration) => {
                        console.log('SW registered: ', registration);
                    })
                    .catch((registrationError) => {
                        console.log('SW registration failed: ', registrationError);
                    });
            });
        }
    </script>
</head>
<body>
    <div class="wrapper d-flex">
        <!-- Mobile menu toggle -->
        <div class="mobile-toggle">
            <button class="navbar-toggler" type="button">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="page-header">
                <!-- <div>
                    <?php if (isset($page_subtitle)): ?>
                        <p class="text-muted"><?php echo $page_subtitle; ?></p>
                    <?php endif; ?>
                </div> -->
                
                <?php if (isset($page_action) && !empty($page_action)): ?>
                    <div>
                        <?php echo $page_action; ?>
                    </div>
                <?php endif; ?>
            </div> 