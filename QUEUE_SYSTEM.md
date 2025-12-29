# Queue-Based Worker System

## نظام العمال القائم على قوائم الانتظار / Queue-Based Worker System

### Overview / نظرة عامة

The system has been redesigned to use a **queue-based architecture** instead of HTTP-based workers. This provides much better reliability, especially on shared hosting and cPanel environments.

تم إعادة تصميم النظام لاستخدام **بنية قائمة على قوائم الانتظار** بدلاً من العمال القائمين على HTTP. يوفر هذا موثوقية أفضل بكثير، خاصة على الاستضافة المشتركة وبيئات cPanel.

---

## How It Works / كيف يعمل

### 1. Job Creation / إنشاء الوظيفة

When you create a job:
- Job is saved to the `jobs` table
- Job is split into **chunks** (based on worker count)
- Each chunk is inserted into the `job_queue` table as "pending"

عند إنشاء وظيفة:
- يتم حفظ الوظيفة في جدول `jobs`
- يتم تقسيم الوظيفة إلى **أجزاء** (بناءً على عدد العمال)
- يتم إدراج كل جزء في جدول `job_queue` كـ "معلق"

### 2. Worker Polling / استطلاع العامل

CLI workers continuously poll for work:
```bash
php app.php worker-1
```

Workers:
- Check `job_queue` for pending chunks every few seconds
- Lock a chunk (mark as "processing")
- Process the chunk
- Mark chunk as "completed"
- Return to polling

العمال يستطلعون باستمرار للعمل:
- فحص `job_queue` للأجزاء المعلقة كل بضع ثوانٍ
- قفل جزء (وضع علامة كـ "قيد المعالجة")
- معالجة الجزء
- وضع علامة على الجزء كـ "مكتمل"
- العودة إلى الاستطلاع

### 3. Progress Tracking / تتبع التقدم

- Job progress = (completed chunks / total chunks) × 100
- Real-time updates on dashboard
- Workers page shows queue status

- تقدم الوظيفة = (الأجزاء المكتملة / إجمالي الأجزاء) × 100
- تحديثات في الوقت الفعلي على لوحة القيادة
- صفحة العمال تعرض حالة قائمة الانتظار

---

## Benefits / الفوائد

### ✓ Reliability / الموثوقية
- Works on **all hosting environments** including cPanel
- No dependency on exec() or HTTP timeouts
- Failed chunks remain in queue for retry

- يعمل على **جميع بيئات الاستضافة** بما في ذلك cPanel
- لا يعتمد على exec() أو مهلات HTTP
- الأجزاء الفاشلة تبقى في قائمة الانتظار لإعادة المحاولة

### ✓ Scalability / قابلية التوسع
- Add more workers anytime without stopping existing ones
- Workers can be started/stopped independently
- Load automatically distributed

- إضافة المزيد من العمال في أي وقت دون إيقاف الموجودين
- يمكن بدء/إيقاف العمال بشكل مستقل
- يتم توزيع الحمل تلقائياً

### ✓ Visibility / الرؤية
- See pending work in the queue
- Track which worker is processing what
- Monitor queue processing rate

- رؤية العمل المعلق في قائمة الانتظار
- تتبع أي عامل يعالج ماذا
- مراقبة معدل معالجة قائمة الانتظار

### ✓ Standard Pattern / نمط قياسي
- Industry-standard queue-based processing
- Similar to Laravel Queue, Celery, RabbitMQ
- Professional and proven architecture

- معالجة قائمة انتظار قياسية في الصناعة
- مشابه لـ Laravel Queue و Celery و RabbitMQ
- بنية احترافية ومثبتة

---

## Database Schema / مخطط قاعدة البيانات

### job_queue Table / جدول job_queue

```sql
CREATE TABLE job_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,                    -- Parent job
    start_offset INT NOT NULL,              -- Where to start scraping
    max_results INT NOT NULL,               -- How many emails to extract
    status ENUM(...) DEFAULT 'pending',     -- pending/processing/completed/failed
    worker_id INT NULL,                     -- Which worker is processing
    created_at TIMESTAMP,                   -- When chunk was created
    started_at TIMESTAMP NULL,              -- When processing started
    completed_at TIMESTAMP NULL,            -- When processing completed
    INDEX idx_status (status),
    INDEX idx_job (job_id)
);
```

---

## Worker Lifecycle / دورة حياة العامل

```
START
  ↓
Register Worker → Update Heartbeat
  ↓
Poll Queue for Pending Chunk
  ↓
┌─────────────────┐
│ Chunk Found?    │
│  NO → Sleep 5s  │ ───┐
│  YES ↓          │    │
└─────────────────┘    │
  ↓                    │
Lock Chunk (processing)│
  ↓                    │
Process Emails         │
  ↓                    │
Update Statistics      │
  ↓                    │
Mark Chunk Complete    │
  ↓                    │
Update Job Progress    │
  ↓                    │
Back to Poll ──────────┘
```

---

## API Endpoints / نقاط API

### GET ?page=api&action=worker-stats
```json
{
  "active_workers": 3,
  "idle_workers": 1,
  "total_pages": 150,
  "total_emails": 1234,
  "avg_runtime": 3600
}
```

### GET ?page=api&action=queue-stats
```json
{
  "pending": 15,      // Chunks waiting
  "processing": 3,    // Chunks being worked on
  "completed": 82     // Chunks finished
}
```

---

## Usage Example / مثال الاستخدام

### 1. Create Job / إنشاء وظيفة

Via UI:
- Go to "New Email Job"
- Fill in details
- Set worker count = 10
- Click "Create Job"

Result: 10 chunks created in `job_queue`

### 2. Start Workers / بدء العمال

Terminal 1:
```bash
php app.php worker-1
```

Terminal 2:
```bash
php app.php worker-2
```

Terminal 3:
```bash
php app.php worker-3
```

Or in background:
```bash
php app.php worker-1 > /dev/null 2>&1 &
php app.php worker-2 > /dev/null 2>&1 &
php app.php worker-3 > /dev/null 2>&1 &
```

### 3. Monitor Progress / مراقبة التقدم

Via UI:
- Go to "Workers" page
- See active workers: 3
- See queue: 3 processing, 7 pending
- Watch as chunks complete

---

## Comparison / المقارنة

### Old HTTP-Based System / النظام القديم القائم على HTTP

❌ Requires exec() or HTTP timeouts
❌ Doesn't work reliably on cPanel
❌ Workers can't be stopped/restarted easily
❌ Lost work if worker dies
❌ No visibility into pending work

### New Queue-Based System / النظام الجديد القائم على قوائم الانتظار

✅ No exec() required
✅ Works on all hosting including cPanel
✅ Workers can be stopped/started anytime
✅ Work preserved in queue
✅ Full visibility into queue status
✅ Standard, proven pattern

---

## Troubleshooting / استكشاف الأخطاء

### No workers running / لا عمال يعملون

**Problem:** Jobs stay in "pending" status

**Solution:** Start at least one worker:
```bash
php app.php worker-1
```

### Workers not picking up jobs / العمال لا يلتقطون الوظائف

**Check:**
1. Are workers running? Check "Workers" page
2. Are there pending chunks? Check queue stats
3. Check PHP error log for worker errors

### Slow processing / معالجة بطيئة

**Solutions:**
1. Start more workers
2. Reduce rate limiting in Settings
3. Disable deep scraping for faster processing

---

## Best Practices / أفضل الممارسات

### For Development / للتطوير
- Start 1-2 workers
- Monitor logs: `tail -f php_errors.log`
- Test with small jobs first

### For Production / للإنتاج
- Start 5-10 workers per server
- Use supervisor/systemd to keep workers running
- Monitor queue stats regularly
- Set appropriate rate limits

### For cPanel / لـ cPanel
- Create cron jobs to start workers
- Set to run every 5 minutes
- Workers will auto-exit if no work
- New cron run will restart them

Example cron:
```
*/5 * * * * cd /home/user/public_html && php app.php worker-cron >> /dev/null 2>&1
```

---

## Conclusion / الخلاصة

The queue-based worker system provides:
- ✅ Better reliability
- ✅ cPanel compatibility
- ✅ Professional architecture
- ✅ Easy monitoring
- ✅ Scalable processing

نظام العمال القائم على قوائم الانتظار يوفر:
- ✅ موثوقية أفضل
- ✅ توافق cPanel
- ✅ بنية احترافية
- ✅ مراقبة سهلة
- ✅ معالجة قابلة للتوسع

This is the standard way professional systems handle background processing!
هذه هي الطريقة القياسية التي تستخدمها الأنظمة الاحترافية للمعالجة في الخلفية!
