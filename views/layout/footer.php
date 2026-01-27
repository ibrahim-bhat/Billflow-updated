        </main> <!-- End of bf-main -->
    </div> <!-- End of bf-wrapper -->
    
    <!-- Mobile Bottom Navigation -->
    <nav class="bf-mobile-nav">
        <div class="bf-mobile-nav-list">
            <a href="../../views/dashboard/index.php" class="bf-mobile-nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'dashboard') !== false ? 'active' : ''; ?>">
                <i class="fas fa-th-large"></i>
                <span>Home</span>
            </a>
            <a href="../../views/customers/index.php" class="bf-mobile-nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'customers') !== false ? 'active' : ''; ?>">
                <i class="fas fa-users"></i>
                <span>Clients</span>
            </a>
            <a href="../../views/invoices/index.php" class="bf-mobile-nav-fab">
                <i class="fas fa-plus"></i>
            </a>
            <a href="../../views/inventory/index.php" class="bf-mobile-nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'inventory') !== false ? 'active' : ''; ?>">
                <i class="fas fa-box"></i>
                <span>Stock</span>
            </a>
            <a href="../../views/settings/index.php" class="bf-mobile-nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'settings') !== false ? 'active' : ''; ?>">
                <i class="fas fa-ellipsis-h"></i>
                <span>More</span>
            </a>
        </div>
    </nav>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Mobile Menu -->
    <script src="../../assets/js/mobile-menu.js"></script>
    <!-- Custom JS -->
    <script src="../../assets/js/script.js"></script>
    
    <script>
    // Sync notification counts between mobile and desktop
    function syncNotificationCounts() {
        const desktopCount = document.getElementById('notification-count');
        const mobileCount = document.getElementById('mobile-notification-count');
        if (desktopCount && mobileCount) {
            mobileCount.textContent = desktopCount.textContent;
        }
    }
    
    // Call sync on page load and when notifications update
    document.addEventListener('DOMContentLoaded', syncNotificationCounts);
    
    // Observe changes to notification count
    const notificationObserver = new MutationObserver(syncNotificationCounts);
    const desktopNotification = document.getElementById('notification-count');
    if (desktopNotification) {
        notificationObserver.observe(desktopNotification, { characterData: true, childList: true, subtree: true });
    }
    </script>
</body>
</html>