<?php
namespace DSNWooPowerall;

class Test {
    /**
     * Logger instance
     *
     * @var Logger
     */
    private $logger;

    /**
     * API Handler instance
     *
     * @var API_Handler
     */
    private $api;

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new Logger();
        $this->api = new API_Handler();
    }

    /**
     * Run all tests
     */
    public function run_all_tests() {
        $this->test_api_connection();
        $productCode = $this->test_product_sync();
        $this->test_order_sync($productCode);
    }

    /**
     * Test API connection
     */
    public function test_api_connection() {
        $this->logger->info('Starting API connection test');
        
        // Test if credentials are set
        if (empty(get_option('dsn_woo_powerall_tenant_name')) || empty(get_option('dsn_woo_powerall_token'))) {
            $this->logger->error('API credentials not configured');
            return false;
        }

        // Test API connection by getting products
        $response = $this->api->get_products();
        
        if (is_wp_error($response)) {
            $this->logger->error('API connection test failed: ' . $response->get_error_message());
            return false;
        }

        $this->logger->info('API connection test successful');
        return true;
    }

    /**
     * Test product synchronization
     */
    public function test_product_sync() {
        $this->logger->info('Starting product sync test');
        
        // Get products from Powerall
        $response = $this->api->get_products(['limit' => 1]);
        
        if (is_wp_error($response)) {
            $this->logger->error('Product sync test failed: ' . $response->get_error_message());
            return false;
        }

        if (empty($response['Data'])) {
            $this->logger->warning('No products found in Powerall CRM');
            return false;
        }

        $product = $response['Data'][0];
        
        // Verify required fields
        $required_fields = ['ProductCode', 'Description1', 'SalesPrice', 'StockPerWarehouse'];
        $missing_fields = array();
        
        foreach ($required_fields as $field) {
            if (!isset($product[$field])) {
                $missing_fields[] = $field;
            }
        }
        
        if (!empty($missing_fields)) {
            $this->logger->error('Missing required fields: ' . implode(', ', $missing_fields));
            return false;
        }

        // Log the product details
        $this->logger->info(sprintf(
            'Product found - Code: %s, Price: %s, Stock: %s',
            $product['ProductCode'],
            $product['SalesPrice'],
            json_encode($product['StockPerWarehouse'])
        ));

   
        // Test stock check for this product
        $stock_response = $this->api->get_product_stock($product['ProductCode']);
        
         if (is_wp_error($stock_response)) {
             $this->logger->error('Stock check test failed: ' . $stock_response->get_error_message());
             return false;
         }

        $this->logger->info('Product sync test successful');
        return $product['ProductCode'];
    }

    /**
     * Test order synchronization
     */
    public function test_order_sync($productCode) {
        $this->logger->info('Starting order sync test');

        // Create test order data this is how WooCommerce will bring out the data
        $order_data = array(
            'order_number' => 19999992, // Use timestamp as order number
            'customer' => array(
                'email' => 'testDS@designstudio.com',
                'first_name' => 'Test DesignStudio',
                'last_name' => 'User DesignStudio',
            ),
            'items' => array(
                array(
                    'sku' => $productCode, // Use the real product code we found
                    'quantity' => 1,
                    'price' => 0.00 // Use the price we found
                )
            ),
            'shipping_address' => array(
                'address_1' => '123 Test Street',
                'city' => 'Test City',
                'postcode' => '12345',
                'country' => 'NL'
            ),
            'billing_address' => array(
                'address_1' => '123 Test Street',
                'city' => 'Test City',
                'postcode' => '12345',
                'country' => 'NL'
            )
        );

        // First try to simulate the order
        $this->logger->info('Simulating order creation');
        $simulation = $this->api->create_order($order_data, true);
        
        if (is_wp_error($simulation)) {
            $this->logger->error('Order sync test failed: ' . $simulation->get_error_message());
            return false;
        }

        $this->logger->info('Order simulation successful');
        return true;
    }
} 