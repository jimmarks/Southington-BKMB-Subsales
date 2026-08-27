# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.4.0] - 2026-08-27

### Fixed
- **The order form stopped nagging about the phone field.** Tapping into Phone and then going back to fix the address popped an error, even with nothing typed — so moving between fields, which sellers do constantly, kept throwing warnings. It now only warns about a **half-typed** number, where the warning actually helps. A phone number is still required to save the order; that hasn't changed.

### Added
- **Text message groundwork (not switched on).** The plumbing for text receipts is now in place: a Text Messages tab in Settings for your Twilio details, an editable receipt message with a live preview, and the behind-the-scenes machinery to send them. **Nothing sends yet** — the master switch is off, and the remaining pieces (handling replies and STOP, and the "check my address" link) are still to come. Safe to install; nothing changes for sellers or customers.
- **Donations are now recorded as donations.** When a seller uses the donation button, the order is marked as such. Previously a donation looked identical to a sale where the customer wouldn't give their phone number — both just had a placeholder number. Keeping them apart means "how many people declined to give a number" becomes an answerable question, and it stops donations from ever generating a pointless text.

## [3.3.0] - 2026-08-26

### Fixed — orders could be silently lost
- **Two children saving an order in the same instant could wipe one of the sales.** Every order got an id built from the clock alone, so two phones saving in the same millisecond produced the same id. The server saw the second one as a repeat of the first, said "already saved", and the app then deleted it from the child's phone. No error appeared anywhere — the sale simply vanished. Looking at last season's real orders, the closest two landed **3 milliseconds apart**, with 38 pairs inside the same second. Order ids now include a per-phone marker, so two phones can't collide at all.
- **One rejected order no longer blocks every order behind it.** If the server refused a single order, syncing stopped dead there — and did so again on every retry, so the rest never went up. Now a rejected order is set aside and reported at the end while everything else sends; if the phone simply has no signal, syncing pauses with one clear message instead of an alert per order. Nothing is ever deleted from the phone until the server confirms it.

### Added
- **"Set Up Season" walkthrough** (Settings → Set Up Season). One button opens a step-by-step guide covering everything a new season needs — naming the season, picking the sale days, updating the roster, checking pricing, confirming team vs individual mode, refreshing addresses, and opening sales. Each step shows where things currently stand, so it's just as useful mid-season to check nothing was missed. Sale days are picked with a simple date picker and list.

### Fixed — seasons
- **Sale days weren't really tied to a season.** Dates created from the calendar were filed under no season at all, every season's dates showed in the list forever, and a given date could only ever exist once across all years. All three are fixed, and the seller app no longer shows last season's sale days to children.
- **A fix that had quietly undone itself.** Team names were supposed to be reusable in a new season; a WordPress schema step kept restoring the old restriction behind the scenes. Both that and the equivalent for sale days are now repaired properly.
- **The "Needs Review" address list now clears itself.** Addresses sorted out by adding a missing ZIP code and re-ingesting stayed on the list as though still outstanding — 153 of them. They're now retired automatically after an ingest and hourly.
- **Deleting a sale day gives the real reason.** It previously only checked for sign-ups, ignored driver assignments and card payments, and always said "cannot delete — signups" whatever the actual cause.

### Changed
- **Menu trimmed from 10 items to 8.** Campaign Dates moved under Seasons as a "Sales Days" tab, and App Sessions moved under Logs. Nothing was removed — both are one click from where they were.

## [3.2.1] - 2026-08-26

### Fixed
- **New database tables are now created when the plugin updates, not only when it's activated.** WordPress doesn't run a plugin's activation step during an update, so the "Needs Review" table added in 3.2.0 was never created on sites that updated normally. The first ingestion then reported addresses as sent to the review list when they had actually been discarded. The schema check now runs automatically after any version change, and this applies to every future update too.
- **The ingestion summary no longer counts addresses it failed to save.** It counts only what actually landed and reports a clear error for the rest, instead of quietly overstating what was filed.

## [3.2.0] - 2026-08-26

### The address book was rebuilt from scratch

Getting addresses into this plugin used to mean hunting down GIS shapefiles on a town website, uploading them, and hoping. Three separate half-finished import systems had accumulated, plus a nightly job that asked an admin to approve addresses one at a time (which stalled after about a month of real use). Now an admin just types the ZIP codes they're selling in and presses one button.

### Added
- **Automatic address ingestion from Connecticut state parcel data.** Type your ZIP codes, press "Ingest Addresses," and the plugin pulls every property address for your town straight from Connecticut's official statewide parcel service - free, no account, no downloads, no GIS knowledge. Re-run it any time; each ZIP's addresses are replaced with a fresh copy.
- **A "Needs Review" list** for the small number of addresses the automatic process can't place confidently, plus addresses sellers typed that aren't on file. Nothing is blocked by this list - it's a to-do list you work through whenever it suits you, not an alarm. Each row can be fixed, looked up, or ignored.
- **A rebuilt "Order Entry Distance" report** that actually flags problems instead of just listing numbers. It now catches the case that matters: the phone's GPS was *accurate* and the seller was still two miles from the address they typed - which usually means they picked the wrong street. It also flags addresses that share a house number and street name but differ only by suffix (Southington really does have a Pine St and a Pine Dr, ~2 miles apart), and shows which one the phone was actually next to.

### Fixed
- **Whole streets were being filed under the wrong ZIP code.** All ~130 addresses on Buckland St were stored as 06489 when they're really 06479 - every single one wrong. The old code never actually tested whether a point fell inside a ZIP code's boundary; it compared against a rectangle drawn around it, and neighbouring ZIP rectangles overlap. Worse, when its cached boundary data was in the wrong format it silently assigned *every* address in a batch to whichever ZIP happened to be listed first. A separate bug did the same thing from another direction, defaulting to "the first ZIP in your settings" whenever a lookup failed. ZIP codes are now decided by a real inside-the-boundary test, and an address that can't be placed confidently goes to the review list instead of being quietly mislabelled - it will never silently guess again.
- **Address autocomplete now works the way sellers actually type.** Typing "196 Pondvi" found nothing, because the search demanded your text appear exactly as one continuous chunk. It now matches on the house number first, then the street, and no longer cares whether you type the right ending (St/Dr/Ave), get the spacing right, or make a small typo - "196 pond view" finds "196 Pond View Dr". The correct, fully spelled address always comes from the database.
- Suggestions appear instantly and are *then* reordered if GPS arrives, so a seller at a door never waits on a location fix.
- Multi-unit buildings no longer flood the suggestions with one entry per unit; they appear once, and the unit goes in the existing Unit/Apt/Floor box.

### Removed
- The shapefile uploader, the Census boundary downloader, the OpenStreetMap matcher (which had processed 7 addresses out of 18,193), the OpenAddresses.io importer, the CSV upload that only ever answered "Coming in Phase 8!", the nightly validation job and its approve-one-at-a-time screen, and an unreachable duplicate GPS report. About 2,000 lines of dead or actively harmful code, including a second address-file generator that wrote a subtly different format to the same filename as the real one.

## [3.1.1] - 2026-08-26

### Fixed
- **"Use my location" no longer costs money on every tap.** The PWA's 📍 button was reverse-geocoding through a direct, uncached call to the Google Geocoding API each time it was pressed - an action a seller can repeat at every stop, with no throttle or cap. It now resolves to the nearest address in the ZIP data already cached on the device, so it makes no network call and incurs no per-request charge. Note: this is only as accurate as the local address data, so it should be re-checked during field testing - if it fills in the wrong house, the fix is better local data, not restoring the billed lookup.

## [3.1.0] - 2026-08-25

### Added
- **Digital payments via Square Checkout.** A third payment option alongside cash/check - the seller shows a QR code, the buyer scans it and pays on their own phone via a Square-hosted checkout page (no account required). Includes a "ready?" confirmation step before the checkout session is created (so the 15-minute link expiry doesn't start until the buyer is actually about to pay), automatic status polling with no manual refresh needed, and a same-shape fallback to cash/check if the seller backs out. Digital sales count toward every sales-total view (the seller's own running total, admin reports, the leaderboard) but are kept separate from the driver's "cash/checks to collect tonight" figure, since nothing physically changes hands for a digital sale.
- **Season management.** Teams, campaigns, and orders are now scoped to a season; a new "Start New Season" admin action retires the prior season's teams (never deletes anything) so next year's rebuild starts clean. The roster-import tool was rebuilt from a destructive full-replace into a safe upsert.
- **Auto-updates.** This plugin now checks GitHub Releases for updates (via [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker)) instead of requiring a manual file replacement for every release.

### Fixed
- Driver signup no longer requires the driver's own phone number to be pre-loaded on the roster - only the child's phone (the actual security gate for driver signup) does.
- A member re-signing up after being deactivated is now reactivated instead of staying silently locked out.
- A member can no longer be signed up to two different teams for the same sales day.
- Signup now rejects a phone number that isn't already on the roster instead of silently creating a new member.

## [3.0.2] - 2026-08-25

### PWA Heartbeat Reliability Fixes

#### Fixed
- **Debug Mode toggle** - "Enable Debug Mode" button was completely non-functional due to a nonce-name mismatch between the button and its handler; even when triggered it wrote to option keys nothing else read. Now correctly enables/disables the actual logging flag used by the heartbeat system, the 24h auto-timeout, and the admin Logs page.
- **Heartbeat session-recovery data loss** - when a heartbeat arrived for a session the server had lost track of, the client would recreate the session but then discard that heartbeat's own GPS/activity data instead of retrying it. The recreated session's first heartbeat is now retried instead of dropped.
- **Silent heartbeat-insert failures** - a missing/broken GPS-history table could fail a heartbeat insert with nothing but a PHP error-log line. Failures now also surface as an ERROR row on the admin Logs page.
- **Duplicate GPS call on order save** - every order save fired two sequential 5-second geolocation requests where only one was ever used; removed the dead, unused call. Cuts up to 10 seconds of latency per save in poor-signal areas.

#### A note on heartbeat gaps while a phone is locked

Some heartbeat gaps will still happen when a phone is locked or the app is backgrounded, and that's expected, not a bug. Every mobile browser (iOS Safari and Android Chrome alike) pauses or heavily throttles anything running in a browser tab once the screen locks or the app drops to the background, as a battery-saving measure — this happens to every website and web app, not something specific to this plugin. A web-based PWA has no way to force the phone to keep it running in the background the way a native, app-store-installed app can; building a native app was a deliberate choice this project didn't make, so this is a limitation we're accepting rather than one we're trying to engineer around. What this release *does* fix are the reliability problems that were actually losing data while the app was open and in active use: the debug toggle that made field issues undiagnosable, the server-side bug that threw away a heartbeat's data even after successfully recovering the session, and the wasted GPS time on every save. An occasional missed ping from a brief network blip, or a gap while the phone sits locked in someone's pocket for an extended stretch, is fine by design - the next heartbeat 30 seconds later covers it - and is a different, expected limitation from the bugs fixed above.

## [3.0.1] - 2026-08-25

### Branch Consolidation

- Merged `feature/driver-signup` (last deployed production code, v2.4.121) into `main`; `main` was previously stale at v2.4.114.
- Retired the multi-branch workflow going forward — `main` is now the single line of development for this plugin.
- Version bumped to 3.0.1 as the starting point for the next phase of work (text messaging).

## [2.4.106] - 2026-02-10

### Import/Restore - Enhanced Progress Diagnostics

#### Added
- **Early acknowledgment message** - "Server received file, initializing..." (21%) sent BEFORE streaming setup
  - Helps diagnose if gaps are in network/buffering vs actual processing
  - Appears immediately after upload completes
- **File size display** - Shows backup file size in MB: "Starting restore (2.45 MB backup file)"
- **ZIP extraction progress**:
  - "Extracting backup archive (N files)..." when extraction starts (39%)  
  - "Extracted N files from archive" when extraction completes (39%)
  - Shows exactly what's happening during the 2-minute gap
- **Additional buffer control headers**:
  - `Cache-Control: no-cache` to prevent caching layers
  - Loops through all output buffer levels to ensure clean state
  - `apache_setenv('no-gzip', '1')` to disable compression

#### Changed
- Progress callback now handles special messages with `$stats['message']` parameter
- "Processing backup file..." moved earlier (38%) before extraction begins  
- Moved from "Starting restore operation" (25%) to "Starting restore (X MB backup file)" (23%)

#### Technical Details
**What the gap reveals:**
- **3:26:16**: Upload completes (client knows)
- **3:26:17** (expected): "Server received file, initializing..." (if server responsive)
- **3:26:18** (expected): "Starting restore (2.45 MB backup file)"  
- **3:26:19** (expected): "Preparing to extract backup file..."
- **3:26:20** (expected): "Extracting backup archive (145 files)..."
- **3:26:XX-3:28:YY**: [ZIP EXTRACTION HAPPENING - ~2 minutes for large backups]
- **3:28:33**: "Extracted 145 files from archive"
- **3:28:34+**: Clear operations, then table-by-table imports

**Purpose**: These diagnostic messages will show exactly where time is spent:
- If "Server received file" appears immediately → Server is responsive, gap is in extraction
- If "Server received file" is delayed → Network/web server buffering issue
- If "Extracting..." to "Extracted" is 2 minutes → ZIP file size/corruption is the bottleneck

## [2.4.105] - 2026-02-10

### Import/Restore - Real-Time Streaming Progress

#### Changed
- **PHP Backend (`includes/class-backup-restore.php`)**:
  - Modified `handle_ajax_import()` to stream progress updates in real-time
  - Enable output buffering control to flush incremental updates
  - Added `$progress_callback` parameter to `import_file()`
  - Calls callback after each table completes with stats (imported/updated counts)
  - Streams progress as newline-delimited JSON (type: 'progress' or 'complete')
  - Shows real progress percentages: Upload (0-20%), Clear (25-35%), Tables (40-95%), Complete (100%)

- **JavaScript Frontend (`assets/js/subsales-import-modal.js`)**:
  - Removed fake progress animation (20%→90% in 1-second increments)
  - Added `progress` event listener to process streaming response line-by-line
  - Parses newline-delimited JSON chunks as they arrive from server
  - Updates progress bar with real percentages based on completed tables
  - Shows table-by-table completion: "Addresses: 18,194 imported, 0 updated"
  - Displays clear stages: "Clearing all data...", "Clearing complete"
  - No more 2-minute silent gaps - updates appear as each table processes

#### Fixed
- Import progress now shows actual completion per table instead of fake animation
- User sees which table is being processed in real-time
- Progress bar increments accurately based on completed work (not time)
- Clear operations show before/during/after stages
- Each table completion (e.g., "Orders: 3,456 imported") appears immediately

#### Technical Details
- Streaming protocol: Server sends JSON lines with `type: 'progress'` (updates) or `type: 'complete'` (final)
- Progress calculation: 40% base + (table_count × 5%) per table, capped at 95%
- Upload phase shows transfer progress (0-20%)
- Clear phase shows warning messages (20-35% if restore mode)
- Import phase shows per-table results (40-95%)
- Completion shows final summary with error grouping (100%)

## [2.4.81] - 2026-02-10

### Major Code Refactoring - Backup/Restore System

#### Added
- **New Class**: `Subsales_Backup_Restore` (`includes/class-backup-restore.php`)
  - ~1,100 lines extracted from main plugin file
  - Handles all export, import, and restore operations
  - Complete rewrite of import processor with filename-based detection
  - Supports ALL 12 database tables (was only 5 tables before)
  - Exports complete schema (orders: 21 cols, addresses: 16 cols, all other tables)
  - Auto-geocodes addresses during import if coordinates missing
  - Per-table import statistics in confirmation messages

#### Changed
- **Export System** - Complete overhaul:
  - Now exports 12 tables: orders, teams, members, user_teams, addresses, edit_history, logs, pwa_sessions, pwa_heartbeats, campaigns, signups, team_campaigns
  - Was: 5 tables (orders 8 cols, teams 4 cols, members 6 cols, addresses 9 cols, settings 12 opts)
  - Now: All tables with complete column sets + 16 settings options
  - Adds `BACKUP_INFO.json` metadata file with record counts
  - Better logging throughout export process

- **Import System** - Complete rewrite:
  - Changed from fragile column-name detection to robust filename-based routing
  - Each table type has dedicated import handler
  - Generic table import method reduces code duplication
  - Special handlers for:
    * Addresses (with auto-geocoding)
    * User-teams junction table (compound keys)
    * Signups (compound keys)
    * Team campaigns (compound keys)
  - Comprehensive error handling and logging
  - Returns per-table statistics (imported, updated, skipped)

- **Confirmation Messages** - Enhanced formatting:
  - HTML formatted with per-table breakdowns
  - Shows exactly which tables were processed
  - Displays geocoding and ZIP correction counts
  - Red error highlighting for issues

#### Technical Details
- **Architecture**: Follows modular pattern (like class-database.php, class-rest-api.php)
- **Admin Hooks**: All `admin_post_` handlers registered in class init
- **Backward Compatible**: Existing settings UI unchanged - just better backend
- **Code Reduction**: Main plugin file reduced by ~600 lines (14,735 → 14,135)
- **Maintainability**: Export/import logic now isolated and testable

#### Developer Notes
See `BACKUP_RESTORE_SPEC.md` for complete technical specification including:
- All table schemas (12 tables documented)
- Export structure and file format
- Import modes (merge vs restore)
- Detection strategy
- Testing checklist

## [2.4.39] - 2026-02-05

### Reports Menu Structure Correction

#### Changed
- **Reports Menu** - Now shows single menu item (no submenus):
  - Reports page displays clickable cards for each report
  - Individual report pages hidden from menu but accessible via links
  - Cleaner navigation without cluttered submenu items
- **Report Renaming**:
  - "Team Sales Report" → "Points Report" (better reflects actual purpose)
  - All page titles and references updated

## [2.4.36] - 2026-02-05

### Address Matching Improvements

#### Fixed
- **International Country Code Support** - Parser now recognizes:
  - "EE. UU." (Spanish for USA)
  - "Stati Uniti" (Italian for USA)
  - Previous versions only removed "USA", "US", "United States" causing parse failures for international-formatted addresses
- **Trailing Junk Data Cleanup** - Removes spurious data after main address:
  - Duplicate house numbers at end (e.g., "231 Debbie Dr... 231")
  - Random words (e.g., "Southington none", "Plantsville early")
  - Extra unit descriptors and formatting issues
- **Street Type Normalization Bug** - Fixed regex grouping in alternation patterns
  - Previous: `/\bSTREET|ST\.?\b$/` (incorrect boundary grouping)
  - Now: `/\b(STREET|ST\.?)\b$/` (proper alternation grouping)
  - Ensures consistent normalization of "Street" → "ST", "Drive" → "DR", etc.

#### Added
- **ZIP Code Fallback Matching** - Address Coverage Report now:
  - First attempts exact match with ZIP code
  - Falls back to matching without ZIP if first attempt fails
  - Catches cases where order has wrong ZIP but address is valid
  - Logs "MATCHED WITHOUT ZIP" for debugging wrong ZIP codes

#### Changed
- **City Extraction** - Strips trailing junk words from city names (none, early, unit, apt, #, numbers)
- **Street Name Cleanup** - Removes duplicate house numbers and trailing periods from street names

## [2.4.35] - 2026-02-05

### Reports Menu Reorganization

#### Changed
- **Hierarchical Reports Menu** - Reports now shows landing page with report cards:
  - Reports → Landing page with report cards
  - ├─ Team Sales → Original sales by team report
  - ├─ Address Coverage → Address matching diagnostics
  - Visual card-based interface for better navigation

#### Added
- New `admin/reports-index.php` - Reports landing page with card layout
- Split `render_reports_page()` and `render_team_sales_report()` functions in `class-admin-pages.php`

## [2.0.0.27] - 2024-12-03

### Address Management Consolidation & Auto-Resumable Matching

#### Added
- **Auto-Continuing Overpass Batch Processor** - Click once and walk away!
  - New "Match Addresses with Overpass" button in Address Management tab
  - Automatically processes all addresses in 25-address batches
  - Real-time progress log with batch-by-batch updates
  - No more manual clicking or PHP timeout errors
  - Extended execution time to 5 minutes per batch
- **Unified Address Management Tab** - Single location for all address settings
  - Consolidated Service Area configuration and Address Extracts functionality
  - Enhanced ZIP configuration UI with visual feedback
  - Google Maps API status indicator (green = configured, red = missing)
  - Shows current ZIP count and configured codes
  - Professional card-based layout with clear sections

#### Changed
- **Menu Structure:** Removed standalone "Address Extracts" menu item (now in Settings → Address Management)
- **Tab Navigation:** Removed duplicate "Service Area" tab (merged into Address Management)
- **Single Unified Interface:** All address and ZIP management now in Settings → Address Management
- **Overpass Batch Size:** Reduced from 100 → 25 addresses per batch to prevent timeouts
- **ZIP Storage:** Dual save to both `subsales_served_zips` AND `subsales_served_zipcodes` for backward compatibility

#### Fixed
- **Fatal Error:** Added missing `require_once` for `includes/overpass-matcher.php` (Class not found error)
- **PHP Timeout on Overpass Matching:** Batch processing prevents 500 errors on large address sets
- **Duplicate ZIP Configuration:** Consolidated three separate locations (standalone menu + 2 tabs) into single interface
- **Menu Clutter:** Cleaner admin menu with address management properly nested under Settings

#### Technical Details
- Deleted 88 lines of duplicate ZIP configuration UI
- Removed redundant form handlers (Address Management tab handles all saves)
- Maintains backward compatibility for existing ZIP data
- All features accessible from Settings → Address Management

## [2.0.0.26] - 2024-12-03

### Major Portability Refactor - Location-Agnostic System

**BREAKING CHANGE (Backward Compatible):** Removed all hardcoded Southington, CT location logic. System now works for any city/state with simple ZIP code configuration.

#### Added
- **Service Area Configuration Panel** in Settings → Service Area with ZIP code management UI
- **Dynamic ZIP Code Management** with multi-level fallback chain
- **Google Maps API Integration** for ZIP boundary geocoding and reverse geocoding
- **Location-Agnostic Overpass Queries** that work with any US location
- 7-day transient caching for bounding box calculations
- Visual status indicators for configured ZIPs and API key status
- Expandable technical documentation in admin panel

#### Changed
- **Overpass Matcher:** Complete refactor of `includes/overpass-matcher.php` (~250 lines changed)
  - Removed hardcoded Southington bounding box `[41.56, -72.92, 41.63, -72.84]`
  - Removed hardcoded latitude-based ZIP guessing logic
  - Removed city requirement from Overpass queries
  - Added Google Maps geocoding for all location logic
- **Settings Navigation:** Added "Service Area" tab
- **ZIP Storage:** New `subsales_served_zipcodes` array format (backward compatible with old format)

#### Fixed
- Portability issue: System was hardcoded for Southington, CT only
- Missing ZIP configuration UI visibility
- Geographic assumptions in coordinate-to-ZIP conversion

#### Technical Details
- Google Maps API: Geocoding + Reverse Geocoding (~$1-5/month typical usage)
- Caching: 7-day transient for bounding boxes
- Backward compatible: Auto-defaults to Southington ZIPs (06479, 06489, 06467)
- Graceful fallback when API key unavailable

## [1.1.1] - 2025-10-18

### Fixed
- Fixed database table creation on plugin activation using proper dbDelta SQL formatting
- Fixed admin menu not appearing after fresh installation
- Fixed team creation error handling with specific validation messages
- Consolidated activation hooks into single function for reliable initialization
- Added activation notice to confirm successful database setup
- **Fixed menu separator conflict that caused Comments menu to disappear when plugin activated**
- Removed custom menu separators that interfered with WordPress core menu system

## [1.1.0] - 2025-10-17

### Added
- Top-level admin menu positioned after Comments with visual separators
- Multi-team management system supporting unlimited teams
- Google Maps API key configuration with secure sharing to mobile clients
- Professional dashboard with team and order statistics
- Enhanced Teams Management page with:
  - Team creation with unique access codes
  - Team member management (add/remove)
  - Role-based permissions (Member, Manager, Admin)
  - Last login tracking for team members
- REST API `/config` endpoint for delivering Google Maps API key to authenticated teams
- Database table for teams (`wp_order_sync_teams`) with unique constraints
- Team-level isolation for orders

### Changed
- Admin menu moved from Settings submenu to top-level menu
- Enhanced admin interface with modern WordPress design patterns
- Updated version management workflow for plugin releases
- Improved REST API permission checks for team-based authentication

### Fixed
- REST API closure syntax error in plugin initialization
- Removed duplicate settings blocks causing PHP parse errors
- Corrected plugin version constants and display

## [1.0.0] - 2025-10-17

### Added
- Initial release of Subsales Management plugin
- Complete team management system with role-based access (Member, Manager, Admin)
- WordPress admin interface for plugin configuration
- Team name and access code authentication for mobile app login
- Database tables for orders and team members with automatic creation
- REST API endpoints for order management (CRUD operations)
- REST API endpoints for team authentication and management
- Multiple authentication methods:
  - System-level API key authentication
  - Team-level authentication (team name + access code)
  - Individual member authentication (email + member access code)
- Order synchronization with configurable sync intervals
- Professional admin dashboard with statistics
- Version management system for clean updates
- Proper WordPress plugin standards compliance
- Security features including nonce protection and data sanitization
- Database cleanup on plugin uninstall

### Security
- Proper capability checks for admin access (`manage_options`)
- SQL injection prevention with prepared statements
- Data sanitization for all user inputs
- CSRF protection with WordPress nonces
- Secure API authentication with multiple methods

### Technical
- WordPress 5.0+ compatibility
- PHP 7.4+ requirement
- Database schema versioning for updates
- Clean uninstall with data removal option
- Following WordPress coding standards
- Proper plugin structure and naming conventions

## [Unreleased]

### Planned
- Mobile app integration examples
- Enhanced reporting features
- Export/import functionality
- Advanced team permissions
- Notification system
- Multi-language support