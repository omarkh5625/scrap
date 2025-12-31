# Professional Email Extraction System

A comprehensive, single-file PHP system for cPanel that performs real-time, multi-job email extraction using the Serper Google Search API.

## Features

### Core Architecture
- **Single-File Design**: All functionality in `index.php` for easy deployment on cPanel
- **Multi-Job Processing**: Run multiple independent email extraction jobs in parallel
- **Worker System**: Managed worker processes using `proc_open` with adaptive scaling
- **Real-time UI**: Professional dark-themed dashboard with AJAX updates

### Modular Components

1. **JobManager**: Handles complete job lifecycle (create, update, delete, monitor)
2. **WorkerGovernor**: Manages worker processes with automatic cleanup and timeout handling
3. **URLFilter**: Validates URLs and excludes images, PDFs, and unwanted patterns
4. **ContentFilter**: Ensures content quality before processing
5. **EmailExtractor**: Extracts emails using sophisticated regex patterns
6. **EmailValidator**: Multi-layer validation including:
   - Regex pattern matching
   - Blacklisted domain filtering (Gmail, Yahoo, etc.)
   - Invalid pattern detection (noreply@, support@, etc.)
   - Format validation
7. **DomainLimiter**: Throttles low-yield domains to optimize API usage
8. **ConfidenceScorer**: Scores extracted emails based on multiple factors
9. **DedupEngine**: In-memory deduplication for URLs and emails (no database overhead)
10. **SearchScheduler**: Dynamically prioritizes search terms based on yield
11. **ErrorHandler**: Comprehensive error logging and recovery
12. **Supervisor/Watchdog**: Monitors system health and auto-recovers from errors

### Key Features

- **API Key Management**: Secure API key storage with test connection feature
- **Job Control**: Create, pause, resume, and delete jobs via UI
- **Real-time Statistics**: Live updates of emails extracted, URLs processed, and active workers
- **Email Export**: Export extracted emails to CSV format
- **SQLite Database**: Lightweight, file-based storage with proper schema
- **Error Recovery**: Automatic retry for rate limits (HTTP 429) and timeouts
- **Professional UI**: SendGrid-inspired dark theme with responsive design

## Requirements

- PHP 7.4 or higher
- SQLite3 extension enabled
- cURL extension enabled
- proc_open() function enabled
- Serper API key (get from https://serper.dev)

## Installation

1. Upload `index.php` to your cPanel public_html directory
2. Ensure PHP has write permissions for the directory (for SQLite database)
3. Access the system through your browser: `https://yourdomain.com/index.php`

## Usage

### Initial Setup

1. **Configure API Key**:
   - Enter your Serper API key in the sidebar
   - Click "Save Key" to store it
   - Click "Test API" to verify connectivity

2. **Create a Job**:
   - Enter a job name (e.g., "Tech Companies")
   - Add search terms, one per line (e.g., "tech startup email", "software company contact")
   - Click "Create Job"

3. **Monitor Progress**:
   - View real-time statistics on the dashboard
   - See active workers, emails extracted, and URLs processed per job
   - Jobs update automatically every 5 seconds

4. **Manage Jobs**:
   - **Pause**: Temporarily stop a job
   - **Resume**: Restart a paused job
   - **Export**: Download extracted emails as CSV
   - **Delete**: Remove a job and all its data

## Technical Details

### Database Schema

The system uses SQLite with the following tables:
- `config`: Stores API keys and settings
- `jobs`: Job information and statistics
- `emails`: Extracted and validated emails
- `urls_processed`: Tracks processed URLs to avoid duplicates
- `error_log`: System errors and recovery attempts
- `domain_stats`: Domain-level statistics for throttling

### Email Validation Pipeline

1. **Regex Extraction**: Pattern-based email extraction from HTML/text
2. **Format Validation**: RFC-compliant email format checking
3. **Blacklist Filtering**: Removes major consumer email providers
4. **Pattern Filtering**: Excludes noreply, support, admin addresses
5. **Confidence Scoring**: Assigns quality score (0-100)
6. **Deduplication**: In-memory hash-based duplicate prevention

### Worker Management

- Maximum 5 workers per job (configurable)
- 300-second timeout per worker
- Automatic cleanup of dead/zombie processes
- Graceful handling of HTTP 429 rate limits
- Independent worker processes using `proc_open()`

### Search Optimization

- Dynamic term prioritization based on email yield
- Automatic throttling of low-yield domains
- Intelligent URL filtering (excludes PDFs, images, etc.)
- Content quality validation before processing

## Security Features

- API keys stored in database, not hardcoded
- SQLite database auto-generated with proper permissions
- Input validation and sanitization
- SQL injection prevention using prepared statements
- XSS protection through proper HTML escaping

## Performance

- Designed to extract up to 4M emails/day with proper API limits
- In-memory deduplication for fast lookups
- Minimal database writes (only validated emails)
- Efficient worker process management
- Automatic scaling based on system load

## Troubleshooting

### Workers Not Starting
- Ensure `proc_open()` is enabled in PHP configuration
- Check file permissions for database directory
- Verify API key is correctly configured

### No Emails Found
- Test API connection to verify credentials
- Check search terms are relevant
- Review error log in database for specific issues

### Database Errors
- Ensure directory is writable by PHP
- Check SQLite3 extension is installed
- Verify sufficient disk space

## Architecture Highlights

### Why Single File?
- Easy deployment on shared hosting (cPanel)
- No complex installation or dependencies
- All components in one place for maintenance
- Simple updates (replace one file)

### Professional Code Structure
Despite being a single file, the system maintains professional architecture:
- Clear separation of concerns with classes
- Modular, reusable components
- Comprehensive error handling
- Well-documented code
- Follows SOLID principles where applicable

## License

This is a proprietary system built for specific requirements. All rights reserved.

## Support

For issues or questions, please contact the system administrator.