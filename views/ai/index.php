<?php
require_once __DIR__ . '/../../config/session_config.php';
require_once __DIR__ . '/../../config/config.php';

$error_message = null;

// Handle upload and redirect BEFORE header output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    try {
        if (!isset($_FILES['register_photo']) || $_FILES['register_photo']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Please upload a clear register photo.');
        }

        $fileTmp = $_FILES['register_photo']['tmp_name'];
        $origName = $_FILES['register_photo']['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $allowed = ['png', 'jpg', 'jpeg', 'webp'];
        if (!in_array($ext, $allowed)) {
            throw new Exception('Unsupported file type. Allowed: png, jpg, jpeg, webp');
        }

        $ts = date('Ymd_His');
        $safeName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $origName);
        $destDir = __DIR__ . '/../../uploads';
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0777, true);
        }
        $destPath = $destDir . DIRECTORY_SEPARATOR . $ts . '_' . $safeName;

        if (!move_uploaded_file($fileTmp, $destPath)) {
            throw new Exception('Failed to save uploaded file.');
        }

        $uploaded_path = $destPath;

        require_once __DIR__ . '/../../ai/OpenAIClient.php';
        $client = new OpenAIClient($conn);
        $parsed_data = $client->extractInvoicesFromImage($uploaded_path);
        $_SESSION['ai_parsed_invoices'] = $parsed_data;
        $_SESSION['ai_uploaded_path'] = $uploaded_path;
        
        // Redirect directly to mapping page
        header('Location: map.php');
        exit();
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Include header only after redirect logic
require_once __DIR__ . '/../layout/header.php';
?>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">AI: Create Invoices from Register Photo</h2>
        <a href="../views/invoices/index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Invoices
        </a>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <form method="post" enctype="multipart/form-data" class="d-flex align-items-center gap-3">
                <input type="hidden" name="action" value="upload">
                <div class="flex-grow-1">
                    <input type="file" class="form-control" name="register_photo" accept="image/*" required>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-magic"></i> Analyze and Create Invoices
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>


