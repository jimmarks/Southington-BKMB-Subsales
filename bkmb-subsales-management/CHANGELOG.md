# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
- Initial release of BKMB Subsales Management plugin
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