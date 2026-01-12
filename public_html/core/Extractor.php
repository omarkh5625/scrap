<?php

require_once __DIR__ . '/PageFilter.php';
require_once __DIR__ . '/EmailHasher.php';

/**
 * Extractor - High-performance parallel HTTP client with email extraction
 */
class Extractor {
    
    private $maxParallel;
    private $timeout;
    private $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    ];
    
    public function __construct($maxParallel = 240, $timeout = 10) {
        $this->maxParallel = $maxParallel;
        $this->timeout = $timeout;
    }
    
    /**
     * Fetch multiple URLs in parallel using curl_multi
     * @param array $urls
     * @param callable $callback Function to call for each completed request
     * @return array Results
     */
    public function fetchParallel($urls, $callback = null) {
        $results = [];
        $chunks = array_chunk($urls, $this->maxParallel);
        
        foreach ($chunks as $urlChunk) {
            $chunkResults = $this->fetchChunk($urlChunk, $callback);
            $results = array_merge($results, $chunkResults);
        }
        
        return $results;
    }
    
    /**
     * Fetch a chunk of URLs in parallel
     * @param array $urls
     * @param callable $callback
     * @return array
     */
    private function fetchChunk($urls, $callback = null) {
        $multiHandle = curl_multi_init();
        $handles = [];
        $results = [];
        
        // Set curl_multi options for better performance
        if (defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')) {
            curl_multi_setopt($multiHandle, CURLMOPT_MAX_TOTAL_CONNECTIONS, $this->maxParallel);
        }
        if (defined('CURLMOPT_MAXCONNECTS')) {
            curl_multi_setopt($multiHandle, CURLMOPT_MAXCONNECTS, $this->maxParallel);
        }
        
        // Create curl handles
        foreach ($urls as $url) {
            $ch = $this->createHandle($url);
            curl_multi_add_handle($multiHandle, $ch);
            $handles[(int)$ch] = ['url' => $url, 'handle' => $ch];
        }
        
        // Execute requests
        $active = null;
        do {
            $mrc = curl_multi_exec($multiHandle, $active);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM);
        
        while ($active && $mrc === CURLM_OK) {
            if (curl_multi_select($multiHandle) === -1) {
                usleep(100);
            }
            
            do {
                $mrc = curl_multi_exec($multiHandle, $active);
            } while ($mrc === CURLM_CALL_MULTI_PERFORM);
        }
        
        // Collect results
        foreach ($handles as $key => $handleData) {
            $ch = $handleData['handle'];
            $url = $handleData['url'];
            
            $content = curl_multi_getcontent($ch);
            $info = curl_getinfo($ch);
            $error = curl_error($ch);
            
            $result = [
                'url' => $url,
                'content' => $content,
                'http_code' => $info['http_code'],
                'content_type' => $info['content_type'] ?? '',
                'error' => $error,
                'success' => $info['http_code'] === 200 && empty($error)
            ];
            
            // Apply page filter
            if ($result['success']) {
                if (!PageFilter::isValid($content, $result['content_type'])) {
                    $result['success'] = false;
                    $result['error'] = 'Page size or content type invalid';
                }
            }
            
            // Extract emails if successful
            if ($result['success']) {
                $result['emails'] = EmailHasher::extractEmails($content);
            } else {
                $result['emails'] = [];
            }
            
            // Call callback if provided
            if ($callback && is_callable($callback)) {
                $callback($result);
            }
            
            $results[] = $result;
            
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }
        
        curl_multi_close($multiHandle);
        
        return $results;
    }
    
    /**
     * Create optimized curl handle
     * @param string $url
     * @return resource
     */
    private function createHandle($url) {
        $ch = curl_init($url);
        
        // Basic options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        
        // SSL options - NO VERIFICATION for performance
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        // TCP optimizations
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
        curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 120);
        curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 60);
        
        // HTTP/2 support if available
        if (defined('CURL_HTTP_VERSION_2_0')) {
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
        }
        
        // Compression
        curl_setopt($ch, CURLOPT_ENCODING, '');
        
        // Random user agent
        $userAgent = $this->userAgents[array_rand($this->userAgents)];
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        
        // Headers
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
            'Connection: keep-alive'
        ]);
        
        return $ch;
    }
    
    /**
     * Fetch single URL
     * @param string $url
     * @return array
     */
    public function fetch($url) {
        $results = $this->fetchParallel([$url]);
        return $results[0] ?? null;
    }
}
