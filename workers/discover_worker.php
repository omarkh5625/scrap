#!/usr/bin/env php
<?php
/**
 * Discover Worker - Serper.dev Integration
 * Discovers companies and business listings using Serper.dev API
 */

require_once __DIR__ . '/BaseWorker.php';

class DiscoverWorker extends BaseWorker {
    private $apiKey;
    private $searchTypes;
    
    public function __construct($workerId) {
        parent::__construct($workerId, 'discover');
        $this->apiKey = getSetting('serper_api_key', getSetting('serpapi_key', ''));
        $this->searchTypes = getSetting('search_engines', 'google');
    }
    
    protected function processTask($task) {
        $taskData = json_decode($task['task_data'], true);
        $query = $taskData['query'] ?? '';
        $country = $taskData['country'] ?? '';
        $niche = $taskData['niche'] ?? '';
        
        if (empty($this->apiKey)) {
            throw new Exception('Serper.dev API key not configured');
        }
        
        // Parse search types (comma-separated)
        $types = array_map('trim', explode(',', $this->searchTypes));
        $totalExtracted = 0;
        
        foreach ($types as $searchType) {
            if (empty($searchType)) continue;
            
            try {
                $extracted = $this->searchByType($searchType, $query, $country, $niche, $task['job_id']);
                $totalExtracted += $extracted;
            } catch (Exception $e) {
                $this->log('error', "Search type '{$searchType}' failed: " . $e->getMessage());
            }
        }
        
        $this->log('info', "Discovered {$totalExtracted} URLs for extraction from query: {$query}");
    }
    
    private function searchByType($searchType, $query, $country, $niche, $jobId) {
        // Determine Serper.dev endpoint based on search type
        $endpoint = 'https://google.serper.dev/search';
        
        switch (strtolower($searchType)) {
            case 'images':
                $endpoint = 'https://google.serper.dev/images';
                break;
            case 'news':
                $endpoint = 'https://google.serper.dev/news';
                break;
            case 'places':
            case 'maps':
                $endpoint = 'https://google.serper.dev/places';
                break;
            case 'shopping':
                $endpoint = 'https://google.serper.dev/shopping';
                break;
            case 'google':
            default:
                $endpoint = 'https://google.serper.dev/search';
                break;
        }
        
        // Build request payload
        $payload = ['q' => $query];
        
        if ($country) {
            $payload['gl'] = $country; // Country code
        }
        
        $language = getSetting('language', 'en');
        if ($language) {
            $payload['hl'] = $language; // Language code
        }
        
        $payload['num'] = 20; // Number of results
        
        $jsonPayload = json_encode($payload);
        
        // Make API request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-KEY: ' . $this->apiKey,
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Serper.dev API request failed with code {$httpCode}");
        }
        
        $data = json_decode($response, true);
        
        if (!$data) {
            throw new Exception('Invalid API response');
        }
        
        // Process results and create extraction tasks
        $results = [];
        
        // Handle different response formats
        if (isset($data['organic'])) {
            $results = array_merge($results, $data['organic']);
        }
        
        if (isset($data['places'])) {
            $results = array_merge($results, $data['places']);
        }
        
        if (isset($data['news'])) {
            $results = array_merge($results, $data['news']);
        }
        
        $extractedCount = 0;
        
        foreach ($results as $result) {
            $url = $result['link'] ?? $result['website'] ?? $result['url'] ?? null;
            $title = $result['title'] ?? $result['name'] ?? '';
            
            if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                // Create extraction task
                $extractTaskData = json_encode([
                    'url' => $url,
                    'company_name' => $title,
                    'source' => 'serper',
                    'niche' => $niche
                ]);
                
                try {
                    $stmt = $this->pdo->prepare("INSERT INTO queue (job_id, task_type, task_data, status, priority, created_at) VALUES (?, 'extract', ?, 'pending', 2, datetime('now'))");
                    $stmt->execute([$jobId, $extractTaskData]);
                    $extractedCount++;
                } catch (Exception $e) {
                    // Ignore duplicates
                }
            }
        }
        
        // Log error if no URLs found
        if ($extractedCount === 0) {
            $errorMsg = "⚠️ NO URLs FOUND for query '{$query}' using search type '{$searchType}'. Check your API key and search settings.";
            $this->log('error', $errorMsg);
            
            // Store alert in database for UI to display
            try {
                $stmt = $this->pdo->prepare("INSERT INTO logs (level, message, context, created_at) VALUES ('alert', ?, ?, ?)");
                $stmt->execute([
                    $errorMsg,
                    json_encode(['worker_id' => $this->workerId, 'worker_type' => $this->workerType, 'query' => $query]),
                    date('Y-m-d H:i:s')
                ]);
            } catch (Exception $e) {
                // Ignore if logs table doesn't exist
            }
        }
        
        return $extractedCount;
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    $workerId = $argv[1] ?? 'discover_' . uniqid();
    $worker = new DiscoverWorker($workerId);
    $worker->run();
}
