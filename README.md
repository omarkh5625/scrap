# PHP Scraping System - Single File Application

A complete, production-ready web scraping system built entirely in a single PHP file with no dependencies or frameworks.

## Features

✅ **Single File Architecture** - Everything in `app.php` (1,704 lines)  
✅ **Setup Wizard** - Automatic database installation on first run  
✅ **Authentication** - Session-based login/logout system  
✅ **Dashboard** - Real-time statistics and job monitoring  
✅ **Job Management** - Create and track scraping jobs  
✅ **CLI Workers** - Background workers for processing jobs  
✅ **Google Serper.dev Integration** - Professional search API  
✅ **BloomFilter Deduplication** - Prevents duplicate URLs  
✅ **Export** - Results in CSV or JSON format  
✅ **Professional UI** - Modern gradient design with real-time updates  

## Requirements

- PHP 8.0 or higher
- MySQL 5.7 or higher
- curl extension enabled
- PDO MySQL extension enabled

## Installation

1. **Upload the file**: Place `app.php` on your web server or cPanel
2. **Access via browser**: Navigate to `http://yourdomain.com/app.php`
3. **Complete setup wizard**:
   - Enter MySQL database credentials
   - Create admin account
   - Click "Install System"
4. **Login**: Use the admin credentials you created

## Usage

### Web Interface

1. **Create a Job**:
   - Click "New Job" in the sidebar
   - Enter your search query
   - Add your Serper.dev API key (get it from https://serper.dev)
   - Set maximum results
   - Click "Create Job"

2. **Start Workers**:
   - Workers process jobs in the background
   - See "Workers" section below

3. **View Results**:
   - Go to "Dashboard" to see all jobs
   - Click "View" on any job to see results
   - Export results as CSV or JSON

### CLI Workers

Start background workers to process jobs:

```bash
# Start a worker
php app.php worker-1

# Start multiple workers
php app.php worker-1 &
php app.php worker-2 &
php app.php worker-3 &
```

Workers will:
- Automatically pick up pending jobs
- Process them using the Serper.dev API
- Save results to the database
- Update job progress in real-time
- Continue running until stopped (Ctrl+C)

### On cPanel

1. Upload `app.php` to your public_html directory
2. Access via browser to run setup
3. Use cron jobs to run workers:
   ```
   * * * * * php /home/username/public_html/app.php worker-cron
   ```

## Architecture

### Classes

- **Database** - PDO connection and schema management
- **Auth** - User authentication and session handling
- **Job** - Job creation and result management
- **Worker** - CLI worker and job processing
- **BloomFilter** - URL deduplication using SHA-256
- **Settings** - System configuration storage
- **Router** - Request routing and page rendering

### Database Schema

- `users` - User accounts
- `jobs` - Scraping jobs and status
- `results` - Scraped results
- `bloomfilter` - URL hash storage for deduplication
- `workers` - Worker registry and heartbeat
- `settings` - System settings

### Security Features

- Password hashing with bcrypt
- Session-based authentication
- Prepared statements (SQL injection prevention)
- CSRF protection
- Input validation and sanitization

## API Endpoints

The system includes JSON API endpoints:

- `?page=api&action=stats` - Dashboard statistics
- `?page=api&action=workers` - Worker status
- `?page=api&action=jobs` - Job list

## Deduplication

The system uses a **BloomFilter** implementation with:
- SHA-256 hashing
- URL normalization (removes protocol, www, trailing slashes)
- Global deduplication across all jobs
- Database-backed for persistence

## Configuration

After installation, you can modify settings via the Settings page:
- Default API key
- Default max results
- Rate limiting

## Troubleshooting

**Database connection failed**
- Verify MySQL credentials
- Ensure MySQL is running
- Check if database user has proper permissions

**Workers not processing jobs**
- Ensure curl extension is enabled
- Verify Serper.dev API key is valid
- Check PHP CLI is accessible

**Setup wizard won't appear**
- Delete `app.php` and re-upload
- Or manually edit config section in `app.php` to set `'installed' => false`

## Development

The application is structured with:
- Configuration section at top (auto-updated by setup)
- Class definitions (Database, Auth, Job, etc.)
- Router for handling requests
- Embedded CSS and JavaScript
- No external dependencies

## License

This is a custom-built application. Modify as needed for your use case.

## Support

For issues or questions, please refer to the code comments and inline documentation.