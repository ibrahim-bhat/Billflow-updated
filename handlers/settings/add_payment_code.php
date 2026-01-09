<?php
require_once __DIR__ . '/../../config/config.php';

try {
    // Check if column already exists
    $check_sql = "SHOW COLUMNS FROM company_settings LIKE 'payment_secret_code'";
    $result = $conn->query($check_sql);
    
    if ($result->num_rows == 0) {
        // Column doesn't exist, so add it
        $sql = "ALTER TABLE company_settings 
                ADD COLUMN payment_secret_code VARCHAR(255) NOT NULL DEFAULT '123456'";
        
        if ($conn->query($sql)) {
            echo "Success: Payment code column added successfully! Default code is: 123456";
        } else {
            echo "Error: " . $conn->error;
        }
    } else {
        echo "Column 'payment_secret_code' already exists in company_settings table.";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
