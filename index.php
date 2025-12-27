<?php
/**
 * Main Dashboard
 */

require_once 'auth.php';
require_once 'db.php';

$user = requireAuth();
$pdo = getDbConnection();

// Get statistics
$stats = [
    'total_jobs' => 0,
    'active_jobs' => 0,
    'total_emails' => 0,
    'active_workers' => 0,
    'pending_tasks' => 0,
    'completed_tasks' => 0
];

try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM jobs");
    $stats['total_jobs'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM jobs WHERE status IN ('running', 'pending')");
    $stats['active_jobs'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM emails");
    $stats['total_emails'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM workers_status WHERE status = 'active'");
    $stats['active_workers'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM queue WHERE status = 'pending'");
    $stats['pending_tasks'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM queue WHERE status = 'completed'");
    $stats['completed_tasks'] = $stmt->fetch()['count'];
    
    // Get recent jobs
    $stmt = $pdo->query("SELECT * FROM jobs ORDER BY created_at DESC LIMIT 5");
    $recentJobs = $stmt->fetchAll();
    
    // Get worker status
    $stmt = $pdo->query("SELECT * FROM workers_status ORDER BY worker_type, worker_id");
    $workers = $stmt->fetchAll();
    
} catch (Exception $e) {
    logMessage('error', 'Dashboard error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Ultra Email Intelligence Platform</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; background: #f5f7fa; }
        
        .navbar { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0 30px; height: 65px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .navbar-brand { font-size: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
        .navbar-menu { display: flex; gap: 25px; align-items: center; }
        .navbar-menu a { color: white; text-decoration: none; font-size: 14px; transition: opacity 0.3s; }
        .navbar-menu a:hover { opacity: 0.8; }
        .navbar-user { display: flex; align-items: center; gap: 15px; }
        .user-badge { background: rgba(255,255,255,0.2); padding: 8px 15px; border-radius: 20px; font-size: 14px; }
        
        .container { max-width: 1400px; margin: 0 auto; padding: 30px; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 12px; padding: 25px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 15px; }
        .stat-icon.blue { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-icon.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-icon.orange { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-icon.purple { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stat-value { font-size: 32px; font-weight: 700; color: #2d3748; margin-bottom: 5px; }
        .stat-label { color: #718096; font-size: 14px; }
        
        .section { background: white; border-radius: 12px; padding: 25px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .section-title { font-size: 20px; font-weight: 600; color: #2d3748; }
        
        .btn { display: inline-block; padding: 10px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; text-decoration: none; transition: transform 0.2s; }
        .btn:hover { transform: translateY(-2px); }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px; background: #f7fafc; color: #4a5568; font-weight: 600; font-size: 13px; border-bottom: 2px solid #e2e8f0; }
        .table td { padding: 12px; border-bottom: 1px solid #e2e8f0; font-size: 14px; color: #2d3748; }
        .table tr:hover { background: #f7fafc; }
        
        .badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .badge-success { background: #c6f6d5; color: #22543d; }
        .badge-warning { background: #feebc8; color: #7c2d12; }
        .badge-danger { background: #fed7d7; color: #742a2a; }
        .badge-info { background: #bee3f8; color: #1e4e8c; }
        
        .empty-state { text-align: center; padding: 60px 20px; color: #718096; }
        .empty-state-icon { font-size: 64px; margin-bottom: 15px; opacity: 0.5; }
        
        .quick-actions { display: flex; gap: 15px; flex-wrap: wrap; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-brand">
            🚀 Ultra Email Intelligence
        </div>
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
        <h1 style="font-size: 28px; color: #2d3748; margin-bottom: 30px;">Dashboard</h1>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue">📊</div>
                <div class="stat-value"><?php echo number_format($stats['total_jobs']); ?></div>
                <div class="stat-label">Total Jobs</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon green">📧</div>
                <div class="stat-value"><?php echo number_format($stats['total_emails']); ?></div>
                <div class="stat-label">Emails Collected</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon orange">⚡</div>
                <div class="stat-value"><?php echo number_format($stats['active_workers']); ?></div>
                <div class="stat-label">Active Workers</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon purple">📋</div>
                <div class="stat-value"><?php echo number_format($stats['pending_tasks']); ?></div>
                <div class="stat-label">Pending Tasks</div>
            </div>
        </div>
        
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">Quick Actions</h2>
            </div>
            <div class="quick-actions">
                <a href="new_job.php" class="btn">➕ Create New Job</a>
                <a href="workers_control.php" class="btn">⚙️ Manage Workers</a>
                <a href="results.php" class="btn">📊 View Results</a>
                <a href="settings.php" class="btn">🔧 Configure Settings</a>
            </div>
        </div>
        
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">Recent Jobs</h2>
            </div>
            
            <?php if (empty($recentJobs)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📭</div>
                    <p>No jobs yet. Create your first job to get started!</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Niche</th>
                            <th>Country</th>
                            <th>Status</th>
                            <th>Emails</th>
                            <th>Progress</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentJobs as $job): ?>
                            <tr>
                                <td>#<?php echo $job['id']; ?></td>
                                <td><?php echo htmlspecialchars($job['name']); ?></td>
                                <td><?php echo htmlspecialchars($job['niche'] ?? '-'); ?></td>
                                <td><?php echo strtoupper($job['country_code'] ?? '-'); ?></td>
                                <td>
                                    <?php 
                                    $statusClass = 'info';
                                    if ($job['status'] === 'completed') $statusClass = 'success';
                                    elseif ($job['status'] === 'failed') $statusClass = 'danger';
                                    elseif ($job['status'] === 'running') $statusClass = 'warning';
                                    ?>
                                    <span class="badge badge-<?php echo $statusClass; ?>"><?php echo $job['status']; ?></span>
                                </td>
                                <td><?php echo number_format($job['total_emails']); ?></td>
                                <td><?php echo number_format($job['progress'], 1); ?>%</td>
                                <td><?php echo date('M j, Y H:i', strtotime($job['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">Worker Status</h2>
            </div>
            
            <?php if (empty($workers)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">⚙️</div>
                    <p>No workers active. Start workers from the Workers Control panel.</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Worker ID</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Current Task</th>
                            <th>Last Heartbeat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($workers as $worker): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($worker['worker_id']); ?></td>
                                <td><?php echo ucfirst($worker['worker_type']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $worker['status'] === 'active' ? 'success' : 'info'; ?>">
                                        <?php echo $worker['status']; ?>
                                    </span>
                                </td>
                                <td><?php echo $worker['current_task'] ? '#' . $worker['current_task'] : '-'; ?></td>
                                <td><?php echo $worker['last_heartbeat'] ? date('H:i:s', strtotime($worker['last_heartbeat'])) : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Auto-refresh stats every 5 seconds
        setInterval(function() {
            location.reload();
        }, 5000);
    </script>
</body>
</html>
