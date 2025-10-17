# Order Management System WordPress Plugin

## Overview
This WordPress plugin is designed to facilitate order synchronization between the mobile application and the WordPress backend. It provides endpoints for managing orders, user authentication, and reporting.

## Installation
1. Download the plugin files.
2. Upload the `order-sync.php` file to the `/wp-content/plugins/` directory of your WordPress installation.
3. Activate the plugin through the 'Plugins' menu in WordPress.

## Usage
- The plugin exposes REST API endpoints that the mobile application can use to synchronize orders and manage user authentication.
- Ensure that the WordPress REST API is enabled on your site.

## API Endpoints
- **GET /wp-json/order-sync/v1/orders**: Retrieve a list of orders.
- **POST /wp-json/order-sync/v1/orders**: Create a new order.
- **GET /wp-json/order-sync/v1/orders/{id}**: Retrieve details of a specific order.
- **PUT /wp-json/order-sync/v1/orders/{id}**: Update an existing order.
- **DELETE /wp-json/order-sync/v1/orders/{id}**: Delete an order.

## Reporting
The plugin can generate reports based on the orders processed through the mobile application. You can access these reports via the WordPress admin dashboard.

## Requirements
- WordPress 5.0 or higher
- PHP 7.0 or higher

## Support
For support, please contact the plugin developer or refer to the documentation available on the plugin's page.

## License
This plugin is licensed under the MIT License.