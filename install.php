<?php
/**
 * Installation Wizard for Email Extractor
 * 
 * This file handles the initial setup:
 * - Database connection configuration
 * - Admin account creation
 * - Database tables creation
 */

session_start();

// If already installed, redirect to main page
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    if (defined('INSTALLED') && INSTALLED) {
        header('Location: app.php');
        exit;
    }
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';
$success = '';

// Handle installation steps
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['step1_submit'])) {
        // Step 1: Database connection
        $db_host = trim($_POST['db_host'] ?? 'localhost');
        $db_name = trim($_POST['db_name'] ?? '');
        $db_user = trim($_POST['db_user'] ?? '');
        $db_pass = $_POST['db_pass'] ?? '';
        
        if (empty($db_name) || empty($db_user)) {
            $error = 'Database name and username are required.';
        } else {
            // Test database connection
            try {
                $pdo = new PDO(
                    "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
                    $db_user,
                    $db_pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                
                // Store connection info in session for next step
                $_SESSION['install_db_host'] = $db_host;
                $_SESSION['install_db_name'] = $db_name;
                $_SESSION['install_db_user'] = $db_user;
                $_SESSION['install_db_pass'] = $db_pass;
                
                header('Location: install.php?step=2');
                exit;
            } catch (PDOException $e) {
                $error = 'Database connection failed: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['step2_submit'])) {
        // Step 2: Admin account
        $admin_username = trim($_POST['admin_username'] ?? '');
        $admin_password = $_POST['admin_password'] ?? '';
        $admin_password_confirm = $_POST['admin_password_confirm'] ?? '';
        
        if (empty($admin_username) || empty($admin_password)) {
            $error = 'Admin username and password are required.';
        } elseif ($admin_password !== $admin_password_confirm) {
            $error = 'Passwords do not match.';
        } elseif (strlen($admin_password) < 6) {
            $error = 'Password must be at least 6 characters long.';
        } else {
            // Store admin info in session for final step
            $_SESSION['install_admin_username'] = $admin_username;
            $_SESSION['install_admin_password_hash'] = password_hash($admin_password, PASSWORD_DEFAULT);
            
            header('Location: install.php?step=3');
            exit;
        }
    } elseif (isset($_POST['step3_submit'])) {
        // Step 3: Create tables and config file
        if (!isset($_SESSION['install_db_host']) || !isset($_SESSION['install_admin_username'])) {
            $error = 'Installation session expired. Please start over.';
        } else {
            try {
                $db_host = $_SESSION['install_db_host'];
                $db_name = $_SESSION['install_db_name'];
                $db_user = $_SESSION['install_db_user'];
                $db_pass = $_SESSION['install_db_pass'];
                $admin_username = $_SESSION['install_admin_username'];
                $admin_password_hash = $_SESSION['install_admin_password_hash'];
                
                // Connect to database
                $pdo = new PDO(
                    "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
                    $db_user,
                    $db_pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                
                // Create all required tables
                
                // 1. Job Profiles table
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS job_profiles (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        profile_name VARCHAR(255) NOT NULL,
                        api_key TEXT NOT NULL,
                        search_query TEXT NOT NULL,
                        target_count INT DEFAULT 100,
                        filter_business_only TINYINT(1) DEFAULT 1,
                        country VARCHAR(100) DEFAULT '',
                        workers INT NOT NULL DEFAULT 4,
                        emails_per_worker INT NOT NULL DEFAULT 100,
                        cycle_delay_ms INT NOT NULL DEFAULT 0,
                        active TINYINT(1) DEFAULT 1,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                
                // 2. Jobs table
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS jobs (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(255) NOT NULL,
                        status VARCHAR(50) DEFAULT 'draft',
                        profile_id INT DEFAULT NULL,
                        target_count INT DEFAULT 100,
                        progress_extracted INT NOT NULL DEFAULT 0,
                        progress_total INT NOT NULL DEFAULT 0,
                        progress_status VARCHAR(50) DEFAULT 'draft',
                        error_message TEXT DEFAULT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        started_at TIMESTAMP NULL DEFAULT NULL,
                        completed_at TIMESTAMP NULL DEFAULT NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                
                // 3. Extracted emails table
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS extracted_emails (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        job_id INT NOT NULL,
                        email VARCHAR(255) NOT NULL,
                        source VARCHAR(500) DEFAULT NULL,
                        extracted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX(job_id),
                        INDEX(email)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                
                // 4. Settings table
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS settings (
                        setting_key VARCHAR(100) PRIMARY KEY,
                        setting_value TEXT,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                
                // 5. Rotation settings table
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS rotation_settings (
                        id INT PRIMARY KEY,
                        rotation_enabled TINYINT(1) DEFAULT 0,
                        workers INT NOT NULL DEFAULT 4,
                        emails_per_worker INT NOT NULL DEFAULT 100,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                
                // Insert default rotation settings
                $pdo->exec("INSERT IGNORE INTO rotation_settings (id, rotation_enabled, workers, emails_per_worker) VALUES (1, 0, 4, 100)");
                
                // Create config.php file
                $config_content = "<?php\n";
                $config_content .= "/**\n";
                $config_content .= " * Email Extractor Configuration\n";
                $config_content .= " * Generated by installation wizard\n";
                $config_content .= " */\n\n";
                $config_content .= "// Database Configuration\n";
                $config_content .= "define('DB_HOST', " . var_export($db_host, true) . ");\n";
                $config_content .= "define('DB_NAME', " . var_export($db_name, true) . ");\n";
                $config_content .= "define('DB_USER', " . var_export($db_user, true) . ");\n";
                $config_content .= "define('DB_PASS', " . var_export($db_pass, true) . ");\n\n";
                $config_content .= "// Admin Account\n";
                $config_content .= "define('ADMIN_USERNAME', " . var_export($admin_username, true) . ");\n";
                $config_content .= "define('ADMIN_PASSWORD_HASH', " . var_export($admin_password_hash, true) . ");\n\n";
                $config_content .= "// Installation Status\n";
                $config_content .= "define('INSTALLED', true);\n";
                
                if (file_put_contents(__DIR__ . '/config.php', $config_content) === false) {
                    throw new Exception('Failed to create config.php file. Please check directory permissions.');
                }
                
                // Clear installation session
                unset($_SESSION['install_db_host']);
                unset($_SESSION['install_db_name']);
                unset($_SESSION['install_db_user']);
                unset($_SESSION['install_db_pass']);
                unset($_SESSION['install_admin_username']);
                unset($_SESSION['install_admin_password_hash']);
                
                // Success! Redirect to completion page
                header('Location: install.php?step=4');
                exit;
                
            } catch (Exception $e) {
                $error = 'Installation failed: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Extractor - Installation</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .install-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 600px;
            width: 100%;
            padding: 40px;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo h1 {
            font-size: 32px;
            color: #667eea;
            margin-bottom: 10px;
        }
        
        .logo p {
            color: #718096;
            font-size: 16px;
        }
        
        .steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
            position: relative;
        }
        
        .steps::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: #e2e8f0;
            z-index: 0;
        }
        
        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 1;
            flex: 1;
        }
        
        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #718096;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .step.active .step-number {
            background: #667eea;
            color: white;
        }
        
        .step.completed .step-number {
            background: #48bb78;
            color: white;
        }
        
        .step-label {
            font-size: 12px;
            color: #718096;
            text-align: center;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #2d3748;
            font-weight: 600;
            font-size: 14px;
        }
        
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .hint {
            font-size: 12px;
            color: #718096;
            margin-top: 4px;
        }
        
        .error {
            background: #fed7d7;
            color: #c53030;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .success {
            background: #c6f6d5;
            color: #2f855a;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .btn {
            background: #667eea;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: background 0.2s;
        }
        
        .btn:hover {
            background: #5a67d8;
        }
        
        .btn-secondary {
            background: #e2e8f0;
            color: #2d3748;
            margin-right: 10px;
        }
        
        .btn-secondary:hover {
            background: #cbd5e0;
        }
        
        .button-group {
            display: flex;
            gap: 10px;
        }
        
        .button-group .btn {
            width: auto;
            flex: 1;
        }
        
        .info-box {
            background: #ebf8ff;
            border-left: 4px solid #4299e1;
            padding: 16px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .info-box h3 {
            color: #2c5282;
            margin-bottom: 8px;
            font-size: 16px;
        }
        
        .info-box p {
            color: #2c5282;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .info-box ul {
            margin-top: 10px;
            margin-left: 20px;
        }
        
        .info-box li {
            color: #2c5282;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .success-icon {
            text-align: center;
            font-size: 64px;
            color: #48bb78;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="install-container">
        <div class="logo">
            <h1>📧 Email Extractor</h1>
            <p>Installation Wizard</p>
        </div>
        
        <div class="steps">
            <div class="step <?php echo $step >= 1 ? 'active' : ''; ?> <?php echo $step > 1 ? 'completed' : ''; ?>">
                <div class="step-number">1</div>
                <div class="step-label">Database</div>
            </div>
            <div class="step <?php echo $step >= 2 ? 'active' : ''; ?> <?php echo $step > 2 ? 'completed' : ''; ?>">
                <div class="step-number">2</div>
                <div class="step-label">Admin Account</div>
            </div>
            <div class="step <?php echo $step >= 3 ? 'active' : ''; ?> <?php echo $step > 3 ? 'completed' : ''; ?>">
                <div class="step-number">3</div>
                <div class="step-label">Install</div>
            </div>
            <div class="step <?php echo $step >= 4 ? 'active' : ''; ?>">
                <div class="step-number">✓</div>
                <div class="step-label">Complete</div>
            </div>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <?php if ($step === 1): ?>
            <div class="info-box">
                <h3>Step 1: Database Configuration</h3>
                <p>Enter your MySQL database connection details. Make sure the database already exists.</p>
            </div>
            
            <form method="POST">
                <div class="form-group">
                    <label>Database Host</label>
                    <input type="text" name="db_host" value="localhost" required>
                    <div class="hint">Usually "localhost" or "127.0.0.1"</div>
                </div>
                
                <div class="form-group">
                    <label>Database Name</label>
                    <input type="text" name="db_name" required>
                    <div class="hint">The name of your MySQL database</div>
                </div>
                
                <div class="form-group">
                    <label>Database Username</label>
                    <input type="text" name="db_user" required>
                    <div class="hint">MySQL username with access to the database</div>
                </div>
                
                <div class="form-group">
                    <label>Database Password</label>
                    <input type="password" name="db_pass">
                    <div class="hint">MySQL password (leave empty if no password)</div>
                </div>
                
                <button type="submit" name="step1_submit" class="btn">Test Connection & Continue →</button>
            </form>
        
        <?php elseif ($step === 2): ?>
            <div class="info-box">
                <h3>Step 2: Admin Account</h3>
                <p>Create your administrator account. This will be used to access the Email Extractor dashboard.</p>
            </div>
            
            <form method="POST">
                <div class="form-group">
                    <label>Admin Username</label>
                    <input type="text" name="admin_username" required>
                    <div class="hint">Choose a username for your admin account</div>
                </div>
                
                <div class="form-group">
                    <label>Admin Password</label>
                    <input type="password" name="admin_password" required>
                    <div class="hint">Minimum 6 characters</div>
                </div>
                
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="admin_password_confirm" required>
                    <div class="hint">Re-enter your password</div>
                </div>
                
                <div class="button-group">
                    <a href="install.php?step=1" class="btn btn-secondary">← Back</a>
                    <button type="submit" name="step2_submit" class="btn">Continue →</button>
                </div>
            </form>
        
        <?php elseif ($step === 3): ?>
            <div class="info-box">
                <h3>Step 3: Final Installation</h3>
                <p>Click the button below to complete the installation. This will:</p>
                <ul>
                    <li>Create all required database tables</li>
                    <li>Save your configuration</li>
                    <li>Set up your admin account</li>
                </ul>
            </div>
            
            <form method="POST">
                <div class="button-group">
                    <a href="install.php?step=2" class="btn btn-secondary">← Back</a>
                    <button type="submit" name="step3_submit" class="btn">Install Now 🚀</button>
                </div>
            </form>
        
        <?php elseif ($step === 4): ?>
            <div class="success-icon">✓</div>
            
            <div class="info-box">
                <h3>Installation Complete!</h3>
                <p>Your Email Extractor has been successfully installed. You can now log in with your admin credentials.</p>
            </div>
            
            <a href="app.php" class="btn">Go to Login Page →</a>
        <?php endif; ?>
    </div>
</body>
</html>
