# Testing & Verification Guide

## Prerequisites

1. PHP 8.0+ installed
2. MySQL database configured
3. System installed via setup wizard
4. At least one job created

## Test Scenarios

### 1. Database Schema Verification

**Test**: Verify new tables and columns exist

```bash
# Connect to MySQL
mysql -u [username] -p [database]

# Check worker_errors table
DESCRIBE worker_errors;

# Expected columns:
# - id, worker_id, job_id, error_type, error_message, 
# - error_details, severity, resolved, created_at

# Check workers table has new columns
DESCRIBE workers;

# Expected new columns:
# - error_count, last_error
```

**Expected Result**: All new tables and columns present

---

### 2. Worker Error Logging

**Test**: Verify errors are logged correctly

**Steps**:
1. Create a job with an invalid API key
2. Start a worker to process it
3. Check for error logs

```bash
# Check error log
tail -f /path/to/app/php_errors.log

# Check database
mysql> SELECT * FROM worker_errors ORDER BY created_at DESC LIMIT 5;
```

**Expected Result**: 
- Error logged to database
- worker_errors table has entry
- Error shows correct severity and type
- Worker error_count incremented

---

### 3. Stale Worker Detection

**Test**: Verify crashed workers are detected

**Steps**:
1. Start a worker
2. Kill the worker process (simulate crash)
3. Wait 5 minutes
4. Check Workers page or worker-errors API

**Expected Result**:
- Worker marked as 'stopped'
- Critical error logged with type 'worker_crash'
- Alert appears on Workers page
- Stale worker appears in API response

---

### 4. UI - Worker Searcher Status

**Test**: Results page shows worker status correctly

**Steps**:
1. Navigate to a running job's results page
2. Observe "Worker Searcher Status" section
3. Check stats update every 3 seconds

**Verify**:
- [ ] Section appears between job details and results
- [ ] Shows 4 stat cards
- [ ] Active workers count is correct
- [ ] Emails collected matches database
- [ ] Completion percentage is accurate
- [ ] Active workers table shows current workers
- [ ] Updates automatically without page refresh

---

### 5. UI - Alert Display

**Test**: Alerts appear correctly

**Steps**:
1. Trigger an error (e.g., invalid API key)
2. Wait for error to be logged
3. Check Results page and Workers page

**Verify Results Page**:
- [ ] Alert appears in Worker Searcher Status section
- [ ] Correct icon (🚨 or ⚠️)
- [ ] Correct color (red/yellow)
- [ ] Shows worker name
- [ ] Shows timestamp
- [ ] "Resolve" button present

**Verify Workers Page**:
- [ ] Alert appears in "System Alerts & Errors" section
- [ ] Shows job query if applicable
- [ ] Shows error details if available
- [ ] Multiple errors display correctly

---

### 6. Alert Resolution

**Test**: Resolving errors works

**Steps**:
1. Find an unresolved error alert
2. Click "Resolve" button
3. Check alert disappears

**Verify**:
- [ ] Alert removes from UI immediately
- [ ] Database updated (resolved = TRUE)
- [ ] Error no longer in unresolved list
- [ ] Can still see in resolved errors (if viewing all)

---

### 7. Parallel Workers

**Test**: Multiple workers process simultaneously

**Steps**:
1. Create a job with 5 workers
2. Check Worker Searcher Status
3. Monitor processing

**Verify**:
- [ ] All 5 workers show as active
- [ ] Each worker processes different offsets
- [ ] Progress updates from all workers
- [ ] No race conditions or duplicates
- [ ] All workers complete successfully

---

### 8. API Endpoints

**Test**: New API endpoints work

#### Test job-worker-status
```bash
curl "http://localhost/app.php?page=api&action=job-worker-status&job_id=1"
```

**Verify Response**:
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

#### Test worker-errors
```bash
curl "http://localhost/app.php?page=api&action=worker-errors&unresolved_only=1"
```

**Verify Response**: Array of error objects

#### Test resolve-error
```bash
curl -X POST "http://localhost/app.php?page=api&action=resolve-error" \
  -d "error_id=1"
```

**Verify Response**: 
```json
{"success": true}
```

---

### 9. Error Types Coverage

**Test**: All error types can be triggered and logged

| Error Type | How to Trigger | Expected Severity |
|-----------|----------------|-------------------|
| job_not_found | Delete job mid-processing | error |
| api_error | Invalid API key | warning |
| no_results | Search query with no results | warning |
| processing_error | Malformed search result | warning |
| page_processing_error | Exception in loop | error |
| critical_error | Unhandled exception | critical |
| worker_crash | Kill worker process | critical |

**Verify**:
- [ ] Each error type logs correctly
- [ ] Severity is appropriate
- [ ] Error messages are descriptive
- [ ] Stack traces included where relevant

---

### 10. Performance & Load

**Test**: System handles many workers and errors

**Steps**:
1. Create job with 20 workers
2. Let them all process simultaneously
3. Monitor system resources
4. Check for slowdowns

**Verify**:
- [ ] UI remains responsive
- [ ] Database queries efficient
- [ ] No memory leaks
- [ ] Updates don't lag
- [ ] Error logging doesn't slow processing

---

### 11. Edge Cases

#### No Workers
**Test**: Pages work when no workers exist

**Verify**:
- [ ] Results page shows "No active workers" message
- [ ] Workers page shows empty state
- [ ] No JavaScript errors

#### No Errors
**Test**: Pages work with no errors

**Verify**:
- [ ] Workers page shows "All systems running smoothly"
- [ ] No empty alert boxes
- [ ] UI looks clean

#### Many Errors
**Test**: Pages work with 50+ errors

**Verify**:
- [ ] Errors display correctly
- [ ] Page doesn't become unresponsive
- [ ] Scrolling works
- [ ] Resolution still works

---

### 12. Browser Compatibility

**Test**: UI works in different browsers

**Browsers to test**:
- [ ] Chrome/Edge (Chromium)
- [ ] Firefox
- [ ] Safari
- [ ] Mobile Chrome
- [ ] Mobile Safari

**Verify**:
- [ ] Alerts display correctly
- [ ] Stats cards align properly
- [ ] Tables are readable
- [ ] Buttons work
- [ ] Auto-refresh works

---

### 13. Migration Safety

**Test**: Existing installations upgrade smoothly

**Steps**:
1. Start with old version (before changes)
2. Deploy new version
3. Load any page

**Verify**:
- [ ] Migrations run automatically
- [ ] No error messages
- [ ] Existing data preserved
- [ ] New features available
- [ ] Old workers still work

---

### 14. Error Recovery

**Test**: System recovers from errors

**Scenarios**:

#### Scenario A: Worker crashes mid-job
**Verify**:
- [ ] Error logged
- [ ] Worker marked as crashed
- [ ] Job continues with other workers
- [ ] Progress still updates

#### Scenario B: All workers crash
**Verify**:
- [ ] All marked as crashed
- [ ] Job status shows issue
- [ ] Can restart workers manually
- [ ] Data not corrupted

#### Scenario C: Database connection lost
**Verify**:
- [ ] Errors logged gracefully
- [ ] No data loss
- [ ] Reconnects when available
- [ ] Users see appropriate message

---

## Automated Testing Commands

### Check Syntax
```bash
php -l app.php
```

### Check Error Log
```bash
tail -100 php_errors.log | grep -i error
```

### Count Active Workers
```bash
mysql -u user -p database -e "SELECT COUNT(*) FROM workers WHERE status='running';"
```

### Count Unresolved Errors
```bash
mysql -u user -p database -e "SELECT COUNT(*) FROM worker_errors WHERE resolved=0;"
```

### Check Latest Errors
```bash
mysql -u user -p database -e "SELECT * FROM worker_errors ORDER BY created_at DESC LIMIT 10;"
```

---

## Performance Benchmarks

### Target Metrics

| Metric | Target | Critical Threshold |
|--------|--------|-------------------|
| Page Load Time | < 1s | < 3s |
| API Response Time | < 200ms | < 1s |
| Worker Heartbeat | Every 1-3s | Every 10s |
| Error Detection | < 10s | < 60s |
| UI Update Rate | Every 3s | Every 10s |
| Memory per Worker | < 128MB | < 512MB |

### Monitoring Commands

```bash
# Check memory usage
ps aux | grep php | awk '{sum+=$6} END {print sum/1024 " MB"}'

# Check MySQL connections
mysql -u user -p -e "SHOW PROCESSLIST;"

# Check slow queries
mysql -u user -p -e "SELECT * FROM information_schema.processlist WHERE time > 5;"
```

---

## Sign-off Checklist

Before marking implementation complete:

- [ ] All database migrations successful
- [ ] No syntax errors in code
- [ ] All API endpoints return expected data
- [ ] UI displays correctly in all tested browsers
- [ ] Worker error logging works
- [ ] Stale worker detection works
- [ ] Alerts display and resolve correctly
- [ ] Parallel workers process simultaneously
- [ ] Performance meets targets
- [ ] No console errors in browser
- [ ] No PHP errors in log (except intentional test errors)
- [ ] Documentation complete
- [ ] Test scenarios pass
- [ ] Edge cases handled
- [ ] Migration tested on clean install

---

## Troubleshooting

### Issue: worker_errors table not created
**Solution**: Check migrations ran. Manually run:
```sql
CREATE TABLE worker_errors (...);
```

### Issue: Alerts not showing
**Solution**: 
- Check browser console for JS errors
- Verify API endpoint returns data
- Check CSS classes applied correctly

### Issue: Workers not marked as crashed
**Solution**:
- Verify detectStaleWorkers timeout (default 300s)
- Check last_heartbeat timestamps
- Ensure API endpoint called regularly

### Issue: High memory usage
**Solution**:
- Reduce number of parallel workers
- Check for memory leaks in loops
- Increase PHP memory_limit if needed

---

## Success Criteria

✅ Implementation is successful when:

1. All tests pass
2. No critical bugs found
3. Performance meets targets
4. UI is intuitive and responsive
5. Error handling is robust
6. Documentation is complete
7. Code review approved (if applicable)
8. User acceptance testing passed
