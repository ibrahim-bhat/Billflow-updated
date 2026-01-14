<?php
/**
 * Form Token Helper - Prevents CSRF and Form Resubmission
 * Implements token-based form validation to prevent duplicate submissions
 */

/**
 * Generate a unique form token and store it in session
 * @return string The generated token
 */
function generateFormToken() {
    // Generate a cryptographically secure random token
    $token = bin2hex(random_bytes(32));
    
    // Initialize token array if not exists
    if (!isset($_SESSION['form_tokens'])) {
        $_SESSION['form_tokens'] = [];
    }
    
    // Store token with timestamp (for cleanup)
    $_SESSION['form_tokens'][$token] = time();
    
    // Clean up old tokens (older than 1 hour)
    cleanupOldTokens();
    
    return $token;
}

/**
 * Validate and consume a form token (one-time use)
 * @param string $token The token to validate
 * @return bool True if valid, false otherwise
 */
function validateFormToken($token) {
    // Check if token exists in session
    if (!isset($_SESSION['form_tokens']) || !isset($_SESSION['form_tokens'][$token])) {
        return false;
    }
    
    // Check if token is not older than 1 hour
    $tokenTime = $_SESSION['form_tokens'][$token];
    if ((time() - $tokenTime) > 3600) {
        unset($_SESSION['form_tokens'][$token]);
        return false;
    }
    
    // Token is valid - remove it (one-time use)
    unset($_SESSION['form_tokens'][$token]);
    return true;
}

/**
 * Clean up tokens older than 1 hour
 */
function cleanupOldTokens() {
    if (!isset($_SESSION['form_tokens'])) {
        return;
    }
    
    $currentTime = time();
    foreach ($_SESSION['form_tokens'] as $token => $timestamp) {
        if (($currentTime - $timestamp) > 3600) {
            unset($_SESSION['form_tokens'][$token]);
        }
    }
}

/**
 * Get HTML input field for form token
 * @return string HTML input field
 */
function getFormTokenField() {
    $token = generateFormToken();
    return '<input type="hidden" name="form_token" value="' . htmlspecialchars($token) . '">';
}

/**
 * Check if current request has valid token
 * Automatically checks POST data for form_token
 * @return bool True if valid
 */
function checkFormToken() {
    if (!isset($_POST['form_token'])) {
        return false;
    }
    
    return validateFormToken($_POST['form_token']);
}
?>
