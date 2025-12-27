#!/usr/bin/env php
<?php
/**
 * Extract Worker - Email Extraction from HTML
 * Parses HTML pages to extract email addresses
 */

require_once __DIR__ . '/BaseWorker.php';

class ExtractWorker extends BaseWorker {
    public function __construct($workerId) {
        parent::__construct($workerId, 'extract');
    }
    
    protected function processTask($task) {
        $taskData = json_decode($task['task_data'], true);
        $url = $taskData['url'] ?? '';
        $companyName = $taskData['company_name'] ?? '';
        $niche = $taskData['niche'] ?? '';
        
        if (empty($url)) {
            throw new Exception('No URL provided');
        }
        
        // Fetch page content
        $html = $this->fetchUrl($url);
        
        if (empty($html)) {
            throw new Exception('Failed to fetch URL');
        }
        
        // Extract emails from HTML
        $emails = $this->extractEmails($html);
        
        if (empty($emails)) {
            // No emails found, create generation task
            $domain = $this->extractDomain($url);
            
            if ($domain) {
                $genTaskData = json_encode([
                    'domain' => $domain,
                    'company_name' => $companyName,
                    'niche' => $niche
                ]);
                
                $stmt = $this->pdo->prepare("INSERT INTO queue (job_id, task_type, task_data, status, priority, created_at) VALUES (?, 'generate', ?, 'pending', 3, datetime('now'))");
                $stmt->execute([$task['job_id'], $genTaskData]);
            }
            
            $this->log('info', "No emails found on {$url}, created generation task");
            return;
        }
        
        // Save emails to database
        $savedCount = 0;
        $domain = $this->extractDomain($url);
        
        foreach ($emails as $email) {
            try {
                $emailType = $this->classifyEmail($email);
                
                $stmt = $this->pdo->prepare("INSERT INTO emails (job_id, email, domain, email_type, source, company_name, created_at) VALUES (?, ?, ?, ?, 'extracted', ?, datetime('now'))");
                $stmt->execute([$task['job_id'], $email, $domain, $emailType, $companyName]);
                $savedCount++;
            } catch (Exception $e) {
                // Ignore duplicates
            }
        }
        
        // Update job email count
        $stmt = $this->pdo->prepare("UPDATE jobs SET total_emails = (SELECT COUNT(*) FROM emails WHERE job_id = ?) WHERE id = ?");
        $stmt->execute([$task['job_id'], $task['job_id']]);
        
        $this->log('info', "Extracted and saved {$savedCount} emails from {$url}");
    }
    
    private function fetchUrl($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 400) {
            return $html;
        }
        
        return null;
    }
    
    private function extractEmails($html) {
        // Email regex pattern
        $pattern = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';
        
        // Remove common obfuscations
        $html = str_replace([' at ', ' AT ', '[at]', '(at)'], '@', $html);
        $html = str_replace([' dot ', ' DOT ', '[dot]', '(dot)'], '.', $html);
        
        preg_match_all($pattern, $html, $matches);
        
        $emails = array_unique($matches[0]);
        
        // Filter out common false positives
        $filtered = [];
        $excludeDomains = ['example.com', 'sentry.io', 'schema.org', 'w3.org', 'example.org'];
        
        foreach ($emails as $email) {
            $email = strtolower($email);
            $isValid = true;
            
            foreach ($excludeDomains as $exclude) {
                if (strpos($email, $exclude) !== false) {
                    $isValid = false;
                    break;
                }
            }
            
            // Filter out image file extensions and paths
            if (preg_match('/\.(png|jpg|jpeg|gif|svg|css|js)(\?|$)/i', $email) || strpos($email, '/') !== false) {
                $isValid = false;
            }
            
            if ($isValid && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $filtered[] = $email;
            }
        }
        
        return $filtered;
    }
    
    private function extractDomain($url) {
        $parts = parse_url($url);
        return $parts['host'] ?? null;
    }
    
    private function classifyEmail($email) {
        $domain = substr(strrchr($email, "@"), 1);
        $localPart = substr($email, 0, strpos($email, '@'));
        
        // Personal email domains
        $personalDomains = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com', 'icloud.com'];
        
        if (in_array($domain, $personalDomains)) {
            return 'personal';
        }
        
        // Executive patterns
        $executivePatterns = ['ceo', 'cto', 'cfo', 'coo', 'founder', 'president', 'director', 'vp', 'chief'];
        
        foreach ($executivePatterns as $pattern) {
            if (stripos($localPart, $pattern) !== false) {
                return 'executive';
            }
        }
        
        return 'domain';
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    $workerId = $argv[1] ?? 'extract_' . uniqid();
    $worker = new ExtractWorker($workerId);
    $worker->run();
}
