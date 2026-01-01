# Email Extraction System

Professional multi-job email extraction system using Serper Google Search API with MySQL database for persistent storage.

## Features

- **MySQL Database Integration**: Persistent email storage that survives server restarts
- **Multi-job concurrent execution** with state separation
- **Worker-based architecture** using proc_open
- **Real-time email extraction** with immediate database persistence
- **Domain-level throttling** and adaptive scaling
- **Multi-layered email validation** (MX, content, confidence scoring)
- **Deduplication** at database level
- **SendGrid-styled UI** with real-time monitoring
- **Pagination support** for large email datasets
- **CSV export** functionality

## Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher (or MariaDB 10.2+)
- PHP Extensions:
  - PDO
  - pdo_mysql
  - curl
  - json
  - mbstring
- 512MB+ RAM (recommended: 32GB for high-volume extraction)
- Serper API key (get one at https://serper.dev)

## Installation

### Step 1: Clone or Download

Clone or download this repository to your web server.

### Step 2: Run Database Setup

Navigate to `install.php` in your web browser:

```
http://your-domain.com/install.php
```

Fill in your MySQL database credentials:
- **Database Host**: Usually `localhost` or `127.0.0.1`
- **Database Port**: Default is `3306`
- **Database Name**: Will be created if it doesn't exist (e.g., `email_extraction`)
- **Database Username**: MySQL user with CREATE and INSERT privileges
- **Database Password**: Your MySQL password

Click "Install Database" to create the necessary tables and configuration.

### Step 3: Verify Installation

After successful installation:
1. The system creates a `config.php` file with your database credentials
2. A `.installed` lock file prevents re-installation
3. You can now access the main application at `app.php`

**Security Note**: For production use, delete `install.php` after installation.

## Database Schema

### Tables Created

#### `emails` Table
Stores all extracted emails with metadata:
- `id`: Auto-incrementing primary key
- `job_id`: Reference to the extraction job
- `email`: Email address (unique per job)
- `quality`: Enum('high', 'medium', 'low')
- `confidence`: Decimal score (0.00 - 1.00)
- `source_url`: URL where email was found
- `worker_id`: Worker that extracted the email
- `created_at`: Timestamp of extraction
- `updated_at`: Last update timestamp

#### `jobs` Table
Stores job metadata and statistics:
- `id`: Unique job identifier
- `name`: Job name
- `query`: Search query
- `options`: JSON configuration
- `status`: Job status (created, running, stopped, error)
- `emails_found`: Total emails found
- `emails_accepted`: Emails passing validation
- `emails_rejected`: Emails rejected
- `urls_processed`: URLs scanned
- `errors`: Error count
- `worker_count`: Number of workers
- `created_at`: Job creation time
- `started_at`: Job start time

#### `job_errors` Table
Tracks errors for debugging:
- `id`: Auto-incrementing primary key
- `job_id`: Reference to job
- `error_message`: Error description
- `created_at`: Error timestamp

## Usage

### Access the Application

Navigate to `app.php` in your web browser:

```
http://your-domain.com/app.php
```

### Create Your First Job

1. **Enter API Key**: Input your Serper API key in the sidebar
2. **Test Connection**: Click "Test Connection" to verify your API key
3. **Configure Job**:
   - Job Name: Descriptive name for your extraction job
   - Main Search Query: Your search term (e.g., "real estate agents")
   - Additional Keywords: Optional keywords to combine with main query
   - Target Country: Select target country
   - Language: Select language
   - Target Emails: Number of emails you want to collect
   - Max Workers: Number of parallel workers (1-1000)
4. **Create Job**: Click "Create Job"
5. **Start Extraction**: Click "Start" on your job card

### Monitor Progress

The dashboard shows real-time progress:
- **Email Collection Progress**: Visual progress bar showing extracted emails vs. target
- **Active Workers**: Number of workers currently running
- **Accept/Reject Rates**: Email validation statistics
- **Emails per Hour**: Extraction rate

### View Results

Click on a job name to view extracted emails:
- Paginated display (50 emails per page)
- Email quality indicators
- Source URLs
- Timestamps
- Export to CSV functionality

### Export Data

Click "Export to CSV" on the results page to download all emails for a job.

## Data Persistence

### How It Works

1. **Real-Time Storage**: Workers save emails directly to MySQL as they're extracted
2. **Deduplication**: UNIQUE KEY constraint prevents duplicate emails per job
3. **Retry Logic**: Automatic retry for database connection failures (3 attempts, 2-second delay)
4. **Fallback**: If database is unavailable, falls back to temporary file storage
5. **Job Recovery**: Jobs persist across server restarts and page refreshes

### Benefits Over File Storage

- **Permanent Storage**: Emails never lost due to refresh or server restart
- **Scalability**: Handle millions of emails efficiently
- **Fast Queries**: Indexed database queries for instant retrieval
- **Concurrent Access**: Multiple users can access data simultaneously
- **Data Integrity**: ACID compliance ensures consistency

## Backward Compatibility

The system maintains full backward compatibility:
- **Without Database**: Falls back to file-based storage in `/tmp/email_extraction/`
- **With Database**: Uses MySQL for permanent storage
- **Hybrid Mode**: Can read from both sources seamlessly

If you haven't run `install.php`, the system will:
- Show a warning in the UI
- Store data in temporary files
- Display "Database: File Storage (Temporary)" indicator

## Configuration

### Database Configuration (config.php)

Auto-generated by `install.php`:

```php
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
define('DB_NAME', 'email_extraction');
```

**Important**: Never commit `config.php` to version control (it's excluded in `.gitignore`).

### API Key Persistence

API keys are stored in browser's localStorage for convenience. They're automatically loaded on page refresh.

## Troubleshooting

### Database Connection Failed

**Error**: "Database connection failed: Access denied"
**Solution**: 
1. Verify MySQL credentials in `config.php`
2. Ensure MySQL user has proper privileges
3. Check MySQL is running: `sudo systemctl status mysql`

### Workers Not Starting

**Error**: "proc_open function is not available"
**Solution**:
1. Enable `proc_open` in php.ini
2. Remove from `disable_functions` list
3. Restart web server

### Emails Not Saving

**Check**:
1. Database status indicator (should show "MySQL Connected")
2. Job errors section in UI
3. System logs in `/tmp/email_extraction/logs/`
4. MySQL error logs

### Memory Issues

**Error**: "High memory usage"
**Solution**:
1. Reduce number of workers
2. Increase PHP memory_limit in php.ini
3. Monitor with dashboard memory indicator

## Security Recommendations

1. **Delete install.php** after setup
2. **Protect config.php** from web access
3. **Use strong MySQL passwords**
4. **Restrict database user privileges** to only required operations
5. **Enable HTTPS** for production use
6. **Regular backups** of MySQL database
7. **Monitor logs** for suspicious activity

## Performance Tuning

### Database Optimization

```sql
-- Add indexes for better performance
ALTER TABLE emails ADD INDEX idx_job_created (job_id, created_at DESC);
ALTER TABLE emails ADD INDEX idx_quality (quality);

-- Optimize tables periodically
OPTIMIZE TABLE emails;
OPTIMIZE TABLE jobs;
```

### PHP Configuration

```ini
memory_limit = 512M
max_execution_time = 0
max_input_time = 0
```

### MySQL Configuration

```ini
innodb_buffer_pool_size = 2G
innodb_log_file_size = 256M
max_connections = 200
```

## Maintenance

### Database Backup

```bash
# Backup all tables
mysqldump -u username -p email_extraction > backup_$(date +%Y%m%d).sql

# Restore from backup
mysql -u username -p email_extraction < backup_20231231.sql
```

### Clean Old Data

```sql
-- Delete jobs older than 30 days
DELETE FROM jobs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Clean orphaned emails (optional - cascade delete handles this)
DELETE FROM emails WHERE job_id NOT IN (SELECT id FROM jobs);
```

## Support

For issues, feature requests, or contributions, please visit the project repository.

## License

This project is proprietary software. All rights reserved.

## Version History

### v1.0.0 (Current)
- MySQL database integration
- Real-time email persistence
- Database status indicators
- API key persistence
- Improved error handling
- Pagination support
- CSV export from database