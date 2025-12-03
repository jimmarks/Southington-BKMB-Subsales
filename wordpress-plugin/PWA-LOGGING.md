# PWA Client-Side Logging

## Overview
The PWA now includes comprehensive client-side logging that integrates with the WordPress backend debug logging system. When debug mode is enabled in the WordPress admin, the PWA will automatically log user interactions and send them to the server.

## Features

### Automatic Logging
- **Button Clicks**: All button clicks are automatically logged
- **Form Inputs**: Input field changes are logged (debounced, without sensitive data)
- **Error Tracking**: Global error handler captures uncaught errors and promise rejections

### Manual Logging
The following user actions are explicitly logged:
- **Login Attempts**: Both legacy (team+code) and user (name+phone) login methods
- **Login Success**: Successful authentication with team/user information
- **Order Save**: When orders are saved, including order metadata
- **Order Save Success**: Confirmation that order was queued successfully

### Debug Mode Integration
- Logging is controlled by the WordPress admin debug mode setting
- When debug mode is ON: All interactions are logged
- When debug mode is OFF: Only ERROR level logs are sent
- Debug mode status is fetched from the server via `/config` endpoint

## Architecture

### Files
- **pwa-logger.js**: Core logging module with singleton PWALogger object
- **app.js**: Main PWA application with integrated logging calls
- **index.html**: Loads pwa-logger.js dynamically

### REST API Endpoint
**POST** `/wp-json/order-manager/v1/log`

Request body:
```json
{
  "level": "DEBUG|INFO|WARNING|ERROR|CRITICAL",
  "category": "pwa|auth|order|ui|navigation|api|system",
  "message": "Description of the event",
  "context": {
    "key": "value",
    "timestamp": "ISO timestamp",
    "url": "current page URL"
  },
  "source": "pwa-client",
  "user_name": "Current user name"
}
```

### Log Levels
- **DEBUG**: User interactions, button clicks, input changes (only when debug mode ON)
- **INFO**: Operational events like successful saves
- **WARNING**: Potential issues
- **ERROR**: Caught errors and failures
- **CRITICAL**: System-level failures

### Log Categories
- **pwa**: General PWA events
- **auth**: Authentication and login events
- **order**: Order creation, editing, deletion
- **ui**: User interface interactions
- **navigation**: Screen changes
- **api**: API calls and responses
- **system**: Global errors, unhandled exceptions

## PWALogger API

### Initialization
```javascript
await window.PWALogger.init({
  apiBase: 'https://example.com/wp-json/order-manager/v1',
  teamName: 'Team A',
  userName: 'John Doe',
  sessionId: 'abc123',
  debugLoggingEnabled: true
});
```

### Logging Methods

#### Generic Log
```javascript
window.PWALogger.log(category, message, context);
// Example:
window.PWALogger.log('order', 'Order saved', {
  order_id: '12345',
  customer: 'present',
  products_count: 3
});
```

#### Button Click
```javascript
window.PWALogger.logButtonClick(buttonId, buttonText);
```

#### Input Change
```javascript
window.PWALogger.logInput(fieldName, fieldType, value);
// Note: Does not log actual value, only presence and length
```

#### Navigation
```javascript
window.PWALogger.logNavigation(fromScreen, toScreen);
```

#### API Call
```javascript
window.PWALogger.logApiCall(endpoint, method, status);
```

#### Error
```javascript
window.PWALogger.logError(category, message, errorObject);
// Always sent regardless of debug mode
```

### Auto-Instrumentation
```javascript
// Enable automatic tracking of all buttons and inputs
window.PWALogger.instrumentUI();
```

## Privacy & Security

### What's Logged
- Button IDs and text
- Field names and types
- Presence of data (not actual values)
- Navigation paths
- API endpoints called
- Error messages and stack traces

### What's NOT Logged
- Actual form input values
- Passwords or access codes
- Customer addresses
- Phone numbers (only presence)
- Credit card or payment information

### Data Minimization
Input logging uses a debounced approach and only logs:
- Field name
- Field type
- Whether value is present
- Value length (not content)

## Viewing Logs

### WordPress Admin
1. Navigate to **BKMB Subsales → Logs & Sessions**
2. Enable **Show Debug Logs** checkbox
3. All PWA logs have `source = 'pwa-client'`
4. Filter by category to find specific events

### Log Columns
- **Timestamp**: When the event occurred (WordPress timezone)
- **Level**: DEBUG, INFO, WARNING, ERROR, CRITICAL
- **Category**: pwa, auth, order, ui, etc.
- **Message**: Human-readable description
- **Context**: JSON object with additional details
- **Source**: Always 'pwa-client' for PWA logs
- **User**: Name of logged-in user

## Debug Mode

### Enabling Debug Mode
1. Go to **BKMB Subsales → Logs & Sessions**
2. Click **Enable Debug Logging**
3. Debug mode auto-expires after 24 hours

### Disabling Debug Mode
- Click **Disable Debug Logging** in admin
- Wait 24 hours for automatic timeout
- DEBUG level logs will be hidden (INFO/WARNING/ERROR still visible)

### Behavior
- **Debug ON**: PWA sends all interaction logs
- **Debug OFF**: PWA only sends ERROR logs
- **Always Visible**: INFO, WARNING, ERROR, CRITICAL logs
- **Hidden When Off**: DEBUG logs (UI interactions, button clicks)

## Performance Considerations

### Fire-and-Forget
Logging uses non-blocking `fetch()` calls that don't wait for responses, ensuring UI responsiveness.

### Debouncing
Input logging is debounced to 500ms to prevent excessive API calls during typing.

### Conditional Execution
Logger checks `debugEnabled` flag before sending logs, avoiding unnecessary network requests.

### Network Efficiency
- Logs sent as small JSON payloads
- No retry mechanism (don't block on failures)
- Errors logged to console if send fails

## Development Testing

### Enable Logging
```javascript
// In browser console
localStorage.setItem('forceDebugLogging', 'true');
location.reload();
```

### Check Logger Status
```javascript
window.PWALogger.debugEnabled  // true/false
window.PWALogger.initialized   // true/false
```

### Manual Test Log
```javascript
window.PWALogger.log('test', 'Manual test log', { test: true });
```

### View Recent Logs
Check WordPress admin **Logs & Sessions** page within a few seconds of the action.

## Troubleshooting

### Logs Not Appearing
1. Verify debug mode is enabled in WordPress admin
2. Check browser console for PWALogger initialization message
3. Verify `window.PWALogger.debugEnabled === true`
4. Check Network tab for POST requests to `/log` endpoint
5. Verify team authentication is working (logs require team context)

### Logger Not Initializing
1. Check that `pwa-logger.js` is loaded in Network tab
2. Verify `/config` endpoint returns `debugLoggingEnabled` field
3. Check console for initialization errors
4. Ensure `apiBase` is set correctly in config

### Too Many Logs
1. Disable UI auto-instrumentation (remove `instrumentUI()` call)
2. Comment out specific log calls in app.js
3. Disable debug mode to hide DEBUG level logs
4. Use log filtering in WordPress admin

### Logs Disappearing
This was a previous bug (now fixed). Ensure you're running the latest version where:
- Only DEBUG level logs have `is_debug=1`
- INFO/WARNING/ERROR logs always have `is_debug=0`
- This ensures non-debug logs remain visible when debug mode is off

## Version History

### Version 1.0.0 (2024-12-01)
- Initial implementation of PWA client-side logging
- Integration with WordPress debug logging system
- Auto-instrumentation of UI interactions
- Global error handlers
- Manual logging for critical user actions
- Privacy-preserving input logging
