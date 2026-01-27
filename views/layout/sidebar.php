<?php
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Get feature settings
$features = get_feature_settings();

// Get company name for sidebar
$sidebar_company_name = "Kichloo & Co";
$sidebar_company_email = "admin@billflow.com";
try {
    $sql_company = "SELECT company_name FROM company_settings LIMIT 1";
    $result_company = $conn->query($sql_company);
    if ($result_company && $result_company->num_rows > 0) {
        $company_data = $result_company->fetch_assoc();
        if (!empty($company_data['company_name'])) {
            $sidebar_company_name = $company_data['company_name'];
        }
    }
} catch (Exception $e) {
    // Keep default value
}

// Get counts for badges
$customer_count = 0;
$vendor_count = 0;
try {
    $count_sql = "SELECT COUNT(*) as count FROM customers";
    $count_result = $conn->query($count_sql);
    if ($count_result) {
        $customer_count = $count_result->fetch_assoc()['count'];
    }
    
    $count_sql = "SELECT COUNT(*) as count FROM vendors";
    $count_result = $conn->query($count_sql);
    if ($count_result) {
        $vendor_count = $count_result->fetch_assoc()['count'];
    }
} catch (Exception $e) {
    // Keep default values
}
?>

<!-- Sidebar Overlay for Mobile -->
<div class="bf-sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- Sidebar -->
<div class="bf-sidebar" id="sidebar">
    <!-- Brand Section -->
    <div class="bf-sidebar-brand">
        <div class="bf-sidebar-brand-icon">
            <i class="fas fa-file-invoice-dollar"></i>
        </div>
        <div class="bf-sidebar-brand-text">
            <span class="bf-sidebar-brand-name">BillFlow</span>
            <span class="bf-sidebar-brand-subtitle">Manage Business</span>
        </div>
    </div>
    
    <!-- Navigation -->
    <nav class="bf-sidebar-nav">
        <!-- Overview Section -->
        <div class="bf-nav-section">
            <div class="bf-nav-section-title">Overview</div>
            <a href="../../views/dashboard/index.php" class="bf-nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'dashboard') !== false ? 'active' : ''; ?>">
                <span class="bf-nav-link-content">
                    <i class="fas fa-th-large"></i>
                    <span>Dashboard</span>
                </span>
            </a>
        </div>
        
        <!-- Management Section -->
        <div class="bf-nav-section">
            <div class="bf-nav-section-title">Management</div>
            <a href="../../views/customers/index.php" class="bf-nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'customers') !== false ? 'active' : ''; ?>">
                <span class="bf-nav-link-content">
                    <i class="fas fa-users"></i>
                    <span>Customers</span>
                </span>
                <span class="bf-nav-badge"><?php echo number_format($customer_count); ?></span>
            </a>
            <a href="../../views/vendors/index.php" class="bf-nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'vendors') !== false ? 'active' : ''; ?>">
                <span class="bf-nav-link-content">
                    <i class="fas fa-store"></i>
                    <span>Vendors</span>
                </span>
                <span class="bf-nav-badge"><?php echo number_format($vendor_count); ?></span>
            </a>
            <a href="../../views/inventory/index.php" class="bf-nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'inventory') !== false ? 'active' : ''; ?>">
                <span class="bf-nav-link-content">
                    <i class="fas fa-boxes"></i>
                    <span>Inventory</span>
                </span>
            </a>
            <a href="../../views/products/index.php" class="bf-nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'products') !== false ? 'active' : ''; ?>">
                <span class="bf-nav-link-content">
                    <i class="fas fa-box"></i>
                    <span>Items</span>
                </span>
            </a>
        </div>
        
        <!-- Finance Section -->
        <div class="bf-nav-section">
            <div class="bf-nav-section-title">Finance</div>
            <a href="../../views/invoices/index.php" class="bf-nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'invoices') !== false ? 'active' : ''; ?>">
                <span class="bf-nav-link-content">
                    <i class="fas fa-file-invoice"></i>
                    <span>Invoices</span>
                </span>
            </a>
            
            <?php if ($features['purchase']): ?>
            <a href="../../views/vendors/invoices.php" class="bf-nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'invoices.php' && strpos($_SERVER['PHP_SELF'], 'vendors') !== false ? 'active' : ''; ?>">
                <span class="bf-nav-link-content">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>Purchase Invoices</span>
                </span>
            </a>
            <?php endif; ?>
            
            <?php if ($features['commission']): ?>
            <a href="../../views/watak/index.php" class="bf-nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'watak') !== false ? 'active' : ''; ?>">
                <span class="bf-nav-link-content">
                    <i class="fas fa-receipt"></i>
                    <span>Watak</span>
                </span>
            </a>
            <?php endif; ?>
            
            <a href="../../views/history/index.php" class="bf-nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'history') !== false ? 'active' : ''; ?>">
                <span class="bf-nav-link-content">
                    <i class="fas fa-history"></i>
                    <span>Transaction History</span>
                </span>
            </a>
        </div>
        
        <!-- System Section -->
        <div class="bf-nav-section">
            <div class="bf-nav-section-title">System</div>
            
            <?php if ($features['ai']): ?>
            <a href="../../views/ai/index.php" class="bf-nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'ai') !== false ? 'active' : ''; ?>">
                <span class="bf-nav-link-content">
                    <i class="fas fa-brain"></i>
                    <span>AI Invoice Scanner</span>
                </span>
            </a>
            <?php endif; ?>
            
            <a href="../../views/settings/license.php" class="bf-nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'license.php' ? 'active' : ''; ?>">
                <span class="bf-nav-link-content">
                    <i class="fas fa-key"></i>
                    <span>License</span>
                </span>
            </a>
            <a href="../../views/settings/index.php" class="bf-nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'settings') !== false ? 'active' : ''; ?>">
                <span class="bf-nav-link-content">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </span>
            </a>
        </div>
    </nav>
    
    <!-- User Profile Section -->
    <div class="bf-sidebar-user">
        <div class="bf-sidebar-user-info">
            <div class="bf-sidebar-user-avatar">
                <i class="fas fa-building"></i>
            </div>
            <div class="bf-sidebar-user-details">
                <span class="bf-sidebar-user-name"><?php echo htmlspecialchars($sidebar_company_name); ?></span>
                <span class="bf-sidebar-user-email"><?php echo htmlspecialchars($sidebar_company_email); ?></span>
            </div>
        </div>
        <a href="../../logout.php" class="bf-sidebar-logout">
            <i class="fas fa-sign-out-alt"></i>
            <span>Sign out</span>
        </a>
    </div>
</div>

<script>
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('active');
}

function openSidebar() {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('sidebarOverlay').classList.add('active');
}
</script>