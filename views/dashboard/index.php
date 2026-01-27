<?php
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../layout/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Function to convert number to words in Indian format
function numberToWords($number) {
    $ones = array(
        0 => "", 1 => "One", 2 => "Two", 3 => "Three", 4 => "Four", 5 => "Five",
        6 => "Six", 7 => "Seven", 8 => "Eight", 9 => "Nine", 10 => "Ten",
        11 => "Eleven", 12 => "Twelve", 13 => "Thirteen", 14 => "Fourteen", 15 => "Fifteen",
        16 => "Sixteen", 17 => "Seventeen", 18 => "Eighteen", 19 => "Nineteen"
    );
    
    $tens = array(
        2 => "Twenty", 3 => "Thirty", 4 => "Forty", 5 => "Fifty",
        6 => "Sixty", 7 => "Seventy", 8 => "Eighty", 9 => "Ninety"
    );
    
    if ($number == 0) return "Zero";
    
    $words = "";
    
    if ($number >= 10000000) {
        $crores = floor($number / 10000000);
        $words .= numberToWords($crores) . " crore ";
        $number %= 10000000;
    }
    
    if ($number >= 100000) {
        $lakhs = floor($number / 100000);
        $words .= numberToWords($lakhs) . " lakh ";
        $number %= 100000;
    }
    
    if ($number >= 1000) {
        $thousands = floor($number / 1000);
        if ($thousands > 0) {
            $words .= numberToWords($thousands) . " thousand ";
        }
        $number %= 1000;
    }
    
    if ($number >= 100) {
        $hundreds = floor($number / 100);
        $words .= $ones[$hundreds] . " hundred ";
        $number %= 100;
    }
    
    if ($number > 0) {
        if ($number < 20) {
            $words .= $ones[$number];
        } else {
            $tens_digit = floor($number / 10);
            $ones_digit = $number % 10;
            $words .= $tens[$tens_digit];
            if ($ones_digit > 0) {
                $words .= " " . $ones[$ones_digit];
            }
        }
    }
    
    return trim($words);
}

// Get dashboard statistics
$stats = array();

// Get total customers
$sql = "SELECT COUNT(*) as count FROM customers";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$stats['customers'] = $row['count'];

// Get total vendors
$sql = "SELECT COUNT(*) as count FROM vendors";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$stats['vendors'] = $row['count'];

// Get total inventory
$sql = "SELECT COALESCE(SUM(remaining_stock), 0) as count FROM inventory_items";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$stats['inventory'] = $row['count'];

// Get low stock items count
$sql = "SELECT COUNT(*) as count FROM inventory_items WHERE remaining_stock < 10 AND remaining_stock > 0";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$stats['low_stock_count'] = $row['count'];

// Get low stock items list
$sql = "SELECT ii.*, i.name as product_name FROM inventory_items ii 
        JOIN items i ON ii.item_id = i.id 
        WHERE ii.remaining_stock < 10 AND ii.remaining_stock > 0 
        ORDER BY ii.remaining_stock ASC LIMIT 5";
$result = $conn->query($sql);
$low_stock_items = [];
while ($row = $result->fetch_assoc()) {
    $low_stock_items[] = $row;
}

// Get accounts receivable (sum of unpaid invoice amounts) - excluding Cash customer
$sql = "SELECT COALESCE(SUM(
    ci.total_amount - COALESCE((
        SELECT SUM(cp.amount + cp.discount)
        FROM customer_payments cp
        WHERE cp.customer_id = ci.customer_id
        AND cp.date <= ci.date
    ), 0)
), 0) as total
FROM customer_invoices ci
JOIN customers c ON ci.customer_id = c.id
WHERE c.name != 'Cash'";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$stats['accounts_receivable'] = $row['total'];

// Get pending invoices count
$sql = "SELECT COUNT(*) as count FROM customer_invoices ci 
        JOIN customers c ON ci.customer_id = c.id 
        WHERE c.name != 'Cash' AND ci.total_amount > 0";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$stats['pending_invoices'] = $row['count'];

// Get today's invoice amount (excluding Cash customer)
$today = date('Y-m-d');
$sql = "SELECT COALESCE(SUM(ci.total_amount), 0) as total 
        FROM customer_invoices ci 
        JOIN customers c ON ci.customer_id = c.id 
        WHERE DATE(ci.date) = ? AND c.name != 'Cash'";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $today);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stats['todays_invoice'] = $row['total'];

// Get yesterday's invoice for comparison
$yesterday = date('Y-m-d', strtotime('-1 day'));
$sql = "SELECT COALESCE(SUM(ci.total_amount), 0) as total 
        FROM customer_invoices ci 
        JOIN customers c ON ci.customer_id = c.id 
        WHERE DATE(ci.date) = ? AND c.name != 'Cash'";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $yesterday);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stats['yesterdays_invoice'] = $row['total'];

// Calculate percentage change
$stats['invoice_change'] = 0;
if ($stats['yesterdays_invoice'] > 0) {
    $stats['invoice_change'] = round((($stats['todays_invoice'] - $stats['yesterdays_invoice']) / $stats['yesterdays_invoice']) * 100);
}

// Calculate total customer ledger (sum of all customer balances, excluding Cash customer)
$sql = "SELECT COALESCE(SUM(balance), 0) as total_balance FROM customers WHERE name != 'Cash'";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$stats['total_customer_balance'] = $row['total_balance'];

// Get today's cash collected (excluding Cash customer)
$sql = "SELECT COALESCE(SUM(cp.amount), 0) as total 
FROM customer_payments cp 
JOIN customers c ON cp.customer_id = c.id 
WHERE cp.payment_mode = 'Cash' AND DATE(cp.date) = ? AND c.name != 'Cash'";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $today);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stats['todays_cash'] = $row['total'];

// Get today's bank transfers (excluding Cash customer)
$sql = "SELECT COALESCE(SUM(cp.amount), 0) as total 
FROM customer_payments cp 
JOIN customers c ON cp.customer_id = c.id 
WHERE cp.payment_mode = 'Bank' AND DATE(cp.date) = ? AND c.name != 'Cash'";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $today);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stats['todays_bank'] = $row['total'];

// Calculate today's total accounts receivable
$stats['todays_total_receivable'] = $stats['todays_cash'] + $stats['todays_bank'];

// Get Commission Based Vendors Payable
$sql = "SELECT COALESCE(SUM(balance), 0) as total FROM vendors WHERE vendor_category = 'Commission Based'";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$stats['commission_payable'] = $row['total'];

// Get Purchase Based Vendors Payable
$sql = "SELECT COALESCE(SUM(balance), 0) as total FROM vendors WHERE vendor_category = 'Purchase Based'";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$stats['purchase_payable'] = $row['total'];

// Get Goods Purchased on Cash (Today) - Assuming this is tracked via expenses or vendor payments made in cash
// However, based on typical systems, this might be direct cash expenses.
// We'll query vendor_payments where mode is Cash and date is today
$sql = "SELECT COALESCE(SUM(amount), 0) as total FROM vendor_payments WHERE payment_mode = 'Cash' AND DATE(date) = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $today);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stats['todays_cash_purchases'] = $row['total'];

// Get recent activities (last 5 transactions)
$recent_activities = [];

// Recent payments
$sql = "SELECT cp.*, c.name as customer_name, 'payment' as type 
        FROM customer_payments cp 
        JOIN customers c ON cp.customer_id = c.id 
        WHERE c.name != 'Cash'
        ORDER BY cp.date DESC, cp.id DESC LIMIT 3";
$result = $conn->query($sql);
while ($row = $result->fetch_assoc()) {
    $recent_activities[] = $row;
}

// Recent invoices
$sql = "SELECT ci.*, c.name as customer_name, 'invoice' as type 
        FROM customer_invoices ci 
        JOIN customers c ON ci.customer_id = c.id 
        WHERE c.name != 'Cash'
        ORDER BY ci.date DESC, ci.id DESC LIMIT 2";
$result = $conn->query($sql);
while ($row = $result->fetch_assoc()) {
    $recent_activities[] = $row;
}

// Recent stock additions
$sql = "SELECT ii.*, i.name as product_name, 'stock' as type, inv.date_received as date
        FROM inventory_items ii 
        JOIN items i ON ii.item_id = i.id
        JOIN inventory inv ON ii.inventory_id = inv.id
        ORDER BY inv.date_received DESC, ii.id DESC LIMIT 2";
$result = $conn->query($sql);
while ($row = $result->fetch_assoc()) {
    $recent_activities[] = $row;
}

// Get company name
$dashboard_company_name = "BillFlow";
try {
    $sql = "SELECT company_name FROM company_settings LIMIT 1";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $settings = $result->fetch_assoc();
        if (!empty($settings['company_name'])) {
            $dashboard_company_name = $settings['company_name'];
        }
    }
} catch (Exception $e) {
    error_log("Error fetching company name: " . $e->getMessage());
}

// Get current hour for time-based greeting
$hour = date('H');
if ($hour >= 5 && $hour < 12) {
    $greeting = "Good Morning";
} elseif ($hour >= 12 && $hour < 17) {
    $greeting = "Good Afternoon";
} elseif ($hour >= 17 && $hour < 21) {
    $greeting = "Good Evening";
} else {
    $greeting = "Good Night";
}
?>

<!-- Desktop Header -->
<header class="bf-header">
    <div class="bf-header-greeting">
        <h1 class="bf-header-title">
            <?php echo $greeting; ?>, <?php echo htmlspecialchars($dashboard_company_name); ?> 👋
            <span class="bf-header-date"><?php echo date('l, M j, Y'); ?></span>
        </h1>
    </div>
    <div class="bf-header-actions">
        <div class="bf-header-time">
            <i class="far fa-clock"></i>
            <span id="current-time"><?php echo date('g:i A'); ?></span>
        </div>
        <button class="bf-header-icon-btn" onclick="openNotificationModal()">
            <i class="fas fa-bell"></i>
            <span class="bf-header-badge" id="notification-count">0</span>
        </button>
        <button class="bf-header-icon-btn" onclick="toggleDarkMode()">
            <i class="fas fa-moon"></i>
        </button>
    </div>
</header>

<!-- Mobile Greeting -->
<div class="bf-mobile-greeting">
    <div class="bf-mobile-greeting-small"><?php echo $greeting; ?>,</div>
    <div class="bf-mobile-greeting-large"><?php echo htmlspecialchars($dashboard_company_name); ?> 👋</div>
    <div class="bf-mobile-greeting-date">
        <?php echo date('l, M j, Y'); ?>
        <span class="bf-mobile-time">
            <i class="far fa-clock"></i>
            <span><?php echo date('g:i A'); ?></span>
        </span>
    </div>
</div>

<!-- Mobile Quick Actions -->
<div class="bf-quick-actions-row">
    <a href="../invoices/index.php" class="bf-quick-action-circle">
        <div class="bf-quick-action-circle-icon primary">
            <i class="fas fa-plus"></i>
        </div>
        <span class="bf-quick-action-circle-text">New Invoice</span>
    </a>
    <a href="../customers/index.php?action=add" class="bf-quick-action-circle">
        <div class="bf-quick-action-circle-icon teal">
            <i class="fas fa-user-plus"></i>
        </div>
        <span class="bf-quick-action-circle-text">Add Customer</span>
    </a>
    <a href="../inventory/index.php?action=add" class="bf-quick-action-circle">
        <div class="bf-quick-action-circle-icon yellow">
            <i class="fas fa-box"></i>
        </div>
        <span class="bf-quick-action-circle-text">Add Item</span>
    </a>
    <a href="../vendors/invoices.php" class="bf-quick-action-circle">
        <div class="bf-quick-action-circle-icon orange">
            <i class="fas fa-shopping-bag"></i>
        </div>
        <span class="bf-quick-action-circle-text">Purchases</span>
    </a>
</div>

<!-- Desktop Stats Cards -->
<div class="bf-stats-grid">
    <!-- Today's Sales -->
    <a href="../invoices/index.php" class="bf-stat-card bf-stat-card-orange">
        <div class="bf-stat-card-header">
            <div class="bf-stat-card-icon orange">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="bf-stat-card-label">Today's Sales</div>
        </div>
        <div class="bf-stat-card-value">₹<?php echo number_format($stats['todays_invoice']); ?></div>
        <div class="bf-stat-card-subtitle <?php echo $stats['invoice_change'] >= 0 ? 'positive' : 'negative'; ?>">
            <i class="fas fa-arrow-<?php echo $stats['invoice_change'] >= 0 ? 'up' : 'down'; ?>"></i>
            <span><?php echo abs($stats['invoice_change']); ?>% from yesterday</span>
        </div>
        <i class="fas fa-chevron-right bf-stat-card-arrow"></i>
    </a>

    <!-- Total Balance (Receivables) -->
    <a href="../customers/index.php" class="bf-stat-card bf-stat-card-green">
        <div class="bf-stat-card-header">
            <div class="bf-stat-card-icon green">
                <i class="fas fa-wallet"></i>
            </div>
            <div class="bf-stat-card-label">Total Balance</div>
        </div>
        <div class="bf-stat-card-value">₹<?php echo number_format($stats['total_customer_balance']); ?></div>
        <div class="bf-stat-card-subtitle muted">
            <span>Total Customer Outstanding</span>
        </div>
        <i class="fas fa-chevron-right bf-stat-card-arrow"></i>
    </a>

    <!-- Accounts Receivable -->
    <a href="../customers/index.php" class="bf-stat-card bf-stat-card-red">
        <div class="bf-stat-card-header">
            <div class="bf-stat-card-icon red">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <div class="bf-stat-card-label">Due Payments</div>
        </div>
        <div class="bf-stat-card-value">₹<?php echo number_format($stats['accounts_receivable']); ?></div>
        <div class="bf-stat-card-subtitle muted">
            <span>Accounts Receivable</span>
        </div>
        <i class="fas fa-chevron-right bf-stat-card-arrow"></i>
    </a>

    <!-- Todays Total Receivables -->
    <div class="bf-stat-card bf-stat-card-blue">
        <div class="bf-stat-card-header">
            <div class="bf-stat-card-icon blue">
                <i class="fas fa-money-check-alt"></i>
            </div>
            <div class="bf-stat-card-label">Today's Collection</div>
        </div>
        <div class="bf-stat-card-value">₹<?php echo number_format($stats['todays_total_receivable']); ?></div>
        <div class="bf-stat-card-subtitle muted">
            <span>Cash + Bank Transfers</span>
        </div>
    </div>
</div>


<!-- Detailed Statistics Section -->
<div class="bf-section-title mt-4 px-3 px-lg-0">DETAILED STATISTICS</div>
<div class="bf-additional-stats mb-5">
    <!-- Commission Vendors Card -->
    <a href="../vendors/index.php?category=Commission%20Based" class="bf-additional-stat-card purple">
        <div class="bf-additional-stat-header">
            <div class="bf-additional-stat-icon purple">
                <i class="fas fa-hand-holding-usd"></i>
            </div>
            <span class="bf-additional-stat-label">Commission Based</span>
        </div>
        <div class="bf-additional-stat-value">₹<?php echo number_format($stats['commission_payable']); ?></div>
        <div class="bf-additional-stat-subtitle">To Pay to Commission Vendors</div>
        <div class="bf-additional-stat-words"><?php echo numberToWords($stats['commission_payable']); ?></div>
    </a>
    
    <!-- Purchase Vendors Card -->
    <a href="../vendors/index.php?category=Purchase%20Based" class="bf-additional-stat-card teal">
        <div class="bf-additional-stat-header">
            <div class="bf-additional-stat-icon teal">
                <i class="fas fa-shopping-cart"></i>
            </div>
            <span class="bf-additional-stat-label">Purchase Based</span>
        </div>
        <div class="bf-additional-stat-value">₹<?php echo number_format($stats['purchase_payable']); ?></div>
        <div class="bf-additional-stat-subtitle">To Pay to Purchase Vendors</div>
        <div class="bf-additional-stat-words"><?php echo numberToWords($stats['purchase_payable']); ?></div>
    </a>
    
    <!-- Cash Purchases Card -->
    <div class="bf-additional-stat-card yellow">
        <div class="bf-additional-stat-header">
            <div class="bf-additional-stat-icon yellow">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <span class="bf-additional-stat-label">Cash Purchases</span>
        </div>
        <div class="bf-additional-stat-value">₹<?php echo number_format($stats['todays_cash_purchases']); ?></div>
        <div class="bf-additional-stat-subtitle">Goods Purchased on Cash (Today)</div>
        <div class="bf-additional-stat-words"><?php echo numberToWords($stats['todays_cash_purchases']); ?></div>
    </div>

    <!-- Total Customers Card -->
    <a href="../customers/index.php" class="bf-additional-stat-card">
        <div class="bf-additional-stat-header">
            <div class="bf-additional-stat-icon blue" style="background-color: var(--bf-accent-blue-light); color: var(--bf-accent-blue);">
                <i class="fas fa-users"></i>
            </div>
            <span class="bf-additional-stat-label">Total Customers</span>
        </div>
        <div class="bf-additional-stat-value"><?php echo number_format($stats['customers']); ?></div>
        <div class="bf-additional-stat-subtitle">Registered Customers</div>
    </a>

    <!-- Total Items Card -->
    <a href="../inventory/index.php" class="bf-additional-stat-card">
        <div class="bf-additional-stat-header">
            <div class="bf-additional-stat-icon orange" style="background-color: var(--bf-accent-orange-light); color: var(--bf-accent-orange);">
                <i class="fas fa-boxes"></i>
            </div>
            <span class="bf-additional-stat-label">Total Inventory</span>
        </div>
        <div class="bf-additional-stat-value"><?php echo number_format($stats['inventory']); ?></div>
        <div class="bf-additional-stat-subtitle">Items in Stock</div>
    </a>
</div>

<!-- Quick Actions (Desktop) -->
<div class="bf-quick-actions hidden-mobile">
    <div class="bf-quick-actions-title">QUICK ACTIONS</div>
    <div class="bf-quick-actions-grid">
        <a href="../invoices/index.php" class="bf-quick-action">
            <div class="bf-quick-action-icon primary">
                <i class="fas fa-plus"></i>
            </div>
            <span class="bf-quick-action-text">New Invoice</span>
        </a>
        <a href="../customers/index.php?action=add" class="bf-quick-action">
            <div class="bf-quick-action-icon teal">
                <i class="fas fa-user-plus"></i>
            </div>
            <span class="bf-quick-action-text">Add Customer</span>
        </a>
        <a href="../inventory/index.php?action=add" class="bf-quick-action">
            <div class="bf-quick-action-icon green">
                <i class="fas fa-plus-square"></i>
            </div>
            <span class="bf-quick-action-text">Add Stock</span>
        </a>
        <a href="../vendors/invoices.php" class="bf-quick-action">
            <div class="bf-quick-action-icon orange">
                <i class="fas fa-shopping-bag"></i>
            </div>
            <span class="bf-quick-action-text">Purchases</span>
        </a>
    </div>
</div>

<!-- Dashboard Grid (Activity + Alerts) -->
<div class="bf-dashboard-grid">
    <!-- Recent Activity -->
    <div class="bf-activity-section">
        <div class="bf-activity-header">
            <span class="bf-activity-title">RECENT ACTIVITY</span>
            <a href="../history/index.php" class="bf-activity-link">View All Activity</a>
        </div>
        <div class="bf-activity-list">
            <?php 
            $activity_count = 0;
            foreach ($recent_activities as $activity): 
                if ($activity_count >= 5) break;
                $activity_count++;
            ?>
            <div class="bf-activity-item">
                <?php if ($activity['type'] === 'payment'): ?>
                <div class="bf-activity-icon green">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="bf-activity-content">
                    <div class="bf-activity-text">Payment Received</div>
                    <div class="bf-activity-subtext">From <?php echo htmlspecialchars($activity['customer_name']); ?></div>
                </div>
                <div class="bf-activity-meta">
                    <div class="bf-activity-amount positive">+₹<?php echo number_format($activity['amount']); ?></div>
                </div>
                <?php elseif ($activity['type'] === 'invoice'): ?>
                <div class="bf-activity-icon blue">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="bf-activity-content">
                    <div class="bf-activity-text">Invoice Generated</div>
                    <div class="bf-activity-subtext">#INV-<?php echo date('Y', strtotime($activity['date'])); ?>-<?php echo str_pad($activity['id'], 3, '0', STR_PAD_LEFT); ?></div>
                </div>
                <div class="bf-activity-meta">
                    <div class="bf-activity-amount pending">Pending</div>
                </div>
                <?php elseif ($activity['type'] === 'stock'): ?>
                <div class="bf-activity-icon orange">
                    <i class="fas fa-box"></i>
                </div>
                <div class="bf-activity-content">
                    <div class="bf-activity-text">Stock Added</div>
                    <div class="bf-activity-subtext"><?php echo htmlspecialchars($activity['product_name']); ?> - <?php echo number_format($activity['quantity']); ?><?php echo htmlspecialchars($activity['unit'] ?? 'kg'); ?></div>
                </div>
                <div class="bf-activity-meta">
                    <div class="bf-activity-amount"><?php echo number_format($activity['quantity']); ?> units</div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            
            <?php if (empty($recent_activities)): ?>
            <div class="bf-activity-item">
                <div class="bf-activity-content" style="text-align: center; width: 100%;">
                    <div class="bf-activity-subtext">No recent activity</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Stock Alerts & Promo -->
    <div class="bf-stock-alerts hidden-mobile">
        <!-- Stock Alerts Panel -->
        <div class="bf-alert-panel">
            <div class="bf-alert-panel-header">
                <div class="bf-alert-panel-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div>
                    <div class="bf-alert-panel-title">Low Stock Alert</div>
                    <div class="bf-alert-panel-subtitle"><?php echo $stats['low_stock_count']; ?> items need restock</div>
                </div>
            </div>
            <div class="bf-alert-list">
                <?php if (!empty($low_stock_items)): ?>
                    <?php foreach ($low_stock_items as $item): ?>
                    <div class="bf-alert-item">
                        <span class="bf-alert-item-name"><?php echo htmlspecialchars($item['product_name']); ?></span>
                        <span class="bf-alert-item-badge"><?php echo number_format($item['remaining_stock']); ?><?php echo htmlspecialchars($item['unit'] ?? 'kg'); ?> left</span>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="bf-alert-item">
                        <span class="bf-alert-item-name" style="color: var(--bf-accent-green);">All items stocked well!</span>
                    </div>
                <?php endif; ?>
            </div>
            <a href="../inventory/index.php" class="bf-btn bf-btn-success bf-btn-block">
                <i class="fas fa-boxes"></i> Manage Inventory
            </a>
        </div>
        
        <!-- Promo Banner -->
        <div class="bf-promo-banner">
            <div class="bf-promo-content">
                <div class="bf-promo-title">New Feature: Mobile App</div>
                <div class="bf-promo-subtitle">Download the app to track sales on the go!</div>
                <a href="#" class="bf-promo-btn" id="headerInstallButton">
                    Try Now
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Activity Section -->
<div class="hidden-desktop">
    <div class="bf-section-title">RECENT ACTIVITY</div>
    <div class="bf-activity-section" style="margin-bottom: var(--bf-space-md);">
        <div class="bf-activity-list">
            <?php 
            $mobile_activity_count = 0;
            foreach ($recent_activities as $activity): 
                if ($mobile_activity_count >= 3) break;
                $mobile_activity_count++;
            ?>
            <div class="bf-activity-item">
                <?php if ($activity['type'] === 'payment'): ?>
                <div class="bf-activity-icon green">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="bf-activity-content">
                    <div class="bf-activity-text">Payment Received</div>
                    <div class="bf-activity-subtext">From <?php echo htmlspecialchars($activity['customer_name']); ?></div>
                </div>
                <div class="bf-activity-meta">
                    <div class="bf-activity-amount positive">+₹<?php echo number_format($activity['amount']); ?></div>
                </div>
                <?php elseif ($activity['type'] === 'invoice'): ?>
                <div class="bf-activity-icon blue">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="bf-activity-content">
                    <div class="bf-activity-text">Invoice Generated</div>
                    <div class="bf-activity-subtext">#INV-<?php echo date('Y', strtotime($activity['date'])); ?>-<?php echo str_pad($activity['id'], 3, '0', STR_PAD_LEFT); ?></div>
                </div>
                <div class="bf-activity-meta">
                    <div class="bf-activity-amount pending">Pending</div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="text-align: center; padding-top: var(--bf-space-md);">
            <a href="../history/index.php" class="bf-activity-link">View All Activity</a>
        </div>
    </div>
</div>



<script>
// Update current time every minute
function updateTime() {
    const now = new Date();
    const timeString = now.toLocaleTimeString('en-US', { 
        hour: 'numeric', 
        minute: '2-digit',
        hour12: true 
    });
    const timeElement = document.getElementById('current-time');
    if (timeElement) {
        timeElement.textContent = timeString;
    }
}

updateTime();
setInterval(updateTime, 60000);

// Dark mode toggle
function toggleDarkMode() {
    document.documentElement.setAttribute('data-theme', 
        document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'
    );
    localStorage.setItem('theme', document.documentElement.getAttribute('data-theme'));
}

// Check saved theme
if (localStorage.getItem('theme') === 'dark') {
    document.documentElement.setAttribute('data-theme', 'dark');
}

// PWA Install Prompt
let deferredPrompt;

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    showInstallButton();
});

function showInstallButton() {
    const headerButton = document.getElementById('headerInstallButton');
    if (headerButton && deferredPrompt) {
        headerButton.addEventListener('click', (e) => {
            e.preventDefault();
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then((choiceResult) => {
                    if (choiceResult.outcome === 'accepted') {
                        console.log('User accepted the install prompt');
                    }
                    deferredPrompt = null;
                });
            } else {
                showManualInstallInstructions();
            }
        });
    }
}

function showManualInstallInstructions() {
    let instructions = '';
    
    if (/iPhone|iPad|iPod/i.test(navigator.userAgent)) {
        instructions = 'To install: Tap the share button 📤 and select "Add to Home Screen"';
    } else if (/Android/i.test(navigator.userAgent)) {
        instructions = 'To install: Tap the menu button ⋮ and select "Add to Home Screen"';
    } else {
        instructions = 'To install: Click the install icon in your browser address bar';
    }
    
    alert('Install Instructions:\n\n' + instructions);
}

window.addEventListener('appinstalled', () => {
    console.log('PWA was installed');
});

// Load overdue customers on page load
document.addEventListener('DOMContentLoaded', function() {
    loadOverdueCustomers();
});

// Function to open notification modal
function openNotificationModal() {
    const modal = new bootstrap.Modal(document.getElementById('notificationModal'));
    modal.show();
    loadOverdueCustomers();
}

// Function to load overdue customers
function loadOverdueCustomers() {
    fetch('../../api/customers/get_overdue.php')
        .then(response => response.json())
        .then(data => {
            const contentDiv = document.getElementById('overdue-customers-content');
            const badgeElement = document.getElementById('notification-count');
            
            if (data.success) {
                const customers = data.customers;
                
                // Update notification badge count
                if (badgeElement) {
                    badgeElement.textContent = customers.length;
                }
                
                if (customers.length === 0) {
                    contentDiv.innerHTML = `
                        <div class="text-center py-4">
                            <i class="fas fa-check-circle text-success" style="font-size: 48px;"></i>
                            <h5 class="mt-3 text-success">Great News!</h5>
                            <p class="text-muted">All customers have made payments within the last 12 days.</p>
                        </div>
                    `;
                } else {
                    let html = `
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-warning">
                                    <tr>
                                        <th>Customer Name</th>
                                        <th>Ledger Balance</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;
                    
                    customers.forEach(customer => {
                        const daysOverdue = customer.days_overdue;
                        const balanceClass = customer.balance > 0 ? 'text-danger' : 'text-success';
                        const overdueClass = daysOverdue > 30 ? 'table-danger' : daysOverdue > 15 ? 'table-warning' : '';
                        
                        html += `
                            <tr class="${overdueClass}">
                                <td><strong>${customer.name}</strong></td>
                                <td class="${balanceClass}">
                                    <strong>₹${Math.abs(customer.balance).toLocaleString()}</strong>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-success" onclick="downloadCustomerPDF(${customer.id})">
                                        <i class="fas fa-download"></i> Download PDF
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                    
                    html += `
                                </tbody>
                            </table>
                        </div>
                    `;
                    
                    contentDiv.innerHTML = html;
                }
            } else {
                contentDiv.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        Error loading overdue customers: ${data.error || 'Unknown error'}
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('overdue-customers-content').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    Failed to load overdue customers. Please try again.
                </div>
            `;
        });
}

// Function to download customer PDF
function downloadCustomerPDF(customerId) {
    window.open('../../handlers/customers/download_payment_details.php?customer_id=' + customerId, '_blank');
}
</script>

<!-- Notification Modal -->
<div class="modal fade" id="notificationModal" tabindex="-1" aria-labelledby="notificationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="notificationModalLabel">
                    <i class="fas fa-exclamation-triangle text-warning"></i> 
                    Overdue Payment Notifications
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="overdue-customers-content">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading overdue customers...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>