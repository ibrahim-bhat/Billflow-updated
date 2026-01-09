<?php
// Error handler to display all errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set custom error handler
function custom_error_handler($errno, $errstr, $errfile, $errline) {
    $error_message = date('Y-m-d H:i:s') . " - Error: [$errno] $errstr in $errfile on line $errline";
    
    // Log error to file
    error_log($error_message . PHP_EOL, 3, 'error_log.txt');
    
    // Display error for the user
    echo "<div style='border: 2px solid red; padding: 10px; margin: 10px; background-color: #ffeeee;'>";
    echo "<h3 style='color: red;'>Error Detected:</h3>";
    echo "<p><strong>Error Type:</strong> $errno</p>";
    echo "<p><strong>Message:</strong> $errstr</p>";
    echo "<p><strong>File:</strong> $errfile</p>";
    echo "<p><strong>Line:</strong> $errline</p>";
    echo "</div>";
    
    // Don't execute PHP's internal error handler
    return true;
}

// Set the custom error handler
set_error_handler("custom_error_handler");

// Handle fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $error_message = date('Y-m-d H:i:s') . " - FATAL ERROR: [{$error['type']}] {$error['message']} in {$error['file']} on line {$error['line']}";
        
        // Log fatal error
        error_log($error_message . PHP_EOL, 3, 'error_log.txt');
        
        // Display fatal error
        echo "<div style='border: 2px solid darkred; padding: 10px; margin: 10px; background-color: #ffeeee;'>";
        echo "<h3 style='color: darkred;'>Fatal Error Detected:</h3>";
        echo "<p><strong>Message:</strong> {$error['message']}</p>";
        echo "<p><strong>File:</strong> {$error['file']}</p>";
        echo "<p><strong>Line:</strong> {$error['line']}</p>";
        echo "</div>";
    }
});

// Also capture and display exceptions
function exception_handler($exception) {
    $error_message = date('Y-m-d H:i:s') . " - Exception: " . $exception->getMessage() . 
        " in " . $exception->getFile() . " on line " . $exception->getLine();
    
    // Log exception
    error_log($error_message . PHP_EOL, 3, 'error_log.txt');
    
    // Display exception
    echo "<div style='border: 2px solid purple; padding: 10px; margin: 10px; background-color: #f8e6ff;'>";
    echo "<h3 style='color: purple;'>Exception Caught:</h3>";
    echo "<p><strong>Message:</strong> " . $exception->getMessage() . "</p>";
    echo "<p><strong>File:</strong> " . $exception->getFile() . "</p>";
    echo "<p><strong>Line:</strong> " . $exception->getLine() . "</p>";
    echo "<p><strong>Stack Trace:</strong><pre>" . $exception->getTraceAsString() . "</pre></p>";
    echo "</div>";
}

set_exception_handler("exception_handler");

// Debug function to dump variables
function debug_var($var, $var_name = 'Variable') {
    echo "<div style='border: 1px solid blue; padding: 10px; margin: 10px; background-color: #e6f0ff;'>";
    echo "<h3 style='color: blue;'>Debug: $var_name</h3>";
    echo "<pre>";
    var_dump($var);
    echo "</pre>";
    echo "</div>";
}
?> 