<?php
// Auto-detect environment based on server name and HTTP host
$server_name = $_SERVER['SERVER_NAME'] ?? '';
$http_host = $_SERVER['HTTP_HOST'] ?? '';
$is_kichlooandco = (strpos($server_name, 'kichlooandco.com') !== false || 
                    strpos($http_host, 'kichlooandco.com') !== false ||
                    strpos($server_name, 'www.kichlooandco.com') !== false ||
                    strpos($http_host, 'www.kichlooandco.com') !== false);
$is_adilwaseementerprises = (strpos($server_name, 'adilwaseementerprises.com') !== false || 
                            strpos($http_host, 'adilwaseementerprises.com') !== false ||
                            strpos($server_name, 'www.adilwaseementerprises.com') !== false ||
                            strpos($http_host, 'www.adilwaseementerprises.com') !== false);
$is_evotec = (strpos($server_name, 'billflow.evotec.in') !== false || 
              strpos($http_host, 'billflow.evotec.in') !== false);

// For debugging - you can temporarily force local environment
// $is_kichlooandco = false;
// $is_adilwaseementerprises = false;

if ($is_kichlooandco) {
    // Kichloo and Co database settings (Production)
    define('DB_SERVER', '127.0.0.1');
    define('DB_USERNAME', 'kichlooandco');
            define('DB_PASSWORD', 'Ibrahimbhat123@@');
    define('DB_NAME', 'kichlooandco');
    define('BASE_URL', 'https://www.kichlooandco.com/');
} elseif ($is_adilwaseementerprises) {
    // Adil Waseem Enterprises database settings (Production)
    define('DB_SERVER', '127.0.0.1');
    define('DB_USERNAME', 'adilwaseemenp');
    define('DB_PASSWORD', 'Ibrahimbhat123@@');
    define('DB_NAME', 'adilwaseemenp');
    define('BASE_URL', 'https://www.adilwaseementerprises.com/');
} elseif ($is_evotec) {
    // Evotec database settings (Production)
    define('DB_SERVER', '127.0.0.1');
    define('DB_USERNAME', 'billsflow');
    define('DB_PASSWORD', 'Evotec123@');
    define('DB_NAME', 'billsflow');
    define('BASE_URL', 'https://billsflow.evotec.in/');
} else {
    // Local development database settings
    define('DB_SERVER', 'localhost');
    define('DB_USERNAME', 'root');
    define('DB_PASSWORD', '');
    define('DB_NAME', 'kichlooandco');
    define('BASE_URL', 'http://localhost/Billflow-updated/');
}


// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    $error_msg = "Connection failed: " . $conn->connect_error;
    $error_msg .= "<br><br>Debug Info:<br>";
    $error_msg .= "Server: " . DB_SERVER . "<br>";
    $error_msg .= "Database: " . DB_NAME . "<br>";
    $error_msg .= "Environment: " . 
        ($is_kichlooandco ? 'Kichloo and Co' : 
        ($is_adilwaseementerprises ? 'Adil Waseem Enterprises' : 
        ($is_evotec ? 'Evotec' : 'Local'))) . "<br>";
    $error_msg .= "Server Name: " . $server_name . "<br>";
    $error_msg .= "HTTP Host: " . $http_host . "<br>";
    
    die($error_msg);
}


// Constants and settings
define('SITE_NAME', 'BillFlow');
define('CURRENCY', '₹');
define('DATE_FORMAT', 'd-m-Y');

// Helper functions
function formatCurrency($amount) {
    return CURRENCY . number_format($amount, 2);
}

function sanitizeInput($data) {
    global $conn;
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    if ($conn) {
        $data = $conn->real_escape_string($data);
    }
    return $data;
}

// Generate invoice/bill number
function generateNumber($prefix, $table) {
    global $conn;
    
    $sql = "SELECT COUNT(*) as count FROM $table";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    
    $count = $row['count'] + 1;
    $number = $prefix . str_pad($count, 6, '0', STR_PAD_LEFT);
    
    return $number;
}
?> 