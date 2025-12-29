# Email Scraper System

## Architecture Overview

### UI/Backend Separation
The system implements a clear separation between UI (user interface) and Backend (data processing) within a single file:

- **GET Requests**: UI rendering only - no heavy processing, instant response
- **POST/API Requests**: Backend processing - data submission, worker spawning, API calls
- **Benefits**: 
  - UI never blocks during backend operations
  - Backend can process in background after sending UI response
  - Clean separation prevents system hanging under load

### 300 Worker Support
The system is optimized to handle up to 300 concurrent workers efficiently:

- **Batch Spawning**: Workers spawn in batches of 50 to prevent resource spikes
- **Staggered Execution**: Small delays between batches ensure system stability
- **Transaction Locking**: Race condition prevention in queue management
- **Automatic Cleanup**: Old workers are cleaned up periodically to prevent database bloat
- **Heartbeat Monitoring**: Workers send heartbeats every few seconds for health tracking

### Performance Features
- Parallel HTTP requests with curl_multi (up to 200 simultaneous)
- Bulk database operations for email insertion
- BloomFilter cache (10K items) for duplicate detection
- Connection reuse with HTTP keep-alive and HTTP/2
- Optimized rate limiting (0.1s default with parallel processing)

### Worker Management
- **Auto-scaling**: System calculates optimal worker count based on job size
- **Error Recovery**: Failed workers don't affect other workers
- **Status Tracking**: Real-time monitoring of active, idle, and stopped workers
- **Crash Detection**: Automatic detection and marking of stale workers