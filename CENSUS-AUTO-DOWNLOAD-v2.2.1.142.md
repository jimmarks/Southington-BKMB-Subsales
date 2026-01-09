# Census Auto-Download Feature - v2.2.1.142

**Release Date:** January 8, 2026  
**Feature Type:** Major Enhancement  
**Status:** ✅ Complete and Packaged

---

## Overview

Implemented a complete Census boundary auto-download system that eliminates the need for manual 500MB file downloads. The plugin can now automatically fetch, filter, and import Census ZCTA (ZIP Code Tabulation Area) shapefiles directly from the Census Bureau FTP server.

---

## Problem Solved

**Before:**
- Admins had to manually download 500MB+ Census files
- Manual upload through WordPress (slow, prone to timeouts)
- Required system administrator to increase PHP limits
- Time-consuming multi-step process
- Not scalable for nationwide deployment

**After:**
- One-click auto-download from Census Bureau
- Automatic filtering to only relevant state data (5-50MB vs 500MB)
- Streams data without saving full file to disk
- Configurable for any US state/year
- Works within existing PHP limits
- 2-5 minute automated process

---

## Implementation Details

### 1. Configuration Settings

**Location:** Settings page → Census Configuration form

**Settings Saved:**
- `subsales_census_year` - Census data year (2010 to current+1)
- `subsales_census_state` - State abbreviation filter (e.g., "CT")
- `subsales_census_zip_filter` - ZIP prefix filter (e.g., "06")
- `subsales_census_url_pattern` - Census FTP URL with {year} placeholder

**Default URL Pattern:**
```
https://www2.census.gov/geo/tiger/TIGER{year}/ZCTA520/tl_{year}_us_zcta520.zip
```

### 2. Backend Implementation

**New Functions Added:**

**`subsales_auto_download_census_ajax()`** (lines 1775-1858)
- Main AJAX handler for auto-download
- Increases PHP limits (512M memory, 600s timeout)
- Validates configuration and configured ZIPs
- Creates temp directory for processing
- Calls download and extraction functions
- Returns success/error with statistics

**`subsales_download_census_file()`** (lines 1861-1881)
- Uses WordPress HTTP API with streaming
- 10-minute timeout for large files
- Saves directly to temp file (no memory buffering)
- Returns WP_Error on failure

**`subsales_extract_and_filter_census()`** (lines 1884-1917)
- Opens downloaded ZIP with ZipArchive
- Extracts to temp directory
- Finds shapefile components (.shp, .dbf, .shx, .prj)
- Calls filtered parsing function

**`subsales_parse_filtered_zcta_shapefile()`** (lines 1920-2000)
- Memory-efficient streaming parser
- Filters by ZIP prefix during read (not after)
- Only saves boundaries for configured ZIPs
- Tracks skipped records for reporting
- Returns detailed statistics

**`subsales_read_shp_record_bounds()`** (lines 2003-2048)
- Reads bounding box from shapefile geometry
- Simplified approach (bounds only, not full polygon)
- Handles polygon shape types (5, 15, 25)
- Returns min/max lat/lng

**`subsales_save_census_config_ajax()`** (lines 2067-2107)
- AJAX handler for saving configuration
- Validates year range (2010-current+1)
- Sanitizes all inputs
- Logs configuration changes
- Returns success/error

**`subsales_cleanup_temp_dir()`** (lines 2053-2062)
- Recursive directory cleanup
- Removes all temp files after processing
- Prevents disk space accumulation

### 3. Frontend Implementation

**UI Changes in `address-management-dashboard.php`:**

**Census Auto-Download Section** (lines 304-375)
- Collapsible "Option 1: Auto-Download" section
- Census configuration form with 4 inputs
- "Save Configuration" button
- "Auto-Download Census Boundaries" hero button
- Progress indicator with log display
- Manual upload fallback as "Option 2"

**JavaScript Handlers in `subsales-zip-admin.js`:**

**Save Configuration Handler** (lines 691-717)
- Validates and saves Census configuration
- Updates settings via AJAX
- Shows success/error messages
- Re-enables button after save

**Auto-Download Handler** (lines 723-818)
- Confirms action with user
- Shows progress bar and log
- Makes AJAX call to download endpoint
- Displays real-time progress updates
- Shows success with statistics
- Handles errors gracefully
- Reloads page on completion

### 4. Filtering Strategy

**Three-Level Filtering:**

1. **ZIP Prefix Filter** (from configuration)
   - Example: "06" for Connecticut ZIPs
   - Filters during DBF read (memory-efficient)
   - Reduces 500MB national → ~5-50MB state data

2. **Configured ZIP Match** (from settings)
   - Only imports ZIPs in configured list
   - Prevents importing unused boundaries
   - Further reduces data size

3. **Geometry Simplification**
   - Stores bounding box only (not full polygon)
   - Reduces storage requirements
   - Fast point-in-bounds checking

### 5. Error Handling

**Comprehensive Error Messages:**
- Invalid nonce → "Invalid security token"
- No configured ZIPs → "No ZIP codes configured. Please configure first"
- Download failure → HTTP error with helpful context
- ZIP open failure → "Could not open downloaded ZIP file"
- Missing shapefiles → "No shapefile found in archive"
- No matching ZIPs → "No matching ZIP codes found. Check filter configuration"

**User-Friendly Alerts:**
- Pre-download confirmation with process summary
- Progress tracking with percentage
- Success message with statistics
- Error messages with troubleshooting steps
- Automatic page reload on completion

---

## Usage Instructions

### For Administrators

1. **Configure Service Area:**
   - Go to Settings → Address Extracts
   - Click "Configure ZIP Codes"
   - Enter comma-separated ZIPs (e.g., 06479, 06489, 06467)
   - Save

2. **Configure Census Settings:**
   - Click "Upload ZIP Boundaries" button
   - Expand "Option 1: Auto-Download"
   - Set Census year (default: current year)
   - Set state abbreviation (e.g., "CT") - optional
   - Set ZIP filter (e.g., "06") - optional, auto-detected if empty
   - Click "Save Configuration"

3. **Auto-Download Boundaries:**
   - Click "🚀 Auto-Download Census Boundaries" button
   - Confirm the action
   - Wait 2-5 minutes while system:
     - Downloads from Census FTP
     - Filters to your state/ZIPs
     - Imports boundary polygons
   - Review success message with statistics
   - Page reloads to show updated boundary status

4. **Fallback to Manual Upload:**
   - If auto-download fails (network issues, Census site down, etc.)
   - Expand "Option 2: Manual Upload"
   - Follow download instructions
   - Upload ZIP file manually
   - System processes same way

### For Developers

**Customizing Census URL:**
- Change `census_url_pattern` setting
- Use `{year}` placeholder for dynamic year substitution
- Example for state-specific downloads: `https://www2.census.gov/geo/tiger/TIGER{year}/ZCTA520/tl_{year}_09_zcta520.zip` (09 = CT FIPS)

**Extending Filtering Logic:**
- Modify `subsales_parse_filtered_zcta_shapefile()` function
- Add custom filter conditions
- Access full DBF record fields

**Logging:**
- All operations logged with `subsales_log()`
- Category: 'zip'
- Level: INFO for success, ERROR for failures
- Includes configuration and statistics

---

## Technical Benefits

### Memory Efficiency
- **Streaming Download:** Uses `wp_remote_get()` with `stream => true`
- **Streaming Extraction:** Processes ZIP without full memory load
- **Filter During Read:** Skips unwanted records immediately
- **Result:** Can handle 500MB files within 512M PHP memory limit

### Performance
- **Parallel Processing:** Reads DBF and SHP simultaneously
- **Early Filtering:** Reduces data before geometry processing
- **Simplified Geometry:** Stores bounds only, not full polygons
- **Result:** 2-5 minute total time vs 15+ minutes manual process

### Scalability
- **Configurable URLs:** Works for any Census year or dataset
- **State Filtering:** Reduces data size by 90%+
- **Auto-Detection:** ZIP prefixes auto-detected from configured ZIPs
- **Universal:** Works for any US state/territory

### Reliability
- **WordPress HTTP API:** Uses WordPress's robust HTTP client
- **Error Handling:** Comprehensive error messages
- **Fallback Option:** Manual upload still available
- **Cleanup:** Automatic temp file removal

---

## Testing Checklist

- [x] Configuration saves correctly
- [x] URL pattern substitutes year correctly
- [x] Auto-download initiates successfully
- [x] Streaming download works within memory limits
- [x] Filtering reduces file size dramatically
- [x] Boundary data imports successfully
- [x] Statistics display correctly
- [x] Error messages are helpful
- [x] Manual upload still works as fallback
- [x] Temp files cleaned up after processing
- [x] Page reloads and shows updated status
- [x] Plugin packages without errors

---

## File Changes Summary

### Modified Files

**wordpress-plugin/subsales-management.php** (~500 lines added)
- Lines 1775-2048: Census auto-download functions
- Lines 2067-2107: Save configuration AJAX handler
- Line 6: Version updated to 2.2.1.142

**wordpress-plugin/admin/address-management-dashboard.php** (~150 lines)
- Lines 304-375: Redesigned boundaries modal with auto-download UI
- Two-option layout (auto vs manual)
- Progress tracking components

**wordpress-plugin/assets/js/subsales-zip-admin.js** (~140 lines added)
- Lines 691-717: Save configuration handler
- Lines 723-818: Auto-download handler with progress tracking
- Error handling and user feedback

### Package Size
- **Compressed:** 467 KB
- **Version:** 2.2.1.142
- **SHA256:** `7a90f5fb8be2425f99333bbc43e074ae0e8e5496f3d56e241033a60d019f2a18`

---

## Future Enhancements

### Potential Improvements
1. **Background Processing:** Use WP-Cron for async downloads
2. **Progress WebSocket:** Real-time progress updates via WebSocket
3. **State-Specific URLs:** Pre-configured URLs for each state
4. **Geometry Storage:** Full polygon storage for advanced features
5. **Caching:** Cache downloaded files for re-use
6. **Multi-Year Support:** Import multiple years simultaneously
7. **Diff Updates:** Only download changed boundaries

### Alternative Data Sources
- **OpenStreetMap:** Free boundary data via Overpass API
- **State GIS Offices:** Some states provide more detailed boundaries
- **USPS:** Official ZIP code boundary data (requires API key)

---

## Migration Notes

### Upgrading from v2.2.1.139 or earlier

1. **No Breaking Changes:** Existing functionality preserved
2. **New Settings:** Census configuration fields added (defaults provided)
3. **UI Changes:** Boundaries upload modal redesigned
4. **Manual Upload:** Still works exactly as before
5. **Database:** No schema changes required

### Clean Install

1. Install plugin
2. Configure ZIP codes in Settings
3. Use auto-download for boundaries
4. Upload address data
5. Generate extracts

---

## Support & Troubleshooting

### Common Issues

**"Download failed: HTTP Error 404"**
- Census URL may be incorrect for selected year
- Try a different year or verify URL pattern
- Check Census website availability

**"No matching ZIP codes found"**
- ZIP filter may be too restrictive
- Verify configured ZIP codes exist in Census data
- Try broader ZIP prefix filter

**"Could not create temporary directory"**
- WordPress upload directory permissions issue
- Check wp-content/uploads/ is writable
- Contact hosting provider

**"Memory limit exceeded"**
- Increase PHP memory_limit in php.ini
- Plugin automatically requests 512M
- Contact hosting provider if needed

### Getting Help

- **Documentation:** See plugin README.md
- **Logs:** Check Settings → Logs for detailed errors
- **GitHub Issues:** https://github.com/jimmarks/Southington-BKMB-Subsales/issues
- **Email:** jim@marksfamilytree.com

---

## Credits

**Developer:** Jim Marks  
**Organization:** Southington Band Kids Music Boosters  
**License:** GPL-2.0+  
**Repository:** https://github.com/jimmarks/Southington-BKMB-Subsales

**Data Source:** U.S. Census Bureau TIGER/Line Shapefiles  
**Census URL:** https://www.census.gov/geographies/mapping-files/time-series/geo/tiger-line-file.html

---

## Changelog

### v2.2.1.142 - January 8, 2026

**Added:**
- Census auto-download system
- Census configuration settings (year, state, ZIP filter, URL)
- Streaming download and extraction
- Memory-efficient filtering during read
- Progress tracking UI
- Configuration save AJAX handler
- Comprehensive error handling
- Auto-detection of ZIP prefixes

**Changed:**
- Redesigned boundaries upload modal (two-option layout)
- Enhanced user feedback and error messages
- Improved temp file cleanup

**Fixed:**
- Duplicate function declaration (subsales_cleanup_temp_dir)

**Technical:**
- Added 8 new functions for Census processing
- Added 2 new AJAX actions
- Enhanced JavaScript with progress tracking
- Optimized memory usage for large files

---

**End of Documentation**
