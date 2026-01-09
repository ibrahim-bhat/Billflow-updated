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

// Get statistics
$stats = array();
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

// Today's cash and bank collected (excluding Cash customer)
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

$stats['todays_total_receivable'] = $stats['todays_cash'] + $stats['todays_bank'];

// Get today's all payment details
$sql = "SELECT cp.*, c.name as customer_name 
        FROM customer_payments cp 
        JOIN customers c ON cp.customer_id = c.id 
        WHERE DATE(cp.date) = ? AND c.name != 'Cash'
        ORDER BY cp.id ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $today);
$stmt->execute();
$result = $stmt->get_result();
$todays_all_details = [];
while ($row = $result->fetch_assoc()) {
    $todays_all_details[] = $row;
}

// Yesterday's data
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

$stats['yesterdays_total_receivable'] = $stats['yesterdays_cash'] + $stats['yesterdays_bank'];

$sql = "SELECT cp.*, c.name as customer_name 
        FROM customer_payments cp 
        JOIN customers c ON cp.customer_id = c.id 
        WHERE DATE(cp.date) = ? AND c.name != 'Cash'
        ORDER BY cp.id ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $yesterday);
$stmt->execute();
$result = $stmt->get_result();
$yesterdays_all_details = [];
while ($row = $result->fetch_assoc()) {
    $yesterdays_all_details[] = $row;
}

// Vendor payments data
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

$sql = "SELECT vp.*, v.name as vendor_name, v.vendor_category FROM vendor_payments vp 
        LEFT JOIN vendors v ON vp.vendor_id = v.id 
        WHERE DATE(vp.date) = ? ORDER BY v.vendor_category, vp.date DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $today);
$stmt->execute();
$result = $stmt->get_result();
$todays_vendor_payments_details_commission = [];
$todays_vendor_payments_details_purchase = [];

while ($row = $result->fetch_assoc()) {
    if ($row['vendor_category'] == 'Commission Based') {
        $todays_vendor_payments_details_commission[] = $row;
    } else if ($row['vendor_category'] == 'Purchase Based') {
        $todays_vendor_payments_details_purchase[] = $row;
    }
}

// Yesterday's vendor payments
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

$sql = "SELECT vp.*, v.name as vendor_name, v.vendor_category FROM vendor_payments vp 
        LEFT JOIN vendors v ON vp.vendor_id = v.id 
        WHERE DATE(vp.date) = ? ORDER BY v.vendor_category, vp.date DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $yesterday);
$stmt->execute();
$result = $stmt->get_result();
$yesterdays_vendor_payments_details_commission = [];
$yesterdays_vendor_payments_details_purchase = [];

while ($row = $result->fetch_assoc()) {
    if ($row['vendor_category'] == 'Commission Based') {
        $yesterdays_vendor_payments_details_commission[] = $row;
    } else if ($row['vendor_category'] == 'Purchase Based') {
        $yesterdays_vendor_payments_details_purchase[] = $row;
    }
}
?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fas fa-history"></i> Transaction History</h1>
        <p class="text-muted">Comprehensive view of all payment transactions</p>
    </div>

    <!-- Tabs Navigation -->
    <ul class="nav nav-tabs mb-4" id="historyTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="customer-today-tab" data-bs-toggle="tab" data-bs-target="#customer-today" type="button" role="tab">
                <i class="fas fa-calendar-day"></i> Today's Collections
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="customer-yesterday-tab" data-bs-toggle="tab" data-bs-target="#customer-yesterday" type="button" role="tab">
                <i class="fas fa-calendar-check"></i> Yesterday's Collections
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="vendor-today-tab" data-bs-toggle="tab" data-bs-target="#vendor-today" type="button" role="tab">
                <i class="fas fa-hand-holding-usd"></i> Today's Vendor Payments
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="vendor-yesterday-tab" data-bs-toggle="tab" data-bs-target="#vendor-yesterday" type="button" role="tab">
                <i class="fas fa-history"></i> Yesterday's Vendor Payments
            </button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content" id="historyTabsContent">
        
        <!-- Today's Customer Collections Tab -->
        <div class="tab-pane fade show active" id="customer-today" role="tabpanel">
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stat-box text-center p-3 border rounded bg-light">
                        <h6 class="text-muted mb-2">Total Collection</h6>
                        <h3 class="text-primary mb-0">₹<?php echo number_format($stats['todays_total_receivable'], 2); ?></h3>
                        <small class="text-muted"><?php echo numberToWords($stats['todays_total_receivable']); ?></small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box text-center p-3 border rounded bg-light">
                        <h6 class="text-muted mb-2"><i class="fas fa-money-bill-wave text-success"></i> Cash</h6>
                        <h3 class="text-success mb-0">₹<?php echo number_format($stats['todays_cash'], 2); ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box text-center p-3 border rounded bg-light">
                        <h6 class="text-muted mb-2"><i class="fas fa-university text-info"></i> Bank</h6>
                        <h3 class="text-info mb-0">₹<?php echo number_format($stats['todays_bank'], 2); ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box text-center p-3 border rounded bg-light">
                        <h6 class="text-muted mb-2">Transactions</h6>
                        <h3 class="text-dark mb-0"><?php echo count($todays_all_details); ?></h3>
                    </div>
                </div>
            </div>
            
            <?php if (count($todays_all_details) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Customer Name</th>
                                <th>Receipt No.</th>
                                <th class="text-end">Amount</th>
                                <th class="text-center">Payment Mode</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1; foreach ($todays_all_details as $payment): ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($payment['customer_name']); ?></strong></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($payment['receipt_no'] ?: '00'); ?></span></td>
                                    <td class="text-end"><strong>₹<?php echo number_format($payment['amount'] + $payment['discount'], 2); ?></strong></td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $payment['payment_mode'] == 'Cash' ? 'bg-success' : 'bg-info'; ?>">
                                            <i class="fas <?php echo $payment['payment_mode'] == 'Cash' ? 'fa-money-bill-wave' : 'fa-university'; ?>"></i>
                                            <?php echo $payment['payment_mode']; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="3" class="text-end"><strong>Grand Total:</strong></td>
                                <td class="text-end"><strong class="text-primary">₹<?php echo number_format($stats['todays_total_receivable'], 2); ?></strong></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle"></i> No customer payments recorded today
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Yesterday's Customer Collections Tab -->
        <div class="tab-pane fade" id="customer-yesterday" role="tabpanel">
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stat-box text-center p-3 border rounded bg-light">
                        <h6 class="text-muted mb-2">Total Collection</h6>
                        <h3 class="text-primary mb-0">₹<?php echo number_format($stats['yesterdays_total_receivable'], 2); ?></h3>
                        <small class="text-muted"><?php echo numberToWords($stats['yesterdays_total_receivable']); ?></small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box text-center p-3 border rounded bg-light">
                        <h6 class="text-muted mb-2"><i class="fas fa-money-bill-wave text-success"></i> Cash</h6>
                        <h3 class="text-success mb-0">₹<?php echo number_format($stats['yesterdays_cash'], 2); ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box text-center p-3 border rounded bg-light">
                        <h6 class="text-muted mb-2"><i class="fas fa-university text-info"></i> Bank</h6>
                        <h3 class="text-info mb-0">₹<?php echo number_format($stats['yesterdays_bank'], 2); ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box text-center p-3 border rounded bg-light">
                        <h6 class="text-muted mb-2">Transactions</h6>
                        <h3 class="text-dark mb-0"><?php echo count($yesterdays_all_details); ?></h3>
                    </div>
                </div>
            </div>
            
            <?php if (count($yesterdays_all_details) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Customer Name</th>
                                <th>Receipt No.</th>
                                <th class="text-end">Amount</th>
                                <th class="text-center">Payment Mode</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1; foreach ($yesterdays_all_details as $payment): ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($payment['customer_name']); ?></strong></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($payment['receipt_no'] ?: '00'); ?></span></td>
                                    <td class="text-end"><strong>₹<?php echo number_format($payment['amount'] + $payment['discount'], 2); ?></strong></td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $payment['payment_mode'] == 'Cash' ? 'bg-success' : 'bg-info'; ?>">
                                            <i class="fas <?php echo $payment['payment_mode'] == 'Cash' ? 'fa-money-bill-wave' : 'fa-university'; ?>"></i>
                                            <?php echo $payment['payment_mode']; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="3" class="text-end"><strong>Grand Total:</strong></td>
                                <td class="text-end"><strong class="text-primary">₹<?php echo number_format($stats['yesterdays_total_receivable'], 2); ?></strong></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle"></i> No customer payments recorded yesterday
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Today's Vendor Payments Tab -->
        <div class="tab-pane fade" id="vendor-today" role="tabpanel">
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stat-box text-center p-3 border rounded bg-danger bg-opacity-10">
                        <h6 class="text-muted mb-2">Total Vendor Payments</h6>
                        <h3 class="text-danger mb-0">₹<?php echo number_format($stats['todays_vendor_payments_commission'] + $stats['todays_vendor_payments_purchase'], 2); ?></h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box text-center p-3 border rounded bg-warning bg-opacity-10">
                        <h6 class="text-muted mb-2"><i class="fas fa-handshake text-warning"></i> Commission Based</h6>
                        <h3 class="text-warning mb-0">₹<?php echo number_format($stats['todays_vendor_payments_commission'], 2); ?></h3>
                        <small class="text-muted"><?php echo ucfirst(numberToWords((int)$stats['todays_vendor_payments_commission'])); ?></small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box text-center p-3 border rounded bg-primary bg-opacity-10">
                        <h6 class="text-muted mb-2"><i class="fas fa-shopping-cart text-primary"></i> Purchase Based</h6>
                        <h3 class="text-primary mb-0">₹<?php echo number_format($stats['todays_vendor_payments_purchase'], 2); ?></h3>
                        <small class="text-muted"><?php echo ucfirst(numberToWords((int)$stats['todays_vendor_payments_purchase'])); ?></small>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-4">
                    <h5 class="mb-3"><i class="fas fa-handshake text-warning"></i> Commission Based Vendors</h5>
                    <?php if (count($todays_vendor_payments_details_commission) > 0): ?>
                        <div class="list-group">
                            <?php foreach ($todays_vendor_payments_details_commission as $payment): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                        <h6 class="mb-0"><?php echo htmlspecialchars($payment['vendor_name'] ?? 'Unknown Vendor'); ?></h6>
                                        <span class="badge <?php echo strtolower($payment['payment_mode']) == 'cash' ? 'bg-success' : 'bg-info'; ?>">
                                            <i class="fas <?php echo strtolower($payment['payment_mode']) == 'cash' ? 'fa-money-bill-wave' : 'fa-university'; ?>"></i>
                                            <?php echo ucfirst($payment['payment_mode']); ?>
                                        </span>
                                    </div>
                                    <div class="d-flex w-100 justify-content-between">
                                        <small class="text-muted">Receipt: <?php echo htmlspecialchars($payment['receipt_no'] ?: 'N/A'); ?></small>
                                        <strong class="text-danger">₹<?php echo number_format($payment['amount'] + $payment['discount'], 2); ?></strong>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No commission vendor payments today
                        </div>
                    <?php endif; ?>
                </div>

                <div class="col-md-6 mb-4">
                    <h5 class="mb-3"><i class="fas fa-shopping-cart text-primary"></i> Purchase Based Vendors</h5>
                    <?php if (count($todays_vendor_payments_details_purchase) > 0): ?>
                        <div class="list-group">
                            <?php foreach ($todays_vendor_payments_details_purchase as $payment): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                        <h6 class="mb-0"><?php echo htmlspecialchars($payment['vendor_name'] ?? 'Unknown Vendor'); ?></h6>
                                        <span class="badge <?php echo strtolower($payment['payment_mode']) == 'cash' ? 'bg-success' : 'bg-info'; ?>">
                                            <i class="fas <?php echo strtolower($payment['payment_mode']) == 'cash' ? 'fa-money-bill-wave' : 'fa-university'; ?>"></i>
                                            <?php echo ucfirst($payment['payment_mode']); ?>
                                        </span>
                                    </div>
                                    <div class="d-flex w-100 justify-content-between">
                                        <small class="text-muted">Receipt: <?php echo htmlspecialchars($payment['receipt_no'] ?: 'N/A'); ?></small>
                                        <strong class="text-danger">₹<?php echo number_format($payment['amount'] + $payment['discount'], 2); ?></strong>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No purchase vendor payments today
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Yesterday's Vendor Payments Tab -->
        <div class="tab-pane fade" id="vendor-yesterday" role="tabpanel">
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stat-box text-center p-3 border rounded bg-danger bg-opacity-10">
                        <h6 class="text-muted mb-2">Total Vendor Payments</h6>
                        <h3 class="text-danger mb-0">₹<?php echo number_format($stats['yesterdays_vendor_payments_commission'] + $stats['yesterdays_vendor_payments_purchase'], 2); ?></h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box text-center p-3 border rounded bg-warning bg-opacity-10">
                        <h6 class="text-muted mb-2"><i class="fas fa-handshake text-warning"></i> Commission Based</h6>
                        <h3 class="text-warning mb-0">₹<?php echo number_format($stats['yesterdays_vendor_payments_commission'], 2); ?></h3>
                        <small class="text-muted"><?php echo ucfirst(numberToWords((int)$stats['yesterdays_vendor_payments_commission'])); ?></small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box text-center p-3 border rounded bg-primary bg-opacity-10">
                        <h6 class="text-muted mb-2"><i class="fas fa-shopping-cart text-primary"></i> Purchase Based</h6>
                        <h3 class="text-primary mb-0">₹<?php echo number_format($stats['yesterdays_vendor_payments_purchase'], 2); ?></h3>
                        <small class="text-muted"><?php echo ucfirst(numberToWords((int)$stats['yesterdays_vendor_payments_purchase'])); ?></small>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-4">
                    <h5 class="mb-3"><i class="fas fa-handshake text-warning"></i> Commission Based Vendors</h5>
                    <?php if (count($yesterdays_vendor_payments_details_commission) > 0): ?>
                        <div class="list-group">
                            <?php foreach ($yesterdays_vendor_payments_details_commission as $payment): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                        <h6 class="mb-0"><?php echo htmlspecialchars($payment['vendor_name'] ?? 'Unknown Vendor'); ?></h6>
                                        <span class="badge <?php echo strtolower($payment['payment_mode']) == 'cash' ? 'bg-success' : 'bg-info'; ?>">
                                            <i class="fas <?php echo strtolower($payment['payment_mode']) == 'cash' ? 'fa-money-bill-wave' : 'fa-university'; ?>"></i>
                                            <?php echo ucfirst($payment['payment_mode']); ?>
                                        </span>
                                    </div>
                                    <div class="d-flex w-100 justify-content-between">
                                        <small class="text-muted">Receipt: <?php echo htmlspecialchars($payment['receipt_no'] ?: 'N/A'); ?></small>
                                        <strong class="text-danger">₹<?php echo number_format($payment['amount'] + $payment['discount'], 2); ?></strong>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No commission vendor payments yesterday
                        </div>
                    <?php endif; ?>
                </div>

                <div class="col-md-6 mb-4">
                    <h5 class="mb-3"><i class="fas fa-shopping-cart text-primary"></i> Purchase Based Vendors</h5>
                    <?php if (count($yesterdays_vendor_payments_details_purchase) > 0): ?>
                        <div class="list-group">
                            <?php foreach ($yesterdays_vendor_payments_details_purchase as $payment): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                        <h6 class="mb-0"><?php echo htmlspecialchars($payment['vendor_name'] ?? 'Unknown Vendor'); ?></h6>
                                        <span class="badge <?php echo strtolower($payment['payment_mode']) == 'cash' ? 'bg-success' : 'bg-info'; ?>">
                                            <i class="fas <?php echo strtolower($payment['payment_mode']) == 'cash' ? 'fa-money-bill-wave' : 'fa-university'; ?>"></i>
                                            <?php echo ucfirst($payment['payment_mode']); ?>
                                        </span>
                                    </div>
                                    <div class="d-flex w-100 justify-content-between">
                                        <small class="text-muted">Receipt: <?php echo htmlspecialchars($payment['receipt_no'] ?: 'N/A'); ?></small>
                                        <strong class="text-danger">₹<?php echo number_format($payment['amount'] + $payment['discount'], 2); ?></strong>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No purchase vendor payments yesterday
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
