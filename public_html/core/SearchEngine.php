<?php

/**
 * Search Engine - Generates search URLs for different engines
 */
class SearchEngine {
    
    /**
     * Get search URLs for keywords
     * @param string $keywords
     * @param string $engine google|bing|duckduckgo|yahoo
     * @param int $maxResults
     * @return array
     */
    public static function getSearchUrls($keywords, $engine = 'google', $maxResults = 100) {
        $urls = [];
        $encoded = urlencode($keywords);
        
        switch (strtolower($engine)) {
            case 'google':
                $urls = self::getGoogleUrls($encoded, $maxResults);
                break;
            case 'bing':
                $urls = self::getBingUrls($encoded, $maxResults);
                break;
            case 'duckduckgo':
                $urls = self::getDuckDuckGoUrls($encoded, $maxResults);
                break;
            case 'yahoo':
                $urls = self::getYahooUrls($encoded, $maxResults);
                break;
            default:
                $urls = self::getGoogleUrls($encoded, $maxResults);
        }
        
        return $urls;
    }
    
    /**
     * Parse search results to extract target URLs
     * @param string $content HTML content of search results
     * @param string $engine
     * @return array
     */
    public static function parseSearchResults($content, $engine = 'google') {
        $urls = [];
        
        switch (strtolower($engine)) {
            case 'google':
                $urls = self::parseGoogleResults($content);
                break;
            case 'bing':
                $urls = self::parseBingResults($content);
                break;
            case 'duckduckgo':
                $urls = self::parseDuckDuckGoResults($content);
                break;
            case 'yahoo':
                $urls = self::parseYahooResults($content);
                break;
        }
        
        return array_unique($urls);
    }
    
    /**
     * Get Google search URLs
     */
    private static function getGoogleUrls($encoded, $maxResults) {
        $urls = [];
        $resultsPerPage = 10;
        $pages = ceil($maxResults / $resultsPerPage);
        
        for ($i = 0; $i < $pages; $i++) {
            $start = $i * $resultsPerPage;
            $urls[] = "https://www.google.com/search?q={$encoded}&start={$start}&num={$resultsPerPage}";
        }
        
        return $urls;
    }
    
    /**
     * Get Bing search URLs
     */
    private static function getBingUrls($encoded, $maxResults) {
        $urls = [];
        $resultsPerPage = 10;
        $pages = ceil($maxResults / $resultsPerPage);
        
        for ($i = 0; $i < $pages; $i++) {
            $first = $i * $resultsPerPage + 1;
            $urls[] = "https://www.bing.com/search?q={$encoded}&first={$first}&count={$resultsPerPage}";
        }
        
        return $urls;
    }
    
    /**
     * Get DuckDuckGo search URLs
     */
    private static function getDuckDuckGoUrls($encoded, $maxResults) {
        return ["https://duckduckgo.com/html/?q={$encoded}"];
    }
    
    /**
     * Get Yahoo search URLs
     */
    private static function getYahooUrls($encoded, $maxResults) {
        $urls = [];
        $resultsPerPage = 10;
        $pages = ceil($maxResults / $resultsPerPage);
        
        for ($i = 0; $i < $pages; $i++) {
            $start = $i * $resultsPerPage + 1;
            $urls[] = "https://search.yahoo.com/search?p={$encoded}&b={$start}&pz={$resultsPerPage}";
        }
        
        return $urls;
    }
    
    /**
     * Parse Google results
     */
    private static function parseGoogleResults($content) {
        $urls = [];
        
        // Extract URLs from search results
        preg_match_all('/<a[^>]+href="(\/url\?q=)?([^"&]+)/i', $content, $matches);
        
        if (!empty($matches[2])) {
            foreach ($matches[2] as $url) {
                $url = urldecode($url);
                if (self::isValidResultUrl($url)) {
                    $urls[] = $url;
                }
            }
        }
        
        return $urls;
    }
    
    /**
     * Parse Bing results
     */
    private static function parseBingResults($content) {
        $urls = [];
        
        preg_match_all('/<a[^>]+href="([^"]+)"/i', $content, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $url) {
                if (self::isValidResultUrl($url)) {
                    $urls[] = $url;
                }
            }
        }
        
        return $urls;
    }
    
    /**
     * Parse DuckDuckGo results
     */
    private static function parseDuckDuckGoResults($content) {
        $urls = [];
        
        preg_match_all('/<a[^>]+href="(\/\/duckduckgo\.com\/l\/\?[^"]+)/i', $content, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $url) {
                // Extract actual URL from DuckDuckGo redirect
                parse_str(parse_url($url, PHP_URL_QUERY), $params);
                if (isset($params['uddg'])) {
                    $actualUrl = $params['uddg'];
                    if (self::isValidResultUrl($actualUrl)) {
                        $urls[] = $actualUrl;
                    }
                }
            }
        }
        
        return $urls;
    }
    
    /**
     * Parse Yahoo results
     */
    private static function parseYahooResults($content) {
        return self::parseBingResults($content); // Similar structure
    }
    
    /**
     * Check if URL is valid for scraping
     */
    private static function isValidResultUrl($url) {
        // Must be http or https
        if (!preg_match('/^https?:\/\//i', $url)) {
            return false;
        }
        
        // Exclude search engine URLs
        $excludeDomains = [
            'google.com', 'bing.com', 'yahoo.com', 
            'duckduckgo.com', 'youtube.com', 'facebook.com'
        ];
        
        foreach ($excludeDomains as $domain) {
            if (stripos($url, $domain) !== false) {
                return false;
            }
        }
        
        return true;
    }
}
