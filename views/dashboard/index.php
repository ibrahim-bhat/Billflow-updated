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
    
    // Handle Crores (1 crore = 10,000,000)
    if ($number >= 10000000) {
        $crores = floor($number / 10000000);
        $words .= numberToWords($crores) . " crore ";
        $number %= 10000000;
    }
    
    // Handle Lakhs (1 lakh = 100,000)
    if ($number >= 100000) {
        $lakhs = floor($number / 100000);
        $words .= numberToWords($lakhs) . " lakh ";
        $number %= 100000;
    }
    
    // Handle Thousands
    if ($number >= 1000) {
        $thousands = floor($number / 1000);
        if ($thousands > 0) {
            $words .= numberToWords($thousands) . " thousand ";
        }
        $number %= 1000;
    }
    
    // Handle Hundreds
    if ($number >= 100) {
        $hundreds = floor($number / 100);
        $words .= $ones[$hundreds] . " hundred ";
        $number %= 100;
    }
    
    // Handle remaining numbers
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

// Get accounts payable (sum of all vendor balances) - separated by vendor category
$sql = "SELECT vendor_category, COALESCE(SUM(balance), 0) as total FROM vendors GROUP BY vendor_category";
$result = $conn->query($sql);
$stats['accounts_payable_commission'] = 0;
$stats['accounts_payable_purchase'] = 0;

while ($row = $result->fetch_assoc()) {
    if ($row['vendor_category'] == 'Commission Based') {
        $stats['accounts_payable_commission'] = $row['total'];
    } else if ($row['vendor_category'] == 'Purchase Based') {
        $stats['accounts_payable_purchase'] = $row['total'];
    }
}

// Total accounts payable (sum of both categories)
$stats['accounts_payable'] = $stats['accounts_payable_commission'] + $stats['accounts_payable_purchase'];

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

// Get today's goods purchased on cash (Cash customer invoices for today only)
$sql = "SELECT COALESCE(SUM(ci.total_amount), 0) as total 
FROM customer_invoices ci 
JOIN customers c ON ci.customer_id = c.id 
WHERE c.name = 'Cash' AND DATE(ci.date) = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $today);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stats['goods_purchased'] = $row['total'];

// Get total cash collected (all time)
$sql = "SELECT COALESCE(SUM(amount), 0) as total FROM customer_payments WHERE payment_mode = 'Cash'";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$stats['total_cash'] = $row['total'];

// Get total bank deposits (all time)
$sql = "SELECT COALESCE(SUM(amount), 0) as total FROM customer_payments WHERE payment_mode = 'Bank'";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$stats['total_bank'] = $row['total'];

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

// Get yesterday's date
$yesterday = date('Y-m-d', strtotime('-1 day'));

// Get yesterday's cash collected (excluding Cash customer)
$sql = "SELECT COALESCE(SUM(cp.amount), 0) as total 
FROM customer_payments cp 
JOIN customers c ON cp.customer_id = c.id 
WHERE cp.payment_mode = 'Cash' AND DATE(cp.date) = ? AND c.name != 'Cash'";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $yesterday);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stats['yesterdays_cash'] = $row['total'];

// Get yesterday's bank transfers (excluding Cash customer)
$sql = "SELECT COALESCE(SUM(cp.amount), 0) as total 
FROM customer_payments cp 
JOIN customers c ON cp.customer_id = c.id 
WHERE cp.payment_mode = 'Bank' AND DATE(cp.date) = ? AND c.name != 'Cash'";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $yesterday);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stats['yesterdays_bank'] = $row['total'];

// Calculate yesterday's total accounts receivable
$stats['yesterdays_total_receivable'] = $stats['yesterdays_cash'] + $stats['yesterdays_bank'];

// Calculate today's outstanding receivable (invoices generated today, unpaid, excluding Cash customer)
$sql = "SELECT ci.id, ci.total_amount, ci.customer_id FROM customer_invoices ci JOIN customers c ON ci.customer_id = c.id WHERE DATE(ci.date) = ? AND c.name != 'Cash'";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $today);
$stmt->execute();
$result = $stmt->get_result();
$todays_receivable = 0;
while ($row = $result->fetch_assoc()) {
    $invoice_id = $row['id'];
    $customer_id = $row['customer_id'];
    $invoice_amount = $row['total_amount'];
    // Payments received for this invoice (up to now)
    $sql_pay = "SELECT COALESCE(SUM(amount + discount), 0) as paid FROM customer_payments WHERE customer_id = ? AND date >= ? AND date <= ?";
    $stmt_pay = $conn->prepare($sql_pay);
    $stmt_pay->bind_param('iss', $customer_id, $today, $today);
    $stmt_pay->execute();
    $result_pay = $stmt_pay->get_result();
    $paid = $result_pay->fetch_assoc()['paid'];
    $outstanding = $invoice_amount - $paid;
    if ($outstanding > 0) $todays_receivable += $outstanding;
}
$stats['todays_outstanding'] = $todays_receivable;

// Calculate yesterday's outstanding receivable (invoices generated yesterday, unpaid, excluding Cash customer)
$sql = "SELECT ci.id, ci.total_amount, ci.customer_id FROM customer_invoices ci JOIN customers c ON ci.customer_id = c.id WHERE DATE(ci.date) = ? AND c.name != 'Cash'";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $yesterday);
$stmt->execute();
$result = $stmt->get_result();
$yesterdays_receivable = 0;
while ($row = $result->fetch_assoc()) {
    $invoice_id = $row['id'];
    $customer_id = $row['customer_id'];
    $invoice_amount = $row['total_amount'];
    // Payments received for this invoice (up to now)
    $sql_pay = "SELECT COALESCE(SUM(amount + discount), 0) as paid FROM customer_payments WHERE customer_id = ? AND date >= ? AND date <= ?";
    $stmt_pay = $conn->prepare($sql_pay);
    $stmt_pay->bind_param('iss', $customer_id, $yesterday, $yesterday);
    $stmt_pay->execute();
    $result_pay = $stmt_pay->get_result();
    $paid = $result_pay->fetch_assoc()['paid'];
    $outstanding = $invoice_amount - $paid;
    if ($outstanding > 0) $yesterdays_receivable += $outstanding;
}
$stats['yesterdays_outstanding'] = $yesterdays_receivable;

// Today's payments to vendors - separated by vendor category
$sql = "SELECT v.vendor_category, COALESCE(SUM(vp.amount + vp.discount), 0) as total 
        FROM vendor_payments vp
        JOIN vendors v ON vp.vendor_id = v.id
        WHERE DATE(vp.date) = ?
        GROUP BY v.vendor_category";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $today);
$stmt->execute();
$result = $stmt->get_result();
$stats['todays_vendor_payments_commission'] = 0;
$stats['todays_vendor_payments_purchase'] = 0;

while ($row = $result->fetch_assoc()) {
    if ($row['vendor_category'] == 'Commission Based') {
        $stats['todays_vendor_payments_commission'] = $row['total'];
    } else if ($row['vendor_category'] == 'Purchase Based') {
        $stats['todays_vendor_payments_purchase'] = $row['total'];
    }
}

// Total vendor payments (sum of both categories)
$stats['todays_vendor_payments'] = $stats['todays_vendor_payments_commission'] + $stats['todays_vendor_payments_purchase'];

// Yesterday's payments to vendors - separated by vendor category
$sql = "SELECT v.vendor_category, COALESCE(SUM(vp.amount + vp.discount), 0) as total 
        FROM vendor_payments vp
        JOIN vendors v ON vp.vendor_id = v.id
        WHERE DATE(vp.date) = ?
        GROUP BY v.vendor_category";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $yesterday);
$stmt->execute();
$result = $stmt->get_result();
$stats['yesterdays_vendor_payments_commission'] = 0;
$stats['yesterdays_vendor_payments_purchase'] = 0;

while ($row = $result->fetch_assoc()) {
    if ($row['vendor_category'] == 'Commission Based') {
        $stats['yesterdays_vendor_payments_commission'] = $row['total'];
    } else if ($row['vendor_category'] == 'Purchase Based') {
        $stats['yesterdays_vendor_payments_purchase'] = $row['total'];
    }
}

// Total vendor payments (sum of both categories)
$stats['yesterdays_vendor_payments'] = $stats['yesterdays_vendor_payments_commission'] + $stats['yesterdays_vendor_payments_purchase'];

// Today's vendor payments (cash and bank breakdown)
$sql = "SELECT payment_mode, COALESCE(SUM(amount + discount), 0) as total FROM vendor_payments WHERE DATE(date) = ? GROUP BY payment_mode";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $today);
$stmt->execute();
$result = $stmt->get_result();
$todays_vendor_cash = 0;
$todays_vendor_bank = 0;
while ($row = $result->fetch_assoc()) {
    if (strtolower($row['payment_mode']) === 'cash') $todays_vendor_cash = $row['total'];
    if (strtolower($row['payment_mode']) === 'bank') $todays_vendor_bank = $row['total'];
}
$stats['todays_vendor_cash'] = $todays_vendor_cash;
$stats['todays_vendor_bank'] = $todays_vendor_bank;

// Today's vendor payment details
$sql = "SELECT vp.*, v.name as vendor_name, v.vendor_category FROM vendor_payments vp 
        LEFT JOIN vendors v ON vp.vendor_id = v.id 
        WHERE DATE(vp.date) = ? ORDER BY v.vendor_category, vp.date DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $today);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Just fetch to complete the query
}

// Yesterday's vendor payments (cash and bank breakdown)
$sql = "SELECT payment_mode, COALESCE(SUM(amount + discount), 0) as total FROM vendor_payments WHERE DATE(date) = ? GROUP BY payment_mode";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $yesterday);
$stmt->execute();
$result = $stmt->get_result();
$yesterdays_vendor_cash = 0;
$yesterdays_vendor_bank = 0;
while ($row = $result->fetch_assoc()) {
    if (strtolower($row['payment_mode']) === 'cash') $yesterdays_vendor_cash = $row['total'];
    if (strtolower($row['payment_mode']) === 'bank') $yesterdays_vendor_bank = $row['total'];
}
$stats['yesterdays_vendor_cash'] = $yesterdays_vendor_cash;
$stats['yesterdays_vendor_bank'] = $yesterdays_vendor_bank;

// Yesterday's vendor payment details
$sql = "SELECT vp.*, v.name as vendor_name, v.vendor_category FROM vendor_payments vp 
        LEFT JOIN vendors v ON vp.vendor_id = v.id 
        WHERE DATE(vp.date) = ? ORDER BY v.vendor_category, vp.date DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $yesterday);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Just fetch to complete the query
}
?>

<div class="main-content">
    <div class="notification-container">
        <div class="notification-icon" onclick="openNotificationModal()">
            <i class="fas fa-bell"></i>
            <span class="notification-badge" id="notification-count">0</span>
        </div>
    </div>
    
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <?php
            // Get company name from database
            $company_name = "BillFlow"; // Default fallback
            try {
                $sql = "SELECT company_name FROM company_settings LIMIT 1";
                $result = $conn->query($sql);
                if ($result && $result->num_rows > 0) {
                    $settings = $result->fetch_assoc();
                    if (!empty($settings['company_name'])) {
                        $company_name = $settings['company_name'];
                    }
                }
            } catch (Exception $e) {
                // Keep default value if there's an error
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
            <h1>Hi 👋 <?php echo htmlspecialchars($company_name); ?>! 👋</h1>
            <p>Here's what's happening with your business today - <?php echo date('l, F j, Y'); ?></p>
        </div>
        <div class="text-end d-flex align-items-center gap-3">
            <div class="current-time">
                <i class="fas fa-clock"></i> <?php echo date('h:i A'); ?>
            </div>
        </div>
    </div>




    <!-- Top Stats Row -->
    <div class="stats-row">
        <a href="../customers/index.php" class="dashboard-card stat-link customers-card">
            <h5><i class="fas fa-users"></i> Total Customers</h5>
            <div class="value"><?php echo number_format($stats['customers']); ?></div>
            <div class="subtitle"><?php echo number_format($stats['customers']); ?> Customers</div>
        </a>
        <a href="../vendors/index.php" class="dashboard-card stat-link vendors-card">
            <h5><i class="fas fa-truck"></i> Total Vendors</h5>
            <div class="value"><?php echo number_format($stats['vendors']); ?></div>
            <div class="subtitle"><?php echo number_format($stats['vendors']); ?> Vendors</div>
        </a>
        <a href="../inventory/index.php" class="dashboard-card stat-link inventory-card">
            <h5><i class="fas fa-boxes"></i> Total Items in Inventory</h5>
            <div class="value"><?php echo number_format($stats['inventory']); ?></div>
            <div class="subtitle">Remaining Stock - <?php echo number_format($stats['inventory']); ?></div>
        </a>
        <div class="dashboard-card receivable-card">
            <h5><i class="fas fa-users"></i> Total Customer Ledger</h5>
            <div class="value">₹<?php echo number_format($stats['total_customer_balance'], 2); ?></div>
            <div class="subtitle receivable">All Customers Outstanding</div>
            <div class="in-words"><?php echo numberToWords($stats['total_customer_balance']); ?></div>
        </div>
        <a href="../invoices/index.php" class="dashboard-card stat-link text-white invoice-card-border">
            <h5><i class="fas fa-file-invoice-dollar"></i> Today's Invoice Amount</h5>
            <div class="value">₹<?php echo number_format($stats['todays_invoice'], 2); ?></div>
            <div class="subtitle">Total Invoices Today</div>
            <div class="in-words"><?php echo numberToWords($stats['todays_invoice']); ?></div>
        </a>
    </div>

    <!-- Bottom Stats Row -->
    <div class="stats-row">
        <div class="dashboard-card payable-card">
            <h5><i class="fas fa-credit-card"></i> Commission Based Vendors</h5>
            <div class="value">₹<?php echo number_format($stats['accounts_payable_commission'], 2); ?></div>
            <div class="subtitle payable">To Pay to Commission Vendors: ₹<?php echo number_format($stats['accounts_payable_commission'], 2); ?></div>
            <div class="in-words"><?php echo numberToWords($stats['accounts_payable_commission']); ?></div>
        </div>
        <div class="dashboard-card payable-card">
            <h5><i class="fas fa-credit-card"></i> Purchase Based Vendors</h5>
            <div class="value">₹<?php echo number_format($stats['accounts_payable_purchase'], 2); ?></div>
            <div class="subtitle payable">To Pay to Purchase Vendors: ₹<?php echo number_format($stats['accounts_payable_purchase'], 2); ?></div>
            <div class="in-words"><?php echo numberToWords($stats['accounts_payable_purchase']); ?></div>
        </div>
        <div class="dashboard-card goods-purchased-border">
            <h5><i class="fas fa-shopping-cart"></i> Goods Purchased on Cash (Today)</h5>
            <div class="value">₹<?php echo number_format($stats['goods_purchased'], 2); ?></div>
            <div class="subtitle">Total Cash Purchases</div>
            <div class="in-words"><?php echo numberToWords($stats['goods_purchased']); ?></div>
        </div>
    </div>

    <!-- Quick Summary Section -->
    <div class="dashboard-card mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5><i class="fas fa-chart-line"></i> Today's Cash Flow Summary</h5>
            <a href="../history/index.php" class="btn btn-primary btn-sm">
                <i class="fas fa-history"></i> View Detailed History
            </a>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="card border-success">
                    <div class="card-body">
                        <h6 class="card-title text-success"><i class="fas fa-arrow-down"></i> Collections</h6>
                        <div class="d-flex justify-content-between">
                            <div>
                                <small class="text-muted">Cash</small>
                                <div class="fw-bold">₹<?php echo number_format($stats['todays_cash'], 2); ?></div>
                            </div>
                            <div>
                                <small class="text-muted">Bank</small>
                                <div class="fw-bold">₹<?php echo number_format($stats['todays_bank'], 2); ?></div>
                            </div>
                            <div>
                                <small class="text-muted">Total</small>
                                <div class="fw-bold text-success">₹<?php echo number_format($stats['todays_total_receivable'], 2); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-danger">
                    <div class="card-body">
                        <h6 class="card-title text-danger"><i class="fas fa-arrow-up"></i> Vendor Payments</h6>
                        <div class="d-flex justify-content-between">
                            <div>
                                <small class="text-muted">Commission</small>
                                <div class="fw-bold">₹<?php echo number_format($stats['todays_vendor_payments_commission'], 2); ?></div>
                            </div>
                            <div>
                                <small class="text-muted">Purchase</small>
                                <div class="fw-bold">₹<?php echo number_format($stats['todays_vendor_payments_purchase'], 2); ?></div>
                            </div>
                            <div>
                                <small class="text-muted">Total</small>
                                <div class="fw-bold text-danger">₹<?php echo number_format($stats['todays_vendor_payments_commission'] + $stats['todays_vendor_payments_purchase'], 2); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="alert alert-info mt-3 mb-0">
            <i class="fas fa-info-circle"></i> 
            For detailed transaction history including individual payments, receipts, and yesterday's data, 
            please visit the <a href="../history/index.php" class="alert-link">Transaction History</a> page.
        </div>
    </div>

</div>

<script>
// Toggle card functionality
function toggleCard(cardId) {
    const card = document.getElementById(cardId);
    let arrowId;
    
    // Determine which arrow to toggle based on the card ID
    switch(cardId) {
        case 'todays-vendor-commission-card':
            arrowId = 'todays-vendor-commission-arrow';
            break;
        case 'todays-vendor-purchase-card':
            arrowId = 'todays-vendor-purchase-arrow';
            break;
        case 'yesterdays-vendor-commission-card':
            arrowId = 'yesterdays-vendor-commission-arrow';
            break;
        case 'yesterdays-vendor-purchase-card':
            arrowId = 'yesterdays-vendor-purchase-arrow';
            break;
        default:
            arrowId = cardId.replace('card', 'arrow');
    }
    
    const arrow = document.getElementById(arrowId);
    
    if (card.style.display === 'none') {
        card.style.display = 'block';
        arrow.classList.remove('rotated');
    } else {
        card.style.display = 'none';
        arrow.classList.add('rotated');
    }
}

// Update current time every minute
function updateTime() {
    const now = new Date();
    const timeString = now.toLocaleTimeString('en-US', { 
        hour: 'numeric', 
        minute: '2-digit',
        hour12: true 
    });
    const timeElement = document.querySelector('.current-time');
    if (timeElement) {
        timeElement.innerHTML = `<i class="fas fa-clock"></i> ${timeString}`;
    }
}

// Update time immediately and then every minute
updateTime();
setInterval(updateTime, 60000);

// PWA Install Prompt
let deferredPrompt;

window.addEventListener('beforeinstallprompt', (e) => {
    // Prevent Chrome 67 and earlier from automatically showing the prompt
    e.preventDefault();
    // Stash the event so it can be triggered later
    deferredPrompt = e;
    
    // Show install buttons if not already installed
    showInstallButton();
    showHeaderInstallButton();
});

// Show header install button
function showHeaderInstallButton() {
    const headerButton = document.getElementById('headerInstallButton');
    if (headerButton) {
        headerButton.style.display = 'inline-block';
        headerButton.addEventListener('click', () => {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then((choiceResult) => {
                    if (choiceResult.outcome === 'accepted') {
                        console.log('User accepted the install prompt');
                        headerButton.style.display = 'none';
                    } else {
                        console.log('User dismissed the install prompt');
                    }
                    deferredPrompt = null;
                });
            } else {
                showManualInstallInstructions();
            }
        });
    }
}

function showInstallButton() {
    // Create install button if it doesn't exist
    if (!document.getElementById('installButton')) {
        const installButton = document.createElement('button');
        installButton.id = 'installButton';
        installButton.innerHTML = '<i class="fas fa-download"></i> Install App';
        installButton.className = 'btn btn-primary position-fixed';
        installButton.style.cssText = 'bottom: 20px; right: 20px; z-index: 1000; border-radius: 25px; padding: 10px 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);';
        
        installButton.addEventListener('click', () => {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then((choiceResult) => {
                    if (choiceResult.outcome === 'accepted') {
                        console.log('User accepted the install prompt');
                        installButton.style.display = 'none';
                    } else {
                        console.log('User dismissed the install prompt');
                    }
                    deferredPrompt = null;
                });
            } else {
                // If no deferred prompt, show manual instructions
                showManualInstallInstructions();
            }
        });
        
        document.body.appendChild(installButton);
        
        // Keep button visible - don't auto-hide
        // setTimeout(() => {
        //     if (installButton) {
        //         installButton.style.display = 'none';
        //     }
        // }, 10000);
    }
}

// Function to show manual install instructions
function showManualInstallInstructions() {
    let instructions = '';
    
    if (/iPhone|iPad|iPod/i.test(navigator.userAgent)) {
        instructions = 'To install: Tap the share button 📤 and select "Add to Home Screen"';
    } else if (/Android/i.test(navigator.userAgent)) {
        instructions = 'To install: Tap the menu button ⋮ and select "Add to Home Screen"';
    } else {
        instructions = 'To install: Click the install icon in your browser address bar or use browser menu';
    }
    
    alert('Install Instructions:\n\n' + instructions);
}

// Hide install buttons if app is already installed
window.addEventListener('appinstalled', () => {
    const installButton = document.getElementById('installButton');
    const headerButton = document.getElementById('headerInstallButton');
    
    if (installButton) {
        installButton.style.display = 'none';
    }
    if (headerButton) {
        headerButton.style.display = 'none';
    }
    console.log('PWA was installed');
});

// Show PWA instructions on mobile devices
function showPWAInstructions() {
    const instructions = document.getElementById('pwaInstructions');
    const installText = document.getElementById('installInstructions');
    
    if (instructions && /Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)) {
        instructions.classList.remove('d-none');
        
        // Customize instructions based on device
        if (/iPhone|iPad|iPod/i.test(navigator.userAgent)) {
            installText.innerHTML = 'Tap the share button <i class="fas fa-share"></i> and select "Add to Home Screen" to use BillFlow like a native app!';
        } else if (/Android/i.test(navigator.userAgent)) {
            installText.innerHTML = 'Tap the menu button <i class="fas fa-ellipsis-v"></i> and select "Add to Home Screen" to use BillFlow like a native app!';
        }
        
        // Hide instructions after 15 seconds
        setTimeout(() => {
            instructions.style.display = 'none';
        }, 15000);
    }
}

// Show instructions when page loads
document.addEventListener('DOMContentLoaded', showPWAInstructions);

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
                badgeElement.textContent = customers.length;
                
                if (customers.length === 0) {
                    contentDiv.innerHTML = `
                        <div class="text-center py-4">
                            <i class="fas fa-check-circle text-success success-icon-lg"></i>
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