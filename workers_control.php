<?php
/**
 * Workers Control Panel
 */

require_once 'auth.php';
require_once 'db.php';

$user = requireAuth();
$pdo = getDbConnection();

$message = '';
$messageType = '';

// Handle worker actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $workerType = $_POST['worker_type'] ?? '';
    $count = (int)($_POST['count'] ?? 1);
    
    // Validate worker type against allowlist
    $allowedWorkerTypes = ['discover', 'extract', 'generate'];
    if ($workerType && !in_array($workerType, $allowedWorkerTypes)) {
        $message = 'Invalid worker type';
        $messageType = 'error';
        $workerType = '';
    }
    
    if ($action === 'start' && $workerType) {
        try {
            for ($i = 0; $i < $count; $i++) {
                $workerId = $workerType . '_' . uniqid();
                $command = "php workers/{$workerType}_worker.php {$workerId} > /dev/null 2>&1 &";
                exec($command);
            }
            
            $message = "Started {$count} {$workerType} worker(s)";
            $messageType = 'success';
            logMessage('info', "Started {$count} {$workerType} workers by " . $user['username']);
        } catch (Exception $e) {
            $message = 'Failed to start workers: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($action === 'stop' && $workerType) {
        try {
            // Mark workers as stopped
            $stmt = $pdo->prepare("UPDATE workers_status SET status = 'stopped' WHERE worker_type = ?");
            $stmt->execute([$workerType]);
            
            // Kill worker processes
            exec("pkill -f {$workerType}_worker.php");
            
            $message = "Stopped all {$workerType} workers";
            $messageType = 'success';
            logMessage('info', "Stopped {$workerType} workers by " . $user['username']);
        } catch (Exception $e) {
            $message = 'Failed to stop workers: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($action === 'stop_all') {
        try {
            $pdo->exec("UPDATE workers_status SET status = 'stopped'");
            exec("pkill -f '_worker.php'");
            
            $message = 'Stopped all workers';
            $messageType = 'success';
            logMessage('info', 'Stopped all workers by ' . $user['username']);
        } catch (Exception $e) {
            $message = 'Failed to stop all workers: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get worker statistics
$workerStats = [];
foreach (['discover', 'extract', 'generate'] as $type) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM workers_status WHERE worker_type = ? AND status = 'active'");
    $stmt->execute([$type]);
    $workerStats[$type] = $stmt->fetch()['count'];
}

// Get all workers
$stmt = $pdo->query("SELECT * FROM workers_status ORDER BY worker_type, worker_id");
$workers = $stmt->fetchAll();

// Get queue statistics
$queueStats = [];
foreach (['discover', 'extract', 'generate'] as $type) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM queue WHERE task_type = ? AND status = 'pending'");
    $stmt->execute([$type]);
    $queueStats[$type] = $stmt->fetch()['count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workers Control - Ultra Email Intelligence Platform</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; background: #f5f7fa; }
        
        .navbar { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0 30px; height: 65px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .navbar-brand { font-size: 20px; font-weight: 600; }
        .navbar-menu { display: flex; gap: 25px; align-items: center; }
        .navbar-menu a { color: white; text-decoration: none; font-size: 14px; transition: opacity 0.3s; }
        .navbar-menu a:hover { opacity: 0.8; }
        .navbar-user { display: flex; align-items: center; gap: 15px; }
        .user-badge { background: rgba(255,255,255,0.2); padding: 8px 15px; border-radius: 20px; font-size: 14px; }
        
        .container { max-width: 1200px; margin: 0 auto; padding: 30px; }
        
        .section { background: white; border-radius: 12px; padding: 30px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .section-title { font-size: 20px; font-weight: 600; color: #2d3748; margin-bottom: 20px; }
        
        .alert { padding: 15px 20px; border-radius: 6px; margin-bottom: 25px; font-size: 14px; }
        .alert-success { background: #c6f6d5; border-left: 4px solid #38a169; color: #22543d; }
        .alert-error { background: #fed7d7; border-left: 4px solid #e53e3e; color: #742a2a; }
        
        .worker-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .worker-card { border: 2px solid #e2e8f0; border-radius: 12px; padding: 25px; }
        .worker-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .worker-title { font-size: 18px; font-weight: 600; color: #2d3748; display: flex; align-items: center; gap: 10px; }
        .worker-count { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 5px 12px; border-radius: 20px; font-size: 14px; font-weight: 600; }
        .worker-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; }
        .stat-item { background: #f7fafc; padding: 12px; border-radius: 6px; }
        .stat-label { font-size: 12px; color: #718096; margin-bottom: 4px; }
        .stat-value { font-size: 20px; font-weight: 700; color: #2d3748; }
        
        .worker-controls { display: flex; gap: 10px; }
        .worker-controls input { flex: 0 0 80px; padding: 8px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; }
        
        .btn { display: inline-block; padding: 10px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; text-decoration: none; transition: transform 0.2s; }
        .btn:hover { transform: translateY(-2px); }
        .btn-danger { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .btn-sm { padding: 8px 16px; font-size: 13px; }
        
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px; background: #f7fafc; color: #4a5568; font-weight: 600; font-size: 13px; border-bottom: 2px solid #e2e8f0; }
        .table td { padding: 12px; border-bottom: 1px solid #e2e8f0; font-size: 14px; color: #2d3748; }
        .table tr:hover { background: #f7fafc; }
        
        .badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .badge-success { background: #c6f6d5; color: #22543d; }
        .badge-danger { background: #fed7d7; color: #742a2a; }
        .badge-warning { background: #feebc8; color: #7c2d12; }
        
        .empty-state { text-align: center; padding: 40px 20px; color: #718096; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-brand">🚀 Ultra Email Intelligence</div>
        <div class="navbar-menu">
            <a href="index.php">Dashboard</a>
            <a href="new_job.php">New Job</a>
            <a href="results.php">Results</a>
            <a href="workers_control.php">Workers</a>
            <a href="settings.php">Settings</a>
        </div>
        <div class="navbar-user">
            <div class="user-badge">👤 <?php echo htmlspecialchars($user['username']); ?></div>
            <a href="logout.php" style="color: white; text-decoration: none;">Logout</a>
        </div>
    </nav>
    
    <div class="container">
        <h1 style="font-size: 28px; color: #2d3748; margin-bottom: 30px;">⚙️ Workers Control</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="worker-grid">
            <!-- Discover Workers -->
            <div class="worker-card">
                <div class="worker-card-header">
                    <div class="worker-title">
                        🔍 Discover
                    </div>
                    <div class="worker-count"><?php echo $workerStats['discover']; ?> Active</div>
                </div>
                
                <div class="worker-stats">
                    <div class="stat-item">
                        <div class="stat-label">Pending Tasks</div>
                        <div class="stat-value"><?php echo $queueStats['discover']; ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Status</div>
                        <div class="stat-value" style="font-size: 16px;">
                            <?php echo $workerStats['discover'] > 0 ? '✓ Running' : '○ Idle'; ?>
                        </div>
                    </div>
                </div>
                
                <form method="post" style="margin-bottom: 10px;">
                    <input type="hidden" name="worker_type" value="discover">
                    <input type="hidden" name="action" value="start">
                    <div class="worker-controls">
                        <input type="number" name="count" value="1" min="1" max="10">
                        <button type="submit" class="btn btn-sm">▶ Start</button>
                    </div>
                </form>
                
                <form method="post">
                    <input type="hidden" name="worker_type" value="discover">
                    <input type="hidden" name="action" value="stop">
                    <button type="submit" class="btn btn-danger btn-sm" style="width: 100%;">⏹ Stop All</button>
                </form>
            </div>
            
            <!-- Extract Workers -->
            <div class="worker-card">
                <div class="worker-card-header">
                    <div class="worker-title">
                        📥 Extract
                    </div>
                    <div class="worker-count"><?php echo $workerStats['extract']; ?> Active</div>
                </div>
                
                <div class="worker-stats">
                    <div class="stat-item">
                        <div class="stat-label">Pending Tasks</div>
                        <div class="stat-value"><?php echo $queueStats['extract']; ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Status</div>
                        <div class="stat-value" style="font-size: 16px;">
                            <?php echo $workerStats['extract'] > 0 ? '✓ Running' : '○ Idle'; ?>
                        </div>
                    </div>
                </div>
                
                <form method="post" style="margin-bottom: 10px;">
                    <input type="hidden" name="worker_type" value="extract">
                    <input type="hidden" name="action" value="start">
                    <div class="worker-controls">
                        <input type="number" name="count" value="1" min="1" max="10">
                        <button type="submit" class="btn btn-sm">▶ Start</button>
                    </div>
                </form>
                
                <form method="post">
                    <input type="hidden" name="worker_type" value="extract">
                    <input type="hidden" name="action" value="stop">
                    <button type="submit" class="btn btn-danger btn-sm" style="width: 100%;">⏹ Stop All</button>
                </form>
            </div>
            
            <!-- Generate Workers -->
            <div class="worker-card">
                <div class="worker-card-header">
                    <div class="worker-title">
                        ⚡ Generate
                    </div>
                    <div class="worker-count"><?php echo $workerStats['generate']; ?> Active</div>
                </div>
                
                <div class="worker-stats">
                    <div class="stat-item">
                        <div class="stat-label">Pending Tasks</div>
                        <div class="stat-value"><?php echo $queueStats['generate']; ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Status</div>
                        <div class="stat-value" style="font-size: 16px;">
                            <?php echo $workerStats['generate'] > 0 ? '✓ Running' : '○ Idle'; ?>
                        </div>
                    </div>
                </div>
                
                <form method="post" style="margin-bottom: 10px;">
                    <input type="hidden" name="worker_type" value="generate">
                    <input type="hidden" name="action" value="start">
                    <div class="worker-controls">
                        <input type="number" name="count" value="1" min="1" max="10">
                        <button type="submit" class="btn btn-sm">▶ Start</button>
                    </div>
                </form>
                
                <form method="post">
                    <input type="hidden" name="worker_type" value="generate">
                    <input type="hidden" name="action" value="stop">
                    <button type="submit" class="btn btn-danger btn-sm" style="width: 100%;">⏹ Stop All</button>
                </form>
            </div>
        </div>
        
        <div class="section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 class="section-title" style="margin: 0;">Active Workers</h2>
                <form method="post" style="margin: 0;">
                    <input type="hidden" name="action" value="stop_all">
                    <button type="submit" class="btn btn-danger btn-sm">⏹ Stop All Workers</button>
                </form>
            </div>
            
            <?php if (empty($workers)): ?>
                <div class="empty-state">
                    <p>No workers currently active</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Worker ID</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Current Task</th>
                            <th>Started</th>
                            <th>Last Heartbeat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($workers as $worker): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($worker['worker_id']); ?></code></td>
                                <td><?php echo ucfirst($worker['worker_type']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $worker['status'] === 'active' ? 'success' : ($worker['status'] === 'stopped' ? 'danger' : 'warning'); ?>">
                                        <?php echo $worker['status']; ?>
                                    </span>
                                </td>
                                <td><?php echo $worker['current_task'] ? '#' . $worker['current_task'] : '-'; ?></td>
                                <td><?php echo $worker['started_at'] ? date('H:i:s', strtotime($worker['started_at'])) : '-'; ?></td>
                                <td><?php echo $worker['last_heartbeat'] ? date('H:i:s', strtotime($worker['last_heartbeat'])) : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Auto-refresh every 3 seconds using AJAX
        setInterval(function() {
            fetch('api.php?action=workers')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update worker status without page reload
                        // For MVP, using simple reload
                        location.reload();
                    }
                })
                .catch(error => console.error('Error fetching workers:', error));
        }, 3000);
    </script>
</body>
</html>
