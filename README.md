# Email Extraction System

A powerful PHP-based email extraction system that uses Google Serper.dev API to find and extract email addresses from search results.

## Features

- **Email Extraction**: Extracts emails from search results and page content using regex patterns
- **Country Targeting**: Target search results from specific countries
- **Email Filtering**: Filter by Gmail, Yahoo, Business domains, or all email types
- **Async Background Workers**: Support for 1-1000 concurrent workers for high-performance extraction
- **Deep Scraping**: Optional page content fetching for comprehensive email extraction
- **Deduplication**: BloomFilter-based duplicate detection
- **Export Options**: Export results as CSV or JSON
- **Real-time Dashboard**: Monitor jobs, progress, and results in real-time
- **CLI Workers**: Run background workers via command line

## Requirements

- PHP 8.0+
- MySQL/MariaDB
- cURL extension
- Serper.dev API key

## Installation

1. Upload `app.php` to your web server
2. Access the setup wizard in your browser
3. Configure database connection and create admin account
4. Start background workers: `php app.php worker-name`

## Usage

### Creating Jobs

1. Login to the dashboard
2. Click "New Email Job"
3. Enter search query (e.g., "real estate agents california")
4. Add your Serper.dev API key
5. Configure options:
   - Maximum emails to extract (1-100,000)
   - Country target (optional)
   - Email type filter (All/Gmail/Yahoo/Business)
6. Submit job

### Running Workers

Start one or more background workers to process jobs:

```bash
php app.php worker-1
php app.php worker-2
...
php app.php worker-N
```

Workers can run on the same machine or distributed across multiple servers.

## Performance

- Supports up to 1000 concurrent workers
- Target: 100,000 emails in < 3 minutes (with adequate resources)
- Recommended: 32GB RAM, 8 vCPU for optimal performance

## Settings

- **Default API Key**: Set a default Serper.dev API key
- **Default Max Results**: Default number of emails per job
- **Rate Limit**: Delay between API requests (seconds)
- **Deep Scraping**: Enable/disable page content fetching

## License

Open source - feel free to modify and use.