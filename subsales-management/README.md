# Subsales Management WordPress Plugin

## Description
A comprehensive order management system for mobile app synchronization with WordPress backend. Includes team management, order tracking, and real-time sync capabilities.

## Features
- **Team Management**: Add and manage team members with role-based access
- **Order Synchronization**: Real-time sync between mobile app and WordPress
- **REST API**: Complete API for order and team management
- **Authentication**: Multiple authentication methods (API key, team login, individual member)
- **Admin Interface**: Professional WordPress admin interface
- **Database Management**: Automatic table creation and updates

## Installation

### Manual Installation
1. Download the plugin zip file
2. Upload to your WordPress site via Admin > Plugins > Add New > Upload Plugin
3. Activate the plugin
4. Go to Settings > Subsales to configure

### FTP Installation
1. Extract the plugin folder to `/wp-content/plugins/`
2. Activate through the WordPress admin
3. Configure in Settings > Subsales

## Configuration

### Initial Setup
1. **API Key**: Set a secure API key for system authentication
2. **Team Name**: Set your team/organization name
3. **Access Code**: Set an access code for mobile app login
4. **Sync Interval**: Configure how often to sync orders (minimum 60 seconds)

### Team Management
1. Add team members with name, email, and role
2. Assign roles: Member, Manager, or Admin
3. Team members inherit the current access code and team name

## API Endpoints

### Authentication
- `POST /wp-json/order-manager/v1/auth/login` - Team login
- `POST /wp-json/order-manager/v1/auth/verify` - Verify team access

### Orders
- `GET /wp-json/order-manager/v1/orders` - List orders
- `POST /wp-json/order-manager/v1/orders` - Create order
- `GET /wp-json/order-manager/v1/orders/{id}` - Get specific order
- `PUT /wp-json/order-manager/v1/orders/{id}` - Update order
- `DELETE /wp-json/order-manager/v1/orders/{id}` - Delete order

### Team
- `GET /wp-json/order-manager/v1/team/members` - List team members

### Authentication Headers
- `X-API-Key`: System-level authentication
- `X-Team-Name` + `X-Access-Code`: Team-level authentication
- `X-Team-Email` + `X-Member-Access-Code`: Individual member authentication

## Database Tables

### wp_order_sync_orders
- Stores order data with sync status
- Includes JSON order data field
- Tracks creation and update timestamps

### wp_order_sync_team_members
- Stores team member information
- Includes role-based access control
- Tracks login activity

## Version History

### Version 1.0.0
- Initial release
- Team management system
- Order synchronization
- REST API endpoints
- WordPress admin interface
- Multiple authentication methods

## Requirements
- WordPress 5.0 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher

## Support
For support and documentation, visit: https://github.com/jimmarks/Southington-BKMB-Subsales

## License
MIT License - See LICENSE file for details

## Author
Jim Marks - https://github.com/jimmarks