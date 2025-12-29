# Testing Guide for 300-Worker Implementation

## Prerequisites
- MySQL database configured
- PHP 8.0+ installed
- Web server (Apache/Nginx) or PHP built-in server
- Serper.dev API key for testing

## Setup Instructions

1. **Database Setup**:
   ```bash
   # Access the setup wizard
   http://localhost/app.php?page=setup
   ```
   
   Enter your database credentials:
   - Host: localhost
   - Database: scrap_db
   - Username: your_db_user
   - Password: your_db_password
   - Admin username: admin
   - Admin password: your_secure_password

2. **Login**:
   ```bash
   http://localhost/app.php?page=login
   ```

## Test Cases

### Test 1: Basic Job Creation (AJAX)

**Objective**: Verify AJAX job creation works without UI blocking

**Steps**:
1. Navigate to Dashboard or New Job page
2. Fill in the form:
   - Query: "real estate agents california"
   - API Key: [your Serper.dev key]
   - Max Emails: 100
   - Filter: Business Only
3. Click "🚀 Start Extraction"
4. Observe:
   - Loading overlay appears
   - Form button shows "⏳ Creating..."
   - Success message appears in ~1 second
   - Page redirects to results

**Expected Result**:
- ✅ UI remains responsive
- ✅ No browser "loading" indicator
- ✅ Success message shows job ID and worker count
- ✅ Redirect happens after 2 seconds

**Check Browser Console**:
- No JavaScript errors
- Should see successful API response

**Check Server Logs** (`php_errors.log`):
```
autoSpawnWorkers: Attempting to spawn X workers
Worker registration logs
Background processing logs
```

### Test 2: Large Job (300 Workers)

**Objective**: Test maximum worker capacity

**Steps**:
1. Create a job with:
   - Query: "restaurants contact email"
   - Max Emails: 30000 (triggers 300 workers)
2. Click "Start Extraction"
3. Navigate to Workers page immediately

**Expected Result**:
- ✅ Job created successfully
- ✅ Worker count calculation: ~300 workers
- ✅ Workers page shows workers starting
- ✅ Queue items created (should see ~300 pending items)

**Monitor**:
```bash
# Watch worker status
tail -f php_errors.log | grep "Worker"

# Check queue
SELECT COUNT(*) FROM job_queue WHERE status='pending';
```

### Test 3: Dashboard Real-Time Updates

**Objective**: Verify live statistics work

**Steps**:
1. Go to Dashboard
2. Create a small job (100 emails)
3. Watch the stats cards update

**Expected Result**:
- ✅ Active Workers count increases
- ✅ Emails extracted counter updates
- ✅ Emails/Min rate shows
- ✅ Updates happen every ~3 seconds
- ✅ Progress widget shows job status

### Test 4: Concurrent Job Creation

**Objective**: Test system handles multiple jobs

**Steps**:
1. Open Dashboard in 2 browser tabs
2. Create Job 1 in tab 1 (1000 emails)
3. Immediately create Job 2 in tab 2 (1000 emails)
4. Watch both progress

**Expected Result**:
- ✅ Both jobs created successfully
- ✅ Workers distributed across both jobs
- ✅ No database conflicts
- ✅ Both show progress independently

### Test 5: Error Handling

**Objective**: Test error scenarios

**Test 5a: Invalid API Key**
1. Enter invalid API key
2. Submit form

**Expected**: Error alert appears, button re-enabled

**Test 5b: Empty Required Fields**
1. Leave query or API key empty
2. Submit form

**Expected**: HTML5 validation prevents submission

**Test 5c: Network Error**
1. Disable network in browser DevTools
2. Submit form

**Expected**: Catch error, show friendly message

### Test 6: Worker Status Monitoring

**Objective**: Verify workers page shows live data

**Steps**:
1. Create a job
2. Navigate to Workers page (`?page=workers`)
3. Observe real-time updates

**Expected Result**:
- ✅ Active Workers count shown
- ✅ Worker table updates every 3 seconds
- ✅ Pages processed increments
- ✅ Emails extracted increments
- ✅ Last heartbeat updates
- ✅ Queue statistics show progress

### Test 7: Job Results Page

**Objective**: Verify results page live updates

**Steps**:
1. Create a job
2. Immediately go to results page
3. Watch progress bar

**Expected Result**:
- ✅ Progress bar animates from 0% to 100%
- ✅ Emails appear in list
- ✅ Worker status widget updates
- ✅ Completion percentage updates

### Test 8: Mobile Responsiveness

**Objective**: Test UI on mobile devices

**Steps**:
1. Open app on mobile or resize browser
2. Create a job
3. Check all pages

**Expected Result**:
- ✅ Forms are usable
- ✅ Loading overlay displays correctly
- ✅ Stats cards stack vertically
- ✅ Tables scroll horizontally

## Performance Benchmarks

### Expected Performance (with good API key and network):

| Job Size | Workers | Expected Time | Emails/Min |
|----------|---------|---------------|------------|
| 100      | 1       | 1-2 min       | 50-100     |
| 1,000    | 10      | 5-10 min      | 100-200    |
| 10,000   | 100     | 30-60 min     | 200-300    |
| 30,000   | 300     | 90-180 min    | 200-300    |

*Note: Actual performance depends on API rate limits, network speed, and data availability*

## Debugging Tips

### Check if Workers Are Running
```bash
# View error log
tail -f php_errors.log

# Check active workers in database
mysql> SELECT * FROM workers WHERE status='running';

# Check queue status
mysql> SELECT status, COUNT(*) FROM job_queue GROUP BY status;
```

### Common Issues

**Issue**: Workers not starting
- **Solution**: Check `exec()` is enabled in php.ini
- **Check**: Look for "autoSpawnWorkers" in logs

**Issue**: API errors
- **Solution**: Verify Serper.dev API key is valid
- **Check**: Look for "API error" in worker logs

**Issue**: UI not updating
- **Solution**: Check browser console for errors
- **Check**: Verify API endpoints return valid JSON

**Issue**: Database errors
- **Solution**: Check MySQL connection
- **Check**: Verify all tables exist

### Enable Detailed Logging

In `app.php`, ensure error logging is enabled:
```php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
```

## API Testing

Test API endpoints directly:

### 1. Create Job
```bash
curl -X POST 'http://localhost/app.php?page=api&action=create-job' \
  -d 'query=test' \
  -d 'api_key=YOUR_KEY' \
  -d 'max_results=100'
```

### 2. Worker Stats
```bash
curl 'http://localhost/app.php?page=api&action=worker-stats'
```

### 3. Job Status
```bash
curl 'http://localhost/app.php?page=api&action=job-worker-status&job_id=1'
```

## Success Criteria

The implementation is successful if:

- [x] Jobs can be created without UI blocking
- [x] Up to 300 workers can be spawned
- [x] Workers start immediately after job creation
- [x] UI remains responsive during processing
- [x] Real-time updates work on all pages
- [x] Error handling is graceful
- [x] No database deadlocks or conflicts
- [x] System handles concurrent jobs
- [x] All AJAX endpoints return valid responses

## Troubleshooting

### Workers Not Spawning

1. Check if `exec()` is available:
   ```bash
   php -r "echo function_exists('exec') ? 'YES' : 'NO';"
   ```

2. Check disabled functions:
   ```bash
   php -i | grep disable_functions
   ```

3. Check if background processing is working:
   - Look for "processWorkersInBackground" in logs
   - Verify `fastcgi_finish_request` is available

### API Endpoints Not Working

1. Check URL rewriting is disabled (using query params)
2. Verify session is started
3. Check authentication is valid
4. Verify Content-Type headers

### Database Issues

1. Check connection:
   ```bash
   mysql -u username -p -e "USE scrap_db; SHOW TABLES;"
   ```

2. Verify migrations ran:
   ```sql
   SHOW TABLES LIKE 'job_queue';
   SHOW TABLES LIKE 'worker_errors';
   ```

3. Check for locks:
   ```sql
   SHOW PROCESSLIST;
   ```

## Cleanup After Testing

```sql
-- Clear test data
TRUNCATE TABLE job_queue;
TRUNCATE TABLE emails;
TRUNCATE TABLE bloomfilter;
DELETE FROM jobs WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY);
DELETE FROM workers WHERE status != 'running';
```

## Support

For issues or questions:
- Check `php_errors.log`
- Review browser console
- Examine network tab in DevTools
- Check database query logs

Happy Testing! 🚀
