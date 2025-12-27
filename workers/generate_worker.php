#!/usr/bin/env php
<?php
/**
 * Generate Worker - Email Generation
 * Generates potential email addresses based on common patterns
 */

require_once __DIR__ . '/BaseWorker.php';

class GenerateWorker extends BaseWorker {
    private $commonPatterns = [
        'info',
        'contact',
        'hello',
        'support',
        'sales',
        'admin',
        'office',
        'enquiries',
        'inquiries'
    ];
    
    public function __construct($workerId) {
        parent::__construct($workerId, 'generate');
    }
    
    protected function processTask($task) {
        $taskData = json_decode($task['task_data'], true);
        $domain = $taskData['domain'] ?? '';
        $companyName = $taskData['company_name'] ?? '';
        $niche = $taskData['niche'] ?? '';
        
        if (empty($domain)) {
            throw new Exception('No domain provided');
        }
        
        // Generate email variations
        $generatedEmails = [];
        
        // Common pattern emails
        foreach ($this->commonPatterns as $pattern) {
            $generatedEmails[] = $pattern . '@' . $domain;
        }
        
        // Company name-based emails (if available)
        if ($companyName) {
            $slug = $this->slugify($companyName);
            if ($slug) {
                $generatedEmails[] = $slug . '@' . $domain;
                $generatedEmails[] = 'hello@' . $slug . '.' . $domain;
            }
        }
        
        // Save generated emails
        $savedCount = 0;
        
        foreach ($generatedEmails as $email) {
            try {
                // Basic validation
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                
                $stmt = $this->pdo->prepare("INSERT INTO emails (job_id, email, domain, email_type, source, company_name, created_at) VALUES (?, ?, ?, 'domain', 'generated', ?, datetime('now'))");
                $stmt->execute([$task['job_id'], $email, $domain, $companyName]);
                $savedCount++;
            } catch (Exception $e) {
                // Ignore duplicates
            }
        }
        
        // Update job email count
        $stmt = $this->pdo->prepare("UPDATE jobs SET total_emails = (SELECT COUNT(*) FROM emails WHERE job_id = ?) WHERE id = ?");
        $stmt->execute([$task['job_id'], $task['job_id']]);
        
        $this->log('info', "Generated and saved {$savedCount} potential emails for {$domain}");
    }
    
    private function slugify($text) {
        // Remove special characters
        $text = preg_replace('/[^a-zA-Z0-9\s-]/', '', strtolower($text));
        
        // Replace spaces and multiple dashes with single dash
        $text = preg_replace('/[\s-]+/', '-', $text);
        
        // Remove leading/trailing dashes
        $text = trim($text, '-');
        
        // Limit length
        if (strlen($text) > 20) {
            $text = substr($text, 0, 20);
        }
        
        return $text;
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    $workerId = $argv[1] ?? 'generate_' . uniqid();
    $worker = new GenerateWorker($workerId);
    $worker->run();
}
