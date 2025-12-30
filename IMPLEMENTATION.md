# Parallel Worker Distribution System - Implementation Documentation

## Overview
This implementation enables high-speed parallel email processing using a distributed worker system that can process **1,000,000 emails in ≤10 minutes**.

## Core Formula
**50 workers per 1000 emails** (20 emails per worker)

### Examples
- 1,000 emails → 50 workers
- 10,000 emails → 500 workers
- 100,000 emails → 1,000 workers (capped)
- 1,000,000 emails → 1,000 workers (capped)

## Key Features

### 1. Worker Calculation (`calculateOptimalWorkerCount`)
```php
Formula: (emails / 1000) × 50 = workers
Max cap: 1,000 workers
```

**Location:** `app.php` - Worker class

### 2. Dynamic ETA Calculation (`calculateETA`)
Real-time estimation based on:
- Current processing rate (emails/minute)
- Elapsed time since job start
- Remaining emails to process

**Returns:**
- `eta_seconds`: Time remaining in seconds
- `eta_formatted`: Human-readable format (e.g., "2h 15m 30s")
- `emails_per_minute`: Current processing rate
- `elapsed_formatted`: Time since job started
- `remaining_emails`: Emails yet to be processed

**Location:** `app.php` - Worker class

### 3. System Resource Monitoring (`getSystemResources`)
Tracks:
- Memory usage (current and peak)
- Memory usage percentage
- CPU load average (1min, 5min, 15min)
- PHP memory limit

**Location:** `app.php` - Worker class

## API Endpoints

### Job ETA Information
```
GET ?page=api&action=job-eta&job_id={id}
```
Returns ETA and progress information for a specific job.

### System Resources
```
GET ?page=api&action=system-resources
```
Returns current system resource usage (RAM and CPU).

### Job Worker Status (Enhanced)
```
GET ?page=api&action=job-worker-status&job_id={id}
```
Returns job status including ETA information.

## UI Components

### Live Progress Widget
Displays in real-time:
- Progress bar with percentage
- Emails collected / target
- Active worker count
- Job status
- **ETA** (estimated time to completion)
- **Elapsed time**
- **Processing rate** (emails/min)
- **Remaining emails**

### System Resource Dashboard
Shows on Workers page:
- Memory usage (MB)
- Memory usage percentage
- CPU load average
- Peak memory usage

## Performance Targets

### 1,000,000 Email Job
- **Workers:** 1,000 (capped)
- **Emails per worker:** 1,000
- **Theoretical time:** ~3.5 minutes
- **Target:** ≤10 minutes ✓

### Assumptions
- API rate limit: 0.1s per request
- ~10 emails per API call
- Deep scraping: ~2s per URL
- Parallel processing with curl_multi (100 connections)

## Resource Requirements

### Memory
- **Per worker:** ~10 MB
- **1000 workers:** ~10 GB
- **Recommendation:** Set `memory_limit` to at least 512M for production

### CPU
- Workers are I/O bound (minimal CPU usage)
- Parallel execution via async/background processes
- Multi-core CPU recommended for optimal performance

## Architecture

### Worker Spawning Flow
1. User creates job via UI
2. System calculates optimal worker count using formula
3. Job queue items created (< 200ms)
4. Workers spawn asynchronously in background
5. Each worker processes queue items in parallel
6. Progress updates every 3 seconds via polling

### Parallel Processing
- **Queue-based:** Jobs split into chunks
- **Concurrent:** Workers process simultaneously
- **Bulk operations:** Database inserts in batches
- **curl_multi:** Up to 100 parallel HTTP requests per worker
- **BloomFilter:** In-memory cache (10K items) for deduplication

## Configuration

### Worker Settings
- `AUTO_MAX_WORKERS`: 1000 (maximum workers)
- `WORKERS_PER_1000_EMAILS`: 50
- `OPTIMAL_RESULTS_PER_WORKER`: 20

### Performance Settings
- `DEFAULT_RATE_LIMIT`: 0.1s (API request delay)
- `memory_limit`: 512M (recommended)
- `max_execution_time`: 600 (10 minutes)

## Testing

### Run Worker Calculation Test
```bash
php test_worker_calculation.php
```

Tests the formula with various email counts and validates:
- Worker count calculation
- Distribution logic
- Performance projections
- Resource estimates

## Monitoring

### Real-Time Metrics
- Active/idle workers
- Queue status (pending/processing/completed)
- Pages processed
- Emails extracted
- Processing rate (emails/min)
- System resources (RAM/CPU)

### Error Tracking
- Worker errors logged to `worker_errors` table
- Stale worker detection (timeout: 5 minutes)
- Error resolution interface in UI

## Optimizations

1. **Non-blocking I/O:** FastCGI finish request
2. **Parallel HTTP:** curl_multi with 100 connections
3. **Connection reuse:** HTTP keep-alive, HTTP/2
4. **Memory caching:** BloomFilter for duplicates
5. **Bulk operations:** Batch database inserts
6. **Dynamic scaling:** Auto-calculate optimal workers

## Notes

- Workers are capped at 1000 to prevent resource exhaustion
- For jobs > 50,000 emails, each worker handles more emails
- System automatically balances load across workers
- Progress updates don't block UI (async polling)
- Works in environments with/without exec() function

## Future Enhancements

Potential improvements:
- Dynamic worker count adjustment based on system resources
- Worker pool management (reuse workers across jobs)
- Distributed processing across multiple servers
- Advanced load balancing algorithms
- Real-time resource throttling
