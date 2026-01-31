# NetSapiens Auto Attendant Extension

A JavaScript extension that adds **Web Responder** and **Custom Directory** options to the NetSapiens Auto Attendant Designer.

## Features

- **Web Responder** - Configure custom web responder URLs for any AA button
- **Custom Directory** - Set up dial-by-name directories with configurable options:
  - Name matching mode (First Name, Last Name, or Both)
  - Site and Department filtering
  - Operator extension for transfer
  - Input timeout and max digits
  - TTS Language and Voice selection
- **Inline Forms** - Native-looking UI that matches the portal design
- **Direct API Integration** - Creates proper dial rules via the NetSapiens API
- **Edit Support** - Detects and loads existing configurations for editing

## Screenshots

*Web Responder and Custom Directory options appear alongside native AA applications*

## Installation

### 1. Host the JavaScript File

Upload `aa-webresponder.js` to a web server accessible by your portal. For example:
- `https://your-server.com/js/aa-webresponder.js`

### 2. Add to Portal via PORTAL_EXTRA_JS

Add the script URL to your NetSapiens portal configuration:

**Option A: System-wide (all domains)**
```
Configuration Name: PORTAL_EXTRA_JS
Configuration Value: https://your-server.com/js/aa-webresponder.js
Domain: *
```

**Option B: Specific domain**
```
Configuration Name: PORTAL_EXTRA_JS
Configuration Value: https://your-server.com/js/aa-webresponder.js
Domain: YourDomain
```

### 3. Configure the Directory URL (Optional)

Edit the `CONFIG` section at the top of the JavaScript file to set your default directory URL:

```javascript
const CONFIG = {
    debug: false,
    directoryBaseUrl: 'https://your-server.com/directory.php'
};
```

## Usage

1. Navigate to **Auto Attendants** → **Edit** an auto attendant
2. Click on any digit button (0-9, *)
3. You'll see two new options:
   - **Web Responder** - For custom web responder applications
   - **Custom Directory** - For dial-by-name directories

### Web Responder Configuration

| Field | Description |
|-------|-------------|
| Web Responder URL | The full URL to your web responder application |
| Description | A friendly name (displayed in the button title) |
| HTTP Method | GET or POST |

### Custom Directory Configuration

| Field | Description |
|-------|-------------|
| Directory URL | Base URL of your directory web responder |
| Name Matching Mode | `firstname`, `lastname`, or `both` |
| Filter by Sites | Limit directory to specific sites |
| Filter by Departments | Limit directory to specific departments |
| Operator Extension | Extension to transfer when 0 is pressed |
| Input Timeout | Seconds to wait for DTMF input (1-30) |
| Max Digits | Maximum digits to collect (1-10) |
| Language | TTS language (BCP-47 format) |
| Voice | TTS voice (male, female, or voice ID) |

## URL Parameters

The extension generates URLs with the following query parameters:

| Parameter | Example | Description |
|-----------|---------|-------------|
| `mode` | `both` | Name matching mode |
| `site` | `Site1,Site2` | Comma-separated site filter |
| `department` | `Sales,Support` | Comma-separated department filter |
| `operator` | `0` | Operator extension |
| `timeout` | `5` | Input timeout in seconds |
| `maxdigits` | `4` | Maximum digits to collect |
| `language` | `en-US` | TTS language code |
| `voice` | `female` | TTS voice |

**Example generated URL:**
```
https://your-server.com/directory.php?mode=both&timeout=5&maxdigits=4&language=en-US&voice=female
```

## Dial Rule Configuration

The extension creates dial rules with the following structure:

| Field | Value |
|-------|-------|
| Matching To URI | `Prompt_{promptId}.Case_{digit}` |
| Application | `sip:0@Web-Main` |
| Parameter | `<HttpMethod=POST>https://...` |
| Destination User | `[*]` |
| Destination Host | Domain name |
| Source Host | `[*]` |

## Debugging

Debug logging is **disabled by default** for production use.

### Enable at Runtime

Open browser console (F12) and run:
```javascript
window.AAExtension.enableDebug(true)
```

### Enable in Code

Edit the CONFIG section:
```javascript
const CONFIG = {
    debug: true,  // Enable logging
    ...
};
```

### Available Debug Commands

```javascript
// View current configuration
window.AAExtension.config

// View pending (unsaved) configurations
window.AAExtension.pendingConfigs

// Get AA info (domain, promptId, dialplan)
window.AAExtension.getAAInfo()

// Fetch existing dial rules from API
window.AAExtension.fetchExistingDialRules()

// Manually trigger loading of existing configurations
window.AAExtension.loadExistingConfigurations()
```

## Requirements

- NetSapiens SNAPsolution v44.1+ (for full Web Responder verb support)
- Portal user with permissions to edit Auto Attendants
- Web server to host the JavaScript file
- (Optional) Web responder application for directory functionality

## File Structure

```
├── aa-webresponder.js   # Main extension script
├── README.md                        # This file
└── docs/
    └── web-responders.md           # NetSapiens Web Responders documentation
```

## Compatibility

- Tested on NetSapiens portal versions 44.x and 45.x
- Works with any auto attendant extension length (2-6+ digits)
- Supports both single-tier and multi-tier auto attendants

## Troubleshooting

### Extension not loading
- Check browser console for CSP (Content Security Policy) errors
- Verify the script URL is accessible
- Ensure PORTAL_EXTRA_JS is configured correctly

### Dial rules not being created
- Enable debug logging to see API responses
- Verify the user has permission to modify dial plans
- Check that the dialplan exists: `{domain}_{extension}`

### Icons not showing for existing configs
- The extension detects configs on page load
- Try running `window.AAExtension.loadExistingConfigurations()` manually
- Check console for "Found existing config" messages

## License

MIT License - See LICENSE file for details.

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly on a NetSapiens portal
5. Submit a pull request

## Related Documentation

- [NetSapiens Web Responders Documentation](https://docs.ns-api.com/docs/web-responders-v2)
- [NetSapiens API Reference](https://docs.ns-api.com/reference/get_version)
