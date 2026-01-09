<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../layout/header.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->begin_transaction();
        
        if (isset($_POST['update_company'])) {
            // Handle logo upload
            $logo_path = $settings['logo_path'] ?? ''; // Keep existing logo if no new one uploaded
            
            if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/logos/';
                
                // Create directory if it doesn't exist
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $new_filename = 'company_logo_' . time() . '.' . $file_extension;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $upload_path)) {
                        // Delete old logo if exists
                        if (!empty($settings['logo_path']) && file_exists($settings['logo_path'])) {
                            unlink($settings['logo_path']);
                        }
                        $logo_path = $upload_path;
                    }
                }
            }
            
            // Update company settings
            $sql = "UPDATE company_settings SET 
                    company_name = ?,
                    company_address = ?,
                    company_phone = ?,
                    company_email = ?,
                    company_gst = ?,
                    terms_conditions = ?,
                    contact_numbers = ?,
                    business_tagline = ?,
                    trademark = ?,
                    bank_account_details = ?,
                    logo_path = ?,
                    openai_api_key = ?,
                    ai_prev_marker = ?,
                    ai_prev_prev_marker = ?
                    WHERE id = ?";
            
            $stmt = $conn->prepare($sql);
            $openai_api_key = isset($_POST['openai_api_key']) ? trim($_POST['openai_api_key']) : '';
            $ai_prev_marker = isset($_POST['ai_prev_marker']) ? trim(strtolower($_POST['ai_prev_marker'])) : 'p';
            $ai_prev_prev_marker = isset($_POST['ai_prev_prev_marker']) ? trim(strtolower($_POST['ai_prev_prev_marker'])) : 'pp';
            $stmt->bind_param('ssssssssssssisi',
                $_POST['company_name'],
                $_POST['company_address'],
                $_POST['company_phone'],
                $_POST['company_email'],
                $_POST['company_gst'],
                $_POST['terms_conditions'],
                $_POST['contact_numbers'],
                $_POST['business_tagline'],
                $_POST['trademark'],
                $_POST['bank_account_details'],
                $logo_path,
                $openai_api_key,
                $ai_prev_marker,
                $ai_prev_prev_marker,
                $_POST['settings_id']
            );
            $stmt->execute();
            
            $success_message = "Company settings updated successfully";
        } elseif (isset($_POST['update_password'])) {
            // Validate new password
            if ($_POST['new_password'] === $_POST['confirm_password']) {
                // Update password
                $new_password_hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                $sql = "UPDATE users SET password = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('si', $new_password_hash, $_SESSION['user_id']);
                $stmt->execute();
                
                $success_message = "Password updated successfully";
            } else {
                $error_message = "New password and confirmation do not match";
            }
        } elseif (isset($_POST['change_payment_code'])) {
            // Handle payment code change
            $current_code = $_POST['current_code'] ?? '';
            $new_code = $_POST['new_code'] ?? '';
            $confirm_code = $_POST['confirm_code'] ?? '';

            // Get current payment code
            $code_sql = "SELECT payment_secret_code FROM company_settings LIMIT 1";
            $code_result = $conn->query($code_sql);
            $current_settings = $code_result->fetch_assoc();

            if (empty($current_code) || empty($new_code) || empty($confirm_code)) {
                $error_message = "All fields are required for payment code change!";
            } elseif ($current_code !== $current_settings['payment_secret_code']) {
                $error_message = "Current payment code is incorrect!";
            } elseif ($new_code !== $confirm_code) {
                $error_message = "New payment code and confirmation do not match!";
            } elseif ($new_code === $current_code) {
                $error_message = "New payment code must be different from current code!";
            } else {
                // Update the payment code
                $update_sql = "UPDATE company_settings SET payment_secret_code = ?";
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param("s", $new_code);
                
                if ($stmt->execute()) {
                    $success_message = "Payment security code updated successfully!";
                } else {
                    $error_message = "Error updating payment code: " . $conn->error;
                }
                
                $stmt->close();
            }
        } elseif (isset($_POST['clear_database'])) {
            // Clear database tables
            $tables = [
                'customer_invoice_items',
                'customer_invoices',
                'customer_payments',
                'customers',
                'inventory_items',
                'inventory',
                'invoice_items',
                'items',
                'vendor_bill_items',
                'vendor_bills',
                'vendor_payments',
                'vendors',
                'vendor_watak',
                'watak_items'
            ];
            
            // Disable foreign key checks temporarily
            $conn->query("SET FOREIGN_KEY_CHECKS = 0");
            
            foreach ($tables as $table) {
                $conn->query("TRUNCATE TABLE $table");
            }
            
            // Re-enable foreign key checks
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
            
            $success_message = "Database cleared successfully";
        }
        
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error: " . $e->getMessage();
    }
}

// Get current settings
$sql = "SELECT * FROM company_settings LIMIT 1";
$settings = $conn->query($sql)->fetch_assoc();
?>

<!-- Main content -->
<div class="main-content">
    <section class="content">
        <div class="container-fluid mt-4">
            <?php if (isset($success_message)): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
            <?php endif; ?>

            <div class="row">
                <!-- Company Settings -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Company Settings</h3>
                        </div>
                        <div class="card-body">
                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="settings_id" value="<?php echo $settings['id']; ?>">
                                
                                <!-- Logo Upload Section -->
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="company_logo">Company Logo</label>
                                            <input type="file" class="form-control" id="company_logo" name="company_logo" accept="image/*">
                                            <small class="form-text text-muted">Allowed formats: JPG, JPEG, PNG, GIF. Max size: 5MB</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <?php if (!empty($settings['logo_path']) && file_exists($settings['logo_path'])): ?>
                                        <div class="current-logo">
                                            <label>Current Logo:</label>
                                            <div class="mt-2">
                                                <img src="<?php echo htmlspecialchars($settings['logo_path']); ?>" 
                                                     alt="Company Logo" class="img-thumbnail logo-preview">
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="company_name">Company Name</label>
                                            <input type="text" class="form-control" id="company_name" name="company_name"
                                                   value="<?php echo htmlspecialchars($settings['company_name']); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="business_tagline">Business Tagline</label>
                                            <input type="text" class="form-control" id="business_tagline" name="business_tagline"
                                                   value="<?php echo htmlspecialchars($settings['business_tagline'] ?? ''); ?>">
                                            <small class="form-text text-muted">Short tagline describing your business</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="company_phone">Phone Number</label>
                                            <input type="text" class="form-control" id="company_phone" name="company_phone"
                                                   value="<?php echo htmlspecialchars($settings['company_phone']); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="company_email">Email</label>
                                            <input type="email" class="form-control" id="company_email" name="company_email"
                                                   value="<?php echo htmlspecialchars($settings['company_email']); ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="company_gst">GST Number</label>
                                            <input type="text" class="form-control" id="company_gst" name="company_gst"
                                                   value="<?php echo htmlspecialchars($settings['company_gst']); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="trademark">Trademark</label>
                                            <input type="text" class="form-control" id="trademark" name="trademark"
                                                   value="<?php echo htmlspecialchars($settings['trademark'] ?? ''); ?>">
                                            <small class="form-text text-muted">Trademark or registered mark information</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="company_address">Address</label>
                                    <textarea class="form-control" id="company_address" name="company_address" rows="3"><?php echo htmlspecialchars($settings['company_address']); ?></textarea>
                                </div>

                                <div class="form-group">
                                    <label for="bank_account_details">Bank Account Details</label>
                                    <textarea class="form-control" id="bank_account_details" name="bank_account_details" rows="4"><?php echo htmlspecialchars($settings['bank_account_details'] ?? ''); ?></textarea>
                                    <small class="form-text text-muted">Enter bank name, account number, IFSC code, etc. (one detail per line)</small>
                                </div>

                                <div class="form-group">
                                    <label for="contact_numbers">Contact Numbers</label>
                                    <textarea class="form-control" id="contact_numbers" name="contact_numbers" rows="4"><?php echo htmlspecialchars($settings['contact_numbers'] ?? ''); ?></textarea>
                                    <small class="form-text text-muted">Enter contact names with phone numbers (e.g., Ibrahim: 788965131, one per line)</small>
                                </div>

                                <div class="form-group">
                                    <label for="terms_conditions">Terms & Conditions</label>
                                    <textarea class="form-control" id="terms_conditions" name="terms_conditions" rows="5"><?php echo htmlspecialchars($settings['terms_conditions']); ?></textarea>
                                    <small class="form-text text-muted">Enter each term on a new line</small>
                                </div>

                                <hr>
                                <h5>AI Settings</h5>
                                <div class="form-group">
                                    <label for="openai_api_key">OpenAI API Key</label>
                                    <input type="password" class="form-control" id="openai_api_key" name="openai_api_key"
                                           value="<?php echo htmlspecialchars($settings['openai_api_key'] ?? ''); ?>" 
                                           placeholder="sk-...">
                                    <small class="form-text text-muted">Enter your OpenAI API key for AI invoice processing. Get it from <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a>.</small>
                                </div>

                                <hr>
                                <h5>AI Register Shortcuts</h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="ai_prev_marker">Marker for Yesterday</label>
                                            <input type="text" class="form-control" id="ai_prev_marker" name="ai_prev_marker"
                                                   value="<?php echo htmlspecialchars($settings['ai_prev_marker'] ?? 'p'); ?>">
                                            <small class="form-text text-muted">Default is "p". Example: ibp means Ibrahim yesterday.</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="ai_prev_prev_marker">Marker for Day-Before-Yesterday</label>
                                            <input type="text" class="form-control" id="ai_prev_prev_marker" name="ai_prev_prev_marker"
                                                   value="<?php echo htmlspecialchars($settings['ai_prev_prev_marker'] ?? 'pp'); ?>">
                                            <small class="form-text text-muted">Default is "pp". Example: ibpp means Ibrahim day-before-yesterday.</small>
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" name="update_company" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Company Settings
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Change Password -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Change Password</h3>
                        </div>
                        <div class="card-body">
                            <form method="post" id="passwordForm">
                                <div class="form-group">
                                    <label for="new_password">New Password</label>
                                    <input type="password" class="form-control" id="new_password" 
                                           name="new_password" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="confirm_password">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirm_password" 
                                           name="confirm_password" required>
                                </div>
                                
                                <button type="submit" name="update_password" class="btn btn-primary">
                                    <i class="fas fa-key"></i> Change Password
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Change Payment Security Code -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h3 class="card-title">Payment Security Code</h3>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">Update the security code required for payment transactions</p>
                            <form method="post" id="paymentCodeForm">
                                <div class="form-group">
                                    <label for="current_code">Current Code</label>
                                    <input type="password" class="form-control" id="current_code" 
                                           name="current_code" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="new_code">New Code</label>
                                    <input type="password" class="form-control" id="new_code" 
                                           name="new_code" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="confirm_code">Confirm New Code</label>
                                    <input type="password" class="form-control" id="confirm_code" 
                                           name="confirm_code" required>
                                </div>
                                
                                <button type="submit" name="change_payment_code" class="btn btn-primary">
                                    <i class="fas fa-shield-alt"></i> Update Payment Code
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Database Management -->
                    <!-- <div class="card mt-4">
                        <div class="card-header">
                            <h3 class="card-title">Database Management</h3>
                        </div>
                        <div class="card-body">
                            <form method="post">
                                <div class="alert alert-danger">
                                    <strong>Warning!</strong> Clicking the button below will permanently delete all data including customers, vendors, inventory, and transactions.
                                </div>
                                
                                <button type="submit" name="clear_database" class="btn btn-danger">
                                    <i class="fas fa-trash"></i> Clear Database
                                </button>
                            </form>
                        </div>
                    </div> -->
                </div>
            </div>
        </div>
    </section>
</div>

<script>
$(document).ready(function() {
    // Password form validation
    $('#passwordForm').submit(function(e) {
        const newPassword = $('#new_password').val();
        const confirmPassword = $('#confirm_password').val();
        
        if (newPassword !== confirmPassword) {
            e.preventDefault();
            alert('New password and confirmation do not match!');
        }
    });

    // Payment code form validation
    $('#paymentCodeForm').submit(function(e) {
        const newCode = $('#new_code').val();
        const confirmCode = $('#confirm_code').val();
        
        if (newCode !== confirmCode) {
            e.preventDefault();
            alert('New payment code and confirmation do not match!');
        }
    });

    // File size validation for logo upload
    $('#company_logo').change(function() {
        const file = this.files[0];
        const maxSize = 5 * 1024 * 1024; // 5MB
        
        if (file && file.size > maxSize) {
            alert('File size must be less than 5MB');
            this.value = '';
        }
    });
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?> 