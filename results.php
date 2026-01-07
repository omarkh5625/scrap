<?php
/**
 * Results Viewer and Export
 */

require_once 'auth.php';
require_once 'db.php';

$user = requireAuth();
$pdo = getDbConnection();

// Handle export
if (isset($_GET['export'])) {
    $format = $_GET['format'] ?? 'csv';
    $jobId = $_GET['job_id'] ?? null;
    
    $query = "SELECT e.*, j.name as job_name FROM emails e LEFT JOIN jobs j ON e.job_id = j.id WHERE 1=1";
    $params = [];
    
    if ($jobId) {
        $query .= " AND e.job_id = ?";
        $params[] = $jobId;
    }
    
    if (!empty($_GET['email_type'])) {
        $query .= " AND e.email_type = ?";
        $params[] = $_GET['email_type'];
    }
    
    if (!empty($_GET['domain'])) {
        $query .= " AND e.domain LIKE ?";
        $params[] = '%' . $_GET['domain'] . '%';
    }
    
    $query .= " ORDER BY e.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $emails = $stmt->fetchAll();
    
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="emails_' . date('Y-m-d_His') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Email', 'Domain', 'Type', 'Company', 'Source', 'Job', 'Date']);
        
        foreach ($emails as $email) {
            fputcsv($output, [
                $email['email'],
                $email['domain'],
                $email['email_type'],
                $email['company_name'],
                $email['source'],
                $email['job_name'],
                $email['created_at']
            ]);
        }
        
        fclose($output);
        exit;
    } elseif ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="emails_' . date('Y-m-d_His') . '.json"');
        
        echo json_encode($emails, JSON_PRETTY_PRINT);
        exit;
    }
}

// Filters
$jobId = $_GET['job_id'] ?? '';
$emailType = $_GET['email_type'] ?? '';
$domain = $_GET['domain'] ?? '';
$search = $_GET['search'] ?? '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Build query
$query = "SELECT e.*, j.name as job_name FROM emails e LEFT JOIN jobs j ON e.job_id = j.id WHERE 1=1";
$countQuery = "SELECT COUNT(*) as total FROM emails e WHERE 1=1";
$params = [];

if ($jobId) {
    $query .= " AND e.job_id = ?";
    $countQuery .= " AND e.job_id = ?";
    $params[] = $jobId;
}

if ($emailType) {
    $query .= " AND e.email_type = ?";
    $countQuery .= " AND e.email_type = ?";
    $params[] = $emailType;
}

if ($domain) {
    $query .= " AND e.domain LIKE ?";
    $countQuery .= " AND e.domain LIKE ?";
    $params[] = '%' . $domain . '%';
}

if ($search) {
    $query .= " AND (e.email LIKE ? OR e.company_name LIKE ?)";
    $countQuery .= " AND (e.email LIKE ? OR e.company_name LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

// Get total count
$stmt = $pdo->prepare($countQuery);
$stmt->execute($params);
$totalEmails = $stmt->fetch()['total'];
$totalPages = ceil($totalEmails / $perPage);

// Get emails
$query .= " ORDER BY e.created_at DESC LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($query);
$stmt->execute(array_merge($params, [$perPage, $offset]));
$emails = $stmt->fetchAll();

// Get jobs for filter
$jobs = $pdo->query("SELECT id, name FROM jobs ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Results - Ultra Email Intelligence Platform</title>
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
        
        .container { max-width: 1400px; margin: 0 auto; padding: 30px; }
        
        .section { background: white; border-radius: 12px; padding: 30px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .section-title { font-size: 20px; font-weight: 600; color: #2d3748; }
        
        .filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .filter-group { }
        label { display: block; margin-bottom: 6px; font-weight: 500; color: #4a5568; font-size: 13px; }
        input, select { width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; }
        input:focus, select:focus { outline: none; border-color: #667eea; }
        
        .btn { display: inline-block; padding: 10px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; text-decoration: none; transition: transform 0.2s; }
        .btn:hover { transform: translateY(-2px); }
        .btn-sm { padding: 8px 16px; font-size: 13px; }
        .btn-secondary { background: #6c757d; }
        
        .stats-bar { display: flex; gap: 30px; padding: 20px; background: #f7fafc; border-radius: 8px; margin-bottom: 20px; }
        .stat { flex: 1; }
        .stat-label { font-size: 12px; color: #718096; margin-bottom: 4px; }
        .stat-value { font-size: 24px; font-weight: 700; color: #2d3748; }
        
        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px; background: #f7fafc; color: #4a5568; font-weight: 600; font-size: 13px; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
        .table td { padding: 12px; border-bottom: 1px solid #e2e8f0; font-size: 14px; color: #2d3748; }
        .table tr:hover { background: #f7fafc; }
        
        .badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .badge-domain { background: #bee3f8; color: #1e4e8c; }
        .badge-executive { background: #feebc8; color: #7c2d12; }
        .badge-personal { background: #c6f6d5; color: #22543d; }
        
        .pagination { display: flex; gap: 10px; justify-content: center; margin-top: 25px; }
        .page-link { padding: 8px 14px; border: 1px solid #e2e8f0; border-radius: 6px; color: #4a5568; text-decoration: none; transition: all 0.2s; }
        .page-link:hover { background: #f7fafc; border-color: #667eea; }
        .page-link.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-color: transparent; }
        
        .empty-state { text-align: center; padding: 60px 20px; color: #718096; }
        .empty-state-icon { font-size: 64px; margin-bottom: 15px; opacity: 0.5; }
        
        .export-buttons { display: flex; gap: 10px; }
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
        <h1 style="font-size: 28px; color: #2d3748; margin-bottom: 30px;">📊 Results</h1>
        
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">Filters & Export</h2>
                <div class="export-buttons">
                    <a href="?export=1&format=csv&<?php echo http_build_query($_GET); ?>" class="btn btn-sm">📥 CSV</a>
                    <a href="?export=1&format=json&<?php echo http_build_query($_GET); ?>" class="btn btn-sm">📥 JSON</a>
                </div>
            </div>
            
            <form method="get">
                <div class="filters">
                    <div class="filter-group">
                        <label for="job_id">Job</label>
                        <select name="job_id" id="job_id">
                            <option value="">All Jobs</option>
                            <?php foreach ($jobs as $job): ?>
                                <option value="<?php echo $job['id']; ?>" <?php echo $jobId == $job['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($job['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="email_type">Email Type</label>
                        <select name="email_type" id="email_type">
                            <option value="">All Types</option>
                            <option value="domain" <?php echo $emailType === 'domain' ? 'selected' : ''; ?>>Domain</option>
                            <option value="executive" <?php echo $emailType === 'executive' ? 'selected' : ''; ?>>Executive</option>
                            <option value="personal" <?php echo $emailType === 'personal' ? 'selected' : ''; ?>>Personal</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="domain">Domain</label>
                        <input type="text" name="domain" id="domain" value="<?php echo htmlspecialchars($domain); ?>" placeholder="e.g., company.com">
                    </div>
                    
                    <div class="filter-group">
                        <label for="search">Search</label>
                        <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Email or company">
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-sm">🔍 Apply Filters</button>
                    <a href="results.php" class="btn btn-secondary btn-sm">Clear</a>
                </div>
            </form>
        </div>
        
        <div class="section">
            <div class="stats-bar">
                <div class="stat">
                    <div class="stat-label">Total Results</div>
                    <div class="stat-value"><?php echo number_format($totalEmails); ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Current Page</div>
                    <div class="stat-value"><?php echo $page; ?> / <?php echo max(1, $totalPages); ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Per Page</div>
                    <div class="stat-value"><?php echo $perPage; ?></div>
                </div>
            </div>
            
            <?php if (empty($emails)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📭</div>
                    <p>No results found. Try adjusting your filters or create a new job.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th>Domain</th>
                                <th>Type</th>
                                <th>Company</th>
                                <th>Source</th>
                                <th>Job</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($emails as $email): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($email['email']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($email['domain'] ?? '-'); ?></td>
                                    <td>
                                        <?php if ($email['email_type']): ?>
                                            <span class="badge badge-<?php echo $email['email_type']; ?>">
                                                <?php echo htmlspecialchars($email['email_type']); ?>
                                            </span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($email['company_name'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($email['source'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($email['job_name'] ?? '-'); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($email['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>" class="page-link">← Previous</a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <a href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>" 
                               class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>" class="page-link">Next →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
