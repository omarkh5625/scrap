# دليل استكشاف أخطاء Workers

## 🔍 تشخيص المشكلة

إذا كانت Workers لا تعمل، اتبع هذه الخطوات:

### 1. افتح Diagnostic Endpoint

أضف `?page=api&action=diagnostic` للـ URL:
```
https://your-domain.com/app.php?page=api&action=diagnostic
```

### 2. افحص النتائج

```json
{
  "exec_available": false,           // هل exec() متاح؟
  "pending_queue_items": 20,         // كم queue item في الانتظار؟
  "active_workers": 0,               // كم worker نشط؟
  "running_jobs": 2,                 // كم job قيد التشغيل؟
  "php_version": "8.1.0",
  "php_sapi": "fpm-fcgi",            // نوع PHP
  "fastcgi_available": true,         // هل fastcgi متاح؟
  "disabled_functions": "exec,shell_exec"  // الدوال المعطلة
}
```

### 3. تحليل المشكلة

#### ✅ الحالة المثالية
```json
{
  "exec_available": true,        // ✓
  "pending_queue_items": 0,      // ✓ Workers معالجة
  "active_workers": 20,          // ✓ Workers تعمل
  "running_jobs": 1              // ✓ Job قيد المعالجة
}
```

#### ❌ المشكلة: Workers لا تبدأ
```json
{
  "exec_available": false,       // ✗ exec معطل
  "pending_queue_items": 20,     // ✗ Queue ممتلئ
  "active_workers": 0,           // ✗ لا workers
  "running_jobs": 1,
  "fastcgi_available": false     // ✗ FastCGI غير متاح
}
```

**السبب:** `exec()` معطل و `fastcgi_finish_request()` غير متاح

**الحل:**

1. **فعّل exec()** في `php.ini`:
   ```ini
   disable_functions = 
   ```

2. **أو استخدم PHP-FPM** بدلاً من Apache mod_php

3. **أو شغّل Workers يدوياً** من SSH:
   ```bash
   cd /path/to/app
   for i in {1..20}; do
     php app.php worker-$i &
   done
   ```

### 4. تحقق من Logs

افحص PHP error log:
```bash
tail -f /path/to/php_errors.log | grep -i worker
```

يجب أن ترى:
```
autoSpawnWorkers: Attempting to spawn 20 workers
spawnWorkersViaHttp: Spawning 20 HTTP workers
handleStartWorker: Worker http-worker-xxx registered
handleStartWorker: Worker http-worker-xxx got job #15
```

### 5. اختبار HTTP Workers يدوياً

اختبر worker واحد:
```bash
curl -X POST "https://your-domain.com/app.php?page=start-worker" \
  -d "worker_name=test-worker&worker_index=0"
```

يجب أن يرجع:
```json
{"status":"started","worker":"test-worker"}
```

ثم تحقق من الـ logs والـ database.

## 🛠️ الحلول الشائعة

### الحل 1: تفعيل exec()

في `php.ini`:
```ini
; قبل
disable_functions = exec,shell_exec,system,passthru

; بعد
disable_functions = shell_exec,system,passthru
```

ثم:
```bash
service php-fpm restart
```

### الحل 2: استخدام Cron Jobs

أضف cron job لتشغيل workers:
```cron
* * * * * cd /path/to/app && php app.php cron-worker >> /dev/null 2>&1
```

### الحل 3: Workers يدوية عبر SSH

في terminal منفصل:
```bash
cd /path/to/app
while true; do
  php app.php auto-worker-$(date +%s)
  sleep 5
done
```

### الحل 4: استخدام Supervisor (الأفضل)

إنشاء `/etc/supervisor/conf.d/email-workers.conf`:
```ini
[program:email-workers]
command=/usr/bin/php /path/to/app/app.php worker-%(process_num)s
process_name=%(program_name)s-%(process_num)s
numprocs=20
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/email-workers.log
```

ثم:
```bash
supervisorctl reread
supervisorctl update
supervisorctl start email-workers:*
```

## 📊 مراقبة Workers

### تحقق من Workers النشطة

```sql
SELECT * FROM workers 
WHERE last_heartbeat > DATE_SUB(NOW(), INTERVAL 30 SECOND)
ORDER BY last_heartbeat DESC;
```

### تحقق من Queue Items

```sql
SELECT 
  status, 
  COUNT(*) as count 
FROM job_queue 
GROUP BY status;
```

### تحقق من Jobs قيد التشغيل

```sql
SELECT 
  id, 
  query, 
  status, 
  created_at,
  TIMESTAMPDIFF(MINUTE, created_at, NOW()) as minutes_running
FROM jobs 
WHERE status = 'running'
ORDER BY created_at DESC;
```

## ⚠️ مشاكل شائعة

### المشكلة: Queue Items موجودة لكن Workers لا تعالجها

**السبب:** Workers لا تبدأ أصلاً

**الحل:** تأكد من تفعيل exec() أو شغّل workers يدوياً

### المشكلة: Workers تبدأ لكن Job عالق عند 0%

**السبب 1:** API Key غير صحيح
```bash
# اختبر API key
curl -X POST https://google.serper.dev/search \
  -H 'X-API-KEY: YOUR_KEY' \
  -H 'Content-Type: application/json' \
  -d '{"q":"test"}'
```

**السبب 2:** Rate limit من Serper API
- انتظر دقيقة وحاول مرة أخرى

**السبب 3:** Workers تتوقف بسبب خطأ
```bash
# افحص الأخطاء
SELECT * FROM worker_errors ORDER BY created_at DESC LIMIT 10;
```

### المشكلة: Workers تعمل لكن بطيئة جداً

**الأسباب المحتملة:**
1. عدد workers قليل (زد إلى 20-50)
2. Rate limit عالي (خفض إلى 0.1s)
3. API بطيء (خارج عن سيطرتك)
4. استعلام سيء (حسّن query)

## 🎯 الإعدادات المثالية

للحصول على أفضل أداء:

```
Workers: 20-50
Rate Limit: 0.1s
PHP Memory: 256M+
Max Execution Time: 300s
exec(): Enabled
PHP-FPM: Enabled
FastCGI: Enabled
```

## 📞 إذا استمرت المشكلة

1. شارك نتائج diagnostic endpoint
2. شارك آخر 50 سطر من error log
3. شارك نتائج SQL queries أعلاه
4. أذكر نوع الـ hosting (shared/VPS/dedicated)
