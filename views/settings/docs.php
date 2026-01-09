<?php
require_once __DIR__ . '/../../config/session_config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About BillFlow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- TODO: Move inline styles to external CSS file: assets/css/docs.css -->
</head>
<body>
    <div class="container">
        <h1 class="text-center">BillFlow: Complete Business Management System</h1>
        
        <!-- Introduction -->
        <div class="section">
            <h2>System Overview</h2>
            <p>BillFlow is a comprehensive business management solution designed for modern businesses. It seamlessly integrates inventory management, billing, customer/vendor relationships, and financial tracking into one unified platform. The system is specifically tailored for businesses dealing with inventory-based sales and vendor management.</p>
        </div>

        <!-- Core Features -->
        <div class="section">
            <h2>Core System Features</h2>

            <h3>1. Inventory Management</h3>
            <p>The inventory system tracks all stock movements with precision:</p>
            <ul class="feature-list">
                <li>Real-time stock tracking for multiple vendors</li>
                <li>Stock receipt and depletion tracking</li>
                <li>Automatic stock updates with sales</li>
                <li>Stock history and movement tracking</li>
                <li>Multiple vendor inventory management</li>
                <li>Date-wise stock organization</li>
            </ul>

            <h3>2. Billing System</h3>
            <p>A robust billing system that handles all sales transactions:</p>
            <ul class="feature-list">
                <li>Professional invoice generation</li>
                <li>Automatic invoice numbering</li>
                <li>Multiple item billing</li>
                <li>Weight-based and quantity-based billing</li>
                <li>Customer balance tracking</li>
                <li>Invoice history and management</li>
            </ul>

            <h3>3. Customer Management</h3>
            <p>Complete customer relationship management:</p>
            <ul class="feature-list">
                <li>Customer profiles and history</li>
                <li>Balance tracking and management</li>
                <li>Payment history tracking</li>
                <li>Customer ledger maintenance</li>
                <li>Multiple payment mode support</li>
                <li>Outstanding balance monitoring</li>
            </ul>

            <h3>4. Vendor Management</h3>
            <p>Comprehensive vendor relationship handling:</p>
            <ul class="feature-list">
                <li>Vendor categorization (Local/Outsider)</li>
                <li>Stock receipt tracking</li>
                <li>Watak (purchase document) management</li>
                <li>Vendor payment processing</li>
                <li>Vendor ledger maintenance</li>
                <li>Balance and payment tracking</li>
            </ul>
        </div>

        <!-- Business Processes -->
        <div class="section">
            <h2>Business Processes</h2>

            <h3>Stock Management Process</h3>
            <p>The system handles stock in a systematic way:</p>
            <ul class="feature-list">
                <li>Stock arrives from vendor - recorded with date and quantities</li>
                <li>Each item tracked individually with initial and remaining stock</li>
                <li>Stock automatically reduces with sales</li>
                <li>System maintains stock history by date and vendor</li>
                <li>Stock reports available for analysis</li>
            </ul>

            <h3>Sales Process</h3>
            <p>Sales handling is streamlined:</p>
            <ul class="feature-list">
                <li>Customer selects items for purchase</li>
                <li>System checks stock availability</li>
                <li>Invoice generated with automatic calculations</li>
                <li>Stock levels updated automatically</li>
                <li>Customer balance updated</li>
            </ul>

            <h3>Payment Processing</h3>
            <p>The system manages both incoming and outgoing payments:</p>
            <ul class="feature-list">
                <li>Customer payments recorded with mode (Cash/Bank)</li>
                <li>Vendor payments processed and tracked</li>
                <li>Balance updates happen automatically</li>
                <li>Payment history maintained for reference</li>
                <li>Discount handling supported</li>
            </ul>
        </div>

        <!-- Financial Management -->
        <div class="section">
            <h2>Financial Management</h2>
            
            <h3>Balance Tracking</h3>
            <p>The system maintains accurate financial records:</p>
            <ul class="feature-list">
                <li>Customer outstanding balances</li>
                <li>Vendor payable amounts</li>
                <li>Payment tracking and history</li>
                <li>Financial summaries and reports</li>
            </ul>

            <h3>Calculations</h3>
            <p>The system handles various calculations automatically:</p>
            <ul class="feature-list">
                <li>Invoice totals based on quantity or weight</li>
                <li>Balance calculations for customers and vendors</li>
                <li>Payment and discount adjustments</li>
                <li>Stock valuations and summaries</li>
            </ul>
        </div>

        <!-- Reports and Analytics -->
        <div class="section">
            <h2>Reports and Analytics</h2>
            <p>Comprehensive reporting system includes:</p>
            <ul class="feature-list">
                <li>Daily sales and purchase summaries</li>
                <li>Customer and vendor ledgers</li>
                <li>Stock reports and valuations</li>
                <li>Payment collection reports</li>
                <li>Outstanding balance reports</li>
                <li>Inventory movement analysis</li>
            </ul>
        </div>

        <!-- System Benefits -->
        <div class="section">
            <h2>System Benefits</h2>
            <ul class="feature-list">
                <li>Complete business process automation</li>
                <li>Accurate financial tracking</li>
                <li>Reduced manual work and errors</li>
                <li>Better inventory control</li>
                <li>Improved customer service</li>
                <li>Enhanced vendor relationships</li>
                <li>Professional business image</li>
                <li>Data-driven decision making</li>
            </ul>
        </div>

        <!-- Mobile Features -->
        <div class="section">
            <h2>Mobile Capabilities</h2>
            <p>The system is fully mobile-responsive with PWA features:</p>
            <ul class="feature-list">
                <li>Access from any device</li>
                <li>Mobile-friendly interface</li>
                <li>Touch-optimized controls</li>
                <li>Offline capabilities</li>
                <li>App-like experience</li>
            </ul>
        </div>

        <!-- Pricing -->
        <!-- <div class="section">
            <h2>System Pricing</h2>
            <div class="alert alert-info">
                <h4>One-Time License: ₹75,000</h4>
                <p>Includes:</p>
                <ul class="feature-list">
                    <li>Complete system setup</li>
                    <li>Installation support</li>
                    <li>3 months technical support</li>
                    <li>User documentation</li>
                    <li>1 year free updates</li>
                </ul>
            </div>

            <div class="mt-4">
                <h4>Optional Add-ons:</h4>
                <ul class="feature-list">
                    <li>Extended Support: ₹15,000/year</li>
                    <li>Custom Modifications: Quote on request</li>
                    <li>Data Migration: Quote on request</li>
                    <li>On-site Training: Quote on request</li>
                </ul>
            </div>
        </div> -->
    </div>
<?php include __DIR__ . '/../layout/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
