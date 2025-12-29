# Automatic Worker System

## نظام العمال التلقائي / Automatic Worker System

### Final Implementation / التنفيذ النهائي

The system now combines the **reliability of queue-based processing** with the **convenience of automatic worker spawning**.

يجمع النظام الآن بين **موثوقية المعالجة القائمة على قوائم الانتظار** و **راحة إطلاق العمال التلقائي**.

---

## How It Works / كيف يعمل

### 1. Job Creation / إنشاء الوظيفة

When you click "Create Job & Start Processing":
```
User clicks button
     ↓
Job created in database
     ↓
Job split into chunks → added to job_queue
     ↓
Workers automatically spawned (exec or HTTP)
     ↓
Workers start processing immediately
     ↓
UI returns instantly, processing in background
```

### 2. Worker Spawning / إطلاق العمال

**Method 1: exec() (Preferred)**
```php
// Spawn N background PHP processes
php app.php worker-1 &
php app.php worker-2 &
...
```

**Method 2: HTTP (Fallback)**
```php
// Make async HTTP requests to start workers
POST ?page=start-worker
// Workers run in background, process queue
```

### 3. Queue Processing / معالجة قائمة الانتظار

Each spawned worker:
```
Register → Poll queue → Pick chunk → Process → Mark complete → Repeat
```

Workers auto-exit when:
- Queue is empty (no more work)
- Timeout reached (5 minutes for HTTP workers)

---

## Architecture / البنية

### Components / المكونات

**1. spawnParallelWorkers()**
- Creates queue chunks
- Calls autoSpawnWorkers()

**2. autoSpawnWorkers()**
- Checks if exec() available
- Spawns via exec() or HTTP

**3. spawnWorkersViaExec()**
- Spawns background PHP processes
- Each runs standard worker loop
- Polls queue continuously

**4. spawnWorkersViaHttp()**
- Makes async HTTP requests
- Starts workers via web interface
- Background processing via ignore_user_abort()

**5. handleStartWorker()**
- HTTP endpoint: ?page=start-worker
- Closes connection immediately
- Runs worker loop in background
- Processes queue for max 5 minutes

---

## Benefits / الفوائد

### ✅ User Experience
- **Click and Go** - No manual worker startup
- **Immediate Feedback** - Workers start instantly
- **Real-time Results** - See progress immediately

### ✅ Reliability
- **Queue-Based** - Work preserved in database
- **Fallback Options** - exec() or HTTP
- **Auto-Recovery** - Failed chunks remain in queue

### ✅ Compatibility
- **Works on cPanel** - No special requirements
- **Shared Hosting** - HTTP fallback available
- **VPS/Dedicated** - exec() for best performance

---

## Configuration / التكوين

### Worker Count
```php
// In UI: Set "Parallel Workers" field
1-1000 workers
Default: 5
```

### Worker Timeout (HTTP)
```php
// In handleStartWorker()
$maxRuntime = 300; // 5 minutes
```

### Polling Interval
```php
// In worker loop
sleep(1); // 1 second between queue checks
```

---

## Comparison / المقارنة

### Before (Manual)
```
1. Create job → queued
2. Manually run: php app.php worker-1
3. Worker processes queue
4. See results
```

### After (Automatic)
```
1. Create job → queued + workers spawn
2. Workers process automatically
3. See results immediately
```

---

## Technical Details / التفاصيل التقنية

### exec() Method
```php
// Spawns background process
exec("php app.php worker-name > /dev/null 2>&1 &");

// Worker runs standard loop
while (true) {
    $job = Worker::getNextJob();
    if ($job) {
        Worker::processJob($job['id']);
    } else {
        break; // Exit when queue empty
    }
}
```

### HTTP Method
```php
// Makes async HTTP request
POST ?page=start-worker
{
    worker_name: "auto-worker-xyz",
    worker_index: 0
}

// Server closes connection immediately
ignore_user_abort(true);
header('Connection: close');
flush();

// Then runs worker loop for max 5 minutes
while ((time() - $startTime) < 300) {
    $job = Worker::getNextJob();
    if ($job) {
        Worker::processJob($job['id']);
    } else {
        break;
    }
}
```

---

## Monitoring / المراقبة

### Workers Page
- Active workers count (auto-spawned + manual)
- Queue status (pending/processing/completed)
- Processing rate
- Individual worker performance

### Dashboard
- Job progress (real-time)
- Emails extracted
- Job status

---

## Manual Workers Still Supported

You can still start manual CLI workers:
```bash
php app.php worker-1
php app.php worker-2
```

These will work alongside auto-spawned workers, all processing the same queue.

يمكنك لا تزال بدء عمال CLI يدويًا وسيعملون جنبًا إلى جنب مع العمال التلقائيين.

---

## Troubleshooting / استكشاف الأخطاء

### Workers not starting
**Check:**
1. PHP error log for exec/HTTP errors
2. exec() not disabled in php.ini
3. HTTP requests reaching server

**Solutions:**
- If exec() disabled: HTTP fallback used automatically
- If HTTP fails: Start manual workers
- Check php_errors.log for details

### Slow processing
**Solutions:**
1. Increase worker count (up to 1000)
2. Reduce rate limiting in Settings
3. Disable deep scraping

### Workers stop early
**Check:**
- HTTP workers timeout after 5 minutes (normal)
- New auto-spawned workers created for each job
- Manual workers run indefinitely

---

## Best Practices / أفضل الممارسات

### For Most Users
- Use default 5 workers
- Let system auto-spawn
- Monitor on Workers page

### For Heavy Usage
- Increase to 10-20 workers
- Consider manual persistent workers too
- Monitor server resources

### For Development
- Use 1-2 workers
- Watch php_errors.log
- Test with small jobs first

---

## Summary / الملخص

✅ **Queue-based** for reliability
✅ **Auto-spawning** for convenience
✅ **Works everywhere** (exec or HTTP)
✅ **Real-time processing** starts immediately
✅ **Manual workers** still supported

The perfect balance of reliability and ease of use!
التوازن المثالي بين الموثوقية وسهولة الاستخدام!
