<?php
/**
 * New Job Creation
 */

require_once 'auth.php';
require_once 'db.php';

$user = requireAuth();
$pdo = getDbConnection();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jobName = $_POST['job_name'] ?? '';
    $niche = $_POST['niche'] ?? '';
    $countryCode = $_POST['country_code'] ?? '';
    $emailType = $_POST['email_type'] ?? 'all';
    $speedMode = $_POST['speed_mode'] ?? 'normal';
    $searchDepth = $_POST['search_depth'] ?? '10';
    $targetEmails = (int)($_POST['target_emails'] ?? 0);
    $timeLimit = (int)($_POST['time_limit'] ?? 0);
    
    if (empty($jobName)) {
        $message = 'Job name is required';
        $messageType = 'error';
    } elseif (empty($niche)) {
        $message = 'Niche/keyword is required';
        $messageType = 'error';
    } else {
        try {
            // Calculate deadline if time limit is set
            $deadline = null;
            if ($timeLimit > 0) {
                $deadline = date('Y-m-d H:i:s', time() + ($timeLimit * 60));
            }
            
            // Create job
            $stmt = $pdo->prepare("INSERT INTO jobs (name, niche, country_code, email_type, speed_mode, status, target_emails, time_limit, deadline, created_at) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, datetime('now'))");
            $stmt->execute([$jobName, $niche, $countryCode, $emailType, $speedMode, $targetEmails, $timeLimit, $deadline]);
            $jobId = $pdo->lastInsertId();
            
            // Create initial discovery tasks
            $searchQueries = generateSearchQueries($niche, $countryCode, (int)$searchDepth);
            
            $stmt = $pdo->prepare("INSERT INTO queue (job_id, task_type, task_data, status, priority, created_at) VALUES (?, 'discover', ?, 'pending', 1, datetime('now'))");
            
            foreach ($searchQueries as $query) {
                $taskData = json_encode([
                    'query' => $query,
                    'country' => $countryCode,
                    'niche' => $niche
                ]);
                $stmt->execute([$jobId, $taskData]);
            }
            
            logMessage('info', "Job created: {$jobName} (ID: {$jobId}) by " . $user['username']);
            
            header('Location: index.php?job_created=' . $jobId);
            exit;
        } catch (Exception $e) {
            $message = 'Failed to create job: ' . $e->getMessage();
            $messageType = 'error';
            logMessage('error', 'Job creation error: ' . $e->getMessage());
        }
    }
}

function generateSearchQueries($niche, $country, $depth) {
    $queries = [];
    $baseKeywords = explode(',', $niche);
    
    foreach ($baseKeywords as $keyword) {
        $keyword = trim($keyword);
        
        // Basic search
        $queries[] = $keyword;
        
        // Location-based searches
        if ($country) {
            $queries[] = "{$keyword} in {$country}";
            $queries[] = "{$keyword} {$country}";
        }
        
        // Industry-specific variations
        $queries[] = "{$keyword} companies";
        $queries[] = "{$keyword} businesses";
        $queries[] = "{$keyword} directory";
        $queries[] = "{$keyword} email contacts";
        
        if ($depth >= 20) {
            $queries[] = "top {$keyword} companies";
            $queries[] = "best {$keyword} services";
            $queries[] = "{$keyword} providers";
        }
    }
    
    return array_slice(array_unique($queries), 0, $depth);
}

$countries = [
    'us' => '🇺🇸 United States',
    'uk' => '🇬🇧 United Kingdom',
    'ca' => '🇨🇦 Canada',
    'au' => '🇦🇺 Australia',
    'de' => '🇩🇪 Germany',
    'fr' => '🇫🇷 France',
    'es' => '🇪🇸 Spain',
    'it' => '🇮🇹 Italy',
    'jp' => '🇯🇵 Japan',
    'cn' => '🇨🇳 China',
    'in' => '🇮🇳 India',
    'br' => '🇧🇷 Brazil'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Job - Ultra Email Intelligence Platform</title>
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
        
        .container { max-width: 800px; margin: 0 auto; padding: 30px; }
        
        .section { background: white; border-radius: 12px; padding: 30px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .section-title { font-size: 20px; font-weight: 600; color: #2d3748; margin-bottom: 20px; }
        
        .form-group { margin-bottom: 25px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #2d3748; font-size: 14px; }
        .label-hint { color: #718096; font-weight: 400; font-size: 13px; margin-top: 2px; }
        input, select, textarea { width: 100%; padding: 12px 15px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; transition: border 0.3s; font-family: inherit; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        textarea { resize: vertical; min-height: 100px; }
        
        .radio-group { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; }
        .radio-card { border: 2px solid #e2e8f0; border-radius: 8px; padding: 15px; cursor: pointer; transition: all 0.3s; }
        .radio-card:hover { border-color: #667eea; background: #f7fafc; }
        .radio-card input { display: none; }
        .radio-card input:checked + .radio-content { color: #667eea; }
        .radio-card input:checked ~ .radio-card { border-color: #667eea; background: #eef2ff; }
        .radio-content { display: flex; flex-direction: column; align-items: center; gap: 5px; }
        .radio-icon { font-size: 32px; }
        .radio-label { font-weight: 600; font-size: 14px; }
        .radio-desc { font-size: 12px; color: #718096; text-align: center; }
        
        .btn { display: inline-block; padding: 12px 24px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; text-decoration: none; transition: transform 0.2s; }
        .btn:hover { transform: translateY(-2px); }
        .btn-secondary { background: #6c757d; }
        
        .alert { padding: 15px 20px; border-radius: 6px; margin-bottom: 25px; font-size: 14px; }
        .alert-error { background: #fed7d7; border-left: 4px solid #e53e3e; color: #742a2a; }
        
        .info-box { background: #edf2f7; border-radius: 6px; padding: 15px; margin-bottom: 20px; font-size: 14px; color: #4a5568; }
        
        .button-group { display: flex; gap: 10px; margin-top: 30px; }
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
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
        <h1 style="font-size: 28px; color: #2d3748; margin-bottom: 30px;">➕ Create New Job</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <form method="post">
            <div class="section">
                <h2 class="section-title">📋 Job Details</h2>
                
                <div class="form-group">
                    <label for="job_name">
                        Job Name
                        <div class="label-hint">A descriptive name for this extraction job</div>
                    </label>
                    <input type="text" name="job_name" id="job_name" required placeholder="e.g., Crypto Companies USA">
                </div>
                
                <div class="form-group">
                    <label for="niche">
                        Niche / Keywords
                        <div class="label-hint">Target industry or keywords (comma-separated for multiple)</div>
                    </label>
                    <textarea name="niche" id="niche" required placeholder="e.g., cryptocurrency, blockchain, bitcoin"></textarea>
                </div>
            </div>
            
            <div class="section">
                <h2 class="section-title">🌍 Targeting</h2>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label for="country_code">
                            Target Country
                            <div class="label-hint">Geographic focus for search</div>
                        </label>
                        <select name="country_code" id="country_code">
                            <option value="">Global / All Countries</option>
                            <?php foreach ($countries as $code => $name): ?>
                                <option value="<?php echo $code; ?>"><?php echo $name; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="search_depth">
                            Number of Search Queries
                            <div class="label-hint">Enter the number of search queries to generate (no limit)</div>
                        </label>
                        <input type="number" name="search_depth" id="search_depth" value="10" min="1" placeholder="e.g., 10, 50, 100">
                    </div>
                </div>
            </div>
            
            <div class="section">
                <h2 class="section-title">📧 Email Targeting</h2>
                
                <div class="form-group">
                    <label>Email Type Preference</label>
                    <div class="radio-group">
                        <label class="radio-card">
                            <input type="radio" name="email_type" value="all" checked>
                            <div class="radio-content">
                                <div class="radio-icon">📬</div>
                                <div class="radio-label">All Types</div>
                                <div class="radio-desc">Any email format</div>
                            </div>
                        </label>
                        
                        <label class="radio-card">
                            <input type="radio" name="email_type" value="domain">
                            <div class="radio-content">
                                <div class="radio-icon">🏢</div>
                                <div class="radio-label">Domain</div>
                                <div class="radio-desc">Company emails</div>
                            </div>
                        </label>
                        
                        <label class="radio-card">
                            <input type="radio" name="email_type" value="executive">
                            <div class="radio-content">
                                <div class="radio-icon">👔</div>
                                <div class="radio-label">Executive</div>
                                <div class="radio-desc">C-level contacts</div>
                            </div>
                        </label>
                        
                        <label class="radio-card">
                            <input type="radio" name="email_type" value="personal">
                            <div class="radio-content">
                                <div class="radio-icon">👤</div>
                                <div class="radio-label">Personal</div>
                                <div class="radio-desc">Gmail, Yahoo, etc.</div>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="section">
                <h2 class="section-title">⚡ Processing Speed</h2>
                
                <div class="form-group">
                    <label>Speed Mode</label>
                    <div class="radio-group">
                        <label class="radio-card">
                            <input type="radio" name="speed_mode" value="normal" checked>
                            <div class="radio-content">
                                <div class="radio-icon">🐢</div>
                                <div class="radio-label">Normal</div>
                                <div class="radio-desc">Balanced speed</div>
                            </div>
                        </label>
                        
                        <label class="radio-card">
                            <input type="radio" name="speed_mode" value="fast">
                            <div class="radio-content">
                                <div class="radio-icon">🚀</div>
                                <div class="radio-label">Fast</div>
                                <div class="radio-desc">Quick processing</div>
                            </div>
                        </label>
                        
                        <label class="radio-card">
                            <input type="radio" name="speed_mode" value="ultra">
                            <div class="radio-content">
                                <div class="radio-icon">⚡</div>
                                <div class="radio-label">Ultra Fast</div>
                                <div class="radio-desc">Maximum speed</div>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="section">
                <h2 class="section-title">🎯 Goals & Limits</h2>
                
                <div class="info-box">
                    Set optional targets and time limits for your job. Leave at 0 for unlimited collection.
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label for="target_emails">
                            Target Email Count
                            <div class="label-hint">Number of emails to collect (0 = unlimited)</div>
                        </label>
                        <input type="number" name="target_emails" id="target_emails" value="0" min="0" placeholder="e.g., 1000">
                    </div>
                    
                    <div class="form-group">
                        <label for="time_limit">
                            Time Limit (minutes)
                            <div class="label-hint">Maximum time to run (0 = unlimited)</div>
                        </label>
                        <input type="number" name="time_limit" id="time_limit" value="0" min="0" placeholder="e.g., 60">
                    </div>
                </div>
                
                <div class="info-box" style="background: #fff4e6; border-left: 4px solid #ff9800;">
                    <strong>⚠️ Note:</strong> The job will automatically stop when either the target email count is reached OR the time limit expires, whichever comes first.
                </div>
            </div>
            
            <div class="button-group">
                <button type="submit" class="btn">🚀 Create Job</button>
                <a href="index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
    
    <script>
        // Make radio cards clickable
        document.querySelectorAll('.radio-card').forEach(card => {
            card.addEventListener('click', function() {
                const radio = this.querySelector('input[type="radio"]');
                radio.checked = true;
                
                // Update visual state
                this.parentElement.querySelectorAll('.radio-card').forEach(c => {
                    c.style.borderColor = '#e2e8f0';
                    c.style.background = 'white';
                });
                this.style.borderColor = '#667eea';
                this.style.background = '#eef2ff';
            });
        });
    </script>
</body>
</html>
