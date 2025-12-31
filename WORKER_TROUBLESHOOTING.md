# Worker Troubleshooting Guide

## Problem: Workers Not Starting or Crashing

If you see errors like:
- "Log file not found! Workers may not have started"
- "Only 2 workers started instead of 50"
- "Stale Workers Detected"
- "Worker execution: ✗ Worker did not write log"
- Workers show as crashed in dashboard

Follow these steps to diagnose and fix the issue:

## Step 1: Run the Diagnostic Script

```bash
php worker_diagnostic.php
```

This will check:
- PHP version and configuration
- Required functions (proc_open, exec, curl, etc.)
- File permissions
- Database connectivity
- Ability to spawn test workers
- **PHP binary type (CLI vs FPM)**

**Fix any ✗ or errors** reported by the diagnostic before proceeding.

### Critical: PHP Binary Issue

If diagnostic shows:
```
⚠️  PHP_BINARY points to FPM: /path/to/php-fpm
Worker execution: ✗ Worker did not write log
```

**This means PHP_BINARY points to php-fpm which cannot execute CLI scripts!**

**SOLUTION:**
1. Diagnostic will try to find the correct CLI PHP binary
2. If found, note the path shown (e.g., `/usr/bin/php`)
3. Add this line after line 38 in `app.php`:
   ```php
   define('PHP_CLI_BINARY', '/usr/bin/php');
   ```
4. Replace `/usr/bin/php` with the actual CLI path from diagnostic
5. System will now use correct PHP binary for workers

Common CLI PHP paths:
- cPanel: `/opt/cpanel/ea-php82/root/usr/bin/php` (adjust version)
- Standard: `/usr/bin/php` or `/usr/local/bin/php`

## Step 2: Enable Worker Debug Mode

Add this line to `app.php` after line 38 (after `define('CONFIG_START', true);`):

```php
define('WORKER_DEBUG_MODE', true);
```

This will create a `worker_logs/` directory with detailed logs from each worker showing why they crashed.

## Step 3: Check Worker Logs

After enabling debug mode and spawning workers, check:

```bash
ls -la worker_logs/
cat worker_logs/*.err.log
```

Look for:
- Database connection errors
- PHP fatal errors
- Missing functions or extensions
- File permission issues

## Step 4: Check PHP Error Log

Workers log initialization errors to the PHP error log:

```bash
tail -f /var/log/php-errors.log
# or wherever your PHP error log is located
```

Look for:
- "Worker Started" messages (should see one per worker)
- "Worker registered with ID" messages
- Any database connection failures
- Initialization errors

## Common Issues and Solutions

### Issue: PHP Binary is FPM (Most Common on cPanel/Shared Hosting)

**Symptoms:**
- Diagnostic shows: "⚠️ PHP_BINARY points to FPM"
- "Worker execution: ✗ Worker did not write log"
- Workers spawn but immediately crash
- `PHP_BINARY` shows path like `/opt/cpanel/.../php-fpm` or contains "fpm"

**Solution:**
1. Run diagnostic - it will search for CLI PHP binary
2. Note the path it finds (e.g., `/usr/bin/php` or `/opt/cpanel/ea-php82/root/usr/bin/php`)
3. Add to `app.php` after line 38:
   ```php
   define('PHP_CLI_BINARY', '/usr/bin/php');  // Use path from diagnostic
   ```
4. System will automatically use this CLI binary instead of FPM
5. Workers should now start successfully

**Why this happens:**
- PHP-FPM is for web requests, not CLI execution
- On shared hosting (especially cPanel), `PHP_BINARY` often points to FPM
- Workers need the CLI binary to execute as background processes

### Issue: Database Connection Failed

**Symptoms:**
- Workers crash immediately after spawn
- Error log shows "Connection failed" or "Access denied"

**Solution:**
1. Verify database credentials in app.php `$DB_CONFIG`
2. Ensure MySQL/MariaDB is running
3. Check that database exists and is accessible
4. Test connection: `php -r "require 'app.php'; Database::connect();"`

### Issue: exec Function Disabled

**Symptoms:**
- Diagnostic shows "exec: ✗ Not Available (disabled in php.ini)"

**Solution:**
- **This is OK!** The system uses `proc_open` as the primary method for spawning workers
- `exec` is only a fallback and is not required
- As long as `proc_open` shows "✓ Available", workers will work correctly
- If both `proc_open` and `exec` are disabled, the system will use HTTP worker fallback (slower but functional)

### Issue: proc_open Disabled

**Symptoms:**
- Diagnostic shows "proc_open: ✗ Not Available (disabled in php.ini)"
- Workers never spawn or system uses HTTP fallback

**Solution:**
1. Contact your hosting provider to enable proc_open
2. System will automatically use HTTP worker fallback (slower but works)
3. Check php.ini for `disable_functions = proc_open` and remove it if you have access

### Issue: File Permissions

**Symptoms:**
- "Log directory: ✗ Not Writable"
- Workers can't write logs or heartbeat

**Solution:**
```bash
chmod 755 /path/to/your/app/directory
chmod 644 app.php
```

### Issue: Memory or Resource Limits

**Symptoms:**
- First few workers start, then others fail
- "Cannot allocate memory" errors

**Solution:**
1. Check `php -i | grep memory_limit` (should be at least 128M)
2. Reduce number of workers if hitting system limits
3. Increase system resources if possible

### Issue: Stale Workers

**Symptoms:**
- Dashboard shows "Worker has not sent heartbeat recently"
- Workers appear to crash after running

**Solution:**
1. Workers may be processing and legitimately taking time
2. Check database for actual worker status
3. Ensure workers have queue items to process
4. Verify job_queue table has pending items

## Testing Workers Manually

Test a single worker manually to see detailed output:

```bash
# Start a single worker in foreground
php app.php test-worker-1

# Or with job ID
php app.php test-worker-1 123
```

This will show real-time logs and any errors.

## Verifying Parallel Execution

Run the test suite:

```bash
php test_parallel_workers.php
```

Expected output:
- "✓✓✓ SUCCESS: Workers started in PARALLEL"
- Spread < 1 second
- Execution time ~2 seconds (not 20+ seconds)

If test fails:
- Check diagnostic script first
- Enable debug mode
- Review worker logs

## Getting Help

If issues persist after following this guide:

1. Run diagnostic: `php worker_diagnostic.php > diagnostic_output.txt`
2. Enable debug mode and spawn workers
3. Collect all error logs from worker_logs/
4. Check PHP error log
5. Provide all outputs when asking for help

## Performance Tips

Once workers are running correctly:

1. **Optimal Worker Count**: 50 workers per 1000 emails
2. **Polling Interval**: Default 2 seconds (configurable in settings)
3. **Monitor Dashboard**: Watch active worker count in real-time
4. **Check Logs**: Regular errors indicate system issues
5. **Database Performance**: Ensure proper indexes exist

## Disabling Debug Mode

Once issues are resolved, remove or comment out:

```php
// define('WORKER_DEBUG_MODE', true);
```

This will restore normal operation without debug overhead and log files.
