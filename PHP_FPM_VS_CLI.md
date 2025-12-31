# PHP-FPM vs CLI Binary Issue - Quick Fix

## The Problem

On cPanel and many shared hosting environments, `PHP_BINARY` points to `php-fpm` (FastCGI Process Manager) instead of the CLI binary. PHP-FPM is designed for web requests, not command-line execution, so workers fail to start.

## How to Detect

Run the diagnostic:
```bash
php worker_diagnostic.php
```

Look for:
```
⚠️  PHP_BINARY points to FPM: /opt/cpanel/ea-php82/root/usr/sbin/php-fpm
Worker execution: ✗ Worker did not write log
```

## The Fix

### Step 1: Find Your CLI PHP Binary

The diagnostic will automatically search for the CLI binary and show:
```
✓ Found CLI PHP binary: /opt/cpanel/ea-php82/root/usr/bin/php
```

### Step 2: Configure app.php

Add this line **after line 38** in `app.php` (after `define('CONFIG_START', true);`):

```php
define('PHP_CLI_BINARY', '/opt/cpanel/ea-php82/root/usr/bin/php');
```

**Replace the path with the actual path shown in your diagnostic output!**

### Step 3: Verify

Run diagnostic again:
```bash
php worker_diagnostic.php
```

Should now show:
```
proc_open: ✓ Test worker spawned with: /opt/cpanel/ea-php82/root/usr/bin/php
Worker execution: ✓ Worker ran successfully
```

## Common CLI PHP Paths by Hosting Type

### cPanel (EasyApache)
- PHP 8.2: `/opt/cpanel/ea-php82/root/usr/bin/php`
- PHP 8.1: `/opt/cpanel/ea-php81/root/usr/bin/php`
- PHP 8.0: `/opt/cpanel/ea-php80/root/usr/bin/php`
- PHP 7.4: `/opt/cpanel/ea-php74/root/usr/bin/php`

### Standard Linux
- `/usr/bin/php`
- `/usr/local/bin/php`
- `/usr/bin/php8.2` (version-specific)

### Custom Installations
- Check with: `which php`
- Or: `whereis php`

## How the System Works

1. **Without fix**: System uses `PHP_BINARY` → points to FPM → workers crash
2. **With fix**: System checks for `PHP_CLI_BINARY` constant → uses CLI binary → workers work!

The system automatically:
- Checks if `PHP_CLI_BINARY` is defined
- Falls back to searching common paths if not defined
- Uses `PHP_BINARY` as last resort

## Testing

After applying the fix:

1. **Test worker spawn**:
   ```bash
   php worker_diagnostic.php
   ```

2. **Test actual job** (if database configured):
   - Create a small test job (10 emails)
   - Enable debug mode: `define('WORKER_DEBUG_MODE', true);`
   - Check `worker_logs/` for any errors

3. **Verify workers register**:
   - Go to Workers page in dashboard
   - Should see workers appear within a few seconds
   - Workers should show "running" or "idle" status

## Why This Happens

- **PHP-FPM**: Process manager for handling web requests through FastCGI
  - Cannot execute scripts from command line
  - Designed for nginx/Apache integration
  - Only works with web server requests

- **PHP CLI**: Command-line interface binary
  - Can execute scripts directly
  - Used for background processes, cron jobs, workers
  - What we need for parallel worker execution

On shared hosting, the web environment uses FPM, but `PHP_BINARY` constant returns FPM path instead of CLI path. This is a PHP configuration issue, not a bug in our system.

## Verification Checklist

✅ Diagnostic shows CLI PHP binary found
✅ `PHP_CLI_BINARY` defined in app.php
✅ Test worker spawn succeeds
✅ Test worker execution succeeds  
✅ Workers appear in dashboard
✅ Workers can process jobs

If all checks pass, your system is ready for production parallel execution!
