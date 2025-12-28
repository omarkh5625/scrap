# 🚀 Ultra Email Intelligence Platform

A comprehensive PHP platform for ultra-fast email extraction and generation using SerpApi. Designed for cPanel hosting with complete worker control and real-time progress tracking.

## ✨ Features

### 🎯 Core Functionality
- **High-Density Email Extraction**: Discover companies, business listings, and contacts from Google search results
- **SerpApi Integration**: Leverage powerful search API for company discovery
- **Multi-Phase Processing**: 
  - **Discover**: Find companies and websites using SerpApi
  - **Extract**: Parse HTML to extract email addresses
  - **Generate**: Create potential email addresses using common patterns
- **Real-Time Progress**: Live dashboard with statistics and progress bars
- **Worker Management**: Dynamic control of concurrent workers through UI

### 🔐 Security
- Secure admin authentication with session management
- Password encryption using bcrypt
- SQL injection protection with prepared statements
- Session-based access control

### 📊 Dashboard
- Total jobs and email collection statistics
- Active worker monitoring
- Processing speed metrics
- Real-time updates via AJAX

### ⚙️ Configuration
- SQLite or MySQL database support
- SerpApi settings management
- Configurable worker counts
- Search engine preferences (Google, Google Maps, Bing)
- Language and country targeting

## 📋 Requirements

- PHP 7.4 or higher
- PDO extension (SQLite or MySQL)
- cURL extension
- PCNTL extension (for worker processes)
- cPanel hosting (recommended) or any PHP web hosting

## 🚀 Installation

### 1. Upload Files
Upload all files to your cPanel public_html directory or a subdirectory.

### 2. Set Permissions
```bash
chmod 755 workers/*.php
chmod 755 data/
chmod 755 logs/
```

### 3. Run Installation Wizard
Navigate to `https://yourdomain.com/install.php` in your browser.

The installation wizard will guide you through:
1. **Database Configuration**: Choose SQLite (recommended) or MySQL
2. **Table Creation**: Automatically creates all necessary database tables
3. **Admin Account**: Create your administrator account
4. **Finalization**: Generates `config.php` and locks the installation

## 📖 Usage Guide

### First Login
1. Navigate to `https://yourdomain.com/login.php`
2. Enter your admin credentials created during installation
3. You'll be redirected to the dashboard

### Configure SerpApi
1. Go to **Settings** from the main menu
2. Enter your SerpApi key (get one from [serpapi.com](https://serpapi.com))
3. Click **Verify** to test the API key
4. Configure default search settings (language, country, search engine)
5. Set worker counts for each task type
6. Click **Save Settings**

### Create a Job
1. Click **New Job** from the menu
2. Fill in job details:
   - **Job Name**: Descriptive name (e.g., "Crypto Companies USA")
   - **Niche/Keywords**: Industry keywords (comma-separated)
   - **Target Country**: Optional geographic focus
   - **Search Depth**: Number of search queries to generate
   - **Email Type**: Filter by domain/executive/personal emails
   - **Speed Mode**: Normal/Fast/Ultra Fast processing
3. Click **Create Job**

### Start Workers
1. Navigate to **Workers Control**
2. For each worker type (Discover, Extract, Generate):
   - Enter the number of workers to start
   - Click **Start**
3. Workers will automatically process pending tasks
4. Monitor worker status in real-time
5. Stop workers when needed using the **Stop All** button

### View Results
1. Go to **Results** from the menu
2. Use filters to narrow down results:
   - Job
   - Email Type
   - Domain
   - Search terms
3. Export results in CSV or JSON format
4. Pagination available for large result sets

## 🏗️ Architecture

### Database Schema
- **users**: Admin accounts
- **jobs**: Email extraction jobs
- **queue**: Task queue for workers
- **emails**: Collected email addresses
- **settings**: Application configuration
- **workers_status**: Worker monitoring and heartbeat
- **logs**: System logs

### Worker System
Workers are CLI-based PHP scripts that run as background processes:

- **Discover Worker** (`workers/discover_worker.php`):
  - Uses SerpApi to search for companies
  - Creates extraction tasks for found URLs
  - Handles Google, Google Maps, and other search engines

- **Extract Worker** (`workers/extract_worker.php`):
  - Fetches website HTML
  - Extracts email addresses using regex patterns
  - Classifies emails (domain/executive/personal)
  - Creates generation tasks for sites without emails

- **Generate Worker** (`workers/generate_worker.php`):
  - Generates common email patterns (info@, contact@, etc.)
  - Creates domain-based email variations
  - Deduplicates against existing emails

### Queue System
- Priority-based task processing
- Automatic task distribution to available workers
- Heartbeat monitoring for worker health
- Retry logic for failed tasks

## 🔧 Performance Optimization

### Speed Modes
- **Normal**: Balanced processing with standard delays
- **Fast**: Reduced delays for quicker processing
- **Ultra Fast**: Maximum speed with minimal delays

### Batch Processing
- Batch INSERT operations for efficiency
- Prepared statements for all database queries
- Transaction support for data consistency

### Resource Management
- Configurable worker counts per task type
- Sleep intervals during idle periods
- Automatic worker shutdown on completion
- Memory-efficient processing

## 📁 File Structure

```
/
├── install.php              # Installation wizard
├── config.php              # Auto-generated configuration
├── db.php                  # Database connection utilities
├── auth.php                # Authentication helpers
├── login.php               # Admin login
├── logout.php              # Logout handler
├── index.php               # Main dashboard
├── new_job.php             # Job creation interface
├── settings.php            # SerpApi and system settings
├── workers_control.php     # Worker management panel
├── results.php             # Results viewer and export
├── api.php                 # AJAX API endpoints
├── workers/
│   ├── BaseWorker.php      # Base worker class
│   ├── discover_worker.php # Discovery worker
│   ├── extract_worker.php  # Extraction worker
│   └── generate_worker.php # Generation worker
├── data/                   # SQLite database (auto-created)
└── logs/                   # Application logs (auto-created)
```

## 🔒 Security Considerations

- Always use HTTPS in production
- Keep your SerpApi key secure
- Regularly backup your database
- Monitor worker processes for unusual activity
- Use strong admin passwords (8+ characters)
- Keep PHP and dependencies updated

## 🐛 Troubleshooting

### Workers Not Starting
- Check PHP CLI is available: `php -v`
- Verify PCNTL extension: `php -m | grep pcntl`
- Check file permissions on worker scripts
- Review logs in the `logs/` directory

### SerpApi Errors
- Verify API key is correct
- Check API quota/limits on SerpApi dashboard
- Ensure cURL extension is enabled
- Test API key using the Verify button in Settings

### Database Errors
- Ensure data directory is writable
- For MySQL: verify credentials in config.php
- Check database connection in install.php
- Review error logs

### No Results
- Ensure workers are running
- Check that SerpApi key is configured
- Verify search queries are generating results
- Monitor queue for pending tasks

## 📊 API Endpoints

The platform includes AJAX endpoints for real-time updates:

- `api.php?action=stats` - Overall statistics
- `api.php?action=job_progress&job_id=X` - Job progress
- `api.php?action=workers` - Worker status
- `api.php?action=recent_emails&limit=X` - Recent emails

## 🤝 Support

For issues and questions:
1. Check the troubleshooting section
2. Review application logs
3. Check worker status in Workers Control panel
4. Verify SerpApi configuration

## 📝 License

This project is provided as-is for email intelligence gathering purposes.

## 🎯 Roadmap

- [ ] Email verification integration
- [ ] Advanced filtering and search
- [ ] Scheduled jobs
- [ ] Multi-user support with roles
- [ ] API rate limiting
- [ ] Webhook notifications
- [ ] Export templates

---

**Built with ❤️ for ultra-fast email intelligence**