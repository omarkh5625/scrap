<?php
/**
 * Installation Script for Email Extraction System
 * 
 * This script sets up the MySQL database for the email extraction system.
 * It should be run only once during initial setup.
 * 
 * Features:
 * - Creates necessary database tables
 * - Stores database configuration
 * - One-time execution protection
 * - Connection validation
 */

session_start();

// Configuration file path
define('CONFIG_FILE', __DIR__ . '/config.php');
define('INSTALL_LOCK_FILE', __DIR__ . '/.installed');

// Check if already installed
if (file_exists(INSTALL_LOCK_FILE)) {
    die('Installation already completed. Delete .installed file to reinstall (this will not delete existing data).');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = $_POST['db_host'] ?? '';
    $dbUser = $_POST['db_user'] ?? '';
    $dbPass = $_POST['db_pass'] ?? '';
    $dbName = $_POST['db_name'] ?? '';
    $dbPort = $_POST['db_port'] ?? '3306';
    
    $errors = [];
    
    // Validate inputs
    if (empty($dbHost)) $errors[] = 'Database host is required';
    if (empty($dbUser)) $errors[] = 'Database username is required';
    if (empty($dbName)) $errors[] = 'Database name is required';
    
    // Validate database name - only alphanumeric and underscore allowed
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
        $errors[] = 'Database name can only contain letters, numbers, and underscores';
    }
    
    // Validate port is numeric
    if (!is_numeric($dbPort) || $dbPort < 1 || $dbPort > 65535) {
        $errors[] = 'Database port must be a valid port number (1-65535)';
    }
    
    if (empty($errors)) {
        try {
            // Test connection
            $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            
            // Create database if it doesn't exist - backticks for safety
            $quotedDbName = "`" . str_replace("`", "``", $dbName) . "`";
            $pdo->exec("CREATE DATABASE IF NOT EXISTS {$quotedDbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE {$quotedDbName}");
            
            // Create emails table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `emails` (
                    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `job_id` VARCHAR(100) NOT NULL,
                    `email` VARCHAR(255) NOT NULL,
                    `quality` ENUM('high', 'medium', 'low') DEFAULT 'medium',
                    `confidence` DECIMAL(3, 2) DEFAULT 0.50,
                    `source_url` TEXT,
                    `worker_id` VARCHAR(100),
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `unique_job_email` (`job_id`, `email`),
                    INDEX `idx_job_id` (`job_id`),
                    INDEX `idx_email` (`email`),
                    INDEX `idx_created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Create jobs table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `jobs` (
                    `id` VARCHAR(100) PRIMARY KEY,
                    `name` VARCHAR(255) NOT NULL,
                    `query` TEXT NOT NULL,
                    `options` JSON,
                    `status` ENUM('created', 'running', 'stopped', 'error', 'completed') DEFAULT 'created',
                    `emails_found` INT UNSIGNED DEFAULT 0,
                    `emails_accepted` INT UNSIGNED DEFAULT 0,
                    `emails_rejected` INT UNSIGNED DEFAULT 0,
                    `urls_processed` INT UNSIGNED DEFAULT 0,
                    `errors` INT UNSIGNED DEFAULT 0,
                    `worker_count` INT UNSIGNED DEFAULT 0,
                    `workers_running` INT UNSIGNED DEFAULT 0,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `started_at` TIMESTAMP NULL,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_status` (`status`),
                    INDEX `idx_created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Create job_errors table for error tracking
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `job_errors` (
                    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `job_id` VARCHAR(100) NOT NULL,
                    `error_message` TEXT NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_job_id` (`job_id`),
                    INDEX `idx_created_at` (`created_at`),
                    FOREIGN KEY (`job_id`) REFERENCES `jobs`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Generate secure configuration file
            // var_export() is actually the safest method as it produces valid PHP literals
            $configContent = "<?php\n";
            $configContent .= "/**\n";
            $configContent .= " * Database Configuration\n";
            $configContent .= " * Auto-generated by install.php\n";
            $configContent .= " * DO NOT COMMIT THIS FILE TO VERSION CONTROL\n";
            $configContent .= " */\n\n";
            $configContent .= "define('DB_HOST', " . var_export($dbHost, true) . ");\n";
            $configContent .= "define('DB_PORT', " . var_export($dbPort, true) . ");\n";
            $configContent .= "define('DB_USER', " . var_export($dbUser, true) . ");\n";
            $configContent .= "define('DB_PASS', " . var_export($dbPass, true) . ");\n";
            $configContent .= "define('DB_NAME', " . var_export($dbName, true) . ");\n";
            
            // Write configuration file
            if (!file_put_contents(CONFIG_FILE, $configContent)) {
                throw new Exception('Failed to write configuration file');
            }
            
            // Create lock file to prevent reinstallation
            file_put_contents(INSTALL_LOCK_FILE, date('Y-m-d H:i:s'));
            
            // Add config.php to .gitignore if not already present
            $gitignoreFile = __DIR__ . '/.gitignore';
            $gitignoreContent = file_exists($gitignoreFile) ? file_get_contents($gitignoreFile) : '';
            if (strpos($gitignoreContent, 'config.php') === false) {
                file_put_contents($gitignoreFile, "\nconfig.php\n.installed\n", FILE_APPEND);
            }
            
            $success = true;
            $successMessage = 'Installation completed successfully! Database tables created and configuration saved.';
            
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        } catch (Exception $e) {
            $errors[] = 'Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Extraction System - Installation</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 600px;
            width: 100%;
            padding: 40px;
        }
        
        h1 {
            font-size: 28px;
            color: #1a1a1a;
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: #666;
            font-size: 14px;
            margin-bottom: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }
        
        input[type="text"],
        input[type="password"],
        input[type="number"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e1e4e8;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        
        input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .help-text {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .alert-error {
            background: #fee;
            border: 1px solid #fcc;
            color: #c33;
        }
        
        .alert-success {
            background: #efe;
            border: 1px solid #cfc;
            color: #3c3;
        }
        
        .alert ul {
            margin: 10px 0 0 20px;
        }
        
        .success-container {
            text-align: center;
        }
        
        .success-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        
        .success-title {
            font-size: 24px;
            color: #28a745;
            margin-bottom: 15px;
        }
        
        .success-message {
            color: #666;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        
        .btn-secondary {
            background: #6c757d;
            margin-top: 10px;
        }
        
        .info-box {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .info-box h3 {
            font-size: 14px;
            margin-bottom: 8px;
            color: #667eea;
        }
        
        .info-box p {
            font-size: 13px;
            color: #666;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (isset($success) && $success): ?>
            <div class="success-container">
                <div class="success-icon">✅</div>
                <h2 class="success-title">Installation Successful!</h2>
                <p class="success-message"><?php echo htmlspecialchars($successMessage); ?></p>
                <a href="app.php" class="btn">Go to Application</a>
                <p style="margin-top: 20px; font-size: 12px; color: #999;">
                    You can now delete install.php for security reasons.
                </p>
            </div>
        <?php else: ?>
            <h1>📧 Email Extraction System</h1>
            <p class="subtitle">Database Installation & Configuration</p>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <strong>Installation Failed:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="info-box">
                <h3>Before You Begin</h3>
                <p>
                    This installation will create the necessary MySQL database tables for storing emails and jobs.
                    Make sure you have MySQL installed and have access to create databases.
                </p>
            </div>
            
            <form method="POST">
                <div class="form-group">
                    <label for="db_host">Database Host *</label>
                    <input type="text" id="db_host" name="db_host" value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>" required>
                    <p class="help-text">Usually 'localhost' or '127.0.0.1'</p>
                </div>
                
                <div class="form-group">
                    <label for="db_port">Database Port</label>
                    <input type="number" id="db_port" name="db_port" value="<?php echo htmlspecialchars($_POST['db_port'] ?? '3306'); ?>" required>
                    <p class="help-text">Default MySQL port is 3306</p>
                </div>
                
                <div class="form-group">
                    <label for="db_name">Database Name *</label>
                    <input type="text" id="db_name" name="db_name" value="<?php echo htmlspecialchars($_POST['db_name'] ?? 'email_extraction'); ?>" required>
                    <p class="help-text">Will be created if it doesn't exist</p>
                </div>
                
                <div class="form-group">
                    <label for="db_user">Database Username *</label>
                    <input type="text" id="db_user" name="db_user" value="<?php echo htmlspecialchars($_POST['db_user'] ?? ''); ?>" required>
                    <p class="help-text">MySQL user with CREATE and INSERT privileges</p>
                </div>
                
                <div class="form-group">
                    <label for="db_pass">Database Password</label>
                    <input type="password" id="db_pass" name="db_pass">
                    <p class="help-text">Leave empty if no password is set</p>
                </div>
                
                <button type="submit" class="btn">Install Database</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
