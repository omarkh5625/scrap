<?php

/**
 * Storage - Handles batch storage of email hashes
 */
class Storage {
    
    private $storagePath;
    private $batchSize;
    private $buffer = [];
    
    public function __construct($storagePath = null, $batchSize = 1000) {
        $this->storagePath = $storagePath ?? __DIR__ . '/../storage/emails.tmp';
        $this->batchSize = $batchSize;
        
        // Ensure storage directory exists
        $dir = dirname($this->storagePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
    
    /**
     * Add email to buffer
     * @param string $emailHash
     * @param string $domain
     */
    public function add($emailHash, $domain) {
        $this->buffer[] = $emailHash . '|' . $domain;
        
        // Auto-flush if buffer is full
        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }
    
    /**
     * Flush buffer to disk
     */
    public function flush() {
        if (empty($this->buffer)) {
            return true;
        }
        
        $data = implode("\n", $this->buffer) . "\n";
        $result = file_put_contents(
            $this->storagePath, 
            $data, 
            FILE_APPEND | LOCK_EX
        );
        
        $this->buffer = [];
        return $result !== false;
    }
    
    /**
     * Read all stored emails
     * @return array
     */
    public function readAll() {
        if (!file_exists($this->storagePath)) {
            return [];
        }
        
        $content = file_get_contents($this->storagePath);
        if ($content === false) {
            return [];
        }
        
        $lines = explode("\n", trim($content));
        $emails = [];
        
        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }
            
            $parts = explode('|', $line);
            if (count($parts) === 2) {
                $emails[] = [
                    'hash' => $parts[0],
                    'domain' => $parts[1]
                ];
            }
        }
        
        return $emails;
    }
    
    /**
     * Count stored emails
     * @return int
     */
    public function count() {
        if (!file_exists($this->storagePath)) {
            return 0;
        }
        
        $count = 0;
        $handle = fopen($this->storagePath, 'r');
        if ($handle) {
            while (fgets($handle) !== false) {
                $count++;
            }
            fclose($handle);
        }
        
        return $count;
    }
    
    /**
     * Clear storage
     */
    public function clear() {
        $this->buffer = [];
        if (file_exists($this->storagePath)) {
            unlink($this->storagePath);
        }
    }
    
    /**
     * Get storage file path
     * @return string
     */
    public function getPath() {
        return $this->storagePath;
    }
}
