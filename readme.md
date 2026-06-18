# Powerall CRM WooCommerce Connector

A WordPress plugin that seamlessly integrates WooCommerce with Powerall CRM, enabling automatic synchronization of products, inventory, and orders between your WooCommerce store and Powerall CRM system.

## Features

- **Product Synchronization**
  - Automatic daily sync of products from Powerall CRM to WooCommerce
  - Real-time price and stock updates
  - Product code mapping between systems
  - Support for product variants and attributes

- **Inventory Management**
  - Real-time stock level synchronization*
  - Pre-purchase stock verification*
  - Automatic stock updates after successful orders*
  - Stock level validation before order processing*

- **Order Processing**
  - Automatic order creation in Powerall CRM
  - Invoice generation in Powerall CRM
  - Stock level updates in CRM after successful orders
  - Order simulation before actual creation

- **Customer Management**
  - Automatic customer creation in Powerall CRM
  - Customer data synchronization*
  - Support for both B2B and B2C customers*
  - Address management for shipping and billing*

## Requirements

- WordPress 6.8.0 or higher
- WooCommerce 9.8.5 or higher
- PHP 8.1 or higher
- Powerall CRM API credentials
- SSL certificate (for secure API communication)

## Installation

1. Download the plugin zip file
2. Go to WordPress admin panel > Plugins > Add New
3. Click "Upload Plugin" and select the downloaded zip file
4. Activate the plugin
5. Go to sidebar under tools DSN Woo To Powerall for Settings

## Configuration

1. **API Settings**
   - Enter your Powerall CRM API Key
   - Configure API endpoint settings


2. **Sync Settings**
   - Configure sync frequency (default: daily)

## Usage

### Product Synchronization
The plugin automatically syncs products daily from Powerall CRM to WooCommerce:
- Product codes are mapped between systems
- Prices are synchronized in real-time
- Stock levels are updated automatically
- Product attributes and variants are supported

### Order Processing
When a customer places an order:
1. The plugin verifies stock availability in Powerall CRM
2. Validates customer information
3. Processes the order in WooCommerce
4. Creates corresponding order in Powerall CRM
5. Updates stock levels in both systems
6. Generates invoice in Powerall CRM

### Customer Management
The plugin handles customer data:
1. Creates new customers in Powerall CRM
2. Syncs customer information between systems

## Testing

The plugin includes a comprehensive testing suite:
1. API Connection Testing
   - Verifies API credentials
   - Tests API endpoint connectivity
   - Validates API responses

2. Product Sync Testing
   - Tests product retrieval
   - Validates product data structure
   - Checks stock level synchronization

3. Order Sync Testing
   - Tests order creation
   - Validates order data
   - Simulates order processing

## Support

For support, please:
1. Check the [documentation](https://developers.powerall.nl/)
2. Review the plugin's error logs
3. Contact support with specific error messages

## Update Plugin
This plugin is updated via Github


## License

This plugin is licensed under the GPL v2 or later.

## Disclaimer 

Items with * at the end are coming soon or work in progress

## Credits

- Developed by DesignStudio Network. Inc,
- Powerall CRM API integration based on [Powerall Developers Documentation](https://developers.powerall.nl/)
