<?php

/**
 * Email Hasher - Handles email hashing and validation
 */
class EmailHasher {
    
    // Fake/test domains to ignore
    private static $fakeDomains = [
        'example.com', 'example.org', 'example.net',
        'test.com', 'test.org', 'test.net',
        'domain.com', 'domain.org', 'domain.net',
        'sample.com', 'sample.org', 'sample.net',
        'demo.com', 'demo.org', 'demo.net',
        'localhost', 'localhost.localdomain',
        'invalid', 'invalid.invalid',
        'yoursite.com', 'yourdomain.com',
        'email.com', 'mail.com'
    ];
    
    /**
     * Hash an email address using SHA256
     * @param string $email
     * @return string|null Returns hash or null if invalid
     */
    public static function hashEmail($email) {
        $email = strtolower(trim($email));
        
        // Basic validation
        if (!self::isValidEmail($email)) {
            return null;
        }
        
        // Check for fake domain
        if (self::isFakeDomain($email)) {
            return null;
        }
        
        return hash('sha256', $email);
    }
    
    /**
     * Extract domain from email
     * @param string $email
     * @return string|null
     */
    public static function extractDomain($email) {
        $email = strtolower(trim($email));
        $parts = explode('@', $email);
        
        if (count($parts) !== 2) {
            return null;
        }
        
        return $parts[1];
    }
    
    /**
     * Validate email format
     * @param string $email
     * @return bool
     */
    public static function isValidEmail($email) {
        // Quick format check
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        // Must have @ symbol
        if (strpos($email, '@') === false) {
            return false;
        }
        
        // Check domain exists
        $domain = self::extractDomain($email);
        if (empty($domain)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if domain is in fake list
     * @param string $email
     * @return bool
     */
    public static function isFakeDomain($email) {
        $domain = self::extractDomain($email);
        
        if (empty($domain)) {
            return true;
        }
        
        return in_array($domain, self::$fakeDomains, true);
    }
    
    /**
     * Extract emails from text content
     * @param string $content
     * @return array Array of valid emails
     */
    public static function extractEmails($content) {
        // Optimized regex for email extraction
        $pattern = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';
        preg_match_all($pattern, $content, $matches);
        
        $emails = [];
        if (!empty($matches[0])) {
            foreach ($matches[0] as $email) {
                $email = strtolower(trim($email));
                if (self::isValidEmail($email) && !self::isFakeDomain($email)) {
                    $emails[] = $email;
                }
            }
        }
        
        return array_unique($emails);
    }
}
