<?php
/**
 * Serper.dev Settings Configuration
 */

require_once 'auth.php';
require_once 'db.php';

$user = requireAuth();
$pdo = getDbConnection();

$message = '';
$messageType = '';

// Handle API key verification
if (isset($_POST['verify_api'])) {
    $apiKey = $_POST['serpapi_key'] ?? '';
    
    if (!empty($apiKey)) {
        // Test API key with a simple search request to Serper.dev
        $testUrl = "https://google.serper.dev/search";
        $testData = json_encode(['q' => 'test']);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $testUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $testData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-KEY: ' . $apiKey,
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Check if request was successful
        if ($httpCode === 200 && !empty($response)) {
            $data = json_decode($response, true);
            // Check if response contains valid search results
            if (isset($data['error'])) {
                $message = 'API key verification failed: ' . $data['error'];
                $messageType = 'error';
            } elseif (isset($data['organic']) || isset($data['searchParameters'])) {
                $message = 'API key verified successfully! ✓';
                $messageType = 'success';
            } else {
                $message = 'API response received. You can save and use this key.';
                $messageType = 'success';
            }
        } else {
            // Try to decode response even on error to get detailed message
            $data = !empty($response) ? json_decode($response, true) : null;
            
            if ($httpCode === 401) {
                if (isset($data['message'])) {
                    $message = 'Invalid API key: ' . $data['message'] . '. Please check your key at serper.dev/api-key';
                } else {
                    $message = 'Invalid API key (401 Unauthorized). Please verify your API key from serper.dev/api-key';
                }
                $messageType = 'error';
            } elseif ($httpCode === 403) {
                $message = 'API key access forbidden (403). Please check your subscription at serper.dev';
                $messageType = 'error';
            } elseif (!empty($error)) {
                $message = 'Connection error: ' . $error . '. Check your internet connection and try again.';
                $messageType = 'error';
            } else {
                $message = 'Verification returned HTTP ' . $httpCode . '. You can still save and use the key if you\'re confident it\'s correct.';
                $messageType = 'error';
            }
        }
    }
}

// Handle settings save
if (isset($_POST['save_settings'])) {
    try {
        $settings = [
            'serper_api_key' => $_POST['serpapi_key'] ?? '', // Using serper.dev now
            'search_engines' => $_POST['search_engines'] ?? 'google', // Now supports multiple
            'language' => $_POST['language'] ?? 'en',
            'country' => $_POST['country'] ?? 'us',
            'discover_workers' => $_POST['discover_workers'] ?? '2',
            'extract_workers' => $_POST['extract_workers'] ?? '3',
            'generate_workers' => $_POST['generate_workers'] ?? '2'
        ];
        
        foreach ($settings as $key => $value) {
            setSetting($key, $value);
        }
        
        logMessage('info', 'Settings updated by ' . $user['username']);
        $message = 'Settings saved successfully!';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Failed to save settings: ' . $e->getMessage();
        $messageType = 'error';
        logMessage('error', 'Settings save error: ' . $e->getMessage());
    }
}

// Load current settings
$currentSettings = [
    'serpapi_key' => getSetting('serper_api_key', getSetting('serpapi_key', '')), // Backward compatibility
    'search_engines' => getSetting('search_engines', 'google'),
    'language' => getSetting('language', 'en'),
    'country' => getSetting('country', 'us'),
    'discover_workers' => getSetting('discover_workers', '2'),
    'extract_workers' => getSetting('extract_workers', '3'),
    'generate_workers' => getSetting('generate_workers', '2')
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Ultra Email Intelligence Platform</title>
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
        
        .container { max-width: 900px; margin: 0 auto; padding: 30px; }
        
        .section { background: white; border-radius: 12px; padding: 30px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .section-title { font-size: 20px; font-weight: 600; color: #2d3748; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        
        .form-group { margin-bottom: 25px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #2d3748; font-size: 14px; }
        .label-hint { color: #718096; font-weight: 400; font-size: 13px; margin-top: 2px; }
        input, select { width: 100%; padding: 12px 15px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; transition: border 0.3s; }
        input:focus, select:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        
        .input-group { display: flex; gap: 10px; }
        .input-group input { flex: 1; }
        
        .btn { display: inline-block; padding: 12px 24px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; text-decoration: none; transition: transform 0.2s; }
        .btn:hover { transform: translateY(-2px); }
        .btn-secondary { background: #6c757d; }
        
        .alert { padding: 15px 20px; border-radius: 6px; margin-bottom: 25px; font-size: 14px; }
        .alert-success { background: #c6f6d5; border-left: 4px solid #38a169; color: #22543d; }
        .alert-error { background: #fed7d7; border-left: 4px solid #e53e3e; color: #742a2a; }
        
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
        
        .info-box { background: #edf2f7; border-radius: 6px; padding: 15px; margin-bottom: 20px; font-size: 14px; color: #4a5568; }
        
        .button-group { display: flex; gap: 10px; margin-top: 30px; }
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
        <h1 style="font-size: 28px; color: #2d3748; margin-bottom: 30px;">⚙️ Settings</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <form method="post">
            <div class="section">
                <h2 class="section-title">🔑 Serper.dev API Configuration</h2>
                
                <div class="info-box">
                    Get your API key from <a href="https://serper.dev/api-key" target="_blank" style="color: #667eea;">serper.dev/api-key</a>. 
                    The API key is required for all discovery operations.
                </div>
                
                <div class="form-group">
                    <label for="serpapi_key">
                        API Key
                        <div class="label-hint">Your Serper.dev authentication key</div>
                    </label>
                    <div class="input-group">
                        <input type="text" name="serpapi_key" id="serpapi_key" 
                               value="<?php echo htmlspecialchars($currentSettings['serpapi_key']); ?>" 
                               placeholder="Enter your Serper.dev API key">
                        <button type="submit" name="verify_api" class="btn">Verify</button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="search_engines">
                        Search Types
                        <div class="label-hint">Comma-separated list of search types (e.g., google, images, news)</div>
                    </label>
                    <input type="text" name="search_engines" id="search_engines" 
                           value="<?php echo htmlspecialchars($currentSettings['search_engines']); ?>" 
                           placeholder="google, images, news">
                    <div style="font-size: 12px; color: #718096; margin-top: 5px;">
                        Available types: google (web search), images, news, places, shopping
                    </div>
                </div>
                
                <div class="grid">
                    <div class="form-group">
                        <label for="language">
                            Language (hl)
                            <div class="label-hint">Interface language</div>
                        </label>
                        <select name="language" id="language">
                            <option value="en" <?php echo $currentSettings['language'] === 'en' ? 'selected' : ''; ?>>English</option>
                            <option value="es" <?php echo $currentSettings['language'] === 'es' ? 'selected' : ''; ?>>Spanish</option>
                            <option value="fr" <?php echo $currentSettings['language'] === 'fr' ? 'selected' : ''; ?>>French</option>
                            <option value="de" <?php echo $currentSettings['language'] === 'de' ? 'selected' : ''; ?>>German</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="country">
                            Country (gl)
                            <div class="label-hint">Target country</div>
                        </label>
                        <select name="country" id="country">
                            <option value="us" <?php echo $currentSettings['country'] === 'us' ? 'selected' : ''; ?>>United States</option>
                            <option value="uk" <?php echo $currentSettings['country'] === 'uk' ? 'selected' : ''; ?>>United Kingdom</option>
                            <option value="ca" <?php echo $currentSettings['country'] === 'ca' ? 'selected' : ''; ?>>Canada</option>
                            <option value="au" <?php echo $currentSettings['country'] === 'au' ? 'selected' : ''; ?>>Australia</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="section">
                <h2 class="section-title">⚡ Worker Configuration</h2>
                
                <div class="info-box">
                    Configure the number of concurrent workers for each task type. More workers = faster processing but higher resource usage.
                </div>
                
                <div class="grid">
                    <div class="form-group">
                        <label for="discover_workers">
                            Discover Workers
                            <div class="label-hint">Company discovery</div>
                        </label>
                        <input type="number" name="discover_workers" id="discover_workers" 
                               value="<?php echo htmlspecialchars($currentSettings['discover_workers']); ?>" 
                               min="1" max="10">
                    </div>
                    
                    <div class="form-group">
                        <label for="extract_workers">
                            Extract Workers
                            <div class="label-hint">Email extraction</div>
                        </label>
                        <input type="number" name="extract_workers" id="extract_workers" 
                               value="<?php echo htmlspecialchars($currentSettings['extract_workers']); ?>" 
                               min="1" max="10">
                    </div>
                    
                    <div class="form-group">
                        <label for="generate_workers">
                            Generate Workers
                            <div class="label-hint">Email generation</div>
                        </label>
                        <input type="number" name="generate_workers" id="generate_workers" 
                               value="<?php echo htmlspecialchars($currentSettings['generate_workers']); ?>" 
                               min="1" max="10">
                    </div>
                </div>
            </div>
            
            <div class="button-group">
                <button type="submit" name="save_settings" class="btn">💾 Save Settings</button>
                <a href="index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</body>
</html>
