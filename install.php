<?php
/**
 * Ultra Email Intelligence Platform - Installation Wizard
 * First-time setup for database and admin account
 */

session_start();

// Check if already installed
if (file_exists('config.php')) {
    require_once 'config.php';
    if (defined('DB_CONFIGURED') && DB_CONFIGURED === true) {
        header('Location: index.php');
        exit;
    }
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$errors = [];
$success = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 1) {
        // Database configuration
        $dbType = $_POST['db_type'] ?? 'sqlite';
        
        if ($dbType === 'sqlite') {
            $dbPath = $_POST['db_path'] ?? 'data/database.sqlite';
            
            try {
                // Create directory if it doesn't exist
                $dir = dirname($dbPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                
                // Test SQLite connection
                $pdo = new PDO("sqlite:$dbPath");
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // Store in session
                $_SESSION['install_db_type'] = 'sqlite';
                $_SESSION['install_db_path'] = $dbPath;
                
                header('Location: install.php?step=2');
                exit;
            } catch (PDOException $e) {
                $errors[] = "SQLite connection failed: " . $e->getMessage();
            }
        } else {
            // MySQL configuration
            $host = $_POST['db_host'] ?? 'localhost';
            $port = $_POST['db_port'] ?? '3306';
            $name = $_POST['db_name'] ?? '';
            $user = $_POST['db_user'] ?? '';
            $pass = $_POST['db_pass'] ?? '';
            
            if (empty($name)) {
                $errors[] = "Database name is required";
            } else {
                try {
                    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    
                    $_SESSION['install_db_type'] = 'mysql';
                    $_SESSION['install_db_host'] = $host;
                    $_SESSION['install_db_port'] = $port;
                    $_SESSION['install_db_name'] = $name;
                    $_SESSION['install_db_user'] = $user;
                    $_SESSION['install_db_pass'] = $pass;
                    
                    header('Location: install.php?step=2');
                    exit;
                } catch (PDOException $e) {
                    $errors[] = "MySQL connection failed: " . $e->getMessage();
                }
            }
        }
    } elseif ($step === 2) {
        // Create database tables
        try {
            // Get database connection
            if ($_SESSION['install_db_type'] === 'sqlite') {
                $pdo = new PDO("sqlite:" . $_SESSION['install_db_path']);
            } else {
                $pdo = new PDO(
                    "mysql:host={$_SESSION['install_db_host']};port={$_SESSION['install_db_port']};dbname={$_SESSION['install_db_name']};charset=utf8mb4",
                    $_SESSION['install_db_user'],
                    $_SESSION['install_db_pass']
                );
            }
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create tables
            createTables($pdo, $_SESSION['install_db_type']);
            
            header('Location: install.php?step=3');
            exit;
        } catch (Exception $e) {
            $errors[] = "Failed to create tables: " . $e->getMessage();
        }
    } elseif ($step === 3) {
        // Create admin account
        $username = $_POST['admin_username'] ?? '';
        $password = $_POST['admin_password'] ?? '';
        $confirm = $_POST['admin_confirm'] ?? '';
        
        if (empty($username) || empty($password)) {
            $errors[] = "Username and password are required";
        } elseif ($password !== $confirm) {
            $errors[] = "Passwords do not match";
        } elseif (strlen($password) < 8) {
            $errors[] = "Password must be at least 8 characters long";
        } else {
            try {
                // Get database connection
                if ($_SESSION['install_db_type'] === 'sqlite') {
                    $pdo = new PDO("sqlite:" . $_SESSION['install_db_path']);
                } else {
                    $pdo = new PDO(
                        "mysql:host={$_SESSION['install_db_host']};port={$_SESSION['install_db_port']};dbname={$_SESSION['install_db_name']};charset=utf8mb4",
                        $_SESSION['install_db_user'],
                        $_SESSION['install_db_pass']
                    );
                }
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // Create admin user
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                
                // Use database-specific datetime syntax
                if ($_SESSION['install_db_type'] === 'mysql') {
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, role, created_at) VALUES (?, ?, 'admin', NOW())");
                } else {
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, role, created_at) VALUES (?, ?, 'admin', datetime('now'))");
                }
                $stmt->execute([$username, $hashedPassword]);
                
                // Create config.php
                createConfigFile($_SESSION);
                
                // Clear session
                session_destroy();
                
                header('Location: install.php?step=4');
                exit;
            } catch (Exception $e) {
                $errors[] = "Failed to create admin account: " . $e->getMessage();
            }
        }
    }
}

function createTables($pdo, $dbType) {
    $autoIncrement = $dbType === 'mysql' ? 'AUTO_INCREMENT' : 'AUTOINCREMENT';
    $datetime = $dbType === 'mysql' ? 'DATETIME DEFAULT CURRENT_TIMESTAMP' : "TEXT DEFAULT (datetime('now'))";
    $text = $dbType === 'mysql' ? 'TEXT' : 'TEXT';
    
    $tables = [
        "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY $autoIncrement,
            username VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(50) DEFAULT 'user',
            created_at $datetime
        )",
        
        "CREATE TABLE IF NOT EXISTS jobs (
            id INTEGER PRIMARY KEY $autoIncrement,
            name VARCHAR(255) NOT NULL,
            niche VARCHAR(255),
            country_code VARCHAR(10),
            email_type VARCHAR(50),
            speed_mode VARCHAR(20) DEFAULT 'normal',
            status VARCHAR(50) DEFAULT 'pending',
            created_at $datetime,
            completed_at $datetime,
            total_emails INTEGER DEFAULT 0,
            progress DECIMAL(5,2) DEFAULT 0,
            target_emails INTEGER DEFAULT 0,
            time_limit INTEGER DEFAULT 0,
            deadline $datetime
        )",
        
        "CREATE TABLE IF NOT EXISTS queue (
            id INTEGER PRIMARY KEY $autoIncrement,
            job_id INTEGER NOT NULL,
            task_type VARCHAR(50) NOT NULL,
            task_data $text,
            status VARCHAR(50) DEFAULT 'pending',
            priority INTEGER DEFAULT 0,
            created_at $datetime,
            started_at $datetime,
            completed_at $datetime,
            worker_id VARCHAR(100),
            error_message $text
        )",
        
        "CREATE TABLE IF NOT EXISTS emails (
            id INTEGER PRIMARY KEY $autoIncrement,
            job_id INTEGER NOT NULL,
            email VARCHAR(255) NOT NULL,
            domain VARCHAR(255),
            email_type VARCHAR(50),
            source VARCHAR(255),
            company_name VARCHAR(255),
            created_at $datetime,
            UNIQUE(email, job_id)
        )",
        
        "CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY $autoIncrement,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value $text,
            updated_at $datetime
        )",
        
        "CREATE TABLE IF NOT EXISTS workers_status (
            id INTEGER PRIMARY KEY $autoIncrement,
            worker_id VARCHAR(100) UNIQUE NOT NULL,
            worker_type VARCHAR(50) NOT NULL,
            status VARCHAR(50) DEFAULT 'idle',
            current_task INTEGER,
            last_heartbeat $datetime,
            started_at $datetime
        )",
        
        "CREATE TABLE IF NOT EXISTS logs (
            id INTEGER PRIMARY KEY $autoIncrement,
            log_level VARCHAR(20) DEFAULT 'info',
            message $text,
            context $text,
            created_at $datetime
        )"
    ];
    
    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }
    
    // Create indexes
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_queue_status ON queue(status)",
        "CREATE INDEX IF NOT EXISTS idx_queue_job_id ON queue(job_id)",
        "CREATE INDEX IF NOT EXISTS idx_emails_job_id ON emails(job_id)",
        "CREATE INDEX IF NOT EXISTS idx_emails_email ON emails(email)",
        "CREATE INDEX IF NOT EXISTS idx_workers_status ON workers_status(status)"
    ];
    
    foreach ($indexes as $sql) {
        $pdo->exec($sql);
    }
    
    // Insert default settings
    $defaultSettings = [
        ['serper_api_key', ''], // Updated to Serper.dev
        ['search_engines', 'google'],
        ['language', 'en'],
        ['country', 'us'],
        ['discover_workers', '2'],
        ['extract_workers', '3'],
        ['generate_workers', '2']
    ];
    
    // Use database-specific INSERT syntax
    if ($dbType === 'mysql') {
        $stmt = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
    } else {
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
    }
    
    foreach ($defaultSettings as $setting) {
        $stmt->execute($setting);
    }
}

function createConfigFile($session) {
    $content = "<?php\n";
    $content .= "/**\n * Ultra Email Intelligence Platform - Configuration\n * Auto-generated by installation wizard\n */\n\n";
    $content .= "define('DB_CONFIGURED', true);\n";
    $content .= "define('DB_TYPE', '{$session['install_db_type']}');\n";
    
    if ($session['install_db_type'] === 'sqlite') {
        $content .= "define('DB_PATH', '{$session['install_db_path']}');\n";
    } else {
        $content .= "define('DB_HOST', '{$session['install_db_host']}');\n";
        $content .= "define('DB_PORT', '{$session['install_db_port']}');\n";
        $content .= "define('DB_NAME', '{$session['install_db_name']}');\n";
        $content .= "define('DB_USER', '{$session['install_db_user']}');\n";
        $content .= "define('DB_PASS', '{$session['install_db_pass']}');\n";
    }
    
    $content .= "\n// Session configuration\n";
    $content .= "define('SESSION_LIFETIME', 3600);\n";
    $content .= "\n// Application settings\n";
    $content .= "define('APP_NAME', 'Ultra Email Intelligence Platform');\n";
    $content .= "define('APP_VERSION', '1.0.0');\n";
    
    file_put_contents('config.php', $content);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation Wizard - Ultra Email Intelligence Platform</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .container { background: white; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); max-width: 600px; width: 100%; overflow: hidden; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
        .header h1 { font-size: 24px; margin-bottom: 5px; }
        .header p { opacity: 0.9; font-size: 14px; }
        .content { padding: 40px; }
        .steps { display: flex; justify-content: space-between; margin-bottom: 30px; position: relative; }
        .steps::before { content: ''; position: absolute; top: 20px; left: 0; right: 0; height: 2px; background: #e0e0e0; z-index: 0; }
        .step { flex: 1; text-align: center; position: relative; z-index: 1; }
        .step-circle { width: 40px; height: 40px; border-radius: 50%; background: white; border: 2px solid #e0e0e0; margin: 0 auto 10px; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #999; }
        .step.active .step-circle { background: #667eea; border-color: #667eea; color: white; }
        .step.completed .step-circle { background: #4caf50; border-color: #4caf50; color: white; }
        .step-label { font-size: 12px; color: #666; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #333; }
        input, select { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; transition: border 0.3s; }
        input:focus, select:focus { outline: none; border-color: #667eea; }
        .radio-group { display: flex; gap: 20px; }
        .radio-option { display: flex; align-items: center; }
        .radio-option input { width: auto; margin-right: 8px; }
        .btn { display: inline-block; padding: 12px 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; font-size: 16px; font-weight: 500; cursor: pointer; transition: transform 0.2s; text-decoration: none; }
        .btn:hover { transform: translateY(-2px); }
        .btn-secondary { background: #6c757d; }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .alert-error { background: #fee; border-left: 4px solid #f44336; color: #c62828; }
        .alert-success { background: #e8f5e9; border-left: 4px solid #4caf50; color: #2e7d32; }
        .mysql-fields { display: none; }
        .success-icon { width: 80px; height: 80px; margin: 0 auto 20px; background: #4caf50; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 40px; color: white; }
        .text-center { text-align: center; }
        .info-box { background: #f5f5f5; border-radius: 6px; padding: 15px; margin-bottom: 20px; font-size: 14px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Ultra Email Intelligence Platform</h1>
            <p>Installation Wizard - SerpApi Powered</p>
        </div>
        
        <div class="content">
            <div class="steps">
                <div class="step <?php echo $step >= 1 ? 'active' : ''; ?> <?php echo $step > 1 ? 'completed' : ''; ?>">
                    <div class="step-circle">1</div>
                    <div class="step-label">Database</div>
                </div>
                <div class="step <?php echo $step >= 2 ? 'active' : ''; ?> <?php echo $step > 2 ? 'completed' : ''; ?>">
                    <div class="step-circle">2</div>
                    <div class="step-label">Tables</div>
                </div>
                <div class="step <?php echo $step >= 3 ? 'active' : ''; ?> <?php echo $step > 3 ? 'completed' : ''; ?>">
                    <div class="step-circle">3</div>
                    <div class="step-label">Admin</div>
                </div>
                <div class="step <?php echo $step >= 4 ? 'active' : ''; ?>">
                    <div class="step-circle">✓</div>
                    <div class="step-label">Complete</div>
                </div>
            </div>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($step === 1): ?>
                <h2>Step 1: Database Configuration</h2>
                <div class="info-box">
                    Choose your database type. SQLite is recommended for cPanel hosting and requires no additional setup.
                </div>
                <form method="post">
                    <div class="form-group">
                        <label>Database Type</label>
                        <div class="radio-group">
                            <div class="radio-option">
                                <input type="radio" name="db_type" value="sqlite" id="sqlite" checked onchange="toggleDbFields()">
                                <label for="sqlite" style="margin: 0;">SQLite (Recommended)</label>
                            </div>
                            <div class="radio-option">
                                <input type="radio" name="db_type" value="mysql" id="mysql" onchange="toggleDbFields()">
                                <label for="mysql" style="margin: 0;">MySQL</label>
                            </div>
                        </div>
                    </div>
                    
                    <div id="sqlite-fields">
                        <div class="form-group">
                            <label for="db_path">Database Path</label>
                            <input type="text" name="db_path" id="db_path" value="data/database.sqlite" required>
                        </div>
                    </div>
                    
                    <div id="mysql-fields" class="mysql-fields">
                        <div class="form-group">
                            <label for="db_host">Host</label>
                            <input type="text" name="db_host" id="db_host" value="localhost">
                        </div>
                        <div class="form-group">
                            <label for="db_port">Port</label>
                            <input type="text" name="db_port" id="db_port" value="3306">
                        </div>
                        <div class="form-group">
                            <label for="db_name">Database Name</label>
                            <input type="text" name="db_name" id="db_name">
                        </div>
                        <div class="form-group">
                            <label for="db_user">Username</label>
                            <input type="text" name="db_user" id="db_user">
                        </div>
                        <div class="form-group">
                            <label for="db_pass">Password</label>
                            <input type="password" name="db_pass" id="db_pass">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn">Continue</button>
                </form>
            <?php elseif ($step === 2): ?>
                <h2>Step 2: Create Database Tables</h2>
                <div class="info-box">
                    Click the button below to create all necessary database tables for the platform.
                </div>
                <form method="post">
                    <p style="margin-bottom: 20px;">The following tables will be created:</p>
                    <ul style="margin-left: 20px; margin-bottom: 20px; color: #666;">
                        <li>users - User accounts</li>
                        <li>jobs - Email extraction jobs</li>
                        <li>queue - Task queue management</li>
                        <li>emails - Collected emails</li>
                        <li>settings - Application settings</li>
                        <li>workers_status - Worker monitoring</li>
                        <li>logs - System logs</li>
                    </ul>
                    <button type="submit" class="btn">Create Tables</button>
                </form>
            <?php elseif ($step === 3): ?>
                <h2>Step 3: Create Admin Account</h2>
                <div class="info-box">
                    Create your administrator account to manage the platform.
                </div>
                <form method="post">
                    <div class="form-group">
                        <label for="admin_username">Admin Username</label>
                        <input type="text" name="admin_username" id="admin_username" required>
                    </div>
                    <div class="form-group">
                        <label for="admin_password">Password</label>
                        <input type="password" name="admin_password" id="admin_password" required minlength="8">
                    </div>
                    <div class="form-group">
                        <label for="admin_confirm">Confirm Password</label>
                        <input type="password" name="admin_confirm" id="admin_confirm" required minlength="8">
                    </div>
                    <button type="submit" class="btn">Complete Installation</button>
                </form>
            <?php elseif ($step === 4): ?>
                <div class="text-center">
                    <div class="success-icon">✓</div>
                    <h2 style="margin-bottom: 10px;">Installation Complete!</h2>
                    <p style="color: #666; margin-bottom: 30px;">Your Ultra Email Intelligence Platform is ready to use.</p>
                    <a href="login.php" class="btn">Go to Login</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function toggleDbFields() {
            const sqliteFields = document.getElementById('sqlite-fields');
            const mysqlFields = document.getElementById('mysql-fields');
            const isSqlite = document.getElementById('sqlite').checked;
            
            sqliteFields.style.display = isSqlite ? 'block' : 'none';
            mysqlFields.style.display = isSqlite ? 'none' : 'block';
        }
    </script>
</body>
</html>
