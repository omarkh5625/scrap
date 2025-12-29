# Worker Status UI Enhancement - Visual Guide

## Overview
The Worker Status page has been completely redesigned to provide real-time monitoring and statistics tracking, similar to professional mail sender systems.

## Page Layout

### 1. Statistics Dashboard (Top Section)
Four stat cards displaying:
- 🚀 **Active Workers** - Count of workers currently processing jobs
- 💤 **Idle Workers** - Count of workers waiting for jobs
- 📄 **Pages Processed** - Total search result pages scraped by all workers
- 📧 **Emails Extracted** - Total emails found by all workers

### 2. Active Workers Table (Middle Section)
Real-time worker monitoring table with columns:
- **Worker** - Worker name/ID
- **Status** - Current status (idle/running/stopped) with color-coded badge
- **Current Job** - Link to the job being processed (if any)
- **Pages** - Number of pages this worker has processed
- **Emails** - Number of emails this worker has extracted
- **Runtime** - How long this worker has been running (formatted as s/m/h)
- **Last Heartbeat** - Timestamp of last worker update

Features:
- Auto-refresh every 3 seconds
- Color-coded status badges
- Animated status indicator showing system activity
- Clickable job IDs linking to job results

### 3. Performance Metrics (Bottom Section)
Additional metrics display:
- **Average Runtime** - Mean runtime across all workers
- **Last Update** - Timestamp showing when data was last refreshed

### 4. Worker Instructions
Command-line instructions for starting new workers manually

## Real-Time Updates

The page uses JavaScript to automatically refresh data every 3 seconds:
- Fetches worker statistics from `/api?action=worker-stats`
- Fetches worker list from `/api?action=workers`
- Updates UI without page reload
- Animated status indicator pulses while workers are active

## Status Indicators

### Status Dot (Top of Active Workers Table)
- **Green (pulsing)** - One or more workers active
- **Yellow** - Workers idle but registered
- **Gray** - No workers registered

### Status Badges (In Table)
- **Running** - Blue badge, worker processing a job
- **Idle** - Gray badge, worker waiting for work
- **Stopped** - Red badge, worker terminated

## API Endpoints

### GET /api?action=worker-stats
Returns system-wide statistics:
```json
{
  "active_workers": 5,
  "idle_workers": 2,
  "total_pages": 150,
  "total_emails": 1234,
  "avg_runtime": 3600
}
```

### GET /api?action=workers
Returns array of all workers:
```json
[
  {
    "id": 1,
    "worker_name": "worker-12345-1234567890",
    "status": "running",
    "current_job_id": 5,
    "last_heartbeat": "2024-01-15 10:30:45",
    "created_at": "2024-01-15 10:00:00",
    "pages_processed": 25,
    "emails_extracted": 150,
    "runtime_seconds": 1845
  }
]
```

## Usage Scenarios

### Monitoring Active Jobs
1. Navigate to Workers page
2. View active workers count in stats dashboard
3. Check which jobs are being processed in the table
4. Click job ID to see extracted emails

### Tracking Performance
1. Monitor pages processed to see extraction progress
2. Check emails extracted for productivity metrics
3. Review runtime to identify slow workers
4. Use average runtime for capacity planning

### Troubleshooting
1. Check last heartbeat to identify stalled workers
2. Verify status badges show expected states
3. Monitor active worker count against spawned workers
4. Review individual worker stats for anomalies

## Technical Details

### Database Schema
Workers table includes tracking fields:
- `pages_processed` - Cumulative page count
- `emails_extracted` - Cumulative email count
- `runtime_seconds` - Calculated as TIMESTAMPDIFF from created_at

### Worker Registration
- Each worker registers on startup
- Receives unique worker_id
- Updates heartbeat regularly
- Tracks statistics per operation

### Statistics Tracking
- **Pages**: Incremented after each search page processed
- **Emails**: Incremented by count of emails extracted per page
- **Runtime**: Auto-calculated on each heartbeat update

### Performance
- Minimal database overhead (single query per metric)
- Efficient SQL with aggregate functions
- Auto-refresh without full page reload
- Responsive layout adapts to screen size

## Benefits

1. **Real-Time Visibility** - See system activity instantly
2. **Performance Monitoring** - Track worker efficiency
3. **Resource Planning** - Understand system capacity
4. **Troubleshooting** - Identify issues quickly
5. **Professional UI** - Clean, modern interface
6. **No CLI Required** - Monitor workers from web browser
7. **Historical Data** - Cumulative statistics per worker
8. **Live Updates** - Auto-refresh keeps data current

## Comparison to Previous Version

### Before
- Simple worker list
- Only name, status, job ID, heartbeat
- No statistics
- Manual refresh required

### After
- Comprehensive dashboard with 4 key metrics
- Detailed worker table with 7 columns
- Real-time statistics tracking
- Auto-refresh every 3 seconds
- Performance metrics section
- Animated status indicators
- Professional styling
- Better data visualization

## Mobile Responsive
The UI adapts to smaller screens:
- Stats cards stack vertically
- Table scrolls horizontally if needed
- Metrics grid adjusts to single column
- Touch-friendly interface
