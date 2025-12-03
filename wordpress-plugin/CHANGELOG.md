# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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