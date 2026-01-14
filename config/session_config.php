<?php
// Set session cookie parameters for 30 days
$lifetime = 60 * 60 * 24 * 30; // 30 days in seconds

// Only apply these settings if session hasn't started yet
if (session_status() == PHP_SESSION_NONE) {
    // Set session cookie parameters
    session_set_cookie_params($lifetime);
    
    // Set session garbage collection lifetime
    ini_set('session.gc_maxlifetime', $lifetime);
    
    // Start the session
    session_start();
}

// Regenerate session ID periodically to prevent session fixation
if (!isset($_SESSION['last_regeneration']) || (time() - $_SESSION['last_regeneration']) > 3600) {
    // Regenerate session ID every hour for security
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}