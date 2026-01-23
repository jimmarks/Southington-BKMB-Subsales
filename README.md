# Subsales Management WordPress Plugin

A comprehensive fundraising order management system with embedded Progressive Web App (PWA) for mobile order entry. Built for band/school fundraising campaigns with multi-team support, delivery manifests, and offline capabilities.

**Current Version**: 2.3.1  
**WordPress Compatibility**: 5.8+  
**PHP Version**: 7.4+

## Overview

This plugin provides a complete end-to-end solution for managing subsales/fundraising campaigns:
- 📱 **Embedded PWA** - Mobile-first order entry (no native app needed)
- 🚚 **Delivery Manifests** - QR-coded route optimization for delivery day
- 👥 **Multi-Team Management** - Support multiple sales teams per campaign
- 🏠 **Address Autocomplete** - GPS-enriched address database with ZIP prefetch
- 📊 **Real-Time Dashboard** - Today/overall stats with team/user leaderboards
- 📴 **Offline Support** - IndexedDB queue for orders entered without connectivity
- 🎯 **Individual Mode** - Team-less ordering with extended tally periods

## Project Structure

```
Southington-BKMB-Subsales/
├── wordpress-plugin/                # Main plugin directory
│   ├── subsales-management.php     # Bootstrap file
│   ├── includes/                   # Modular class architecture
│   │   ├── class-database.php      # DB schema & migrations
│   │   ├── class-rest-api.php      # REST endpoint registration
│   │   ├── class-pwa.php           # PWA serving & config
│   │   ├── class-orders.php        # Order business logic
│   │   ├── class-teams.php         # Team/user management
│   │   ├── class-delivery.php      # Delivery manifest generation
│   │   └── class-signups.php       # Campaign signup system
│   ├── admin/                      # Admin page templates
│   │   ├── main-dashboard.php
│   │   ├── orders-page.php
│   │   ├── delivery-page.php
│   │   └── settings-page.php
│   ├── pwa/                        # Progressive Web App client
│   │   ├── app.js                  # Main PWA logic
│   │   ├── address-autocomplete.js # Address entry system
│   │   ├── service-worker.js       # Offline support
│   │   └── index.html              # PWA entry point
│   └── assets/                     # CSS/JS for admin
├── scripts/                        # Build & deployment scripts
│   ├── package-plugin.sh           # Version & package (→ .zip)
│   └── dev-deploy.sh               # Deploy to dev site
└── .github/
    └── copilot-instructions.md     # AI agent instructions
```

## Key Features

### 📱 Progressive Web App (PWA)
- **No app store** - Access via browser at `/pwa/` endpoint
- **Install to home screen** - Works like native app
- **Offline order queue** - Syncs when connectivity restored
- **Session tracking** - Admin sees active users in real-time
- **Address autocomplete** - Prefetches ZIP data, suggests addresses
- **EOD Tally** - End-of-day summary filtered by user/date

### 🚚 Delivery Manifest System
- **Route optimization** - Nearest-neighbor algorithm with haversine distance
- **QR codes** - One per route for tracking (endroid/qr-code library)
- **Printable HTML** - Single page with CSS page breaks (7-8 stops/page)
- **Packing lists** - Product totals with team member breakdown
- **GPS coordinates** - Geocoded via Google Maps API

### 👥 Team & User Management
- **Multi-team campaigns** - Multiple teams per organization
- **Many-to-many relationships** - Users can be on multiple teams
- **Individual mode** - team_id = -1 for personal orders
- **Phone-based auth** - Required 10-digit phone, optional email
- **CSV import/export** - Bulk user/team management

### 📊 Admin Dashboard
- **Today/Overall toggle** - Real-time financial metrics
- **Team leaderboards** - Top 3 teams by revenue
- **User leaderboards** - Top 3 sellers
- **Active sessions badge** - Live PWA user count
- **Product breakdowns** - Sales by product type

## Quick Start

### Installation
1. Download latest release: `subsales-management-{version}.zip`
2. Upload via WordPress Admin → Plugins → Add New → Upload Plugin
3. Activate plugin
4. Navigate to **Subsales** menu in admin

### Initial Configuration
1. **Settings → General**
   - Set API keys (Google Maps for geocoding)
   - Configure product catalog
   - Enable debug mode (optional)

2. **Settings → Address Management**
   - Upload address database or configure Overpass API
   - Set served ZIP codes
   - Generate JSON extracts for PWA

3. **Teams**
   - Create teams with access codes
   - Import users via CSV (phone required)
   - Assign users to teams

### Access PWA
- **URL**: `https://yoursite.com/pwa/`
- **Login**: Team name + access code (team mode) or user phone (individual mode)
- **Test**: Place order, verify in admin Orders page

## REST API

**Base URL**: `/wp-json/order-manager/v1/`

### Key Endpoints
- **Orders**: `GET/POST /orders`, `GET/PUT/DELETE /orders/{id}`
- **Authentication**: `POST /auth/login`, `POST /auth/verify`
- **Config**: `GET /config` (PWA configuration)
- **Teams**: `GET /teams/members`, `GET/POST /users`
- **Delivery**: Via admin form (generates manifest HTML)

See `REST-API-ENDPOINTS.md` for complete documentation.

## Database Schema

**Table Prefix**: `wp_ss_*`

| Table | Purpose |
|-------|---------|
| `wp_ss_orders` | Order records with JSON data, GPS coords |
| `wp_ss_teams` | Team definitions (name, access code) |
| `wp_ss_team_members` | User records (phone REQUIRED, email optional) |
| `wp_ss_user_teams` | Many-to-many junction table |
| `wp_ss_order_edit_history` | Audit trail for order changes |
| `wp_ss_subsales_logs` | System-wide logging |
| `wp_ss_addresses` | GPS-enriched address lookup |
| `wp_ss_pwa_sessions` | Active PWA session tracking |

## Development

### Build & Package
```bash
# Auto-increment version and create ZIP
bash scripts/package-plugin.sh
# Output: subsales-management-2.3.2.zip

# Deploy to dev site (requires WP-CLI)
bash scripts/dev-deploy.sh
```

### Version Management
- **Format**: Semantic versioning (MAJOR.MINOR.PATCH)
- **Auto-increment**: Packaging script bumps patch version
- **History**: Old ZIPs preserved for rollback

### Technology Stack
- **Backend**: WordPress 5.8+, PHP 7.4+
- **PWA**: Vanilla JavaScript, IndexedDB, Service Workers
- **API**: WordPress REST API (custom namespace)
- **Database**: MySQL/MariaDB (WordPress schema)
- **Dependencies**: Composer (endroid/qr-code, bacon/bacon-qr-code)

### Key Files for Development
- `subsales-management.php` - Bootstrap, menu registration, legacy functions
- `includes/class-*.php` - Modular architecture (8 classes)
- `admin/*.php` - Admin page templates (no inline CSS allowed!)
- `pwa/app.js` - PWA main logic (~3,500 lines)
- `assets/css/admin-dashboard.css` - ALL admin styles live here

### Coding Standards
- Follow WordPress coding standards
- ALL CSS in `assets/css/admin-dashboard.css` (no inline styles)
- Use `$wpdb->prepare()` for all queries
- Escape output: `esc_html()`, `esc_attr()`
- Sanitize input: `sanitize_text_field()`, `absint()`
- Soft delete only (set `deleted=1`)

## Documentation

- `README.md` - This file (overview & quick start)
- `wordpress-plugin/README.md` - Plugin-specific docs
- `wordpress-plugin/CHANGELOG.md` - Version history
- `PLUGIN-ARCHITECTURE.md` - Deep technical reference
- `REST-API-ENDPOINTS.md` - Complete endpoint reference
- `.github/copilot-instructions.md` - AI agent context

## Support & Contributing

This is a private project for Southington BKMB band organization. For questions or issues, contact:
- **Maintainer**: Jim Marks (jim@marksfamilytree.com)
- **Repository**: jimmarks/Southington-BKMB-Subsales

## License

Proprietary software. All rights reserved.
6. Run: `npm run android` or `npm run ios`

## Documentation

- **WordPress Plugin**: See `wordpress-plugin/README.md`
- **Mobile App**: See `mobile-app/README.md`
- **AI Development**: See `.github/copilot-instructions.md`
- **Migration Notes**: See `docs/expo-migration-guide.md`

## Development Workflow

1. **WordPress Backend**: Develop and test the plugin locally
2. **Mobile Frontend**: Use Android Studio or Xcode for mobile development
3. **Integration**: Test mobile app against WordPress API endpoints
4. **Deployment**: WordPress plugin to production, mobile app via app stores

## Support

For issues and feature requests, please use the GitHub issue tracker.

## License

MIT License - See LICENSE file for details.