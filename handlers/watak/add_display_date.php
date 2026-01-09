<?php
require_once __DIR__ . '/../../config/config.php';

header('Content-Type: text/html'); // Ensure browser displays HTML output

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Check if display_date column exists
$check_sql = "SHOW COLUMNS FROM vendor_watak LIKE 'display_date'";
$result = $conn->query($check_sql);

if ($result && $result->num_rows > 0) {
    echo "Column 'display_date' already exists in table 'vendor_watak'. No changes made.<br>";
} else {
    // Add display_date column
    $alter_sql = "ALTER TABLE vendor_watak ADD COLUMN display_date DATE DEFAULT NULL";
    if ($conn->query($alter_sql) === TRUE) {
        echo "Column 'display_date' added successfully to table 'vendor_watak'.<br>";
        
        // Update existing records to set display_date equal to date
        $update_sql = "UPDATE vendor_watak SET display_date = date WHERE display_date IS NULL";
        if ($conn->query($update_sql) === TRUE) {
            echo "Updated existing records: display_date set to match date field.<br>";
        } else {
            echo "Error updating existing records: " . $conn->error . "<br>";
        }
    } else {
        echo "Error adding column 'display_date': " . $conn->error . "<br>";
    }
}

$conn->close();
?>
