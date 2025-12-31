# Email Domain Filtering Feature

## Overview
This document describes the email domain filtering feature added to the Email Extraction System. This feature allows you to target specific email domains (like Gmail, Yahoo, ATT, etc.) or exclude certain domains when extracting emails using the serper.dev API.

## Features Added

### 1. Email Domain Selection
Job profiles now support selecting specific email domains to target or exclude during extraction:

- **Business Only Mode** (default): Excludes free email providers (gmail.com, yahoo.com, hotmail.com, outlook.com, aol.com, icloud.com, att.net, etc.)
- **Include Only Mode**: Extract ONLY emails from selected domains
- **Exclude Mode**: Extract all emails EXCEPT from selected domains  
- **All Emails Mode**: No filtering - extract all found emails

### 2. Common Email Providers
The UI provides checkboxes for common email providers:
- Gmail (gmail.com)
- Yahoo (yahoo.com)
- Outlook (outlook.com)
- Hotmail (hotmail.com)
- AOL (aol.com)
- iCloud (icloud.com)
- AT&T (att.net)
- Comcast (comcast.net)
- Verizon (verizon.net)
- Live.com (live.com)
- MSN (msn.com)

### 3. Custom Domains
You can also add custom domains not in the preset list by entering them in the "Custom Domains" field (comma-separated).

## Database Changes

Two new fields were added to the `job_profiles` table:

```sql
ALTER TABLE job_profiles ADD COLUMN email_domains TEXT DEFAULT NULL;
ALTER TABLE job_profiles ADD COLUMN domain_filter_mode VARCHAR(20) DEFAULT 'business_only';
```

- **email_domains**: Comma-separated list of target domains (e.g., "gmail.com,yahoo.com,att.net")
- **domain_filter_mode**: Filter mode - 'include', 'exclude', 'business_only', or 'all'

## Usage

### Creating a Profile with Domain Filtering

1. Go to **Job Profiles** in the sidebar
2. Click **Add New Profile** or edit an existing profile
3. Scroll to the **Email Domain Filtering (Advanced)** section
4. Select a **Filter Mode**:
   - **Business Only**: Default mode, excludes free providers
   - **Include Only**: Show domain checkboxes to select which domains to include
   - **Exclude**: Show domain checkboxes to select which domains to exclude
   - **All Emails**: No filtering
5. If using Include or Exclude mode:
   - Check the email provider checkboxes you want
   - Or enter custom domains in the "Custom Domains" field
6. Save the profile

### Examples

#### Example 1: Extract ONLY Gmail and Yahoo emails
```
Filter Mode: Include Only Selected Domains
Selected Domains: ☑ Gmail, ☑ Yahoo
```

#### Example 2: Extract business emails but EXCLUDE AT&T
```
Filter Mode: Exclude Selected Domains
Selected Domains: ☑ AT&T
```

#### Example 3: Extract ALL emails (no filtering)
```
Filter Mode: All Emails (no filtering)
```

#### Example 4: Extract only from specific company domains
```
Filter Mode: Include Only Selected Domains
Custom Domains: company.com, business.org, startup.io
```

## Error Handling Improvements

### Enhanced Error Messages
When extraction fails (0 emails found), the error message now includes:

1. **Job Configuration**: Shows the exact filter mode and target domains used
2. **Possible Reasons**: Context-aware suggestions based on your configuration
3. **Troubleshooting Steps**: Specific steps for your filter mode
4. **HTTP Status Code Reference**: Explains what each API error code means
5. **API Errors Log**: Shows the last 10 API errors encountered

### Common Error Scenarios

#### Scenario 1: "Business Only" filter excludes all emails
**Error**: No emails found with Business Only filter enabled  
**Solution**: 
- Switch to "All Emails" mode to see if any emails exist
- Or use "Include Only" mode with specific domains like gmail.com

#### Scenario 2: "Include Only" is too restrictive
**Error**: No emails found from selected domains  
**Solution**:
- Add more domains to the Include list
- Check if your search query targets the right audience
- Test with "All Emails" mode first to see what domains are available

#### Scenario 3: API Key Issues
**Error**: HTTP 401 Unauthorized  
**Solution**:
- Verify your API key at https://serper.dev/dashboard
- Check if your API key has expired or been revoked
- Use the "Test Connection" button in the profile

#### Scenario 4: Rate Limiting
**Error**: HTTP 429 Too Many Requests  
**Solution**:
- Wait a few minutes before trying again
- Reduce the number of workers in your profile
- Check your API quota at serper.dev/dashboard

## Technical Details

### Backend Implementation

The `extract_emails_from_results()` function now accepts additional parameters:

```php
function extract_emails_from_results(
    array $results, 
    bool $businessOnly = true,           // Legacy parameter for backward compatibility
    string $filterMode = 'business_only', // Filter mode
    array $targetDomains = []             // Array of domains to include/exclude
): array
```

Filter modes:
- `business_only`: Excludes free email providers
- `include`: Include ONLY emails from targetDomains
- `exclude`: Exclude emails from targetDomains
- `all`: No filtering

### Backward Compatibility

The feature maintains full backward compatibility:
- Existing profiles without domain filtering use "business_only" mode by default
- The legacy `filter_business_only` checkbox still works
- No database migrations required - columns are added with ALTER TABLE IF NOT EXISTS

### Performance

Domain filtering adds minimal overhead:
- Uses O(1) hash lookups for domain matching
- Filtering happens during email extraction (no additional database queries)
- No impact on API call performance

## Testing

### Manual Testing Checklist

- [ ] Create a new profile with "Include Only" mode (Gmail + Yahoo)
- [ ] Verify only Gmail and Yahoo emails are extracted
- [ ] Create a profile with "Exclude" mode (exclude Gmail)
- [ ] Verify Gmail emails are not extracted
- [ ] Test "Business Only" mode - verify free providers are excluded
- [ ] Test "All Emails" mode - verify all emails are extracted
- [ ] Test custom domains field
- [ ] Test backward compatibility with existing profiles
- [ ] Verify "Test Connection" button works
- [ ] Test error messages when 0 emails are found
- [ ] Verify API error logging

### Test Queries

Good test queries that return emails:
- "real estate agents california contact"
- "lawyers new york email"
- "dentists texas contact information"

## Troubleshooting

### Problem: Domain selection doesn't appear
**Solution**: Make sure you selected "Include Only" or "Exclude" as the filter mode. Domain selection only shows for these modes.

### Problem: Still getting free provider emails in "Business Only" mode
**Solution**: Check that the domain is in the free providers list. The current list includes: gmail.com, yahoo.com, hotmail.com, outlook.com, aol.com, icloud.com, att.net, live.com, msn.com, comcast.net, verizon.net

### Problem: Custom domains not working
**Solution**: Make sure domains are comma-separated and include the full domain (e.g., "company.com" not just "company")

## Support

For issues or questions:
1. Check the error.log file on the server
2. Use the "Test Connection" button in Job Profiles
3. Review the detailed error messages in the Job Stats page
4. Verify API key at https://serper.dev/dashboard
