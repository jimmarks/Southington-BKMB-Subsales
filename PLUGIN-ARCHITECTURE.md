# Subsales Management Plugin - Complete Architecture Documentation

**Plugin File**: `wordpress-plugin/subsales-management.php` (5335 lines)  
**Version**: 1.1.3  
**Last Documented**: 2025-11-24

---

## 1. DATABASE SCHEMA

### 1.1 Orders Table: `wp_order_sync_orders`
**Lines**: 596-609  
**Status**: ✅ EXISTS

```sql
CREATE TABLE {prefix}order_sync_orders (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    order_id varchar(255) NOT NULL,
    user_id varchar(255) NOT NULL,
    team_id mediumint(9),
    order_data text NOT NULL,
    sync_status varchar(50) DEFAULT 'pending',
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY order_id (order_id),
    KEY team_id (team_id)
)
```

**Purpose**: Stores all order records with JSON data  
**Dependencies**: Referenced by teams via `team_id`

### 1.2 Teams Table: `wp_order_sync_teams`
**Lines**: 610-622  
**Status**: ✅ EXISTS

```sql
CREATE TABLE {prefix}order_sync_teams (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    name varchar(255) NOT NULL,
    access_code varchar(255) NOT NULL,
    description text,
    status varchar(50) NOT NULL DEFAULT 'active',
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY name (name),
    UNIQUE KEY access_code (access_code)
)
```

**Unique Constraints**: name, access_code

### 1.3 Team Members Table: `wp_order_sync_team_members`
**Lines**: 623-640  
**Status**: ✅ EXISTS

```sql
CREATE TABLE {prefix}order_sync_team_members (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    team_id mediumint(9) NOT NULL DEFAULT 0,
    name varchar(255) NOT NULL,
    email varchar(255) DEFAULT '',
    phone varchar(50) NOT NULL,
    role varchar(50) NOT NULL DEFAULT 'member',
    status varchar(50) NOT NULL DEFAULT 'active',
    last_login datetime,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY phone (phone),
    KEY team_id (team_id)
)
```

**Critical**: 
- Phone is `UNIQUE` and `NOT NULL` (10-digit normalized)
- Email is OPTIONAL (can be empty string)
- `team_id` = 0 for unassigned users

### 1.4 User-Teams Junction Table: `wp_order_sync_user_teams`
**Lines**: 641-652  
**Status**: ✅ EXISTS

```sql
CREATE TABLE {prefix}order_sync_user_teams (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    user_id mediumint(9) NOT NULL,
    team_id mediumint(9) NOT NULL,
    assigned_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY user_team (user_id, team_id),
    KEY user_id (user_id),
    KEY team_id (team_id)
)
```

**Purpose**: Many-to-many relationship for multi-team user support  
**Migration**: Auto-populated from existing team_id assignments (lines 654-662)

### 1.5 Geocode Cache Table: `wp_order_sync_geocodes`
**Lines**: 2659-2671  
**Status**: ✅ CREATED ON DEMAND

```sql
CREATE TABLE IF NOT EXISTS {prefix}order_sync_geocodes (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    address_hash varchar(64) NOT NULL,
    address_normalized text NOT NULL,
    lat double DEFAULT NULL,
    lng double DEFAULT NULL,
    status varchar(32) DEFAULT 'unknown',
    updated_at datetime DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY address_hash (address_hash(64))
)
```

**Purpose**: Cache Google Maps Geocoding API results  
**Function**: `order_sync_ensure_geocode_table()` (line 2655)

---

## 2. REST API ENDPOINTS

**Base Namespace**: `order-manager/v1`  
**Registration Hook**: `rest_api_init` (line 811)

### 2.1 Orders Endpoints

#### `GET /wp-json/order-manager/v1/orders`
- **Line**: 813
- **Callback**: `get_orders` (line 1829)
- **Permission**: `order_sync_check_permissions`
- **Parameters**:
  - `limit` (default: 10)
  - `offset` (default: 0)
  - `entered_by_id` (optional filter)
- **Returns**: Array of order objects with:
  - Decoded `order_data` JSON
  - `is_today` boolean flag
  - Timestamps

**Example Response**:
```json
[
  {
    "id": 1,
    "order_id": "ORD-001",
    "user_id": "user123",
    "team_id": 5,
    "order_data": {
      "products": [{"id": "turkey", "quantity": 2}],
      "total": 40.00
    },
    "is_today": true,
    "created_at": "2025-11-24 10:30:00"
  }
]
```

#### `POST /wp-json/order-manager/v1/orders`
- **Line**: 819
- **Callback**: `create_order` (line 1867)
- **Permission**: `order_sync_check_permissions`
- **Required Fields**:
  - `order_id` (string, unique)
  - `user_id` (string)
- **Optional Fields**:
  - `team_id` (auto-derived from headers if omitted)
  - `order_data` (JSON object)
- **Creates**: Order with site-local timestamp
- **Returns**: Created order object

#### `GET /wp-json/order-manager/v1/orders/{id}`
- **Line**: 825
- **Callback**: `get_order_by_id` (line 1852)
- **Returns**: Single order with decoded order_data

#### `PUT /wp-json/order-manager/v1/orders/{id}`
- **Line**: 831
- **Callback**: `update_order` (line 2141)
- **Updates**: order_data, sets sync_status to 'updated'

#### `DELETE /wp-json/order-manager/v1/orders/{id}`
- **Line**: 837
- **Callback**: `delete_order` (line 2179)

### 2.2 Authentication Endpoints

#### `POST /wp-json/order-manager/v1/auth/login`
- **Line**: 843
- **Callback**: `team_member_login` (line 2197)
- **Permission**: `__return_true` (public)
- **Payload**:
```json
{
  "team_name": "Team Alpha",
  "access_code": "ABC123"
}
```
- **Returns**: Team object if valid:
```json
{
  "id": 5,
  "name": "Team Alpha",
  "access_code": "ABC123",
  "status": "active"
}
```

#### `POST /wp-json/order-manager/v1/auth/verify`
- **Line**: 849
- **Callback**: `verify_team_access` (line 2221)
- **Purpose**: Validate team credentials
- **Same payload as login**

### 2.3 Configuration Endpoint

#### `GET /wp-json/order-manager/v1/config`
- **Line**: 857
- **Callback**: `get_app_config` (line 2242)
- **Permission**: `__return_true` (public with conditional data)

**Authenticated Response** (with valid team headers):
```json
{
  "google_maps_api_key": "AIza...",
  "app_version": "1.1.3",
  "portal_url": "https://site.com/subsales-portal/",
  "brandName": "BKMB Subsales",
  "brandingImage": "https://site.com/wp-content/uploads/2025/11/logo.png",
  "styleVariant": "default",
  "primaryColor": "#2d6cdf",
  "products": [
    {"id": "turkey", "name": "Turkey", "price": "20.00", "visible": 1}
  ]
}
```

**Unauthenticated Response**:
```json
{
  "app_version": "1.1.3",
  "portal_url": "https://site.com/subsales-portal/",
  "brandName": "BKMB Subsales",
  "styleVariant": "default",
  "primaryColor": "#2d6cdf"
}
```

#### `GET /wp-json/order-manager/v1/time`
- **Line**: 864
- **Callback**: `order_manager_get_server_time` (line 1864)
- **Returns**:
```json
{
  "server_date": "2025-11-24",
  "server_timestamp": 1732464000,
  "gmt_offset": -18000
}
```
- **Purpose**: Client-server time alignment for "today" filtering

#### `GET /wp-json/order-manager/v1/teams/members`
- **Line**: 871
- **Callback**: `order_sync_get_team_members_endpoint` (line 2147)
- **Headers Required**: `X-Team-Name`, `X-Access-Code`
- **Returns**: Array of team members for authenticated team

---

## 3. AJAX HANDLERS

### 3.1 Orders Management

#### `wp_ajax_subsales_fetch_orders`
- **Line**: 879
- **Function**: `order_sync_fetch_orders_ajax` (line 880)
- **Nonce**: `subsales_orders_nonce`
- **Capability**: `manage_options`
- **Parameters**:
  - `start_date`, `end_date` (YYYY-MM-DD)
  - `team_id` (int)
  - `entered_by_id` (string)
  - `payment_method` (cash/check)
  - `page`, `page_size` (max 100)
- **Returns**:
```json
{
  "success": true,
  "data": {
    "orders": [...],
    "total": 150,
    "page": 1,
    "page_size": 100,
    "has_more": true,
    "totals": {
      "sales": 3000.00,
      "cash": 2500.00,
      "checks": 500.00
    },
    "products": {
      "turkey": 75,
      "ham": 60
    }
  }
}
```

#### `wp_ajax_subsales_run_init`
- **Line**: 1111
- **Function**: `order_sync_run_init_ajax` (line 1112)
- **Nonce**: `subsales_init_nonce`
- **Purpose**: Onboarding wizard initialization
- **Creates**:
  - Database tables
  - Portal page
  - Sample team: "Demo Team" (code: DEMO123)
  - Default products: Turkey ($20), Ham ($18), Pie ($12)

### 3.2 ZIP Extract Generation

#### `wp_ajax_subsales_generate_zip_extracts`
- **Line**: 482
- **Function**: `subsales_generate_zip_extracts` (line 483)
- **Nonce**: `subsales_zip_generate`
- **Process**:
  1. Reads served ZIPs from option `subsales_served_zips`
  2. For each ZIP, queries Overpass API
  3. Parses address nodes/ways
  4. Writes JSON to `wp-content/uploads/subsales-zipdata/{zip}.json`
  5. Updates `pwa/zip-index.json`
- **Calls**: `subsales_generate_zip_from_overpass($zip, $base_dir)` (line 508)

**Overpass Query** (line 529):
```overpass
[out:json][timeout:25];
area["ISO3166-2"="US"]["admin_level"="4"]->.state;
(
  node(area.state)["addr:postcode"="{zip}"]["addr:housenumber"];
  way(area.state)["addr:postcode"="{zip}"]["addr:housenumber"];
);
out body;
```

### 3.3 Settings & Testing

#### `wp_ajax_subsales_update_sales_mode`
- **Line**: 462
- **Function**: `subsales_update_sales_mode_ajax` (line 463)
- **Values**: 'legacy' (team mode) or 'user' (individual mode)
- **Option**: `subsales_sales_mode`

#### `wp_ajax_subsales_test_maps_key`
- **Line**: 1776
- **Function**: `order_sync_test_maps_key_ajax` (line 1777)
- **Tests**: Google Maps Geocoding API with address "1600 Amphitheatre Parkway, Mountain View, CA"
- **Returns**:
```json
{
  "status": "OK",
  "first_result": {
    "formatted_address": "...",
    "geometry": {...}
  },
  "full_response": {...}
}
```

### 3.4 Delivery Preview

#### `wp_ajax_subsales_delivery_preview`
- **Line**: Not found in grep (integrated into delivery page)
- **Function**: `order_sync_ajax_delivery_preview` (line 3681)
- **Nonce**: `subsales_delivery_preview`
- **Process**:
  1. Groups orders by normalized address
  2. Assigns to drivers using greedy balancing algorithm
  3. Geocodes addresses (cached in geocodes table)
  4. Returns driver routes with lat/lng
- **Returns**:
```json
{
  "drivers": {
    "Driver 1": [
      {
        "address": "123 Main St",
        "orders": [...],
        "lat": 41.1234,
        "lng": -72.5678,
        "cached": true
      }
    ]
  },
  "api_key": "AIza...",
  "products": [...],
  "start_address": "100 Depot St"
}
```

### 3.5 Team Management

#### `wp_ajax_subsales_add_user_to_team`
- **Line**: 4980
- **Function**: `subsales_ajax_add_user_to_team` (line 4981)
- **Nonce**: `subsales_team_assign`
- **Inserts**: Junction table record (many-to-many)
- **Payload**: `user_id`, `team_id`

#### `wp_ajax_subsales_remove_user_from_team`
- **Line**: 5023
- **Function**: `subsales_ajax_remove_user_from_team` (line 5024)
- **Nonce**: `subsales_team_assign`
- **Deletes**: Junction table record

---

## 4. ADMIN PAGES

**Hook**: `admin_menu` (line 322)  
**Function**: `order_sync_admin_menu` (line 325)

### 4.1 Main Menu Item

#### BKMB Subsales (Dashboard)
- **Slug**: `subsales-management`
- **Callback**: `order_sync_main_page` (line 3186)
- **Icon**: `dashicons-clipboard`
- **Position**: 26 (after Comments)
- **Capability**: `manage_options`

**Features**:
- Sales mode toggle (Team/Individual)
- Financial totals:
  - Total Sales
  - Cash Collected
  - Checks Collected
- Statistics:
  - Total Orders
  - Active Teams
  - Active Members
- Per-product quantity totals (all products from config)
- View toggle: Compact / Comfortable
- "Active Users" placeholder (UI only, not functional)

### 4.2 Submenu Pages

#### Settings
- **Slug**: `subsales-settings`
- **Parent**: `subsales-management`
- **Callback**: `order_sync_settings_page` (line 3857)
- **Line**: 347

**Tabs** (5 total):

1. **Overall Settings** (line 4003):
   - Google Maps API key (with test button)
   - Sync interval (seconds, min 60)
   - Portal slug (page URL)
   - Default session duration (dropdown)

2. **Branding / Look & Feel** (line 4067):
   - Brand name
   - Header image (media uploader)
   - Style variant: default, flat, rounded, dark
   - Primary color (color picker)
   - Preview samples

3. **Products** (line 4133):
   - Repeatable fields (max 10)
   - Product name, price, visibility toggle
   - Auto-generated IDs from names

4. **Backup / Restore** (line 4193):
   - Export Orders (CSV)
   - Export Settings (CSV)
   - Export Combined (ZIP)
   - Import Backup (CSV or ZIP)
   - "Update existing" option

5. **System Info** (line 4291):
   - Plugin version
   - PHP version
   - PhpSpreadsheet status
   - Database table status
   - Clear all data (danger zone)

#### Teams
- **Slug**: `subsales-teams`
- **Parent**: `subsales-management`
- **Callback**: `order_sync_teams_page` (line 4405)
- **Line**: 338

**Tabs** (2 total):

1. **Users Tab** (line 4582):
   - Add/Edit user form:
     - Name (required)
     - Email (optional)
     - Phone (required, 10 digits, unique, pattern validation)
     - Role (member/manager/admin)
   - User list table:
     - Name, Email, Phone, Role
     - Team badges (multiple, read-only)
     - Edit/Delete actions
   - Features:
     - Phone validation: 10 digits, stripped formatting
     - Email optional
     - Multi-team membership visible

2. **Team Assignments Tab** (line 4728):
   - Two-column layout:
     - Left: Available users (all users, draggable)
     - Right: Team boxes (drop zones)
   - Team CRUD:
     - Add team: Name, Access Code, Description
     - Edit team
     - Delete team (confirmation)
   - Drag-and-drop:
     - jQuery UI draggable/droppable
     - Visual feedback (green on hover)
     - AJAX assignment (page reload after)
     - Remove buttons (×) on member cards

**JavaScript** (lines 4879-4979):
- Tab switching
- Drag-and-drop handlers
- AJAX calls for add/remove user from team

#### Orders
- **Slug**: `subsales-orders`
- **Parent**: `subsales-management`
- **Callback**: `order_sync_orders_page` (line 5054)
- **Line**: 356

**Features**:
- **Filters**:
  - Date range (start/end date pickers)
  - Team dropdown (all teams)
  - Member/User ID text input
  - Payment method (Cash/Check radio)
  - "Filter Orders" button
- **Table**:
  - Columns: Order ID, User, Team, Date, Total, Products (*), Payment, Actions
  - Product quantity columns (dynamic from config)
  - Edited indicator (*) if `sync_status != 'synced'`
- **Pagination**:
  - Client-side (100 per page default)
  - Page size dropdown: 25/50/100/All
  - Next/Previous buttons
- **Footer**:
  - Page totals (sum of displayed orders)
- **AJAX**:
  - Uses `subsales_fetch_orders` (line 879)
  - Nonce: `subsales_orders_nonce`

#### Delivery
- **Slug**: `subsales-delivery`
- **Parent**: `subsales-management`
- **Callback**: `order_sync_delivery_page` (line 2743)
- **Line**: 365

**Export Options**:

1. **Administrative CSV** (no routing):
   - Flat list: Order ID, Customer, Address, Products, Total
   - No grouping or driver assignment
   - Function: `order_sync_handle_generate_admin_csv` (line 1197)

2. **Driver Manifests** (XLSX/CSV):
   - Groups orders by normalized address
   - Assigns to drivers (greedy balancing)
   - Geocodes addresses
   - One sheet per driver
   - Formatted for printing
   - Function: `order_sync_handle_generate_delivery_xlsx` (line 1293)
   - **PhpSpreadsheet** required for XLSX (fallback to CSV)

**Preflight Summary**:
- Order count
- Unique address count
- Product totals
- "Generate Preview" button (AJAX map)

**Preview** (AJAX):
- Google Maps with markers (one per address)
- Colored by driver assignment
- Route polylines (optional)
- Start address marker

#### Address Extracts
- **Slug**: `subsales-zip-extracts`
- **Parent**: `subsales-management` (after Settings)
- **Callback**: `order_sync_zip_extracts_page` (line 400)
- **Line**: 378

**Features**:
- Served ZIP list management:
  - Textarea input (comma-separated)
  - Save button (updates `subsales_served_zips` option)
- Generate button:
  - Queries Overpass API for each ZIP
  - Writes JSON files to `wp-content/uploads/subsales-zipdata/`
  - Updates `pwa/zip-index.json`
- File listing:
  - Shows all generated ZIPs
  - File sizes
  - Delete button (per file)

**Assets**:
- JS: `assets/js/subsales-zip-admin.js` (line 390)
- AJAX: `subsales_generate_zip_extracts` (line 482)

---

## 5. WORDPRESS OPTIONS

### 5.1 Core Settings
- `order_sync_portal_slug` - PWA page slug (default: 'subsales-portal')
- `order_sync_google_maps_api_key` - Google Maps API key
- `order_sync_interval` - Sync interval in seconds (default: 300)
- `order_sync_session_duration` - Session duration in ms (default: 86400000 = 24 hours)
- `order_sync_pwa_page_id` - Portal page post ID
- `subsales_sales_mode` - 'legacy' or 'user' (⚠️ NOT YET IN PHASE 6)

### 5.2 Branding
- `subsales_branding` - Brand/group name (default: 'Subsales')
- `subsales_header_image` - Attachment ID for header logo
- `order_sync_style_variant` - Style: 'default', 'flat', 'rounded', 'dark'
- `order_sync_primary_color` - Hex color (default: '#2d6cdf')

### 5.3 Products
- `order_sync_products` - JSON string or array:
```json
[
  {
    "id": "turkey",
    "name": "Turkey",
    "price": "20.00",
    "visible": 1
  },
  {
    "id": "ham",
    "name": "Ham",
    "price": "18.00",
    "visible": 1
  }
]
```
- Normalized by `order_sync_get_products_config()` (line 116)

### 5.4 Delivery
- `order_sync_delivery_start_address` - Depot/starting address for routes
- `subsales_served_zips` - Array of 5-digit ZIP codes:
```php
['06489', '06451', '06492']
```

### 5.5 System
- `subsales_delete_on_uninstall` - Boolean flag (not currently used)
- Transients:
  - `subsales_activated` (30s) - Shows activation notice
  - `subsales_show_onboarding` (60s) - Triggers onboarding wizard
  - `subsales_suppress_onboarding` (30s) - Prevents repeated wizard

---

## 6. KEY FUNCTIONS

### 6.1 Database Functions

#### `order_sync_create_table()`
- **Line**: 587
- **Creates**: All 4 tables (orders, teams, team_members, user_teams)
- **Migrates**: Existing team_id assignments to junction table
- **Called**: Activation hook (line 56)
- **Uses**: `dbDelta()` for safe schema updates

#### `order_sync_add_team($name, $code, $desc = '')`
- **Line**: 671
- **Validates**: Unique name and access_code
- **Returns**: Boolean (true on success)
- **Status**: Sets to 'active' by default

#### `order_sync_remove_team($team_id)`
- **Line**: 707
- **Deletes**: Team record
- **Note**: Does NOT cascade delete members or orders

#### `order_sync_get_teams()`
- **Line**: 716
- **Returns**: Array of all teams
- **Order**: By name ASC

#### `order_sync_get_team_by_credentials($team_name, $access_code)`
- **Line**: 726
- **Returns**: Team array or null
- **Validates**: Active status
- **Used by**: Permission checks, REST auth

#### `order_sync_add_team_member($team_id, $name, $email, $role = 'member')`
- **Line**: 740
- **Deprecated**: Use direct insert (team_id now optional, junction table for assignment)
- **Returns**: Boolean

#### `order_sync_remove_team_member($member_id)`
- **Line**: 759
- **Deletes**: Member record AND all junction table entries
- **Cascade**: Removes from all teams

#### `order_sync_get_team_members_by_team($team_id)`
- **Line**: 770
- **Returns**: Array of members for given team
- **Uses**: Junction table join

#### `order_sync_verify_team_member($email, $team_id)`
- **Line**: 783
- **Updates**: `last_login` timestamp
- **Returns**: Member array or false
- **Note**: Currently uses email (should migrate to phone)

### 6.2 Utility Functions

#### `order_sync_get_products_config()`
- **Line**: 116
- **Normalizes**: JSON string or array from option
- **Returns**: Array of product objects
- **Handles**: Invalid JSON gracefully (returns empty array)

#### `order_sync_normalize_address($addr)`
- **Line**: 2673
- **Process**:
  1. Trim whitespace
  2. Collapse multiple spaces
  3. Lowercase
- **Returns**: Normalized string for deduplication
- **Used by**: Delivery grouping

#### `order_sync_geocode_address($address)`
- **Line**: 2680
- **Cache**: MD5 hash lookup in `geocodes` table
- **API**: Google Maps Geocoding API (if not cached)
- **Returns**:
```php
[
  'lat' => 41.1234,
  'lng' => -72.5678,
  'cached' => true
]
```
- **On Error**: Returns null
- **Caches**: Results for 30 days

#### `subsales_haversine_distance($lat1, $lon1, $lat2, $lon2)`
- **Line**: 2505
- **Formula**: Haversine (great-circle distance)
- **Returns**: Distance in meters
- **Used by**: Nearby address search (`/nearby` endpoint)

### 6.3 Portal & Assets

#### `subsales_serve_portal_assets()`
- **Line**: 2515
- **Hook**: `template_redirect` (priority 1)
- **Serves**:
  - `/manifest.json` - Rewrites icon URLs to absolute
  - `/{portal_slug}/service-worker.js` - Proxies from `pwa/`
  - `/{portal_slug}/index.html` - Injects config, branding
  - `/icons/*` - SVG, PNG from `pwa/icons/`
- **Headers**: Proper MIME types, no-cache for HTML

#### `subsales_register_pwa_scripts()`
- **Line**: 2165
- **Enqueues**:
  - `subsales-pwa-css` - `pwa/styles.css`
  - `subsales-pwa-js` - `pwa/app.js`
- **Localizes**: Config object in `subsalesConfig` global

#### `subsales_pwa_shortcode($atts)`
- **Line**: 2192
- **Shortcode**: `[subsales_pwa]`
- **Output**: Full PWA UI (login, order form, order list, EOD tally)
- **Injects**: 
  - Branding
  - Style variant classes
  - Config object
  - Auth controls
- **Used**: In portal page content

### 6.4 Export/Import

#### `order_sync_admin_export_orders()`
- **Line**: 1176
- **Format**: CSV (headers + data rows)
- **Columns**: All order table columns + decoded order_data fields
- **Download**: Direct to browser

#### `order_sync_admin_export_settings()`
- **Line**: 1557
- **Format**: CSV (key, value rows)
- **Exports**: All `order_sync_*` and `subsales_*` options

#### `order_sync_admin_export_backup_combined()`
- **Line**: 1578
- **Format**: ZIP containing:
  - `orders.csv`
  - `settings.csv`
- **Uses**: `ZipArchive` class

#### `order_sync_admin_import_backup()`
- **Line**: 1631
- **Handles**: File upload, validation
- **Calls**: `order_sync_process_import_file()`

#### `order_sync_process_import_file($tmp, $update_existing = false)`
- **Line**: 1650
- **Handles**: 
  - CSV (orders or settings)
  - ZIP (extracts both, processes sequentially)
- **Logic**: 
  - Orders: Insert or update based on `order_id`
  - Settings: Update options
- **Returns**:
```php
[
  'imported' => 25,
  'updated' => 5,
  'skipped' => 3,
  'errors' => ['Row 10: Invalid order_id']
]
```

#### `order_sync_clear_data()`
- **Line**: 2359
- **Calls**:
  - `order_sync_clear_orders()` - Truncates orders table
  - `order_sync_clear_settings()` - Deletes all plugin options
- **Used by**: Danger zone, restore workflow

---

## 7. AUTHENTICATION METHODS

### 7.1 Team-Based Authentication (Legacy Mode)

**Headers**: `X-Team-Name`, `X-Access-Code`

**Flow**:
1. Client sends team credentials in headers
2. Server calls `order_sync_get_team_by_credentials()` (line 726)
3. Validates team exists and is active
4. Returns team object

**Usage**:
- Mobile app login
- REST API permission callback
- Config endpoint (determines which data to return)

**Example**:
```http
GET /wp-json/order-manager/v1/orders
X-Team-Name: Team Alpha
X-Access-Code: ABC123
```

### 7.2 Individual User Mode (⚠️ PARTIALLY IMPLEMENTED)

**Headers** (proposed): `X-Team-Email`, `X-Member-Access-Code`

**Current Status**:
- ✅ User table with phone as unique identifier
- ✅ Multi-team support via junction table
- ✅ Admin UI for user management
- ⚠️ Backend permission callback partially supports member headers (line 1818)
- ❌ Login endpoint for user-based auth not implemented
- ❌ Phone-based search endpoint not implemented (Phase 2)

**Intended Flow**:
1. Client searches for user by name
2. User enters phone number (10 digits)
3. Server validates phone against database
4. Returns user object with team list
5. User selects team (or "Individual Sales")
6. Session stored with `user_id` + `team_id`

### 7.3 Permission Callback

**Function**: `order_sync_check_permissions($request)`  
**Line**: 1818

**Priority Order**:
1. Check team headers (`X-Team-Name` + `X-Access-Code`)
   - Calls `order_sync_get_team_by_credentials()`
   - Returns true if valid team
2. Check member headers (`X-Team-Email` + `X-Team-ID`)
   - Calls `order_sync_verify_team_member()`
   - Returns true if valid member
3. Fallback to WordPress capability
   - `current_user_can('edit_posts')`
   - Allows admin users

**Used by**: All REST endpoints except `/config`, `/time`, `/auth/*`

### 7.4 Sales Mode Toggle

**Option**: `subsales_sales_mode`  
**Values**: 'legacy' (team login) or 'user' (individual login)  
**UI**: Dashboard toggle (line 3438)  
**Status**: ⚠️ UI exists, option saving works, but NOT in settings page (Phase 6 pending)

**Client**: PWA shows appropriate login form based on mode

---

## 8. DATA FLOW

### 8.1 Order Creation Flow

```
┌─────────────┐
│   PWA/App   │
└──────┬──────┘
       │ 1. User fills form
       │ 2. Saves locally (IndexedDB)
       ▼
┌──────────────────┐
│  Sync Service    │
│  (attempts POST) │
└──────┬───────────┘
       │ 3. POST /wp-json/order-manager/v1/orders
       │    Headers: X-Team-Name, X-Access-Code
       │    Body: { order_id, user_id, order_data }
       ▼
┌──────────────────┐
│  REST Endpoint   │
│  create_order()  │
└──────┬───────────┘
       │ 4. Validates permission
       │ 5. Derives team_id from headers
       │ 6. Sets created_at to site-local time
       ▼
┌──────────────────┐
│  wp_order_sync_  │
│     orders       │
└──────┬───────────┘
       │ 7. INSERT with sync_status='synced'
       │ 8. Returns order object
       ▼
┌─────────────┐
│  PWA/App    │
│  (success)  │
└─────────────┘
   - Marks local order as synced
   - Removes from queue
```

**Error Handling**:
- Network failure: Order stays in IndexedDB queue
- Duplicate order_id: 409 Conflict (UNIQUE constraint)
- Invalid team: 403 Forbidden

### 8.2 Order Retrieval Flow (Admin)

```
┌─────────────┐
│ Admin Page  │
│  (Orders)   │
└──────┬──────┘
       │ 1. User sets filters
       │ 2. Clicks "Filter Orders"
       ▼
┌──────────────────┐
│  AJAX Handler    │
│ subsales_fetch_  │
│   orders         │
└──────┬───────────┘
       │ 3. Builds WHERE clause
       │ 4. Queries wp_order_sync_orders
       │ 5. Decodes order_data JSON
       │ 6. Extracts payment method
       │ 7. Maps product quantities
       ▼
┌──────────────────┐
│  JSON Response   │
│  { orders: [],   │
│    totals: {},   │
│    products: {}} │
└──────┬───────────┘
       │ 8. Returns to JavaScript
       ▼
┌─────────────┐
│  Client JS  │
│  (renders)  │
└─────────────┘
   - Populates table rows
   - Shows pagination controls
   - Displays footer totals
```

### 8.3 Delivery Export Flow

```
┌─────────────┐
│ Admin Page  │
│ (Delivery)  │
└──────┬──────┘
       │ 1. Sets date, driver count, start address
       │ 2. (Optional) Clicks "Generate Preview"
       ▼
┌──────────────────┐
│  AJAX Handler    │
│ delivery_preview │
└──────┬───────────┘
       │ 3. Fetches orders for date
       │ 4. Groups by normalized address
       │ 5. Assigns to drivers (greedy)
       │ 6. Geocodes each address (cached)
       ▼
┌──────────────────┐
│  JSON Response   │
│  { drivers: {},  │
│    api_key: ""} │
└──────┬───────────┘
       │ 7. Returns to JavaScript
       ▼
┌─────────────┐
│  Google Map │
│  (preview)  │
└─────────────┘
   - Shows markers (colored by driver)
   - Polylines (routes)
   - Start address
       │
       │ User clicks "Generate XLSX"
       ▼
┌──────────────────┐
│  Form Submit     │
│  (POST action)   │
└──────┬───────────┘
       │ Same grouping/geocoding
       ▼
┌──────────────────┐
│ PhpSpreadsheet   │
│  (if available)  │
└──────┬───────────┘
       │ Creates workbook
       │ One sheet per driver
       │ Formatted cells
       ▼
┌──────────────────┐
│  Download XLSX   │
│  (or CSV)        │
└──────────────────┘
```

### 8.4 ZIP Extract Flow

```
┌─────────────┐
│ Admin Page  │
│  (Address   │
│  Extracts)  │
└──────┬──────┘
       │ 1. Admin saves served ZIPs
       │ 2. Clicks "Generate"
       ▼
┌──────────────────┐
│  AJAX Handler    │
│ subsales_        │
│ generate_zip_    │
│ extracts         │
└──────┬───────────┘
       │ 3. For each ZIP...
       ▼
┌──────────────────┐
│ Overpass API     │
│  (OSM query)     │
└──────┬───────────┘
       │ 4. Returns nodes/ways with addresses
       ▼
┌──────────────────┐
│  Parse Results   │
│  (extract        │
│   housenumber,   │
│   street)        │
└──────┬───────────┘
       │ 5. Write JSON
       ▼
┌──────────────────┐
│  File System     │
│  wp-content/     │
│  uploads/        │
│  subsales-       │
│  zipdata/        │
│  {zip}.json      │
└──────┬───────────┘
       │ 6. Update index
       ▼
┌──────────────────┐
│  pwa/zip-        │
│  index.json      │
│  ["06489",       │
│   "06451"]       │
└──────────────────┘
       │
       │ PWA lazy-loads ZIPs as needed
       ▼
┌─────────────┐
│  IndexedDB  │
│  (client)   │
└─────────────┘
   - Caches ZIP data
   - Provides offline autocomplete
```

---

## 9. DEPENDENCIES BETWEEN COMPONENTS

### 9.1 Database Dependencies

```
order_sync_teams (id)
  ↓ FK team_id
order_sync_orders (team_id)

order_sync_team_members (id)
  ↓ FK user_id
order_sync_user_teams (user_id)

order_sync_teams (id)
  ↓ FK team_id
order_sync_user_teams (team_id)
```

**Note**: Foreign key constraints NOT enforced (MyISAM/InnoDB compat), but logical relationships maintained in queries.

### 9.2 REST ↔ Database
- All REST endpoints use global `$wpdb`
- Permission callback queries `teams` and `team_members` tables
- Config endpoint reads WordPress options
- Orders endpoint joins with teams for team name display

### 9.3 Admin Pages ↔ Options
- Settings page reads/writes 15+ options
- Products saved as JSON, normalized by `order_sync_get_products_config()`
- Branding options consumed by PWA config endpoint
- Portal slug used to create/update page on save

### 9.4 PWA ↔ Backend
- Config endpoint provides runtime settings (branding, products, API key)
- REST endpoints for CRUD operations
- Service worker caches API responses
- ZIP extracts served as static files via `template_redirect`

### 9.5 Geocoding ↔ Delivery
- Delivery preview/export calls `order_sync_geocode_address()`
- Results cached in `order_sync_geocodes` table
- Google Maps API key from options
- Cache TTL: 30 days (hardcoded in geocode function)

### 9.6 PhpSpreadsheet ↔ Delivery
- Optional Composer dependency (`vendor/autoload.php` check)
- If available: XLSX export with formatting
- If missing: Graceful degradation to CSV
- No error shown to user (seamless fallback)

---

## 10. IMPLEMENTATION STATUS SUMMARY

### ✅ FULLY IMPLEMENTED

**Database**:
- 4 core tables (orders, teams, team_members, user_teams)
- 1 cache table (geocodes)
- Junction table for many-to-many user-team relationships
- Auto-migration from legacy team_id assignments

**REST API**:
- 8 endpoints (orders CRUD, auth, config, time, members)
- Team-based authentication via headers
- Permission callback with fallback to WP capabilities

**Admin UI**:
- Dashboard (sales mode, totals, stats)
- Settings (5 tabs: overall, branding, products, backup, system)
- Teams (2 tabs: users CRUD, team assignments drag-drop)
- Orders (filtering, pagination, product columns)
- Delivery (preview, XLSX/CSV export, geocoding)
- Address Extracts (ZIP management, Overpass generation)

**Features**:
- Team/member CRUD
- Multi-team user support (junction table)
- Phone validation (required, unique, 10 digits)
- Product configuration (max 10, dynamic columns)
- Branding/theming (4 style variants, custom color)
- Export (orders CSV, settings CSV, combined ZIP)
- Import (CSV, ZIP, restore with update option)
- Delivery routing (driver assignment, geocoding, cache)
- ZIP extract generation (Overpass API, JSON files)
- PWA shortcode and asset serving
- Onboarding wizard
- Sales mode toggle (dashboard UI)

### ⚠️ PARTIALLY IMPLEMENTED

**User-Based Authentication** (Phase 2-4 pending):
- ✅ User table with phone as unique ID
- ✅ Multi-team support via junction table
- ✅ Admin UI for user management
- ⚠️ Permission callback supports member headers (partial)
- ❌ Login endpoint for name+phone auth
- ❌ User search endpoint (Phase 2)
- ❌ Settings toggle for login mode (Phase 6 in progress)
- ❌ PWA dual-mode login screen (Phase 7)

**PhpSpreadsheet Integration**:
- ✅ Composer autoload check
- ✅ Graceful degradation to CSV
- ⚠️ Not bundled with plugin (manual install required)

### ❌ NOT IMPLEMENTED

**Monitoring**:
- Active user tracking (UI placeholder exists, no backend)
- Real-time sync status dashboard
- Rate limiting on Overpass queries

**Optimization**:
- Batch geocoding (currently one-by-one)
- Database indexing tuning
- REST API caching headers

**Security**:
- Nonce rotation
- Rate limiting on auth endpoints
- CSRF protection on public endpoints

**Testing**:
- Unit tests
- Integration tests
- End-to-end tests

---

## 11. NEXT STEPS (FROM DEVELOPMENT-STATE.md)

### Current Phase: Phase 6
**Add login mode toggle to Settings page**

**Tasks**:
1. Add `login_mode` option retrieval in `order_sync_settings_page()`
2. Add radio button UI in Overall Settings tab (after session duration field)
3. Add warning/description text about switching modes
4. Update form submission handler to save `login_mode`
5. Test and package plugin

### Upcoming Phases

**Phase 2**: User Management REST API
- Create endpoints for user CRUD
- Search endpoint (name or phone)
- User teams endpoint

**Phase 3**: Team Assignment API
- Assign users to teams (bulk)
- Remove user from team
- Get team members

**Phase 4**: New Auth Logic
- Update `/auth/login` for dual mode
- Update `/config` to return `login_mode`
- Session validation for both modes

**Phase 7**: PWA Login Screen
- Check `login_mode` from config
- Show legacy or user-based form
- Name search + phone entry + team dropdown

**Phase 8-13**: PWA session, order submission, filtering, backend queries

---

**END OF ARCHITECTURE DOCUMENTATION**
