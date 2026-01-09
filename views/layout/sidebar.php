<?php
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}
?>
<div class="sidebar">
    <div class="brand">
        <div class="logo-container">
            <i class="fas fa-file-invoice-dollar logo-icon"></i>
            <span>BillFlow</span>
        </div>
    </div>
    <nav class="mt-3">
        <a href="../../views/dashboard/index.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'dashboard') !== false ? 'active' : ''; ?>">
            <i class="fas fa-th-large"></i> Dashboard
        </a>
        <a href="../../views/history/index.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'history') !== false ? 'active' : ''; ?>">
            <i class="fas fa-history"></i> Transaction History
        </a>
        <a href="../../views/customers/index.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'customers') !== false ? 'active' : ''; ?>">
            <i class="fas fa-users"></i> Customers
        </a>
        <a href="../../views/vendors/index.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'vendors') !== false ? 'active' : ''; ?>">
            <i class="fas fa-store"></i> Vendors
        </a>
        <a href="../../views/products/index.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'products') !== false ? 'active' : ''; ?>">
            <i class="fas fa-box"></i> Items
        </a>
        <a href="../../views/inventory/index.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'inventory') !== false ? 'active' : ''; ?>">
            <i class="fas fa-boxes"></i> Inventory
        </a>
        <a href="../../views/invoices/index.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'invoices') !== false ? 'active' : ''; ?>">
            <i class="fas fa-file-invoice"></i> Invoices
        </a>
        <a href="../../views/watak/index.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'watak') !== false ? 'active' : ''; ?>">
            <i class="fas fa-receipt"></i> Watak
        </a>
        <a href="../../views/vendors/invoices.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'invoices.php' && strpos($_SERVER['PHP_SELF'], 'vendors') !== false ? 'active' : ''; ?>">
            <i class="fas fa-file-invoice"></i> Purchase Invoices
        </a>
        <a href="../../views/ai/index.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'ai') !== false ? 'active' : ''; ?>">
            <i class="fas fa-brain"></i> AI Invoice Scanner
        </a>
        <a href="../../views/settings/index.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'settings') !== false ? 'active' : ''; ?>">
            <i class="fas fa-cog"></i> Settings
        </a>
    </nav>
    <a href="../../logout.php" class="nav-link logout-btn">
        <i class="fas fa-sign-out-alt"></i> Logout
    </a>
</div> 