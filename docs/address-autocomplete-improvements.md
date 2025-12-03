# Address Autocomplete Improvements - Dec 1, 2025

## Session Summary

### Goals
1. Improve address entry UX for users (especially kids) who may not know their ZIP code or town
2. Handle incomplete address database (not all valid addresses exist in ZIP data)
3. Make autocomplete work immediately after login without delays

### Changes Implemented

#### 1. **Option C: Smart Fallback with Manual Mode** ✅
**Location:** `wordpress-plugin/pwa/address-autocomplete.js`

- **Default Mode:** Shows autocomplete suggestions from prefetched ZIP data
- **"Address isn't listed" Button:**
  - Appears when user types 3+ characters with no matches
  - Clicking switches to manual entry mode
  - Field clears, shows helpful placeholder, adds green border indicator
  - No autocomplete interference - saves whatever they type
  - No validation errors

**Code additions:**
- `showManualModeButton()` - displays button below input
- `removeManualModeButton()` - cleanup function  
- `isManualMode` flag - tracks state
- Button appears when: `matches.length === 0 && q.length >= 3`

#### 2. **"📍 Use my location" GPS Button** ✅
**Location:** `wordpress-plugin/pwa/address-autocomplete.js`

- **Always visible** next to address field
- Uses browser geolocation API
- Reverse geocodes via Google Maps API (if configured)
- Falls back to showing coordinates if no API key
- Shows loading state while working

**Code additions:**
- `addLocationButton()` - creates GPS button on init
- Handles geolocation errors gracefully
- Logs GPS usage for debugging

#### 3. **Improved ZIP Prefetch Logic** ✅ (Partially Working)
**Location:** `wordpress-plugin/pwa/address-autocomplete.js`

**Goals:**
- Load address data immediately after login
- Make searches work right away (no delays)
- Show progress to user

**Changes:**
- Made `prefetchAllZips()` async (returns Promise)
- First ZIP loads **synchronously** before function returns
- Remaining ZIPs load in background with 200ms delay
- All callers now `await` the first ZIP load
- Better progress logging

**Code changes:**
```javascript
// OLD: function prefetchAllZips(indexData)
// NEW: async function prefetchAllZips(indexData)

// Loads first ZIP immediately (blocking)
if(zipList.length > 0){
  const firstZip = (''+zipList[0]).trim();
  if(/^[0-9]{5}$/.test(firstZip)){
    await loadZipData(firstZip);
    console.log('first ZIP loaded immediately:', firstZip);
  }
}

// Then continues with remaining ZIPs in background
for(let i=1; i<zipList.length; i++){
  // ...load with 200ms delay
}
```

**Updated callers:**
- `window.subsalesNearby.prefetch()` - awaits first ZIP
- Manual "Load address data" button - awaits first ZIP  
- Auto-prefetch on page load - awaits first ZIP

#### 4. **Enhanced Search Logic** ✅
**Location:** `wordpress-plugin/pwa/address-autocomplete.js`

- Searches all prefetched ZIP data from first character typed
- No longer waits for ZIP detection in input
- Falls back to IndexedDB if memory cache empty
- Better logging shows which data source used

**Search flow:**
1. Detect ZIP in input (`\b([0-9]{5})\b`) - loads that specific ZIP if different
2. If ZIP already loaded, search within it
3. Otherwise, search ALL cached ZIPs (memory + IDB)
4. Show "Address isn't listed" if no matches after 3+ chars

#### 5. **Database Table Renaming** ✅
**Location:** Multiple PHP files

Renamed all tables from `order_sync_*` and `subsales_*` to `ss_*` prefix:
- `wp_order_sync_orders` → `wp_ss_orders`
- `wp_order_sync_teams` → `wp_ss_teams`
- `wp_order_sync_team_members` → `wp_ss_team_members`
- `wp_order_sync_user_teams` → `wp_ss_user_teams`
- `wp_order_edit_history` → `wp_ss_edit_history`
- `wp_subsales_logs` → `wp_ss_logs`
- `wp_subsales_pwa_sessions` → `wp_ss_pwa_sessions`

**Files modified:**
- `wordpress-plugin/subsales-management.php`
- `wordpress-plugin/includes/class-database.php`
- `wordpress-plugin/includes/class-orders.php`
- `wordpress-plugin/includes/class-teams.php`

**Migration:**
Created `wordpress-plugin/tools/rename_tables_to_ss.php` script but encountered issues with WordPress loading path. Abandoned in favor of manual SQL commands.

**SQL Migration (to run in WP-Adminer):**
```sql
RENAME TABLE `wp_order_sync_orders` TO `wp_ss_orders`;
RENAME TABLE `wp_order_sync_teams` TO `wp_ss_teams`;
RENAME TABLE `wp_order_sync_team_members` TO `wp_ss_team_members`;
RENAME TABLE `wp_order_sync_user_teams` TO `wp_ss_user_teams`;
RENAME TABLE `wp_order_edit_history` TO `wp_ss_edit_history`;
RENAME TABLE `wp_subsales_logs` TO `wp_ss_logs`;
RENAME TABLE `wp_subsales_pwa_sessions` TO `wp_ss_pwa_sessions`;
```

### Known Issues ⚠️

#### **CRITICAL: Address Autocomplete Still Not Working**
**Status:** Not resolved as of Dec 1, 2025 end of session

**Symptoms:**
User logs in, types known good address "196 Annelise Ave" - no autocomplete suggestions appear.

**Console logs show:**
```
===== SUBSALES PWA LOADED =====
Timestamp: 2025-12-01T22:46:16.768Z
User Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1 Edg/142.0.0.0
Online Status: true
Config loaded: {apiBase: '...', pluginBase: '...', ...}
API Base: https://www.subsales-test.southingtonbkmb.com/wp-json/order-manager/v1
Plugin Base: https://www.subsales-test.southingtonbkmb.com/wp-content/plugins/subsales-management/pwa/
Brand Name: Southington Subsales
My Orders button found, attaching listener
EOD button found, attaching listener
sw registered
sw registered
[Session Tracking] Module loaded
subsalesNearby: debug build initialized
Config loaded - loginMode: user salesMode: legacy
subsalesNearby: input triggered fetch for query 196
subsalesNearby: searching 0 addresses from IndexedDB
subsalesNearby: input triggered fetch for query 1
subsalesNearby: searching 0 addresses from IndexedDB
```

**Key observations:**
- PWA loads successfully
- Config loads properly (pluginBase set correctly)
- `subsalesNearby: debug build initialized` appears
- **NO prefetch logs** - missing "zip-index.json loaded, starting prefetch"
- **NO first ZIP load** - missing "first ZIP loaded immediately"
- Searches happen with 0 addresses available

**Analysis:**
- Prefetch is **NOT being triggered at all**
- No "zip-index.json loaded" log appears
- No "Manual prefetch triggered" log appears (should happen after login)
- User types immediately after page load
- Search finds 0 addresses in both memory cache (ZIP_CACHE) and IndexedDB
- The prefetch call from `app.js:1446` is not reaching `address-autocomplete.js`

**Expected behavior:**
```
subsalesNearby: zip-index.json loaded, starting prefetch
subsalesNearby: first ZIP loaded immediately: 06489, cache now has 1 ZIPs
subsalesNearby: first ZIP loaded, searches should work now
subsalesNearby: input triggered fetch for query 1
subsalesNearby: searching 10180 addresses from 1 cached ZIPs
[dropdown shows suggestions]
```

**Possible causes:**
1. **Most likely:** `window.subsalesNearby.prefetch()` not being called from app.js (line 1446)
2. `window.subsalesNearby` not exposed/available when app.js tries to call it
3. Script load order issue - app.js loads before address-autocomplete.js
4. The prefetch call is wrapped in a condition that's not being met
5. Error thrown before prefetch is reached (check for earlier errors)
6. Auto-prefetch on page load disabled or timing issue with requestIdleCallback

**Next debugging steps:**
1. **Check if `window.subsalesNearby` exists** - add console log in app.js before calling prefetch
2. **Verify script load order** - ensure address-autocomplete.js loads before app.js tries to use it
3. Add console.log at very start of `window.subsalesNearby.prefetch()` function
4. Check app.js line 1446 - verify the prefetch call is actually executed
5. Look for JavaScript errors that prevent address-autocomplete.js from fully loading
6. Test calling `window.subsalesNearby.prefetch()` manually from browser console
7. Check if auto-prefetch (requestIdleCallback) is working at all

### Files Modified

**Address Autocomplete:**
- `wordpress-plugin/pwa/address-autocomplete.js` - Major UX improvements

**Database Schema:**
- `wordpress-plugin/subsales-management.php`
- `wordpress-plugin/includes/class-database.php`
- `wordpress-plugin/includes/class-orders.php`
- `wordpress-plugin/includes/class-teams.php`

**Tools:**
- `wordpress-plugin/tools/rename_tables_to_ss.php` (created but not used)
- `wordpress-plugin/tools/test_wp_load.php` (diagnostic tool)

### Plugin Packages

**Final package:** `subsales-management.zip`  
**SHA-256:** `e14f7c97f0ccb500ce11437d239bc0342123d91046bbc5dec28d49052b300797`  
**Date:** Dec 1, 2025 22:44

**Includes:**
- Address autocomplete improvements (manual mode + GPS button)
- Database table renames to ss_* prefix
- Prefetch improvements (attempted fix for immediate data availability)
- Enhanced logging for debugging

### Testing Checklist for Tomorrow

- [ ] Verify first ZIP actually loads before searches
- [ ] Check ZIP_CACHE has data after prefetch completes
- [ ] Test known address "196 Annelise Ave" appears in autocomplete
- [ ] Test "Address isn't listed" button appears when no matches
- [ ] Test manual mode allows free-form entry
- [ ] Test "📍 Use my location" button with GPS enabled
- [ ] Verify green border shows in manual mode
- [ ] Check placeholder text updates correctly
- [ ] Test with multiple ZIPs in zip-index.json
- [ ] Verify background prefetch continues after first ZIP

### User Experience Goals (Reminder)

**Problem:** Kids using the system may not know:
- Their ZIP code
- What town they're in
- How to format an address properly

**Solution implemented:**
1. **Autocomplete when data exists** - helpful suggestions if address in database
2. **"Address isn't listed" fallback** - clear escape hatch when not found
3. **GPS location button** - automatic address fill for those who don't know
4. **No validation errors** - accepts whatever they type
5. **Visual feedback** - green border in manual mode, loading states

**Still needs:**
- Autocomplete to actually work (critical bug fix)
- Possible future: Team-based default ZIP/town pre-fill
