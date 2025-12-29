# Deployment Guide / دليل النشر

## Prerequisites / المتطلبات

### Server Requirements
- PHP 7.4+ (PHP 8.x recommended for better performance)
- Apache 2.4+ or Nginx 1.18+
- PHP Extensions:
  - curl
  - json
  - posix
  - mbstring
- Minimum: 2GB RAM, 2 CPU cores
- Recommended: 32GB RAM, 8 CPU cores

### PHP Configuration
Edit your `php.ini`:

```ini
; Memory and execution limits
memory_limit = 512M
max_execution_time = 300
max_input_time = 300

; For CLI workers
; cli/php.ini
memory_limit = 1G
max_execution_time = 0
```

## Installation Steps

### 1. Clone Repository

```bash
cd /var/www
git clone https://github.com/omarkh5625/scrap.git
cd scrap
```

### 2. Set Permissions

```bash
# Make workers executable
chmod +x public_html/workers/worker.php

# Set storage permissions
chmod 755 public_html/storage
chown www-data:www-data public_html/storage

# Set proper ownership
chown -R www-data:www-data public_html
```

### 3. Configure Web Server

#### Apache Configuration

Create a virtual host:

```apache
<VirtualHost *:80>
    ServerName email-extractor.example.com
    DocumentRoot /var/www/scrap/public_html

    <Directory /var/www/scrap/public_html>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Security headers
    <IfModule mod_headers.c>
        Header set X-Content-Type-Options "nosniff"
        Header set X-Frame-Options "SAMEORIGIN"
        Header set X-XSS-Protection "1; mode=block"
    </IfModule>

    ErrorLog ${APACHE_LOG_DIR}/email-extractor-error.log
    CustomLog ${APACHE_LOG_DIR}/email-extractor-access.log combined
</VirtualHost>
```

Enable site and restart:
```bash
a2ensite email-extractor
a2enmod rewrite headers
systemctl restart apache2
```

#### Nginx Configuration

Create site configuration:

```nginx
server {
    listen 80;
    server_name email-extractor.example.com;
    root /var/www/scrap/public_html;
    index index.php;

    # Security headers
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # Main router
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP handling
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Protect sensitive files
    location ~ \.(json|bin|tmp|log)$ {
        deny all;
    }

    # Disable access logs for assets
    location ~* \.(jpg|jpeg|png|gif|ico|css|js)$ {
        access_log off;
        expires 30d;
    }

    access_log /var/log/nginx/email-extractor-access.log;
    error_log /var/log/nginx/email-extractor-error.log;
}
```

Enable site and restart:
```bash
ln -s /etc/nginx/sites-available/email-extractor /etc/nginx/sites-enabled/
nginx -t
systemctl restart nginx
```

### 4. Test Installation

```bash
cd /var/www/scrap

# Run system tests
php test_system.php

# Run demo
php demo.php

# Run benchmark
php benchmark.php
```

### 5. Create Configuration (Optional)

```bash
cp config.example.php config.php
# Edit config.php with your settings
```

## Production Optimizations

### 1. Enable PHP OPcache

Edit `php.ini`:
```ini
[opcache]
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
opcache.fast_shutdown=1
```

### 2. Configure Process Management

For systemd, create `/etc/systemd/system/email-worker@.service`:

```ini
[Unit]
Description=Email Extraction Worker %i
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/scrap/public_html
ExecStart=/usr/bin/php /var/www/scrap/public_html/workers/worker.php --job=%i --threads=40
Restart=on-failure
RestartSec=5s

[Install]
WantedBy=multi-user.target
```

Usage:
```bash
systemctl start email-worker@job_123
systemctl status email-worker@job_123
systemctl stop email-worker@job_123
```

### 3. Set Up Monitoring

Create a monitoring script `/var/www/scrap/monitor.sh`:

```bash
#!/bin/bash

# Check if workers are running
WORKERS=$(ps aux | grep worker.php | grep -v grep | wc -l)
echo "Active workers: $WORKERS"

# Check storage usage
STORAGE_SIZE=$(du -sh /var/www/scrap/public_html/storage | cut -f1)
echo "Storage size: $STORAGE_SIZE"

# Check recent jobs
JOBS=$(cat /var/www/scrap/public_html/storage/jobs.json 2>/dev/null | jq length)
echo "Total jobs: $JOBS"
```

Add to cron:
```bash
*/5 * * * * /var/www/scrap/monitor.sh >> /var/log/email-extractor-monitor.log 2>&1
```

### 4. Configure Firewall

```bash
# Allow HTTP/HTTPS
ufw allow 80/tcp
ufw allow 443/tcp

# Rate limiting (optional)
ufw limit 80/tcp
```

### 5. SSL Certificate (Production)

Using Let's Encrypt:

```bash
# Install certbot
apt install certbot python3-certbot-apache  # For Apache
# OR
apt install certbot python3-certbot-nginx   # For Nginx

# Get certificate
certbot --apache -d email-extractor.example.com  # Apache
# OR
certbot --nginx -d email-extractor.example.com   # Nginx

# Auto-renewal
certbot renew --dry-run
```

## Usage

### Web Interface
1. Open browser: `http://email-extractor.example.com`
2. Fill form with keywords and settings
3. Click "بدء الاستخراج"
4. Monitor progress

### API Usage

#### Start Job
```bash
curl -X POST http://email-extractor.example.com/api/start_job.php \
  -H "Content-Type: application/json" \
  -d '{
    "keywords": "technology companies",
    "search_engine": "google",
    "max_results": 100,
    "threads": 40
  }'
```

#### Check Status
```bash
curl http://email-extractor.example.com/api/job_status.php?job_id=JOB_ID
```

#### Stop Job
```bash
curl -X POST http://email-extractor.example.com/api/stop_job.php \
  -H "Content-Type: application/json" \
  -d '{"job_id": "JOB_ID"}'
```

### Manual Worker
```bash
cd /var/www/scrap/public_html
php workers/worker.php --job=JOB_ID --threads=40
```

## Maintenance

### Clear Storage
```bash
cd /var/www/scrap/public_html/storage
rm -f emails.tmp bloom.bin
echo '{}' > jobs.json
```

### Backup
```bash
# Backup storage
tar -czf backup-$(date +%Y%m%d).tar.gz public_html/storage/

# Backup to remote
rsync -avz public_html/storage/ backup-server:/backups/email-extractor/
```

### Logs
```bash
# Apache logs
tail -f /var/log/apache2/email-extractor-error.log

# Nginx logs
tail -f /var/log/nginx/email-extractor-error.log

# Application logs (if enabled)
tail -f /var/www/scrap/public_html/storage/app.log
```

## Troubleshooting

### Issue: Workers not starting
```bash
# Check PHP CLI
php -v
which php

# Check permissions
ls -la public_html/workers/worker.php

# Test manually
php public_html/workers/worker.php --help
```

### Issue: Low performance
```bash
# Check PHP settings
php -i | grep -E 'memory_limit|max_execution_time'

# Check OPcache
php -i | grep opcache

# Run benchmark
php benchmark.php
```

### Issue: Storage errors
```bash
# Check permissions
ls -la public_html/storage/

# Check disk space
df -h

# Fix permissions
chown -R www-data:www-data public_html/storage/
chmod 755 public_html/storage/
```

### Issue: HTTP errors
```bash
# Check web server status
systemctl status apache2  # or nginx

# Test configuration
apache2ctl -t  # or nginx -t

# Check error logs
tail -100 /var/log/apache2/error.log
```

## Security Checklist

- [ ] Storage directory protected (via .htaccess or nginx config)
- [ ] SSL certificate installed (for production)
- [ ] Firewall configured
- [ ] Rate limiting enabled (optional)
- [ ] Regular backups configured
- [ ] Monitoring setup
- [ ] PHP updated to latest stable
- [ ] Sensitive files not accessible via web
- [ ] Strong server passwords
- [ ] SSH key authentication enabled

## Performance Tuning

### For 100,000 emails in <3 minutes:

1. **Server specs**: 8 CPU cores, 32GB RAM
2. **PHP settings**:
   - memory_limit = 1G
   - max_execution_time = 300
3. **Worker threads**: 100-240
4. **Enable OPcache**
5. **Use PHP 8.x**
6. **SSD storage**
7. **Good internet connection**

Expected performance:
- ≥35,000 emails/minute
- ≥240 parallel HTTP requests
- 100,000 unique emails in ~2-3 minutes

## Support

For issues or questions:
- GitHub Issues: https://github.com/omarkh5625/scrap/issues
- Documentation: See README.md and QUICKSTART.md

---

Made with ❤️ for high-performance email extraction
