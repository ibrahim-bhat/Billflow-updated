<?php
// Include error handler
require_once __DIR__ . '/error_handler.php';

// Include session configuration
require_once __DIR__ . '/config/session_config.php';

// Check if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: views/dashboard/index.php");
    exit;
}

// Database connection
require_once __DIR__ . '/config/config.php';

// Get company settings for logo and company name
$company_name = "BillFlow"; // Default fallback
$company_logo = "assets/images/kichlooandco-iconlogo.jpeg"; // Default fallback
try {
    $sql = "SELECT company_name, logo_path FROM company_settings LIMIT 1";
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
    error_log("Error fetching company settings: " . $e->getMessage());
}

$error = '';

// Process login
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        // Query to check user
        $sql = "SELECT id, username, password FROM users WHERE username = ?";
        
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("s", $username);
            
            if ($stmt->execute()) {
                $stmt->store_result();
                
                if ($stmt->num_rows == 1) {
                    $stmt->bind_result($id, $username, $hashed_password);
                    
                    if ($stmt->fetch()) {
                        if (password_verify($password, $hashed_password)) {
                            // Password is correct, store data in session variables
                            $_SESSION['user_id'] = $id;
                            $_SESSION['username'] = $username;
                            
                            // Redirect to dashboard
                            header("Location: views/dashboard/index.php");
                            exit;
                        } else {
                            $error = 'Invalid username or password';
                        }
                    }
                } else {
                    $error = 'Invalid username or password';
                }
            } else {
                $error = 'Something went wrong. Please try again later.';
            }
            
            $stmt->close();
        }
    }
    
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BillFlow - Login</title>
    <link rel="manifest" href="manifest-generator.php">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container">
        <div class="row justify-content-center mt-5">
            <div class="col-md-6 col-lg-4">
                <div class="card shadow">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <img src="<?php echo htmlspecialchars($company_logo); ?>" alt="<?php echo htmlspecialchars($company_name); ?> Logo" class="mb-3" style="max-width: 120px; height: auto;">
                            <h2 class="fw-bold" style="color: #28a745;"><?php echo htmlspecialchars($company_name); ?></h2>
                            <p class="text-muted">Login to your account</p>
                        </div>
                        
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                            <div class="mb-4">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Login</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include __DIR__ . '/views/layout/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 