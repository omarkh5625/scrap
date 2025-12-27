#!/usr/bin/env php
<?php
/**
 * Discover Worker - SerpApi Integration
 * Discovers companies and business listings using SerpApi
 */

require_once __DIR__ . '/BaseWorker.php';

class DiscoverWorker extends BaseWorker {
    private $apiKey;
    private $searchEngine;
    
    public function __construct($workerId) {
        parent::__construct($workerId, 'discover');
        $this->apiKey = getSetting('serpapi_key');
        $this->searchEngine = getSetting('search_engine', 'google');
    }
    
    protected function processTask($task) {
        $taskData = json_decode($task['task_data'], true);
        $query = $taskData['query'] ?? '';
        $country = $taskData['country'] ?? '';
        $niche = $taskData['niche'] ?? '';
        
        if (empty($this->apiKey)) {
            throw new Exception('SerpApi key not configured');
        }
        
        // Build SerpApi request
        $params = [
            'engine' => $this->searchEngine,
            'q' => $query,
            'api_key' => $this->apiKey,
            'num' => 20
        ];
        
        if ($country) {
            $params['gl'] = $country;
        }
        
        $language = getSetting('language', 'en');
        if ($language) {
            $params['hl'] = $language;
        }
        
        $url = 'https://serpapi.com/search.json?' . http_build_query($params);
        
        // Make API request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("SerpApi request failed with code {$httpCode}");
        }
        
        $data = json_decode($response, true);
        
        if (!$data) {
            throw new Exception('Invalid API response');
        }
        
        // Process results and create extraction tasks
        $results = [];
        
        if (isset($data['organic_results'])) {
            $results = array_merge($results, $data['organic_results']);
        }
        
        if (isset($data['local_results'])) {
            $results = array_merge($results, $data['local_results']);
        }
        
        $extractedCount = 0;
        
        foreach ($results as $result) {
            $url = $result['link'] ?? $result['website'] ?? null;
            $title = $result['title'] ?? $result['name'] ?? '';
            
            if ($url) {
                // Create extraction task
                $extractTaskData = json_encode([
                    'url' => $url,
                    'company_name' => $title,
                    'source' => 'serpapi',
                    'niche' => $niche
                ]);
                
                try {
                    $stmt = $this->pdo->prepare("INSERT INTO queue (job_id, task_type, task_data, status, priority, created_at) VALUES (?, 'extract', ?, 'pending', 2, datetime('now'))");
                    $stmt->execute([$task['job_id'], $extractTaskData]);
                    $extractedCount++;
                } catch (Exception $e) {
                    // Ignore duplicates
                }
            }
        }
        
        $this->log('info', "Discovered {$extractedCount} URLs for extraction from query: {$query}");
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    $workerId = $argv[1] ?? 'discover_' . uniqid();
    $worker = new DiscoverWorker($workerId);
    $worker->run();
}
