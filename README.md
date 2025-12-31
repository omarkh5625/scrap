# Email Extraction System

A high-performance PHP email extraction system with parallel worker processing and advanced database connection management.

## Features

- **Parallel Worker Execution**: Spawn up to 1000 workers for maximum throughput
- **Database Connection Pooling**: Intelligent connection management with retry logic
- **Exponential Backoff**: Automatic retry on connection failures
- **Batch Operations**: Optimized bulk inserts for high performance
- **Email Filtering**: Support for Gmail, Yahoo, and business emails
- **Country Targeting**: Extract emails by geographic region
- **BloomFilter Deduplication**: Efficient duplicate detection

## Database Configuration

### Recommended MySQL Settings for High Concurrency

To handle 500-1000 concurrent workers, update your MySQL configuration (`my.cnf` or `my.ini`):

```ini
[mysqld]
# Connection settings
max_connections = 1000          # Allow more concurrent connections (default: 151)
max_connect_errors = 100000     # Prevent blocking after failed attempts
connect_timeout = 10            # Connection timeout in seconds

# Performance optimizations
table_open_cache = 4000         # Cache for table file descriptors
innodb_buffer_pool_size = 2G    # InnoDB buffer pool (adjust to 70-80% of RAM)
innodb_log_file_size = 512M     # Transaction log size

# Thread settings
thread_cache_size = 100         # Cache threads for connection reuse
thread_stack = 256K             # Stack size per thread

# Query cache (optional, deprecated in MySQL 8.0)
query_cache_size = 0            # Disable if using MySQL 8.0+
query_cache_type = 0
```

### Connection Management Features

The system includes advanced connection management to handle "Too many connections" errors:

1. **Automatic Retry**: Up to 5 retry attempts with exponential backoff
2. **Connection Pooling**: Singleton pattern reuses connections across requests
3. **Connection Cleanup**: Automatic closing after batch operations
4. **Health Checks**: Automatic reconnection on stale connections
5. **Jitter**: Random delay added to prevent thundering herd problem

### Error Recovery

When encountering database connection issues:

- **Error 1040 (Too many connections)**: System automatically retries with increasing delays
- **Connection lost**: Automatic reconnection on next query
- **Transaction failures**: Proper rollback and retry logic

## Scaling Guidelines

### Email Volume vs Workers

**IMPORTANT**: Default settings are conservative to work with standard MySQL configurations (max_connections ~151).

The system automatically calculates optimal worker count:

- 1,000 emails → 10 workers (100 emails/worker)
- 10,000 emails → 100 workers (100 emails/worker, capped at AUTO_MAX_WORKERS)
- 100,000 emails → 100 workers (1000 emails/worker, capped)

**Note**: Fewer workers doesn't mean slower processing! Workers process multiple emails in batches and reuse connections efficiently. 100 workers can handle large-scale extractions without overwhelming the database.

### Increasing Worker Count (Advanced)

If you've increased MySQL `max_connections` (see MYSQL_TUNING.md), you can increase worker limits in `app.php`:

```php
// In Worker class
private const AUTO_MAX_WORKERS = 100;        // Increase to 500-800 if max_connections = 1000
private const WORKERS_PER_1000_EMAILS = 10;  // Increase to 20-30 if max_connections = 1000
```

**Warning**: Don't increase workers without first increasing MySQL max_connections!

### Performance Expectations

With default settings (100 max workers):
- **Single Worker**: ~2-5 emails/second
- **10 Workers**: ~20-50 emails/second
- **100 Workers**: ~200-500 emails/second

With increased settings (500 workers, requires MySQL tuning):
- **500 Workers**: ~1000-2500 emails/second

Actual performance depends on:
- Database server resources
- Network latency
- API rate limits
- Deep scraping settings
- MySQL max_connections configuration

## Troubleshooting

### "Too many connections" Error

**This is the most common issue.** It means the number of workers exceeds MySQL's connection limit.

**SOLUTION (Choose ONE)**:

**Option 1: Use Default Settings (Recommended for most users)**
- The system now defaults to 100 max workers, which works with standard MySQL configurations
- No changes needed - this should work out of the box
- For 10,000 emails: 100 workers will be spawned (safe for default MySQL)

**Option 2: Increase MySQL max_connections (For advanced users)**

If you need more workers for faster processing:

1. **Check current limit**:
   ```sql
   SHOW VARIABLES LIKE 'max_connections';
   ```

2. **Increase temporarily**:
   ```sql
   SET GLOBAL max_connections = 500;
   ```

3. **Increase permanently** (edit MySQL config):
   ```ini
   [mysqld]
   max_connections = 500
   ```
   Then restart MySQL.

4. **Increase worker limits** in `app.php`:
   ```php
   // In Worker class (around line 1377)
   private const AUTO_MAX_WORKERS = 300;        // Increase from 100 to 300
   private const WORKERS_PER_1000_EMAILS = 20;  // Increase from 10 to 20
   ```

**Option 3: Reduce Workers Further**

If still getting errors, reduce even more:
```php
private const AUTO_MAX_WORKERS = 50;         // Very conservative
private const WORKERS_PER_1000_EMAILS = 5;   // Very conservative
```

2. **Check current connections**:
   ```sql
   SHOW STATUS WHERE `variable_name` = 'Threads_connected';
   SHOW PROCESSLIST;
   ```

3. **Verify configuration**:
   ```sql
   SHOW VARIABLES LIKE 'max_connections';
   ```

### High Memory Usage

If workers consume too much memory:

1. Reduce `BULK_INSERT_BATCH_SIZE` (default: 1000)
2. Lower `BloomFilter` cache size
3. Increase `php.ini` memory_limit if needed

### Worker Crashes

Check logs in `/php_errors.log` for:
- Database connection timeouts
- Memory exhaustion
- PHP fatal errors

## Installation

1. Configure database settings in `app.php`
2. Set up MySQL with recommended settings above
3. Ensure PHP 8.0+ is installed
4. Run the application: `php app.php`

## Technical Details

### Connection Retry Strategy

Exponential backoff with 30% random jitter (increased delays for "Too many connections" errors):

```
Normal connection errors:
Attempt 1: Wait 500ms + jitter (0-150ms)     = 500-650ms
Attempt 2: Wait 1000ms + jitter (0-300ms)    = 1000-1300ms
Attempt 3: Wait 2000ms + jitter (0-600ms)    = 2000-2600ms
Attempt 4: Wait 4000ms + jitter (0-1200ms)   = 4000-5200ms
Attempt 5: Wait 8000ms + jitter (0-2400ms)   = 8000-10400ms (capped at 10s)

"Too many connections" errors (doubled delays):
Attempt 1: Wait 1000ms + jitter              = 1000-1300ms
Attempt 2: Wait 2000ms + jitter              = 2000-2600ms
Attempt 3: Wait 4000ms + jitter              = 4000-5200ms
Attempt 4: Wait 8000ms + jitter              = 8000-10400ms (capped at 10s)
Attempt 5: Wait 10000ms + jitter             = 10000-13000ms (capped at 10s)
```

Maximum retry delay is capped at 10 seconds. The 30% jitter prevents thundering herd problem where all workers retry simultaneously.

**Note**: Longer delays give other workers time to finish and release connections.

### Batch Processing

- Emails are inserted in batches of 1000
- BloomFilter checks in batches of 1000
- Parallel URL scraping in batches of 100

## License

MIT