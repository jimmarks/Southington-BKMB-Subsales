# Current Development State

**Last Updated**: 2025-11-24  
**Current Phase**: Phase 8 (PWA - Session Management)  
**Plugin Version**: 1.0.0  
**Main File**: `wordpress-plugin/subsales-management.php` (6439 lines)

---

## 🔒 Locked Architecture Decisions

### Database Schema
- **Users Table**: `wp_order_sync_team_members`
  - `phone`: `varchar(50) NOT NULL` with `UNIQUE` constraint (10 digits, normalized)
  - `email`: `varchar(255) DEFAULT ''` (optional)
  - `name`: `varchar(255) NOT NULL`
  - `role`: `varchar(50) NOT NULL DEFAULT 'member'`
  
- **Junction Table**: `wp_order_sync_user_teams` (many-to-many)
  - `user_id`: Links to team_members.id
  - `team_id`: Links to teams.id
  - Allows users to belong to multiple teams

### Authentication Design
- **Login Method**: Name search + Phone entry (user-based)
- **Phone Format**: 10 digits required, stripped of formatting before storage
- **Session**: Based on user_id + team_id selection
- **Two Modes**:
  - Legacy: Team + Code (existing functionality)
  - User-based: Name + Phone → Team selection (NEW)

### Validation Rules
- Phone: REQUIRED, 10 digits, unique across all users
- Email: Optional (can be empty string)
- Name: Required
- Phone normalization: Strip all non-numeric characters before DB insert/update

---

## 📊 Phase Completion Status

### ✅ Completed Phases

**Phase 1: Database Schema & Migration**
- ✅ Created users table (`team_members`) with phone (NOT NULL, UNIQUE)
- ✅ Created junction table (`user_teams`) for many-to-many relationships
- ✅ Migration logic for existing team assignments → junction table
- ✅ Added `login_mode` setting (default: 'legacy')

**Phase 5: Admin UI - User Management Tab**
- ✅ Two-tab interface: Users | Team Assignments
- ✅ Users tab: CRUD operations (name, phone, email, role)
- ✅ Users table shows multiple team badges per user
- ✅ Team Assignments tab: Drag-and-drop interface
- ✅ All users visible in available users list (can join multiple teams)
- ✅ Remove buttons (×) on team member cards
- ✅ Phone validation: Required, 10-digit format, server + client side

**Phase 6: Admin UI - Settings Toggle** ✅ **COMPLETE**
- ✅ Added login mode toggle to "Overall Settings" tab in admin
- ✅ Radio buttons: "Legacy Login (Team + Code)" vs "User-Based Login (Name + Phone)"
- ✅ Warning message about mode switching
- ✅ Form handler saves `order_sync_login_mode` option
- ✅ Default: 'legacy' mode for backwards compatibility

### 🔄 Current Phase

**Phase 7: PWA - New Login Screen** ✅ **COMPLETE**
- ✅ Updated `initLoginMode()` to check `config.loginMode` instead of `salesMode`
- ✅ Changed localStorage key from `detectedSalesMode` to `loginMode`
- ✅ Updated `restoreSession()` to handle both legacy and user mode sessions
- ✅ Removed hardcoded `salesMode` storage from user login handler
- ✅ Updated logout handler to clear user session keys (userId, userName, userPhone, selectedTeamId, selectedTeamName)
- ✅ User login form already present in HTML (name autocomplete, phone input, team selector)
- ✅ Autocomplete functionality already implemented (searches users by name)
- **Files updated**: `app.js` (1776 lines)

### ⏳ Pending Phases

**Phase 2: Backend - User Management API** ✅ **COMPLETE**
- ✅ POST /wp-json/order-manager/v1/users (create user)
- ✅ GET /wp-json/order-manager/v1/users (list all users with teams)
- ✅ GET /wp-json/order-manager/v1/users/{id} (get single user with teams)
- ✅ PUT /wp-json/order-manager/v1/users/{id} (update user)
- ✅ DELETE /wp-json/order-manager/v1/users/{id} (delete user and team associations)
- ✅ GET /wp-json/order-manager/v1/users/search?q={name_or_phone} (search users, public endpoint for PWA login)

**Phase 3: Backend - Team Assignment API** ✅ **COMPLETE**
- ✅ GET /wp-json/order-manager/v1/users/{id}/teams (get all teams for a user)
- ✅ POST /wp-json/order-manager/v1/teams/{id}/assign (assign user to team, body: {user_id})
- ✅ DELETE /wp-json/order-manager/v1/teams/{id}/users/{userId} (remove user from team)
- ✅ GET /wp-json/order-manager/v1/teams/{id}/users (get all users in a team)

**Phase 4: Backend - New Auth Logic** ✅ **COMPLETE**
- ✅ Updated POST /wp-json/order-manager/v1/auth/login (dual mode support)
  - Legacy mode: team_name + access_code → returns team info
  - User mode: name + phone + optional team_id → returns user + teams array + selected_team
  - Name matching: case-insensitive partial match
  - Returns mode in response for client handling
- ✅ Updated /config endpoint to include loginMode field
- ✅ Updated permission checks to support user-based auth headers (X-User-ID + X-Team-ID)
- ✅ Backwards compatible with all legacy auth methods

**Phase 7: PWA - New Login Screen** ✅ **COMPLETE**
- ✅ Updated `initLoginMode()` to check `config.loginMode` instead of `salesMode`
- ✅ Changed localStorage key from `detectedSalesMode` to `loginMode`
- ✅ Updated `restoreSession()` to handle both legacy and user mode sessions
- ✅ Removed hardcoded `salesMode` storage from user login handler
- ✅ Updated logout handler to clear user session keys (userId, userName, userPhone, selectedTeamId, selectedTeamName)
- ✅ User login form already present in HTML (name autocomplete, phone input, team selector)
- ✅ Autocomplete functionality already implemented (searches users by name)
- ✅ **Branding Updates**: Removed hardcoded "Subsales" text throughout PWA
  - Page title now uses dynamic brand name (not "Subsales — PWA")
  - Header h1 uses brand name dynamically
  - Login screen titles use brand name ("BrandName - Team Login" / "BrandName - Login")
  - Brand name persisted to localStorage for offline support
  - All branding applied consistently in 3 places: initial load, persisted cache, config fetch
- **Files updated**: `app.js` (1776 lines), `index.html` (218 lines)

**Phase 8: PWA - Session Management** (CURRENT)
- ⏳ Store user_id, team_id in localStorage (already implemented in login handler)
- ⏳ Handle team switching UI
- ⏳ Update session expiry logic for user mode
- **Files to update**: `app.js`

**Phase 9: PWA - Order Submission**
- ⏳ Include user_id and team_id in order payload
- ⏳ Update order headers to use X-User-ID + X-Team-ID (user mode) or X-Team-Name + X-Access-Code (legacy mode)
- **Files to update**: `app.js`

**Phase 10: PWA - My Orders Filter**
- ⏳ Filter by user_id (current user)
- ⏳ Filter by today only
- **Files to update**: `app.js`, `index.html`, `styles.css`

**Phase 11: PWA - EOD Tally Filter**
- ⏳ Show only current user's orders
- ⏳ Show only today's orders
- **Files to update**: `app.js`, `index.html`

**Phase 12: Backend - Orders Query Updates**
- ⏳ Support filtering by user_id, team_id, date range
- **Files to update**: `subsales-management.php` (get_orders function)

**Phase 13: Backend - Time Endpoint Enhancement**
- ⏳ Return server date/time for "today" filtering
- **Files to update**: `subsales-management.php` (order_manager_get_server_time function)

---

## 🗂️ Key File References

### Documentation
- **Full Architecture**: `PLUGIN-ARCHITECTURE.md` (complete plugin analysis)
  - Database schema (all 5 tables)
  - REST API endpoints (8 total)
  - AJAX handlers (10 total)
  - Admin pages (6 pages, multiple tabs)
  - All functions, options, data flows
  - **Read this first** for comprehensive understanding

### WordPress Plugin
- **Main file**: `wordpress-plugin/subsales-management.php` (6407 lines)
- **Admin Teams page**: Lines 4405-5050 (tabbed interface)
- **Settings page**: Lines 3857-4400 (Overall Settings tab)
- **Database setup**: Lines 587-670 (schema definitions)

### PWA Files (Three Core Files Need Updates for Phases 7-13)
- **Main App**: `wordpress-plugin/pwa/app.js` (~1400 lines)
  - Handles login logic, session management, order submission
  - Phases 7, 8, 9: Login screen, session storage, order payload
  - Must check `config.loginMode` and render appropriate login UI
  - Must store `user_id` + `team_id` for user-based mode
  - Must include `user_id` in order submissions
  
- **HTML Shell**: `wordpress-plugin/pwa/index.html` (~200 lines)
  - Login form structure, order form, tally screens
  - Phase 7: Update login form HTML for dual mode
  - Phase 10: Add "My Orders" filter UI
  - Phase 11: Update EOD tally display
  
- **Styles**: `wordpress-plugin/pwa/styles.css` (~500 lines)
  - Phase 7: Style new login UI elements (name search, phone input, team dropdown)
  - Phase 10-11: Style filter UI

**Note**: These three files work together and ALL need updates for user-based login to work properly.

### Database Tables
- `wp_order_sync_teams` - Teams table
- `wp_order_sync_team_members` - Users/members table
- `wp_order_sync_user_teams` - Junction table (user-team assignments)
- `wp_order_sync_orders` - Orders table
- `wp_order_sync_geocodes` - Geocoding cache

### Admin Menu Structure
- BKMB Subsales (main menu)
  - Dashboard (sales mode toggle, stats)
  - Teams → User Management (two tabs: Users | Team Assignments)
  - Settings → Overall Settings (where login mode toggle goes)
  - Orders (filtering, pagination)
  - Delivery (export, routing)
  - Address Extracts (ZIP generation)

---

## 🔧 Recent Changes & Fixes

### 2025-11-24 (Latest - Database Migration Fix)
**Fixed database update issues:**
- Added automatic schema migration for existing tables
- Legacy users with NULL/empty phones now get auto-generated defaults (000000XXXX format)
- Phone column properly set to NOT NULL with UNIQUE constraint
- Email UNIQUE constraint removed (allows duplicate/empty emails)
- Added "Run Database Migration" button in Settings → System Info tab
- Migration runs automatically on plugin activation for new installs
- Junction table (user_teams) now included in System Info display

**Previous UI/UX improvements:**
- Form field order: Name → Phone → Email
- Users table columns: "Name - Phone" | Email | Team(s)
- Search box for Available Users
- Fixed nonce mismatch for team assignments

---

## 🎯 Quick Session Start Checklist

When starting a new session, verify these critical constraints:

1. **Phone numbers**: Are they REQUIRED and UNIQUE? ✓
2. **Email**: Is it optional? ✓
3. **Login flow**: Name search → Phone entry? ✓
4. **Current phase**: What are we implementing? Phase 6
5. **File line count**: ~5306 lines in main plugin file

---

## 📝 Notes for Future Development

### Testing Checklist (Not Yet Done)
- [ ] Test phone validation on user creation
- [ ] Test phone uniqueness constraint
- [ ] Test multi-team assignment drag-and-drop
- [ ] Test remove user from team
- [ ] Test login mode toggle (once Phase 6 complete)
- [ ] Test PWA login with name search + phone
- [ ] Test order submission with user_id + team_id

### Known Technical Debt
- Mobile app dependencies need alignment (Redux Toolkit not in package.json)
- React Native version outdated (0.64.0)
- Need end-to-end testing of offline address completion
- REST API endpoints for user management not yet created (Phase 2)

### WordPress Options Used
- `order_sync_google_maps_api_key` - Google Maps API key
- `order_sync_interval` - Sync interval in seconds
- `order_sync_portal_slug` - PWA URL slug
- `order_sync_session_duration` - Session duration in ms
- `subsales_branding` - Branding/group name
- `order_sync_style_variant` - PWA style variant
- `order_sync_primary_color` - Primary color
- `subsales_header_image` - Header image attachment ID
- `order_sync_products` - JSON-encoded products array
- `login_mode` - 'legacy' or 'user_based' (Phase 6 will implement)

---

## 🚀 Next Immediate Action

**Complete Phase 6:**
1. Read current settings page structure (line ~3844)
2. Add `login_mode` retrieval from options
3. Add radio button UI in Overall Settings form
4. Update form submission handler
5. Test and package plugin

**Then move to Phase 2:**
- Create REST API endpoints for user management
- Enable PWA to search/fetch users via API
