# Copilot Instructions for Subsales Management Plugin

## Project Overview
WordPress plugin for subsales/fundraising order management with embedded PWA for mobile order entry. Multi-team support, address autocomplete, delivery manifests, route optimization, and comprehensive logging.

**Current Version**: 2.2.1.48  
**Main File**: `wordpress-plugin/subsales-management.php` (~9,930 lines)  
**Plugin Slug**: `subsales-management`  
**Menu Position**: Position 26 (after Comments)

---

## Project Structure

### Core Plugin Files
```
wordpress-plugin/
├── subsales-management.php          # Main plugin file (bootstrap, menu, handlers)
├── includes/                         # Modular class architecture
│   ├── class-database.php           # DB schema, migrations, CRUD
│   ├── class-rest-api.php           # REST endpoint registration
│   ├── class-pwa.php                # PWA serving & config
│   ├── class-orders.php             # Order business logic
│   ├── class-teams.php              # Team/user management
│   ├── class-background-matcher.php # Background Overpass matching
│   ├── overpass-matcher.php         # Overpass API integration
│   ├── shapefile-parser.php         # Shapefile address parsing
│   └── zip-extracts.php             # ZIP data generation
├── admin/                            # Admin page templates
│   ├── address-management-dashboard.php
│   ├── delivery-page.php
│   ├── settings-page.php
│   └── zip-extract-admin.php
├── assets/
│   ├── css/admin-dashboard.css      # ALL admin styling (no inline CSS!)
│   └── js/subsales-zip-admin.js     # Admin interactions
├── pwa/                              # Embedded PWA client
│   ├── app.js                       # Main PWA logic
│   ├── address-autocomplete.js      # Address entry with ZIP prefetch
│   ├── pwa-logger.js                # Client-side debug logging
│   └── service-worker.js            # Offline support
└── vendor/                           # Composer dependencies (QR codes, PDF)
```

### Database Tables (Prefix: `wp_ss_`)
- `wp_ss_orders` - Order records with products, customer info, GPS coords
- `wp_ss_teams` - Team definitions (name, access code, status)
- `wp_ss_team_members` - User records (phone REQUIRED/UNIQUE, email optional)
- `wp_ss_user_teams` - Many-to-many junction (users can be on multiple teams)
- `wp_ss_order_edit_history` - Audit trail for order changes
- `wp_ss_subsales_logs` - System-wide logging (auth, orders, API, etc.)
- `wp_ss_addresses` - GPS-enriched address lookup table
- `wp_ss_pwa_sessions` - Active PWA session tracking

---

## Admin Menu Structure

**Main Menu**: "Subsales" (position 26, dashicons-clipboard)

**Submenus**:
1. **Settings** (`subsales-settings`) - Configuration, API keys, deletion settings
2. **Teams** (`subsales-teams`) - Team/user management, CSV import/export
3. **Orders** (`subsales-orders`) - Order list, edit, delete, history, tally
4. **Delivery** (`subsales-delivery`) - Delivery manifest generation, PDF export
5. **Logs** (`subsales-logs`) - System logs with debug mode toggle
6. **App Sessions** (`subsales-pwa-sessions`) - Active PWA sessions (badge shows count)
7. **Delivery Manifest** (`subsales-manifest-viewer`) - Hidden page for manifest viewing

### Key Admin Page Rendering Pattern
Admin pages follow this structure in `subsales-management.php`:
```php
function order_sync_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }
    // Include template from admin/
    include SUBSALES_PLUGIN_PATH . 'admin/settings-page.php';
}
```

Templates in `admin/` folder handle HTML rendering. CSS goes in `assets/css/admin-dashboard.css`.

---

## REST API Architecture

**Base URL**: `/wp-json/order-manager/v1/`

**Endpoint Registration**: `includes/class-rest-api.php` (lines 25-180)

### Key Endpoints
**Orders**:
- `GET/POST /orders` - List/create orders
- `GET/PUT/DELETE /orders/{id}` - Get/update/delete single order
- `GET /orders/{id}/history` - Order edit history
- `POST /orders/{id}/restore` - Restore soft-deleted order
- `POST /orders/tally` - Tally operations (bulk updates)

**Authentication**:
- `POST /auth/login` - Team-based login (phone + name search)
- `POST /auth/verify` - Verify existing session

**Config**:
- `GET /config` - PWA configuration (products, API keys, debug mode)
- `GET /time` - Server time sync
- `GET /zip-index` - Available ZIP codes for address autocomplete

**Teams & Users**:
- `GET /teams/members` - List team members
- `GET/POST /users` - List/create users
- `GET/PUT/DELETE /users/{id}` - User CRUD
- `GET /users/search` - Search by phone/name
- `GET/PUT /users/{id}/teams` - User team assignments

### Authentication Headers (PWA)
- `X-Team-Name` + `X-Access-Code` - Team-level auth
- `X-Team-Email` + `X-Member-Access-Code` - Individual user auth
- Phone is REQUIRED (10 digits), email is OPTIONAL

---

## Modular Class Architecture

### Subsales_Database (`includes/class-database.php`)
- **Purpose**: Database schema, migrations, team/user CRUD
- **Key Methods**:
  - `init()` - Register hooks
  - `create_tables()` - Schema creation
  - `migrate_*()` - Schema migrations
  - `get_active_pwa_sessions()` - Active session tracking
  - Team/user CRUD operations

### Subsales_REST_API (`includes/class-rest-api.php`)
- **Purpose**: REST endpoint registration only
- **Key Methods**:
  - `init()` - Register hooks
  - `register_routes()` - Register all REST endpoints
- **Note**: Actual endpoint handlers live in `subsales-management.php`

### Subsales_PWA (`includes/class-pwa.php`)
- **Purpose**: PWA serving, config localization
- **Key Methods**:
  - `init()` - Register hooks
  - `register_pwa_scripts()` - Enqueue PWA assets
  - `pwa_shortcode()` - `[subsales_pwa]` shortcode handler
  - `get_product_config()` - Product configuration for PWA

### Subsales_Background_Matcher (`includes/class-background-matcher.php`)
- **Purpose**: WP-Cron based Overpass matching
- **Key Methods**:
  - `start()` - Start background job
  - `stop()` - Pause job
  - `process_batch()` - Process address batch
  - `get_status()` - Job progress

---

## Key Features & Workflows

### 1. Delivery Manifest Generation
**Location**: `subsales-management.php` line ~3525  
**Function**: `order_sync_generate_combined_manifest_html()`

**Page Capacity** (as of v2.2.1.48):
- **First delivery page**: 7 stops
- **Subsequent pages**: 8 stops
- **Calculation**: `if (stops <= 7) → 1 page, else 1 + ceil((stops - 7) / 8)`

**Manifest Structure**:
1. Two packing list pages (product summary)
2. QR code page (one QR per route)
3. Delivery pages (7/8 stops per page with horizontal product tables)

Each page has footer: "Seller: {name} | Page X of Y | Date: {date}"

### 2. Address Autocomplete System
**Admin Side**:
- Overpass API queries generate per-ZIP JSON: `wp-content/uploads/subsales-zipdata/{zip}.json`
- Background matching via WP-Cron (see `class-background-matcher.php`)
- Address dashboard: Settings → Address Extracts

**PWA Side** (`pwa/address-autocomplete.js`):
- Prefetches all served ZIPs on login
- Autocomplete from local IndexedDB cache
- Manual mode fallback if address not in database
- GPS location button for reverse geocoding

### 3. Order Edit/Delete with Audit Trail
**Features**:
- Soft delete (sets `deleted=1`, preserves record)
- Full edit history in `wp_ss_order_edit_history`
- Field-by-field diff tracking (JSON format)
- Restore capability for deleted orders

### 4. System Logging
**Location**: `subsales-management.php` line ~500  
**Function**: `subsales_log($level, $category, $message, $context)`

**Levels**: DEBUG, INFO, WARNING, ERROR, CRITICAL  
**Categories**: auth, orders, sync, api, system, zip

**Debug Mode**:
- Toggle in Settings page
- Auto-disables after 24 hours
- Stores all DEBUG logs (normal mode excludes DEBUG)
- Admin UI shows floating badge when active

---

## Refactoring Status (Current Branch: refactor/extract-admin-pages)

**Goal**: Extract admin page rendering from monolithic `subsales-management.php`

**Completed Extractions**:
- ✅ Database class (838 lines)
- ✅ REST API class (177 lines)
- ✅ PWA class (221 lines)
- ✅ Orders class (partial)
- ✅ Teams class (partial)
- ✅ Admin page templates moved to `admin/` folder

**Remaining**: Continue extracting admin handlers and consolidating business logic into classes.

---

## Critical Coding Standards

### CSS & Styling (MANDATORY)
- **ALL CSS must live in `assets/css/admin-dashboard.css`**
- **NEVER use inline styles** except for truly dynamic PHP-computed values
- **No exceptions** - even quick prototypes must use stylesheet
- Pattern: (1) Add CSS class to stylesheet, (2) Apply class in HTML, (3) Only inline for dynamic values

### PHP Conventions
- Follow WordPress coding standards
- Use `SUBSALES_` constant prefix
- Escape all output: `esc_html()`, `esc_attr()`, `wp_kses_post()`
- Sanitize all input: `sanitize_text_field()`, `absint()`, etc.
- Use nonces for AJAX/form submissions

### Database Queries
- Use `$wpdb->prepare()` for all queries with user input
- Table prefix: `wp_ss_` (use `$wpdb->prefix . 'ss_orders'` pattern)
- Never use `DELETE` - use soft delete (set `deleted=1`)

### REST API
- All endpoints in `includes/class-rest-api.php::register_routes()`
- Handlers in `subsales-management.php` (for now, refactoring in progress)
- Use `permission_callback` for all routes
- Return `WP_Error` for failures, arrays/objects for success

---

## Development Workflow

### 1. Making Changes
```bash
# Edit files in wordpress-plugin/
vim wordpress-plugin/subsales-management.php

# Package plugin (auto-increments version)
bash scripts/package-plugin.sh

# Verify package created
ls -lh subsales-management.zip

# Upload to WordPress site for testing
```

### 2. Version Management
- **Auto-increment**: `scripts/package-plugin.sh` bumps patch version automatically
- **Manual version**: Edit line 6 in `subsales-management.php`
- **Format**: Semantic versioning (2.2.1.48)

### 3. Git Workflow
```bash
# Branch: refactor/extract-admin-pages
git add -A
git commit -m "Brief description of changes"
git push origin refactor/extract-admin-pages
```

### 4. Testing Checklist
After making changes, verify:
- [ ] Plugin activates without errors
- [ ] Admin menu loads (Subsales → all submenus)
- [ ] Orders page displays orders
- [ ] PWA serves at `/pwa/` endpoint
- [ ] REST API endpoints respond (`/wp-json/order-manager/v1/config`)
- [ ] Package script completes successfully

---

## When to Ask a Human

- Database schema changes (adding columns/tables)
- Security-sensitive changes (authentication, permissions)
- Breaking changes to REST API
- Refactoring across multiple classes
- Performance concerns (large datasets, N+1 queries)

---

## Quick Reference

### File Locations
- **Main plugin**: `wordpress-plugin/subsales-management.php`
- **Admin CSS**: `wordpress-plugin/assets/css/admin-dashboard.css`
- **PWA entry**: `wordpress-plugin/pwa/app.js`
- **Package script**: `scripts/package-plugin.sh`

### Common Functions
- `subsales_log()` - System logging
- `order_sync_generate_combined_manifest_html()` - Manifest generation
- `Subsales_Database::get_active_pwa_sessions()` - Session tracking
- `Subsales_PWA::get_product_config()` - Product config for PWA

### Database Prefixes
- Tables: `wp_ss_*`
- Options: `subsales_*`
- Capabilities: `manage_options` (admin only, no custom caps yet)

---

**Last Updated**: 2025-12-09  
**Maintainer**: Jim Marks (jim@marksfamilytree.com)
If you'd like I can now apply this update to `.github/copilot-instructions.md` and run two quick validations: (1) confirm `scripts/package-plugin.sh` exists and is executable, and (2) grep for the admin ZIP generator function name to ensure the file references are correct.