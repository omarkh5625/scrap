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
            $errorMsg = 'Serper.dev API key not configured. Please add your API key in Settings.';
            $this->log('error', $errorMsg);
            throw new Exception($errorMsg);
        }
        
        // Parse search types (comma-separated) - default to google if empty
        $searchEngines = !empty($this->searchTypes) ? $this->searchTypes : 'google';
        $types = array_map('trim', explode(',', $searchEngines));
        $totalExtracted = 0;
        $errors = [];
        
        foreach ($types as $searchType) {
            if (empty($searchType)) continue;
            
            try {
                $extracted = $this->searchByType($searchType, $query, $country, $niche, $task['job_id']);
                $totalExtracted += $extracted;
                
                // If this search type found results, we're good
                if ($extracted > 0) {
                    $this->log('info', "Discovered {$extracted} URLs using '{$searchType}' for query: {$query}");
                }
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
                $this->log('error', "Search type '{$searchType}' failed: " . $e->getMessage());
                // Continue to next search type instead of failing completely
            }
        }
        
        // If NO results from ANY search type, throw exception to mark task as failed
        if ($totalExtracted === 0) {
            $errorMsg = "❌ FAILED: No URLs found for query '{$query}' across all search types (" . implode(', ', $types) . "). ";
            $errorMsg .= "Errors: " . implode(' | ', $errors);
            $this->log('error', $errorMsg);
            throw new Exception($errorMsg);
        }
        
        $this->log('info', "✓ Discovered total of {$totalExtracted} URLs for extraction from query: {$query}");
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
        
        // Make API request with better error handling
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-KEY: ' . $this->apiKey,
            'Content-Type: application/json',
            'User-Agent: UltraEmailIntelligence/1.0'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMessage = "Serper.dev API request failed with HTTP {$httpCode}";
            if (isset($errorData['message'])) {
                $errorMessage .= ": " . $errorData['message'];
            }
            if (!empty($error)) {
                $errorMessage .= " (cURL error: {$error})";
            }
            throw new Exception($errorMessage);
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
                    $stmt = $this->pdo->prepare("INSERT INTO queue (job_id, task_type, task_data, status, priority, created_at) VALUES (?, 'extract', ?, 'pending', 2, ?)");
                    $stmt->execute([$jobId, $extractTaskData, date('Y-m-d H:i:s')]);
                    $extractedCount++;
                } catch (Exception $e) {
                    // Ignore duplicates
                }
            }
        }
        
        // Log error and throw exception if no URLs found
        if ($extractedCount === 0) {
            $errorMsg = "⚠️ NO URLs FOUND for query '{$query}' using search type '{$searchType}'. API Response: " . 
                        (isset($data['organic']) ? count($data['organic']) . ' organic results' : 'No organic results') . 
                        (isset($data['places']) ? ', ' . count($data['places']) . ' places' : '') . 
                        ". Check your API key and search settings at Serper.dev";
            $this->log('error', $errorMsg);
            
            // Store alert in database for UI to display
            try {
                $stmt = $this->pdo->prepare("INSERT INTO logs (level, message, context, created_at) VALUES ('alert', ?, ?, ?)");
                $stmt->execute([
                    $errorMsg,
                    json_encode([
                        'worker_id' => $this->workerId, 
                        'worker_type' => $this->workerType, 
                        'query' => $query,
                        'search_type' => $searchType,
                        'api_response_keys' => array_keys($data)
                    ]),
                    date('Y-m-d H:i:s')
                ]);
            } catch (Exception $e) {
                // Ignore if logs table doesn't exist
            }
            
            // Throw exception to mark task as failed and stop processing
            throw new Exception($errorMsg);
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
