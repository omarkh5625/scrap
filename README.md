# Email Extraction System / نظام استخراج الإيميلات

نظام احترافي لاستخراج الإيميلات بسرعة وكفاءة عالية - يدعم استخراج 100,000+ إيميل فريد في أقل من 3 دقائق.

## Features / المميزات

- ✅ **High Performance**: استخراج ≥35,000 إيميل/دقيقة
- ✅ **Parallel Processing**: دعم ≥240 طلب HTTP متوازي
- ✅ **Bloom Filter**: منع التكرار باستخدام Bloom Filter فعال
- ✅ **Email Hashing**: تخزين آمن باستخدام SHA256
- ✅ **Domain Filtering**: تجاهل الدومينات الوهمية تلقائياً
- ✅ **Page Filtering**: فلترة حسب الحجم (2KB - 5MB)
- ✅ **CLI Workers**: معالجة في الخلفية عبر CLI فقط
- ✅ **Modern UI**: واجهة عربية احترافية
- ✅ **RESTful API**: API كامل للتحكم في المهام

## Architecture / الهيكلة

```
public_html/
├── index.php              ← Router رئيسي
├── ui.php                 ← جميع الواجهات
├── api/
│   ├── start_job.php      ← بدء مهمة جديدة
│   ├── job_status.php     ← حالة المهمة
│   └── stop_job.php       ← إيقاف المهمة
├── core/
│   ├── Router.php         ← نظام التوجيه
│   ├── JobManager.php     ← إدارة المهام
│   ├── SearchEngine.php   ← محركات البحث
│   ├── WorkerManager.php  ← إدارة Workers
│   ├── Extractor.php      ← استخراج المحتوى
│   ├── BloomFilter.php    ← منع التكرار
│   ├── EmailHasher.php    ← تجزئة الإيميلات
│   ├── PageFilter.php     ← فلترة الصفحات
│   └── Storage.php        ← التخزين
├── workers/
│   └── worker.php         ← CLI Worker فقط
└── storage/
    ├── jobs.json          ← بيانات المهام
    ├── emails.tmp         ← الإيميلات المستخرجة
    └── bloom.bin          ← Bloom Filter

```

## Requirements / المتطلبات

- PHP 7.4 or higher
- PHP Extensions: curl, json, posix
- Apache with mod_rewrite (or Nginx with similar config)
- 8 CPU cores + 32GB RAM (للأداء الأمثل)

## Installation / التثبيت

### 1. Clone the repository

```bash
git clone https://github.com/omarkh5625/scrap.git
cd scrap
```

### 2. Configure web server

#### Apache:
Point your web server document root to `public_html/` directory.

The `.htaccess` file is already configured for URL rewriting.

#### Nginx:
```nginx
server {
    root /path/to/scrap/public_html;
    index index.php;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
    }
}
```

### 3. Set permissions

```bash
chmod +x public_html/workers/worker.php
chmod 755 public_html/storage
```

## Usage / الاستخدام

### Web Interface / الواجهة

1. افتح المتصفح وانتقل إلى: `http://your-domain/`
2. املأ النموذج بالمعلومات المطلوبة:
   - **الكلمات المفتاحية**: كلمات البحث
   - **محرك البحث**: Google, Bing, DuckDuckGo, Yahoo
   - **الحد الأقصى للنتائج**: عدد نتائج البحث
   - **عدد الخيوط**: عدد الطلبات المتوازية (10-240)
3. اضغط "بدء الاستخراج"
4. تابع حالة المهمة في قائمة "المهام الحالية"

### API Usage / استخدام API

#### 1. Start a Job / بدء مهمة

```bash
curl -X POST http://your-domain/api/start_job.php \
  -H "Content-Type: application/json" \
  -d '{
    "keywords": "technology companies",
    "search_engine": "google",
    "max_results": 100,
    "threads": 40
  }'
```

Response:
```json
{
  "success": true,
  "job": {
    "id": "job_abc123",
    "status": "pending",
    "worker_pid": 12345
  }
}
```

#### 2. Get Job Status / حالة المهمة

```bash
curl http://your-domain/api/job_status.php?job_id=job_abc123
```

Response:
```json
{
  "success": true,
  "job": {
    "id": "job_abc123",
    "status": "running",
    "stats": {
      "urls_processed": 45,
      "emails_found": 1234,
      "emails_unique": 987,
      "start_time": 1234567890,
      "duration": 120
    }
  }
}
```

#### 3. Stop a Job / إيقاف المهمة

```bash
curl -X POST http://your-domain/api/stop_job.php \
  -H "Content-Type: application/json" \
  -d '{"job_id": "job_abc123"}'
```

### CLI Worker / Worker يدوي

You can also start workers manually from the command line:

```bash
cd public_html
php workers/worker.php --job=job_abc123 --threads=40
```

## Performance / الأداء

### Target Metrics / المقاييس المستهدفة

- ✅ **≥35,000 emails/minute** - معدل الاستخراج
- ✅ **≥240 parallel requests** - الطلبات المتوازية
- ✅ **100,000 unique emails in <3 minutes** - على سيرفر 8 CPU + 32GB RAM

### Optimization Features / ميزات التحسين

1. **Bloom Filter**: يمنع معالجة الإيميلات المكررة
2. **curl_multi**: طلبات HTTP متوازية
3. **TCP Optimizations**: CURLOPT_TCP_KEEPALIVE, HTTP/2
4. **Batch Storage**: حفظ على دفعات بدلاً من كل إيميل منفرد
5. **Page Size Filtering**: تجاهل الصفحات <2KB أو >5MB
6. **Domain Filtering**: تجاهل الدومينات الوهمية قبل Regex
7. **No SSL Verification**: تعطيل SSL للسرعة
8. **Email Hashing**: تخزين SHA256 فقط (security + space)

## Data Format / صيغة البيانات

### Stored Emails / الإيميلات المخزنة

Format in `storage/emails.tmp`:
```
email_hash|domain
```

Example:
```
a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6|example.com
```

### Jobs Data / بيانات المهام

Format in `storage/jobs.json`:
```json
{
  "job_id": {
    "id": "job_id",
    "status": "running|completed|failed|stopped",
    "params": {
      "keywords": "...",
      "search_engine": "google",
      "max_results": 100
    },
    "stats": {
      "urls_processed": 0,
      "emails_found": 0,
      "emails_unique": 0,
      "duration": 0
    }
  }
}
```

## Security / الأمان

- ✅ Email hashing with SHA256
- ✅ No plain email storage
- ✅ Protected storage files via .htaccess
- ✅ CLI-only workers (no web scraping)
- ✅ Input validation
- ✅ Fake domain filtering

## Prohibited / ممنوعات

- ❌ No scraping in UI or API endpoints
- ❌ No synchronous loops for scraping
- ❌ No SSL verification (for performance)
- ❌ No plain email storage

## License

MIT License

## Author

Omar Khalil (@omarkh5625)

---

Made with ❤️ for high-performance email extraction