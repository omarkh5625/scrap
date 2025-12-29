<?php

/**
 * Bloom Filter implementation for duplicate email detection
 * Uses a bit array and multiple hash functions
 */
class BloomFilter {
    private $bitArray;
    private $size;
    private $hashCount;
    private $filePath;
    
    public function __construct($expectedElements = 1000000, $falsePositiveRate = 0.01, $filePath = null) {
        $this->filePath = $filePath ?? __DIR__ . '/../storage/bloom.bin';
        
        // Calculate optimal size and hash count
        $this->size = (int) ceil(
            ($expectedElements * log($falsePositiveRate)) / 
            log(1 / pow(2, log(2)))
        );
        $this->hashCount = (int) round(($this->size / $expectedElements) * log(2));
        
        $this->load();
    }
    
    /**
     * Add an email hash to the filter
     */
    public function add($emailHash) {
        $hashes = $this->getHashes($emailHash);
        foreach ($hashes as $hash) {
            $byteIndex = (int)($hash / 8);
            $bitIndex = $hash % 8;
            
            if (!isset($this->bitArray[$byteIndex])) {
                $this->bitArray[$byteIndex] = 0;
            }
            $this->bitArray[$byteIndex] |= (1 << $bitIndex);
        }
    }
    
    /**
     * Check if an email hash might exist in the filter
     */
    public function contains($emailHash) {
        $hashes = $this->getHashes($emailHash);
        foreach ($hashes as $hash) {
            $byteIndex = (int)($hash / 8);
            $bitIndex = $hash % 8;
            
            if (!isset($this->bitArray[$byteIndex]) || 
                !($this->bitArray[$byteIndex] & (1 << $bitIndex))) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Generate multiple hash values using MurmurHash-like approach
     */
    private function getHashes($data) {
        $hashes = [];
        $hash1 = $this->murmurHash3($data, 0) % $this->size;
        $hash2 = $this->murmurHash3($data, $hash1) % $this->size;
        
        for ($i = 0; $i < $this->hashCount; $i++) {
            $hashes[] = abs(($hash1 + ($i * $hash2)) % $this->size);
        }
        
        return $hashes;
    }
    
    /**
     * Simple MurmurHash3-inspired hash function
     */
    private function murmurHash3($key, $seed) {
        $key = (string)$key;
        $len = strlen($key);
        $h = $seed;
        $c1 = 0xcc9e2d51;
        $c2 = 0x1b873593;
        
        for ($i = 0; $i < $len; $i++) {
            $k = ord($key[$i]);
            $k = ($k * $c1) & 0xFFFFFFFF;
            $k = (($k << 15) | ($k >> 17)) & 0xFFFFFFFF;
            $k = ($k * $c2) & 0xFFFFFFFF;
            
            $h ^= $k;
            $h = (($h << 13) | ($h >> 19)) & 0xFFFFFFFF;
            $h = ($h * 5 + 0xe6546b64) & 0xFFFFFFFF;
        }
        
        $h ^= $len;
        $h ^= ($h >> 16);
        $h = ($h * 0x85ebca6b) & 0xFFFFFFFF;
        $h ^= ($h >> 13);
        $h = ($h * 0xc2b2ae35) & 0xFFFFFFFF;
        $h ^= ($h >> 16);
        
        return $h;
    }
    
    /**
     * Load bloom filter from disk
     */
    public function load() {
        if (file_exists($this->filePath)) {
            $data = file_get_contents($this->filePath);
            if ($data !== false && strlen($data) > 0) {
                $unpacked = unpack('C*', $data);
                // Convert to 0-indexed array
                $this->bitArray = [];
                foreach ($unpacked as $index => $value) {
                    $this->bitArray[$index - 1] = $value;
                }
                return true;
            }
        }
        $this->bitArray = [];
        return false;
    }
    
    /**
     * Save bloom filter to disk
     */
    public function save() {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // Convert array to binary
        $bytes = [];
        if (!empty($this->bitArray)) {
            $maxIndex = max(array_keys($this->bitArray));
            for ($i = 0; $i <= $maxIndex; $i++) {
                $bytes[] = $this->bitArray[$i] ?? 0;
            }
        } else {
            $bytes = [0];
        }
        
        $binary = pack('C*', ...$bytes);
        return file_put_contents($this->filePath, $binary, LOCK_EX) !== false;
    }
    
    /**
     * Clear the bloom filter
     */
    public function clear() {
        $this->bitArray = [];
        if (file_exists($this->filePath)) {
            unlink($this->filePath);
        }
    }
}
