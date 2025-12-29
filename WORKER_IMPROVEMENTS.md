# Worker System Improvements Documentation

## Overview
This document describes the improvements made to the worker system for the email extraction application.

## New Features

### 1. Worker Error Tracking System

#### Database Schema
- **worker_errors table**: Stores all worker errors with detailed information
  - `id`: Error ID
  - `worker_id`: Associated worker
  - `job_id`: Associated job
  - `error_type`: Type of error (e.g., 'api_error', 'processing_error', 'critical_error')
  - `error_message`: Human-readable error message
  - `error_details`: Stack trace or additional details
  - `severity`: 'warning', 'error', or 'critical'
  - `resolved`: Boolean flag for resolved errors
  - `created_at`: Timestamp

- **workers table additions**:
  - `error_count`: Running count of errors per worker
  - `last_error`: Last error message for quick reference

#### Error Logging API
```php
Worker::logError(
    int $workerId,
    ?int $jobId,
    string $errorType,
    string $errorMessage,
    ?string $errorDetails = null,
    string $severity = 'error'
)
```

### 2. Enhanced Error Handling

All worker processing now includes try-catch blocks that:
- Catch exceptions during API calls
- Log processing errors
- Handle critical failures gracefully
- Mark workers as crashed when necessary

### 3. Stale Worker Detection

Automatic detection of workers that have stopped responding:
```php
Worker::detectStaleWorkers(int $timeoutSeconds = 300)
```

Workers are marked as crashed if they:
- Are marked as 'running'
- Haven't sent a heartbeat in 5+ minutes
- Are automatically flagged with a critical error

### 4. Worker Searcher Status UI

New UI section on the Results page showing:
- **Active Workers Count**: Number of workers currently processing the job
- **Emails Collected**: Current count of collected emails
- **Emails Required**: Target number of emails
- **Completion Percentage**: Real-time progress calculation

### 5. Alert System

Visual alerts displayed for:
- Critical errors (red, 🚨)
- Errors (red, ⚠️)
- Warnings (yellow, ⚠️)

Features:
- Real-time display of unresolved errors
- Clickable "Resolve" button to mark errors as handled
- Shows worker name, job info, and timestamp
- Auto-refreshes every 3 seconds

### 6. API Endpoints

#### Get Job Worker Status
```
GET ?page=api&action=job-worker-status&job_id={jobId}
```

Returns:
```json
{
  "job": {...},
  "active_workers": 2,
  "workers": [...],
  "emails_collected": 45,
  "emails_required": 100,
  "completion_percentage": 45.0,
  "recent_errors": [...],
  "stale_workers": [...]
}
```

#### Get Worker Errors
```
GET ?page=api&action=worker-errors&unresolved_only=1
```

Returns array of error objects with:
- Error details
- Worker name
- Job query
- Timestamp
- Severity level

#### Resolve Error
```
POST ?page=api&action=resolve-error
Body: error_id={errorId}
```

### 7. Workers Page Enhancements

Added new section "System Alerts & Errors" showing:
- All unresolved errors across all workers
- Color-coded by severity
- Detailed error information
- One-click resolution

## Error Types and Causes

### Detected Error Types:

1. **job_not_found**
   - Severity: error
   - Cause: Job was deleted or doesn't exist
   
2. **api_error**
   - Severity: warning
   - Cause: Search API returned no data
   - Possible reasons: API quota exceeded, network issues
   
3. **no_results**
   - Severity: warning
   - Cause: API response has no organic results
   
4. **processing_error**
   - Severity: warning
   - Cause: Error processing individual search result
   
5. **page_processing_error**
   - Severity: error
   - Cause: Error during page processing loop
   
6. **critical_error**
   - Severity: critical
   - Cause: Unhandled exception in worker
   - Includes full stack trace
   
7. **worker_crash**
   - Severity: critical
   - Cause: Worker stopped sending heartbeats
   - Auto-detected by stale worker monitoring

## Resource Issue Detection

The system can detect and report:

### Memory Issues
- Check PHP memory limit
- Monitor memory usage patterns
- Log out-of-memory errors

### CPU Issues
- Workers timing out
- Processing taking too long
- Rate limiting violations

### Network Issues
- API connection failures
- Timeout errors
- DNS resolution failures

## UI Screenshots

### Results Page - Worker Searcher Status
Shows:
- 4 stat cards with active workers, collected/required emails, and completion %
- Alert section showing any errors
- Active workers details table

### Workers Page - System Alerts
Shows:
- All unresolved errors
- Color-coded alerts
- Resolve buttons
- Worker and job information

## Auto-Refresh Behavior

- Worker status updates every 3 seconds
- Results page reloads every 30 seconds (reduced from 5)
- Stale worker detection runs on every error fetch
- Alerts appear in real-time

## Implementation Benefits

1. **Improved Reliability**: Automatic error detection and recovery
2. **Better Visibility**: Admins can see exactly what's happening with workers
3. **Easier Debugging**: Detailed error logs with stack traces
4. **Proactive Monitoring**: Stale worker detection prevents silent failures
5. **User Experience**: Clear progress indicators and error notifications
6. **Parallel Processing**: Multiple workers can run simultaneously with full tracking
7. **Performance Insights**: Track pages processed and emails extracted per worker

## Migration Safety

All changes include database migrations that:
- Check for existing columns/tables before creating
- Won't break existing installations
- Run automatically on first connection
- Log errors without crashing the application

## Future Enhancements

Potential improvements:
- Email/SMS notifications for critical errors
- Automatic worker restart on crashes
- Performance metrics dashboard
- Historical error analytics
- Worker health scores
- Load balancing across workers
