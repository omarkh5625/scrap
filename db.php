<?php
/**
 * Database Connection and Utilities
 */

function getDbConnection() {
    if (!file_exists('config.php')) {
        header('Location: install.php');
        exit;
    }
    
    require_once 'config.php';
    
    if (!defined('DB_CONFIGURED') || DB_CONFIGURED !== true) {
        header('Location: install.php');
        exit;
    }
    
    try {
        if (DB_TYPE === 'sqlite') {
            $pdo = new PDO('sqlite:' . DB_PATH);
        } else {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
        }
        
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        return $pdo;
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

function getSetting($key, $default = null) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    return $result ? $result['setting_value'] : $default;
}

function setSetting($key, $value) {
    $pdo = getDbConnection();
    
    if (DB_TYPE === 'sqlite') {
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, datetime('now'))");
        return $stmt->execute([$key, $value]);
    } else {
        // MySQL compatible UPSERT
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
        return $stmt->execute([$key, $value, $value]);
    }
}

function logMessage($level, $message, $context = null) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("INSERT INTO logs (log_level, message, context, created_at) VALUES (?, ?, ?, datetime('now'))");
        $stmt->execute([$level, $message, $context ? json_encode($context) : null]);
    } catch (Exception $e) {
        error_log("Failed to log message: " . $e->getMessage());
    }
}
