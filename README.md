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

The system automatically calculates optimal worker count:

- 1,000 emails → 50 workers (20 emails/worker)
- 10,000 emails → 500 workers (20 emails/worker)
- 100,000 emails → 1,000 workers (100 emails/worker, capped)

### Performance Expectations

- **Single Worker**: ~2-5 emails/second
- **50 Workers**: ~100-250 emails/second
- **500 Workers**: ~1000-2500 emails/second

Actual performance depends on:
- Database server resources
- Network latency
- API rate limits
- Deep scraping settings

## Troubleshooting

### "Too many connections" Error

If you see this error despite the retry logic:

1. **Increase MySQL max_connections**:
   ```sql
   SET GLOBAL max_connections = 1000;
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

```
Attempt 1: Wait 100ms
Attempt 2: Wait 200ms (with 0-60ms jitter)
Attempt 3: Wait 400ms (with 0-120ms jitter)
Attempt 4: Wait 800ms (with 0-240ms jitter)
Attempt 5: Wait 1600ms (with 0-480ms jitter)
```

Maximum retry delay is capped at 5 seconds.

### Batch Processing

- Emails are inserted in batches of 1000
- BloomFilter checks in batches of 1000
- Parallel URL scraping in batches of 100

## License

MIT