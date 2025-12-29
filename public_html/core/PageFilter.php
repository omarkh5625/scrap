<?php

/**
 * Page Filter - Validates page content size and quality
 */
class PageFilter {
    
    const MIN_SIZE = 2048;      // 2 KB minimum
    const MAX_SIZE = 5242880;   // 5 MB maximum
    
    /**
     * Check if content size is within acceptable range
     * @param string $content
     * @return bool
     */
    public static function isValidSize($content) {
        $size = strlen($content);
        return $size >= self::MIN_SIZE && $size <= self::MAX_SIZE;
    }
    
    /**
     * Check if content type is acceptable
     * @param string $contentType
     * @return bool
     */
    public static function isValidContentType($contentType) {
        $acceptedTypes = [
            'text/html',
            'text/plain',
            'application/xhtml+xml',
            'application/xml'
        ];
        
        foreach ($acceptedTypes as $type) {
            if (stripos($contentType, $type) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Validate both size and content type
     * @param string $content
     * @param string $contentType
     * @return bool
     */
    public static function isValid($content, $contentType = 'text/html') {
        return self::isValidSize($content) && self::isValidContentType($contentType);
    }
    
    /**
     * Get content size in bytes
     * @param string $content
     * @return int
     */
    public static function getSize($content) {
        return strlen($content);
    }
    
    /**
     * Format size for display
     * @param int $bytes
     * @return string
     */
    public static function formatSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
