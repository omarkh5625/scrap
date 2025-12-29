# Migration Guide: HTTP Workers → Queue Workers

## What Changed

### Before (HTTP-Based)
```
User clicks "Create Job"
     ↓
System spawns 5 HTTP workers immediately
     ↓
Each worker makes HTTP request to itself
     ↓
Workers process in background (hopefully)
     ↓
Problem: Doesn't work reliably on cPanel
```

### After (Queue-Based)
```
User clicks "Create Job"
     ↓
System creates 5 chunks in job_queue table
     ↓
Job status: "pending"
     ↓
User starts CLI workers: php app.php worker-1
     ↓
Workers poll queue → pick up chunks → process
     ↓
Works reliably everywhere!
```

---

## For Users

### Old Workflow
1. Create job
2. ❌ Hope workers start automatically
3. ❌ No visibility if workers are running
4. ❌ Didn't work on cPanel

### New Workflow
1. Create job
2. ✅ Start CLI workers explicitly
3. ✅ See workers on Workers page
4. ✅ Works on cPanel!

### Starting Workers

**Terminal:**
```bash
php app.php worker-1
```

**Background:**
```bash
php app.php worker-1 > /dev/null 2>&1 &
```

**Multiple workers:**
```bash
php app.php worker-1 &
php app.php worker-2 &
php app.php worker-3 &
```

**cPanel cron (every 5 minutes):**
```bash
*/5 * * * * cd /home/user/public_html && php app.php worker-cron >> /dev/null 2>&1
```

---

## For Developers

### Database Changes

**New table:**
```sql
CREATE TABLE job_queue (
    id INT PRIMARY KEY,
    job_id INT NOT NULL,
    start_offset INT,
    max_results INT,
    status ENUM('pending', 'processing', 'completed', 'failed'),
    worker_id INT,
    created_at TIMESTAMP,
    started_at TIMESTAMP,
    completed_at TIMESTAMP
);
```

**Migration handled automatically** - existing installations will be migrated.

### Code Changes

**Worker::getNextJob():**
- Now checks `job_queue` first
- Falls back to old method for compatibility
- Returns job with queue info if from queue

**Worker::processJob():**
- Handles queue-based chunks
- Updates queue item status
- Calculates job progress from queue

**Job Creation:**
- `spawnParallelWorkers()` now creates queue items
- No HTTP/exec calls
- Job stays "pending" until worker picks it up

### API Changes

**New endpoint:**
```
GET ?page=api&action=queue-stats

Returns:
{
  "pending": 15,
  "processing": 3,
  "completed": 82
}
```

---

## UI Changes

### Workers Page - Before
- 4 stat cards
- Worker table with 4 columns
- No queue visibility

### Workers Page - After
- 8 stat cards
- Worker table with 7 columns
- Queue metrics displayed:
  - Pending chunks
  - Processing chunks
  - Completed chunks
  - Processing rate %

### New Job Page - Before
- "Start Processing Immediately" button
- Message: "Workers spawn automatically"

### New Job Page - After
- "Create Job & Queue for Processing" button
- Message: "Start CLI workers to process"
- Instructions: `php app.php worker-1`

---

## Troubleshooting

### Jobs stay "pending"
**Problem:** No workers running
**Solution:** Start at least one worker:
```bash
php app.php worker-1
```

### Progress not updating
**Problem:** Workers not processing
**Check:**
1. Are workers running? (Workers page)
2. Are chunks pending? (Queue stats)
3. Check error log: `tail -f php_errors.log`

### Want faster processing
**Solutions:**
1. Start more workers
2. Each worker processes one chunk at a time
3. More workers = more parallel processing

---

## Benefits Summary

### Reliability
✅ Works on cPanel and shared hosting
✅ No dependency on exec() or HTTP
✅ Standard queue pattern
✅ Work preserved if worker stops

### Visibility
✅ See pending work in queue
✅ Track which worker processes what
✅ Monitor queue processing rate
✅ Real-time statistics

### Control
✅ Start/stop workers anytime
✅ Scale workers up or down
✅ No automatic spawning
✅ Predictable behavior

### Compatibility
✅ All PHP environments
✅ Shared hosting
✅ cPanel
✅ VPS/Dedicated servers

---

## No Breaking Changes

✅ Existing jobs work (fallback to old method)
✅ Database migrates automatically
✅ API endpoints unchanged (new one added)
✅ Authentication unchanged
✅ Email extraction logic unchanged

Only change: You must **explicitly start workers** now.

This is actually better because:
- You control when workers run
- You see exactly what's happening
- It works reliably everywhere

---

## Questions?

Check:
- README.md - Complete system documentation
- QUEUE_SYSTEM.md - Queue system details
- WORKER_STATUS_UI.md - UI guide
- IMPLEMENTATION_SUMMARY.md - Arabic/English summary

Or create an issue!
