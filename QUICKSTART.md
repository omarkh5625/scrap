# Quick Start Guide

## 1. Test the System

Run the test suite to ensure everything is working:

```bash
php test_system.php
```

Expected output:
```
=== Email Extraction System Tests ===
...
✓ All tests passed!
```

## 2. Start a Test Job via CLI

Create a test job manually:

```bash
cd public_html

# Start worker with test parameters
php workers/worker.php --job=test_001 --threads=10
```

Note: You'll need to create a job first via the API or manually in `storage/jobs.json`.

## 3. API Testing

### Create a job:

```bash
curl -X POST http://localhost/api/start_job.php \
  -H "Content-Type: application/json" \
  -d '{
    "keywords": "software developer contact",
    "search_engine": "google",
    "max_results": 50,
    "threads": 20
  }'
```

Expected response:
```json
{
  "success": true,
  "job": {
    "id": "job_xxx",
    "status": "pending",
    "worker_pid": 12345
  }
}
```

### Check job status:

```bash
curl "http://localhost/api/job_status.php?job_id=job_xxx"
```

### Stop a job:

```bash
curl -X POST http://localhost/api/stop_job.php \
  -H "Content-Type: application/json" \
  -d '{"job_id": "job_xxx"}'
```

## 4. Web Interface

Open your browser and navigate to:
```
http://localhost/
```

Fill in the form and click "بدء الاستخراج" (Start Extraction).

## 5. Monitor Results

### View extracted emails:
```bash
cat public_html/storage/emails.tmp
```

Format: `email_hash|domain`

### View jobs data:
```bash
cat public_html/storage/jobs.json
```

### Check bloom filter:
```bash
ls -lh public_html/storage/bloom.bin
```

## Performance Tips

1. **Increase threads**: For better performance on powerful servers:
   ```
   --threads=100  # or higher, up to 240
   ```

2. **Monitor system resources**:
   ```bash
   htop
   # Watch CPU and memory usage
   ```

3. **For production deployment**:
   - Use PHP 8.x for better performance
   - Enable OPcache
   - Use PHP-FPM with optimized settings
   - Increase PHP memory_limit and max_execution_time

## Troubleshooting

### Worker not starting:
```bash
# Check PHP CLI is available
which php
php --version

# Check permissions
chmod +x public_html/workers/worker.php

# Check logs
tail -f /var/log/apache2/error.log  # or nginx error log
```

### Storage issues:
```bash
# Check permissions
chmod 755 public_html/storage

# Clear storage
rm public_html/storage/*.tmp
rm public_html/storage/*.bin
rm public_html/storage/jobs.json
```

### Low performance:
- Check your curl version supports HTTP/2
- Verify your server has good internet connectivity
- Increase threads parameter
- Check for rate limiting from search engines

## Example Workflow

1. Start extraction job via API
2. Monitor progress via job status endpoint
3. Worker processes URLs in parallel
4. Emails are extracted and deduplicated
5. Results stored in `storage/emails.tmp`
6. Job completes with final statistics

## Security Notes

- Never expose `storage/` directory publicly
- Use `.htaccess` rules (already configured)
- Emails are hashed (SHA256) for privacy
- No plain email addresses stored
