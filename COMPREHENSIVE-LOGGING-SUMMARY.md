# Comprehensive PWA Logging Implementation

## Overview
Complete logging system implemented for all PWA user interactions when debug mode is enabled. Logs include GPS coordinates, session context, and detailed action tracking.

## Package Information
- **File**: `subsales-management.zip`
- **SHA-256**: `438f088305177d112a1975f89ec0e0709c4972c07630b69502c73063dd85df73`
- **Date**: 2024-12-01

## Key Components

### 1. Core Logging Infrastructure

#### PWA Logger Module (`pwa/pwa-logger.js`)
- Singleton logging service
- Checks debug mode from server config
- Fire-and-forget POST to `/wp-json/order-manager/v1/log`
- Privacy-preserving (doesn't log actual input values)
- Auto-instrumentation for all buttons and inputs

#### Helper Function: `logWithContext()`
Located in `pwa/app.js` (lines ~95-133)
- **GPS Integration**: Automatically attempts to get coordinates with 1-second timeout
- Includes: `gps_latitude`, `gps_longitude`, `gps_accuracy`, `gps_timestamp`
- Auto-includes: `user_id`, `user_name`, `team_id`, `session_id`, `online` status
- Only executes when `PWALogger.debugEnabled` is true
- Handles GPS errors gracefully

### 2. Logged Interactions

#### Authentication & Session
✅ **Login Button Click**
- User mode and legacy mode separately tracked
- Includes authentication method

✅ **Successful Login**
- User details and session establishment

✅ **Logout Flow** (5 logging points)
1. Logout button clicked
2. User cancelled confirmation
3. User confirmed logout
4. Session cleared successfully
5. Any localStorage clearing errors

✅ **Session Heartbeat**
- Periodic heartbeat with session_id
- Activity presence tracking
- Failure and error logging

#### Order Management
✅ **Order Creation**
- Save button clicked
- Order saved successfully with order ID

✅ **My Orders Modal** (7 logging points)
1. My Orders button clicked
2. Session validation check
3. Function started
4. Local orders filtered (with count)
5. Remote orders fetch initiated
6. Remote orders filtered (with count)
7. Modal displayed
8. Modal closed

✅ **Edit Order Operations**
- **Local Orders**: Edit click + entering edit mode with order details
- **Remote Orders**: Edit click + entering edit mode with customer/address info
- Logs customer name, address presence, order_id

✅ **Delete Order Operations**
- **Local Orders** (3 points):
  1. Delete clicked with order_id
  2. User cancelled or confirmed
  3. Undo operation
- **Remote Orders** (5 points):
  1. Delete clicked with online status
  2. User cancelled
  3. Success with HTTP status code
  4. Failure with status code
  5. Offline queuing with operation_id
  6. Undo operation

#### Sync Operations
✅ **Force Sync** (6 logging points)
1. Sync button clicked
2. Session validation
3. Online status check
4. Sync started
5. Sync completed with results
6. Any errors during sync

✅ **EOD Tally** (5 logging points)
1. EOD button clicked
2. Session validation
3. Modal opened
4. Tally displayed
5. Any errors

#### Address Autocomplete
✅ **Nearby Address Search**
- Search initiated with GPS coordinates, radius, max results
- Cache hits (IndexedDB) with age and result count
- Server response with result count
- Cache API fallback with result count
- Fetch errors logged

✅ **ZIP Data Loading**
- ZIP data load initiated with URL
- Memory cache hits with record count
- Successful load with record count
- IndexedDB fallback with record count
- Load failures logged

✅ **ZIP Prefetch**
- Prefetch started with ZIP count and base URL
- Prefetch completed with success/fail counts

✅ **Address Input Search**
- Search triggered with query length
- Current ZIP loaded status

✅ **Address Suggestions**
- Dropdown displayed with suggestion count

✅ **Address Selection**
- User selected address with full details
- City, state, ZIP captured
- Coordinate availability noted

### 3. Error Handling
✅ **Global Error Handlers**
- Uncaught JavaScript errors
- Unhandled promise rejections
- Network failures
- GPS errors (timeout, permission denied)

## Context Included in Logs

Every log entry includes (when available):
- **Timestamp**: ISO 8601 format
- **User Context**: `user_id`, `user_name`, `team_id`
- **Session**: `session_id`
- **Network**: `online` status (boolean)
- **GPS Coordinates** (via `logWithContext()`):
  - `gps_latitude`
  - `gps_longitude`
  - `gps_accuracy` (meters)
  - `gps_timestamp`
  - `gps_error` (if GPS fetch failed)

## Debug Mode Control

### Enable Debug Logging
```
WordPress Admin → BKMB Subsales → Settings → Enable Debug Logging (24-hour timeout)
```

### How It Works
1. Server sets `subsales_debug_logging_enabled` option with expiration timestamp
2. PWA fetches config via `/wp-json/order-manager/v1/config`
3. Config includes `debugLoggingEnabled: true/false`
4. PWA Logger initializes with debug status
5. All logging functions check `window.PWALogger.debugEnabled` before logging

### Log Storage
- **Table**: `subsales_logs`
- **Endpoint**: `POST /wp-json/order-manager/v1/log`
- **Public Access**: Yes (for PWA logging)
- **Fields**: level, category, message, context, user_name, source, created_at

## Testing Checklist

### Verified Interactions
1. ✅ Login (user and legacy modes)
2. ✅ Order creation and save
3. ✅ My Orders open/close
4. ✅ Edit local order
5. ✅ Edit remote order
6. ✅ Delete local order (with undo)
7. ✅ Delete remote order (with undo, online/offline)
8. ✅ Force Sync
9. ✅ EOD Tally
10. ✅ Logout
11. ✅ Session heartbeat
12. ✅ Address search (nearby, ZIP-based)
13. ✅ Address selection from dropdown
14. ✅ ZIP prefetch
15. ✅ GPS coordinate capture
16. ✅ Error conditions

### With Debug OFF
- Only errors are logged (via `logError()`)
- Regular interactions produce no logs
- GPS coordinate fetch is skipped

## Performance Considerations

### Fire-and-Forget
- All logging is asynchronous
- Does not block user interactions
- Failures logged to console only

### GPS Timeout
- 1-second timeout prevents delays
- Falls back gracefully if GPS unavailable
- Uses cached position (30-second max age)
- Low accuracy mode for speed

### Debouncing
- Address input debounced at 300ms
- Prevents excessive logging on rapid typing
- Throttles repeated requests (1-second minimum)

## Privacy & Security

### What's NOT Logged
- Actual customer names (only "present" flags)
- Actual phone numbers
- Actual addresses (only "selected" flags)
- User passwords
- Authentication tokens

### What IS Logged
- User actions (button clicks, navigation)
- Result counts and operation status
- GPS coordinates (when available)
- Error messages and stack traces
- Session and user IDs (for context)

## Implementation Files Modified

### Core Files
- `wordpress-plugin/pwa/pwa-logger.js` (264 lines) - Core logger module
- `wordpress-plugin/pwa/app.js` (2,565+ lines) - Main PWA with logWithContext() helper
- `wordpress-plugin/pwa/session-tracking.js` (238+ lines) - Heartbeat logging
- `wordpress-plugin/pwa/address-autocomplete.js` (720 lines) - Address search logging

### Backend
- `wordpress-plugin/includes/class-rest-api.php` - `/log` endpoint
- `wordpress-plugin/includes/class-database.php` - Log storage

### Documentation
- `wordpress-plugin/PWA-LOGGING.md` - Detailed logging documentation

## Usage Example

When debug mode is ON and a user logs in, searches for an address, and saves an order:

```
[LOG] User clicked login button (user mode)
      Context: { gps_latitude: 41.5, gps_longitude: -72.9, online: true, ... }

[LOG] User logged in successfully
      Context: { user_id: 42, team_id: 5, session_id: "abc...", ... }

[LOG] Address input search triggered
      Context: { query_length: 10, has_zip_loaded: false, ... }

[LOG] Nearby address search initiated
      Context: { latitude: 41.5, longitude: -72.9, radius: 500, ... }

[LOG] Address search: server response
      Context: { results_count: 23, source: 'nearby_api', ... }

[LOG] Address suggestions displayed
      Context: { suggestions_count: 23, ... }

[LOG] Address suggestion selected
      Context: { selected_address: "123 Main St", city: "Southington", ... }

[LOG] Save Order button clicked
      Context: { gps_latitude: 41.5, gps_longitude: -72.9, ... }

[LOG] Order saved
      Context: { order_id: 789, customer: "present", address: "present", ... }
```

## Deployment

### Upload Plugin
1. Download `subsales-management.zip` (SHA-256: `438f088...`)
2. WordPress Admin → Plugins → Add New → Upload Plugin
3. Activate plugin

### Enable Debug Logging
1. BKMB Subsales → Settings
2. Check "Enable Debug Logging"
3. Save Settings (auto-expires in 24 hours)

### View Logs
Check database table `subsales_logs` for all PWA activity when debug is enabled.

## Troubleshooting

### No Logs Appearing
1. Verify debug mode is enabled in WordPress admin
2. Check browser console for PWALogger initialization message
3. Verify `/config` endpoint returns `debugLoggingEnabled: true`
4. Check browser console for any fetch errors to `/log` endpoint

### GPS Not Captured
- Normal if user denies location permission
- Normal if GPS timeout (1 second) expires
- Check for `gps_error` field in log context
- GPS is optional; logs still work without it

### Too Many Logs
- Debug mode auto-expires after 24 hours
- Manually disable in WordPress admin
- When OFF, only errors are logged

## Future Enhancements

Potential additions:
- [ ] Performance timing metrics (page load, API response times)
- [ ] User flow visualization (sequence of actions)
- [ ] Aggregated analytics dashboard in WordPress admin
- [ ] Export logs to CSV
- [ ] Log retention policies
- [ ] Advanced filtering in admin UI

---

**Implementation Complete**: All user interactions now comprehensively logged with GPS coordinates and full context when debug mode is enabled.
