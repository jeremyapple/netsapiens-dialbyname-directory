# NetSapiens Dial-By-Name Directory

A feature-rich, session-aware dial-by-name directory for NetSapiens VoIP platforms using Web Responders.

## Features

- **DTMF Input** - Search by pressing digits on the telephone keypad
- **Multi-language TTS** - Configurable language and voice for text-to-speech
- **Smart Pagination** - Browse large result sets with "Press 9 for more"
- **Auto-detect Call Type** - Automatically handles internal vs external call routing
- **Auto-return to Auto Attendant** - Pressing * returns caller to original destination
- **Operator Transfer** - Optional "Press 0 for operator" at main prompt
- **Multiple Sites/Departments** - Filter by comma-separated sites and/or departments
- **Flexible Filtering** - Filter by site, department, first name, or last name
- **Caching** - Reduces API calls with configurable TTL
- **Session Management** - Maintains state across multi-step interactions

## Requirements

- PHP 8.0 or higher
- NetSapiens SNAPsolution v44.1+ (for TTS features)
- NetSapiens API access (API key with user read permissions)
- Voice Services integration (Deepgram recommended for TTS)

## Installation

### 1. Upload Files

Upload the following files to your web server:

```
/var/www/your-app/
├── directory.php          # Main dial-by-name script
├── cache_cleanup.php      # Cache maintenance script (optional)
├── dbn_diagnostic.php     # Diagnostic tool (optional)
└── config.ini             # Configuration file (one directory up recommended)
```

### 2. Create Configuration

Create `config.ini` one directory above your scripts:

```ini
[production]
; NetSapiens API Settings
SERVER = api.netsapiens.com
API_KEY = your_api_key_here

; Cache Settings
CACHE_ENABLED = true
CACHE_DIR = /var/cache/dial_by_name
CACHE_TTL = 300
CACHE_PURGE_CHANCE = 0

; Debug/Logging
DEBUG_MODE = false

; API Settings
API_PAGE_LIMIT = 1000

; Voice/Language Settings
DEFAULT_LANGUAGE = en-US
DEFAULT_VOICE = female

; Exit Behavior
EXIT_URL =
```

### 3. Create Cache Directory

```bash
sudo mkdir -p /var/cache/dial_by_name
sudo chown www-data:www-data /var/cache/dial_by_name
sudo chmod 775 /var/cache/dial_by_name
```

### 4. Set Up Cron (Optional)

Add a cron job to clean up expired cache files:

```bash
# Every hour
0 * * * * /usr/bin/php /var/www/your-app/cache_cleanup.php
```

### 5. Create Dial Translation in NetSapiens

1. Go to **Dial Translations** in the NetSapiens admin portal
2. Create a new translation with:
   - **Match Pattern**: Your desired dial code (e.g., `*411` or `9999`)
   - **Application**: `To-Web`
   - **Parameter**: `https://your-server.com/directory.php`

The domain is automatically detected from the POST data (`AccountDomain` or `ToDomain`).

## Configuration Reference

### Multi-Server Configuration

You can configure multiple NetSapiens servers in a single config.ini file and select which one to use via URL parameter:

**config.ini:**
```ini
[production]
SERVER = api.server1.com
API_KEY = key_for_server1
DEFAULT_LANGUAGE = en-US

[server2]
SERVER = api.server2.com
API_KEY = key_for_server2
DEFAULT_LANGUAGE = es-ES

[server3]
SERVER = api.server3.com
API_KEY = key_for_server3
```

**Usage:**
```
# Uses [production] section (default)
https://your-server.com/directory.php

# Uses [server2] section
https://your-server.com/directory.php?config=server2

# Uses [server3] section with site filter
https://your-server.com/directory.php?config=server3&site=NYC
```

Each section is independent - settings don't inherit from other sections.

### config.ini Options

| Option | Default | Description |
|--------|---------|-------------|
| `SERVER` | `api.netsapiens.com` | NetSapiens API hostname |
| `API_KEY` | (required) | API key for authentication |
| `CACHE_ENABLED` | `true` | Enable/disable user list caching |
| `CACHE_DIR` | `/tmp/dial_by_name_cache` | Directory for cache files |
| `CACHE_TTL` | `300` | Cache lifetime in seconds (5 minutes) |
| `CACHE_PURGE_CHANCE` | `0` | 1-in-X chance to purge expired files (0=disabled, use cron) |
| `DEBUG_MODE` | `false` | Enable detailed logging |
| `API_PAGE_LIMIT` | `1000` | Users per API request (max 1000) |
| `DEFAULT_LANGUAGE` | `en-US` | Default TTS language (BCP-47 code) |
| `DEFAULT_VOICE` | `female` | Default TTS voice (male/female or voice ID) |
| `DEFAULT_MAX_DIGITS` | `4` | Max digits to gather for name search (2-10) |
| `DEFAULT_MAX_RESULTS` | `8` | Max results per page (1-9) |
| `OPERATOR_EXTENSION` | (empty) | Extension to transfer to when pressing 0 (empty = disabled) |
| `EXIT_URL` | (empty) | URL to forward when user presses * to exit |

### URL Parameters

All parameters can be passed via URL query string to override defaults.

#### Server Selection

| Parameter | Type | Description |
|-----------|------|-------------|
| `config` | string | Config section to use (default: `production`) |

#### Filtering

| Parameter | Type | Description |
|-----------|------|-------------|
| `domain` | string | NetSapiens domain (auto-detected from POST data if not specified) |
| `site` | string | Filter users by site(s) - comma-separated for multiple |
| `department` | string | Filter users by department(s) - comma-separated for multiple |
| `mode` | string | Search mode: `lastname` (default), `firstname`, or `both` |

#### Display

| Parameter | Type | Description |
|-----------|------|-------------|
| `maxdigits` | int | Max digits to gather (2-10, default: 4) |
| `maxresults` | int | Max results per page (1-9, default: 8) |
| `language` | string | TTS language code (e.g., `en-US`, `es-ES`, `fr-CA`) |
| `voice` | string | TTS voice: `male`, `female`, or specific voice ID |

#### Behavior

| Parameter | Type | Description |
|-----------|------|-------------|
| `operator` | string | Extension to transfer to when pressing 0 (overrides config) |
| `exit_url` | string | URL to forward when pressing * |
| `exit_action` | string | Exit action: `forward` (default), `hangup`, or `restart` |
| `bycaller` | string | Override ByCaller auto-detection: `yes`, `no`, or `none` |

### URL Encoding for Spaces

If your site or department name contains spaces, you must URL-encode them:

| Character | Encoding |
|-----------|----------|
| Space | `%20` or `+` |

**Examples:**

```
# Site with space
https://your-server.com/directory.php?site=Main%20Office
https://your-server.com/directory.php?site=Main+Office

# Department with space
https://your-server.com/directory.php?department=Customer%20Support

# Both with spaces
https://your-server.com/directory.php?site=New%20York&department=Tech%20Support
```

**In NetSapiens Dial Translation:**
```
https://your-server.com/directory.php?site=Main%20Office
```

**Note:** Most browsers and tools will automatically encode spaces, but when configuring dial translations manually, you must use `%20` or `+` for spaces.

### Multiple Sites or Departments

Use comma-separated values to include users from multiple sites or departments:

```
# Multiple sites
https://your-server.com/directory.php?site=NYC,LA,Chicago

# Multiple departments
https://your-server.com/directory.php?department=Sales,Support,Engineering

# Multiple sites with spaces
https://your-server.com/directory.php?site=New%20York,Los%20Angeles,San%20Francisco

# Both multiple sites AND departments
https://your-server.com/directory.php?site=NYC,LA&department=Sales,Support
```

**How it works:**
- If only `site` is specified: includes users from any of those sites
- If only `department` is specified: includes users from any of those departments  
- If both are specified: includes users matching ANY site AND ANY department
- Results are automatically deduplicated (no duplicate extensions)

## Usage Examples

### Basic Directory

```
https://your-server.com/directory.php
```

### Search by First or Last Name

```
https://your-server.com/directory.php?mode=both
```

### Spanish Language with Male Voice

```
https://your-server.com/directory.php?language=es-ES&voice=male
```

### Filter by Site and Department

```
https://your-server.com/directory.php?site=MainOffice&department=Sales
```

### Filter with Spaces in Names

```
https://your-server.com/directory.php?site=Main%20Office&department=Customer%20Support
```

### Return to Main IVR on Exit

```
https://your-server.com/directory.php?exit_url=https://your-server.com/main-menu.php
```

### Enable Operator Transfer via URL

```
https://your-server.com/directory.php?operator=100
```

### Full Example

```
https://your-server.com/directory.php?site=HQ&mode=both&language=en-US&voice=female&maxresults=8&operator=0
```

## How It Works

### Call Flow

```
┌─────────────────┐
│  Caller Dials   │
│  Directory Code │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Welcome Prompt  │
│ "Enter name..." │
└────────┬────────┘
         │
         ▼
┌─────────────────┐     No Matches      ┌─────────────────┐
│  User Enters    │ ─────────────────▶  │  "No matches,   │
│  DTMF/Speech    │                     │   try again"    │
└────────┬────────┘                     └─────────────────┘
         │
         │ Matches Found
         ▼
┌─────────────────┐     1 Match         ┌─────────────────┐
│  Search Users   │ ─────────────────▶  │   Transfer to   │
│                 │                     │      User       │
└────────┬────────┘                     └─────────────────┘
         │
         │ Multiple Matches
         ▼
┌─────────────────┐
│  Present Menu   │◀────────┐
│  "Press 1 for   │         │
│   John Smith"   │         │ Press 0
└────────┬────────┘         │ (repeat)
         │                  │
         ├──────────────────┘
         │
         │ Press 1-8 (select)
         ▼
┌─────────────────┐
│   Transfer to   │
│   Selected User │
└─────────────────┘

Press * at main prompt → Exit to auto attendant (or restart)
Press * during search/results → Return to main directory prompt
Press 0 at main prompt → Transfer to operator (if configured)
Press 9 → Next page (if more results)
```

### DTMF to Letter Mapping

```
2 = A B C
3 = D E F
4 = G H I
5 = J K L
6 = M N O
7 = P Q R S
8 = T U V
9 = W X Y Z
```

Example: To search for "SMITH", enter `76484`

### Star Key Behavior

The * key behavior depends on where the caller is in the directory:

| Location | * Key Behavior |
|----------|----------------|
| Main prompt (before searching) | Exit to auto attendant (if available) |
| After entering digits | Return to main directory prompt |
| Viewing search results | Return to main directory prompt |

### Operator Option

If an operator extension is configured, callers can press **0** at the main prompt to transfer to an operator.

**config.ini:**
```ini
OPERATOR_EXTENSION = 100
```

**Or via URL parameter:**
```
https://your-server.com/directory.php?operator=100
```

**Behavior:**
| Key | Location | Action |
|-----|----------|--------|
| 0 | Main prompt | Transfer to operator extension |
| 0 | Viewing results | Repeat current menu |

**Prompt when enabled:**
> "Welcome to the dial by name directory. Using your telephone keypad, enter up to 4 letters of the person's last name, then press pound. **Press 0 for the operator.** Press star to return to the main menu."

### Auto-Return to Auto Attendant

When a caller presses * **at the main directory prompt** (before entering any digits), the script determines where to return:

| Condition | Exit Behavior |
|-----------|---------------|
| `exit_url` is set | Forwards to that URL |
| AccountUser is `system-aa` | Forwards back to auto attendant |
| Otherwise | Restarts directory |

**How it works:**
1. When call arrives, the script gets `AccountUser` and `AccountDomain` from POST data
2. It makes an API call to check if that user's `service-code` starts with `system-aa`
3. If it's an auto attendant, that user is stored as the return destination
4. When * is pressed at the main prompt, the call is forwarded back to that auto attendant

**Example flow:**
```
External caller dials company number
  ↓
Auto attendant (system-aa) answers: "Press 2 for directory"
  ↓
Caller presses 2 → directory.php receives AccountUser=aa-main
  ↓
Script checks API: aa-main has service-code=system-aa ✓
  ↓
Script stores return_to=aa-main in session
  ↓
Caller hears: "Welcome to dial by name directory... Press star to return to main menu."
  ↓
Caller enters "764" to search for "Smith"
  ↓
Caller hears results: "1, John Smith. 2, Jane Smith. Star to start over."
  ↓
Caller presses * → Returns to "Welcome to dial by name directory..."
  ↓
At main prompt, caller presses * → Forwards to aa-main@domain (auto attendant)
```

**Override:** Set `exit_url` parameter to force a specific exit destination.

### ByCaller Auto-Detection

The script automatically detects whether to include the `ByCaller` attribute:

| ANI (Caller) | DNIS (Dialed) | Detection | Forward Output |
|--------------|---------------|-----------|----------------|
| `1001` | `9999` | Internal | `<Forward ByCaller="yes">` |
| `6025551234` | `8005551234` | External | `<Forward>` |

**Logic**: If both ANI and DNIS are 10+ digits, it's an external/PSTN call and ByCaller is omitted.

## User Directory Requirements

For users to appear in the dial-by-name directory, they must have:

1. `directory-annouce-in-dial-by-name-enabled` = `yes`
2. `service-code` NOT starting with `system-`
3. Either `name-first-name` or `name-last-name` populated
4. A valid `user` (extension) value

## Caching

### How Caching Works

1. On first request, users are fetched from the NetSapiens API
2. Results are filtered and cached to a JSON file
3. Subsequent requests use the cached data until TTL expires
4. Cache key is based on: `domain|site|department`

### Cache File Location

Cache files are stored in `CACHE_DIR` with MD5-hashed filenames:

```
/var/cache/dial_by_name/
├── 16859f8f80fb34b0f48b21bef6e8e86d.json  # example.com||
├── a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6.json  # example.com|MainOffice|
└── ...
```

### Cache Maintenance

**Using Cron (Recommended)**:

```bash
# Purge expired files every hour
0 * * * * /usr/bin/php /var/www/your-app/cache_cleanup.php

# With verbose output to log
0 * * * * /usr/bin/php /var/www/your-app/cache_cleanup.php --verbose >> /var/log/dbn_cache.log 2>&1
```

**Manual Cache Management**:

```bash
# View cache stats
php cache_cleanup.php --verbose

# Dry run (show what would be deleted)
php cache_cleanup.php --dry-run --verbose

# Force delete all cache files
php cache_cleanup.php --force
```

### Note on systemd PrivateTmp

If using `/tmp` for cache and Apache has `PrivateTmp=true`, cache files will be in:

```
/tmp/systemd-private-*-apache2.service-*/tmp/dial_by_name_cache/
```

**Recommendation**: Use `/var/cache/dial_by_name` instead to avoid this issue.

## Supported Languages

Common BCP-47 language codes for TTS:

| Code | Language |
|------|----------|
| `en-US` | English (US) |
| `en-GB` | English (UK) |
| `en-AU` | English (Australia) |
| `es-ES` | Spanish (Spain) |
| `es-MX` | Spanish (Mexico) |
| `fr-FR` | French (France) |
| `fr-CA` | French (Canada) |
| `de-DE` | German |
| `it-IT` | Italian |
| `pt-BR` | Portuguese (Brazil) |
| `pt-PT` | Portuguese (Portugal) |
| `nl-NL` | Dutch |
| `ja-JP` | Japanese |
| `ko-KR` | Korean |
| `zh-CN` | Chinese (Simplified) |

## Troubleshooting

### Enable Debug Logging

Set `DEBUG_MODE = true` in config.ini, then check Apache error log:

```bash
tail -f /var/log/apache2/error.log | grep -i dial-by-name
```

### Run Diagnostics

Access the diagnostic script in your browser:

```
https://your-server.com/dbn_diagnostic.php
```

This shows:
- Config file location and parsed values
- Cache directory permissions
- Write test results
- Web server user

### Common Issues

**"Domain is required" error**
- Ensure `AccountDomain` or `ToDomain` is being passed in the POST data
- Or manually specify `?domain=yourdomain.com` in the URL

**No users appearing**
- Check that users have `directory-annouce-in-dial-by-name-enabled = yes`
- Verify API key has permission to read users
- Check API_PAGE_LIMIT if domain has many users

**Cache not working**
- Check cache directory permissions
- Ensure web server user can write to CACHE_DIR
- If using `/tmp`, check for systemd PrivateTmp isolation

**Forward not working (internal calls)**
- ByCaller should be auto-detected
- Check debug logs for "Auto-detect ByCaller" messages
- Override with `?bycaller=yes` if needed

**Forward not working (auto attendant)**
- ByCaller should be auto-detected as external
- Check debug logs for "Auto-detect ByCaller" messages
- Override with `?bycaller=none` if needed

**TTS not working**
- Requires SNAPsolution v44.1+
- Voice Services integration must be configured
- Check domain/user TTS settings in NetSapiens

## Files Reference

| File | Description |
|------|-------------|
| `dial_by_name_session_v2.php` | Main dial-by-name directory script |
| `config.ini` | Configuration file |
| `cache_cleanup.php` | Cron script for cache maintenance |
| `cache_check.php` | CLI tool for viewing cache stats |
| `dbn_diagnostic.php` | Web-based diagnostic tool |

## Web Responder XML Reference

The script generates NetSapiens Web Responder XML. Key verbs used:

- `<Gather>` - Collect DTMF digits and/or speech
- `<Say>` - Text-to-speech prompts
- `<Forward>` - Transfer call to user extension
- `<Hangup>` - End the call
- `<Response>` - Container for multiple verbs

Example output:

```xml
<Gather input="dtmf" numDigits="4" action="/directory.php?domain=example.com">
  <Say voice="female" language="en-US">
    Welcome to the dial by name directory. 
    Using your telephone keypad, enter up to 4 letters of the person's last name, then press pound. 
    Press star to start over.
  </Say>
</Gather>
```

## Contributing

Contributions are welcome! Please submit issues and pull requests to the GitHub repository.

## License

MIT License - See LICENSE file for details.

## Credits

Built for NetSapiens VoIP platforms. 

For more information on NetSapiens Web Responders, see:
- [NetSapiens Web Responder Documentation](https://documentation.netsapiens.com)
- [NetSapiens Web Responder Examples](https://github.com/netsapiens/netsapiens-webresponder-examples)
