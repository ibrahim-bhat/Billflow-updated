<?php
/**
 * Authentication Middleware
 * Checks if user is logged in and redirects to login if not
 */

// Include session configuration
require_once __DIR__ . '/../../config/session_config.php';

/**
 * Check if user is authenticated
 * @param string $redirect_path Path to redirect if not authenticated (relative to root)
 */
function checkAuth($redirect_path = '../../index.php') {
    if (!isset($_SESSION['user_id'])) {
        header("Location: $redirect_path");
        exit();
    }
}

/**
 * Check if user is authenticated and return boolean
 * @return bool True if authenticated, false otherwise
 */
function isAuthenticated() {
    return isset($_SESSION['user_id']);
}

/**
 * Get current user ID
 * @return int|null User ID if authenticated, null otherwise
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Logout user
 */
function logout() {
    session_destroy();
    header("Location: index.php");
    exit();
}
?>
