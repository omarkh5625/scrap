# Quick Start Guide - Email Extraction System

## 🚀 Get Started in 3 Steps

### Step 1: Open the Dashboard
Simply open `dashboard.html` in your web browser.

### Step 2: Create Your First Job
1. Fill in the form:
   - **Search Query**: e.g., "real estate agents california"
   - **API Key**: Your serper.dev API key
   - **Maximum Emails**: e.g., 1000
   - **Worker Count**: 0 for auto, or 1-300 for manual

2. Click "🚀 Start Extraction"

3. Workers will spawn automatically and start processing!

### Step 3: Monitor Progress
The dashboard updates every 5 seconds showing:
- Active workers
- Jobs progress
- Extracted emails count
- Queue status

## 📱 API Usage Examples

### Create a Job via API
```bash
curl -X POST "api.php?action=create_job" \
  -H "Content-Type: application/json" \
  -d '{
    "query": "dentists new york",
    "api_key": "YOUR_SERPER_KEY",
    "max_results": 500,
    "country": "us",
    "email_filter": "business"
  }'
```

### Spawn 20 Workers
```bash
curl -X POST "api.php?action=spawn_workers" \
  -d '{"worker_count":20}'
```

### Check System Status
```bash
curl "api.php?action=get_system_status" | json_pp
```

## 🔧 Manual Worker Control

### Start a Single Worker
```bash
php worker.php my_worker_1
```

### Start Multiple Workers in Background
```bash
for i in {1..10}; do
  php worker.php "worker_$i" &
done
```

### Monitor Workers
```bash
# View all workers
curl "api.php?action=get_workers" | json_pp

# View worker stats
curl "api.php?action=get_worker_stats" | json_pp

# Check for errors
curl "api.php?action=get_errors" | json_pp
```

## 📊 Recommended Configurations

### Small Job (< 100 emails)
- Workers: 5-10
- Expected time: 1-2 minutes

### Medium Job (100-1,000 emails)
- Workers: 20-50
- Expected time: 5-10 minutes

### Large Job (1,000-10,000 emails)
- Workers: 50-100
- Expected time: 10-30 minutes

### Massive Job (10,000-100,000 emails)
- Workers: 100-300
- Expected time: 30-60 minutes
- **Requires**: Dedicated server with 16GB+ RAM

## ⚡ Performance Tips

1. **Start Small**: Begin with 10 workers and scale up
2. **Monitor Memory**: Check server RAM usage
3. **Database Optimization**: Run `OPTIMIZE TABLE` regularly
4. **Network**: Ensure good internet connection
5. **API Rate Limits**: Be aware of serper.dev limits

## 🐛 Troubleshooting

### Workers Not Starting
```bash
# Check PHP is available
php -v

# Check worker script syntax
php -l worker.php

# Test manually
php worker.php test_worker
```

### API Not Responding
```bash
# Health check
curl "api.php?action=health"

# Check PHP errors
tail -f php_errors.log
```

### Database Connection Issues
1. Check database credentials in `app.php`
2. Verify MySQL is running
3. Test connection:
   ```bash
   mysql -u username -p database_name
   ```

## 📝 Files Overview

| File | Purpose | Access |
|------|---------|--------|
| `dashboard.html` | User interface | Browser |
| `api.php` | Backend API | HTTP/curl |
| `worker.php` | Worker script | CLI |
| `app.php` | Legacy system | Browser/CLI |

## 🎯 Next Steps

1. **Read Full Documentation**:
   - English: `README_ARCHITECTURE.md`
   - Arabic: `README_ARABIC.md`

2. **Test the System**:
   ```bash
   ./test.sh
   ```

3. **Start Production Use**:
   - Configure database
   - Set up monitoring
   - Add API authentication
   - Scale workers as needed

## 💡 Pro Tips

- **Auto-scaling**: Set `worker_count: 0` to let system calculate optimal workers
- **Real-time Monitoring**: Keep dashboard open during processing
- **Log Analysis**: Use `tail -f php_errors.log` to watch progress
- **Queue Management**: Check queue stats frequently
- **Error Handling**: Resolve errors via API or dashboard

## 🎉 Success Metrics

After setup, you should see:
- ✅ Dashboard loads successfully
- ✅ API health check passes
- ✅ Workers register and process jobs
- ✅ Emails being extracted in real-time
- ✅ No critical errors in logs

---

**Need Help?** Check the full documentation or logs for detailed information.
