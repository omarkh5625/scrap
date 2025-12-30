# Email Extraction System - Scrap

## Overview
A high-performance PHP-based email extraction system with SendGrid-inspired asynchronous job processing architecture.

## Key Features

### 🚀 Zero UI Blocking (SendGrid-Inspired Architecture)
- **Instant Response**: Job creation completes in < 200ms
- **Background Processing**: Workers spawn asynchronously after client disconnects
- **Fire-and-Forget**: Trigger-workers endpoint uses proper connection closing
- **Real-time Updates**: Live progress tracking via polling or Server-Sent Events

### ⚡ Performance Optimizations
- **Automatic Worker Scaling**: Up to 1000 workers based on job size
- **Parallel HTTP Requests**: curl_multi for 100+ simultaneous connections
- **Bulk Operations**: Batch database inserts and bulk email validation
- **Smart Caching**: 10K-item BloomFilter in-memory cache
- **Connection Reuse**: HTTP keep-alive and HTTP/2 support

### 🎯 SendGrid-Like User Experience
1. Click "🚀 Start Extraction" - get instant feedback
2. UI responds immediately (never hangs)
3. Workers process in background
4. Live progress updates every 3 seconds
5. Navigate away and come back - job continues running!

## Technical Implementation

### Job Creation Flow
```
User clicks "Start" 
  → AJAX request to create-job endpoint
  → Job created in < 100ms
  → Queue items created in < 100ms
  → Response sent with Content-Length header
  → fastcgi_finish_request() closes connection
  → Session closed to release lock
  → Workers spawned in background (client already disconnected)
```

### Worker Triggering (Fire-and-Forget)
```
create-job success
  → Fire-and-forget AJAX to trigger-workers
  → trigger-workers responds immediately
  → Connection closed with proper headers
  → Workers spawn in background process
```

### Progress Updates (Hybrid Approach)
- **Default**: Efficient polling every 3 seconds
- **Optional**: Server-Sent Events for instant updates
- **Configurable**: Set `USE_SSE = true` in JavaScript

## Installation

1. Upload `app.php` to your web server
2. Navigate to the file in your browser
3. Complete the setup wizard
4. Start extracting emails!

## Requirements
- PHP 8.0+
- MySQL/MariaDB
- cURL extension
- Serper.dev API key

## Architecture Highlights

### Non-Blocking Response Pattern
```php
// Send response immediately
header('Content-Type: application/json');
header('Content-Length: ' . strlen($response));
header('Connection: close');
echo $response;

// Flush to client
ob_end_flush();
flush();

// Close connection (FastCGI)
fastcgi_finish_request();

// Close session
session_write_close();

// NOW do heavy work (client already disconnected)
spawnWorkers();
```

### Real-time Updates via SSE
```php
// Set SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

// Send updates
echo "event: progress\n";
echo "data: " . json_encode($status) . "\n\n";
flush();
```

## Configuration

### Progress Update Method
Choose between polling and Server-Sent Events:
1. Navigate to **Settings** in the web interface
2. Find **Progress Update Method**
3. Select:
   - **Polling (Recommended)**: Updates every 3 seconds, works on all servers
   - **Server-Sent Events (SSE)**: Real-time updates, requires modern browser

**Note**: The setting is stored in the database and applies to all users. No code changes needed.

### Adjust Worker Count
Workers are automatically calculated but can be customized:
```php
// In Worker class (app.php)
private const AUTO_MAX_WORKERS = 1000;
private const OPTIMAL_RESULTS_PER_WORKER = 50;
```

## Performance Metrics
- Job creation: < 200ms
- Worker spawn: Non-blocking (background)
- Progress update interval: 3 seconds
- Parallel connections: 100 per worker
- Maximum workers: 1000
- BloomFilter cache: 10,000 items

## Browser Compatibility
- Chrome/Edge: Full support (SSE + polling)
- Firefox: Full support (SSE + polling)
- Safari: Full support (SSE + polling)
- IE11: Polling only (no SSE)

## License
MIT License - feel free to use and modify!