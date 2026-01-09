<?php
// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../views/layout/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit();
}

// Process form submission
if (isset($_POST['change_code'])) {
    $current_code = $_POST['current_code'] ?? '';
    $new_code = $_POST['new_code'] ?? '';
    $confirm_code = $_POST['confirm_code'] ?? '';

    // Verify current code
    $code_sql = "SELECT payment_secret_code FROM company_settings LIMIT 1";
    $code_result = $conn->query($code_sql);
    $settings = $code_result->fetch_assoc();

    if (empty($current_code) || empty($new_code) || empty($confirm_code)) {
        $error_message = "All fields are required!";
    } else if ($current_code !== $settings['payment_secret_code']) {
        $error_message = "Current code is incorrect!";
    } else if ($new_code !== $confirm_code) {
        $error_message = "New code and confirmation do not match!";
    } else if ($new_code === $current_code) {
        $error_message = "New code must be different from current code!";
    } else {
        // Update the code
        $update_sql = "UPDATE company_settings SET payment_secret_code = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("s", $new_code);
        
        if ($stmt->execute()) {
            $success_message = "Payment code updated successfully!";
        } else {
            $error_message = "Error updating payment code: " . $conn->error;
        }
        
        $stmt->close();
    }
}
?>

<div class="main-content">
    <div class="page-header">
        <h1>Change Payment Security Code</h1>
        <p>Update the security code required for payment transactions</p>
    </div>

    <!-- Display Messages -->
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="dashboard-card mt-4">
        <form method="post" action="change_payment_code.php">
            <div class="mb-3">
                <label for="current_code" class="form-label">Current Code <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="current_code" name="current_code" required>
            </div>
            <div class="mb-3">
                <label for="new_code" class="form-label">New Code <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="new_code" name="new_code" required>
            </div>
            <div class="mb-3">
                <label for="confirm_code" class="form-label">Confirm New Code <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="confirm_code" name="confirm_code" required>
            </div>
            <div class="mb-3">
                <button type="submit" name="change_code" class="btn btn-primary">Update Code</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../views/layout/footer.php'; ?>
