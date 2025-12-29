# Email Extraction System

High-performance PHP email extraction system with intelligent filtering and parallel processing.

## 🚀 Recent Performance Improvements

### Performance Enhancements
- **3x Faster Extraction**: Reduced rate limit from 0.3s to 0.1s
- **2x Parallel Capacity**: Increased curl_multi connections from 50 to 100
- **Optimized Timeouts**: Reduced connection timeouts for faster processing
- **Target Performance**: 100,000 emails in ~3 minutes with 20-50 workers

### UI/UX Improvements
- **Unified Dashboard**: All worker management moved to main dashboard
- **One-Click Job Creation**: Create jobs directly from dashboard
- **Real-Time Statistics**: Live worker stats and extraction rates
- **Smart Query Templates**: 6 pre-built templates for high-yield searches
- **Mandatory Worker Count**: Must specify workers before starting extraction

### Email Quality Filtering
- **50+ Blacklisted Domains**: Automatic filtering of junk/famous sites
- **Smart Validation**: Removes placeholder and example emails
- **Business Email Default**: Filters out social media and free email providers
- **Domain Blacklist**: Blocks google.com, facebook.com, news sites, gov/edu domains

### Query Optimization
- **Industry Templates**: Pre-built queries for real estate, dentists, lawyers, etc.
- **Location Targeting**: Recommendations for geographic filtering
- **Performance Tips**: Built-in guidance for optimal extraction

## 🎯 Key Features

### Parallel Processing
- Up to 100 simultaneous HTTP connections via curl_multi
- 20 parallel connections per host
- Automatic worker spawning and management
- Queue-based job distribution

### Smart Filtering
- BloomFilter deduplication with 10K in-memory cache
- Bulk email insertion (1000 per batch)
- Email type filtering (All/Business/Gmail/Yahoo)
- Country targeting support

### Performance Monitoring
- Real-time worker statistics
- Emails per minute extraction rate
- Active/idle worker tracking
- Job progress tracking

## 📊 Default Settings

| Setting | Value | Notes |
|---------|-------|-------|
| Default Workers | 20 | Recommended: 20-50 for best performance |
| Rate Limit | 0.1s | Optimized for maximum throughput |
| Max Connections | 100 | Parallel HTTP requests |
| Target Emails | 1000 | Recommended starting point |
| Email Filter | Business | Highest quality results |
| Connection Timeout | 3s | Balanced speed/reliability |

## 🔧 Configuration

### Rate Limiting
- Default: 0.1 seconds between requests
- Configurable in Settings (0.01s granularity)
- Works with curl_multi parallel processing

### Worker Configuration
- Minimum: 1 worker required
- Maximum: 1000 workers supported
- Recommended: 20-50 workers for 100k emails in 3 minutes

### Email Filtering
- **All Types**: No filtering
- **Business Only**: Excludes free providers (gmail, yahoo, etc.)
- **Gmail Only**: Only gmail.com addresses
- **Yahoo Only**: Only yahoo.com addresses

## 📈 Performance Targets

| Workers | Target Emails | Expected Time | Extraction Rate |
|---------|---------------|---------------|-----------------|
| 5-10    | 1,000        | ~1-2 min      | ~500-1000/min  |
| 10-20   | 10,000       | ~3-5 min      | ~2000-3000/min |
| 20-50   | 100,000      | ~3-5 min      | ~20000-30000/min |

*Times are approximate and depend on query quality, network speed, and API rate limits*

## 🛡️ Security Features

- Password hashing with bcrypt
- Session-based authentication
- SQL injection protection via PDO prepared statements
- XSS protection via htmlspecialchars
- CSRF protection recommended for production

## 📦 Requirements

- PHP 8.0 or higher
- MySQL 5.7 or higher
- curl with multi support
- Serper.dev API key

## 🎨 UI Changes

### Removed Pages
- Workers page (functionality moved to Dashboard)
- New Job page (integrated into Dashboard)

### Enhanced Dashboard
- Inline job creation form
- Live worker statistics
- Query template selector
- Performance tips and recommendations
- Real-time progress tracking

## 🔍 Query Templates

Pre-built templates for high-yield searches:
- 🏘️ Real Estate Agents
- 🦷 Dentists
- ⚖️ Lawyers
- 🍽️ Restaurants
- 🔧 Plumbers
- 📢 Marketing Agencies

**Pro Tip**: Add location terms like "california" or "new york" for better targeting

## 🚫 Blacklisted Domains

The system automatically filters out:
- Social media (facebook, twitter, linkedin, etc.)
- Search engines (google, bing, yahoo)
- Tech giants (amazon, microsoft, apple)
- News sites (cnn, bbc, nytimes, forbes)
- Government/education (.gov, .edu domains)
- E-commerce platforms (ebay, etsy, shopify)
- Service providers (wordpress, wix, godaddy)

## 💡 Best Practices

1. **Use specific queries**: "real estate agents california" > "real estate"
2. **Enable business filter**: Highest quality emails
3. **Optimal worker count**: 20-50 workers for best performance
4. **Target realistic numbers**: Start with 1000-10000 emails
5. **Monitor extraction rate**: Should see 20k-30k emails/min with 20-50 workers

## 📝 Changelog

### Version 2.0 (Latest)
- Moved all worker management to dashboard
- Added mandatory worker count field
- Implemented smart email filtering (50+ junk domains)
- Added query templates for high-yield searches
- Optimized performance (0.3s → 0.1s rate limit)
- Increased parallel capacity (50 → 100 connections)
- Improved timeout settings for faster processing
- Added real-time worker statistics to dashboard
- Set business email filter as default

### Version 1.0
- Initial release
- Basic email extraction
- Worker management page
- Manual job creation