<?php
/**
 * Authentication Helper
 */

function requireAuth() {
    session_start();
    
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Location: login.php');
        exit;
    }
    
    return $_SESSION;
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function getUsername() {
    return $_SESSION['username'] ?? 'Guest';
}
