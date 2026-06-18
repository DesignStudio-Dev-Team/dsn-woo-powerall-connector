<?php
namespace DSNWooPowerall;

class API_Handler {
    /**
     * API base URL
     *
     * @var string
     */
    private $api_base_url = 'https://connect.powerall.io/v1/';

    /**
     * Tenant name
     *
     * @var string
     */
    private $tenant_name;

    /**
     * API token
     *
     * @var string
     */
    private $token;

    /**
     * Logger instance
     *
     * @var Logger
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new Logger();
        $this->tenant_name = get_option('dsn_woo_powerall_tenant_name');
        $this->token = get_option('dsn_woo_powerall_token');
        
        // Log authentication details (without exposing the full token)
        // $this->logger->info(sprintf(
        //     // 'API Handler initialized with tenant: %s, token: %s...',
        //     $this->tenant_name,
        //     substr($this->token, 0, 4) . '...'
        // ));
    }

    /**
     * Make a GET request to the Powerall API
     *
     * @param string $endpoint API endpoint
     * @param array $params Query parameters
     * @return array|WP_Error Response data or error
     */
    public function get($endpoint, $params = array()) {
        $this->logger->info(sprintf('Making GET request to %s with params: %s', $endpoint, json_encode($params)));
        return $this->request('GET', $endpoint, $params);
    }

    /**
     * Make a POST request to the Powerall API
     *
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @return array|WP_Error Response data or error
     */
    public function post($endpoint, $data = array()) {
        $this->logger->info(sprintf('Making POST request to %s with data: %s', $endpoint, json_encode($data)));
        return $this->request('POST', $endpoint, array(), $data);
    }

    /**
     * Make a PUT request to the Powerall API
     *
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @return array|WP_Error Response data or error
     */
    public function put($endpoint, $data = array()) {
        $this->logger->info(sprintf('Making PUT request to %s with data: %s', $endpoint, json_encode($data)));
        return $this->request('PUT', $endpoint, array(), $data);
    }

    /**
     * Make a request to the Powerall API
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $params Query parameters
     * @param array $data Request data
     * @return array|WP_Error Response data or error
     */
    private function request($method, $endpoint, $params = array(), $data = array()) {
        if (empty($this->tenant_name) || empty($this->token)) {
            $error = new \WP_Error(
                'api_credentials_missing',
                __('Powerall CRM tenant name and token are required.', 'dsn-woo-powerall')
            );
            $this->logger->error('API credentials missing: ' . $error->get_error_message());
            return $error;
        }

        $url = trailingslashit($this->api_base_url) . ltrim($endpoint, '/');
        
        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }

        // Create Basic Auth header
        $auth_string = base64_encode($this->tenant_name . ':' . $this->token);
        
        $headers = array(
            'Authorization' => 'Basic ' . $auth_string,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        );

        $this->logger->info(sprintf('Making authenticated %s request to Powerall endpoint: %s', $method, $endpoint));

        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
        );

        if (!empty($data)) {
            $args['body'] = json_encode($data);
        }

        $this->logger->info(sprintf('Making request to URL: %s', $url));
        
        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->logger->error(sprintf('API request failed: %s', $response->get_error_message()));
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $error = new \WP_Error('invalid_response', __('Invalid response from Powerall API.', 'dsn-woo-powerall'));
            $this->logger->error(sprintf('Invalid JSON response: %s', $body));
            return $error;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_headers = wp_remote_retrieve_headers($response);
        
        $this->logger->info(sprintf(
            'Response received - Status: %d, Headers: %s',
            $response_code,
            json_encode($response_headers)
        ));

        if ($response_code >= 200 && $response_code < 300 && !empty($data['Error'])) {
            $error_message = $this->get_api_error_message($data, $response_code);
            $this->logger->error(sprintf('Powerall response contained an error: %s. Response body: %s', $error_message, $body));
            return new \WP_Error('api_error', $error_message, array('status' => $response_code));
        }

        if ($response_code < 200 || $response_code >= 300) {
            $error_message = $this->get_api_error_message($data, $response_code);
            $error = new \WP_Error('api_error', $error_message, array('status' => $response_code));
            $this->logger->error(sprintf(
                'API error (HTTP %d): %s. Response body: %s',
                $response_code,
                $error_message,
                $body
            ));
            return $error;
        }

        $this->logger->info(sprintf('API request successful (HTTP %d)', $response_code));
        return $data;
    }

    /**
     * Extract an actionable message from Powerall error responses.
     *
     * @param mixed $data Decoded response body
     * @param int $response_code HTTP response status
     * @return string
     */
    private function get_api_error_message($data, $response_code) {
        if (is_array($data)) {
            foreach (array('message', 'Message', 'detail', 'Detail', 'title', 'Title', 'error_description', 'ErrorMessage', 'Error', 'error') as $key) {
                if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
                    return trim($data[$key]);
                }
            }

            foreach (array('errors', 'Errors', 'Error', 'error') as $key) {
                if (!empty($data[$key])) {
                    $messages = array();
                    array_walk_recursive($data[$key], function ($value) use (&$messages) {
                        if (is_string($value) && trim($value) !== '') {
                            $messages[] = trim($value);
                        }
                    });

                    if (!empty($messages)) {
                        return implode(' ', array_unique($messages));
                    }
                }
            }
        }

        return sprintf(
            /* translators: %d: HTTP response status code */
            __('Powerall API returned HTTP %d without an error message. See the plugin log for the response body.', 'dsn-woo-powerall'),
            (int) $response_code
        );
    }

    /**
     * Get products from Powerall API
     *
     * @param array $params Query parameters
     * @return array|WP_Error Products data or error
     */
    public function get_products($params = array()) {
        $this->logger->info('Fetching products from Powerall API (export endpoint)');
        $response = $this->get('products/export.JSON?include=Stock');

        if (is_wp_error($response)) {
            return $response;
        }

        // The export endpoint returns an array of products directly
        if (!is_array($response)) {
            $this->logger->error('Invalid response structure from Powerall API export endpoint');
            return new \WP_Error('invalid_response', 'Invalid response structure from Powerall API export endpoint');
        }

        return $response;
    }

    /**
     * Get product stock from Powerall API
     *
     * @param string $product_id Product ID
     * @return array|WP_Error Stock data with 'quantity' key and 'warehouses' detail, or error
     */
    public function get_product_stock($product_id) {
        $response = $this->get("products/{$product_id}?include=Stock");
        
        if (is_wp_error($response)) {
            return $response;
        }

        // Handle different response structures: Data can be an array or object
        $product_data = null;
        if (isset($response['Data'])) {
            // Check if Data is an indexed array (Data[0]) or direct object (Data)
            if (isset($response['Data'][0]['StockPerWarehouse'])) {
                $product_data = $response['Data'][0];
            } elseif (isset($response['Data']['StockPerWarehouse'])) {
                $product_data = $response['Data'];
            }
        }

        if (!$product_data || !isset($product_data['StockPerWarehouse'])) {
            $this->logger->warning('No stock information available for product: ' . $product_id);
            return array('quantity' => 0, 'warehouses' => array());
        }

        $stock_mode = Stock_Helper::get_selected_mode();

        // Pass raw warehouse data so calculate_total_stock can filter by
        // excluded warehouse names before summing (it normalizes internally).
        $total_quantity = Stock_Helper::calculate_total_stock(
            $product_data['StockPerWarehouse'],
            $stock_mode
        );

        // Build a normalized list for the response payload.
        $warehouses = array();
        foreach ($product_data['StockPerWarehouse'] as $stock) {
            if (!is_array($stock) || !Stock_Helper::is_warehouse_included($stock)) {
                continue;
            }
            $warehouses[] = Stock_Helper::normalize_warehouse_stock($stock);
        }

        $this->logger->info('Stock data for product ' . $product_id . ' (mode: ' . $stock_mode . ', total: ' . $total_quantity . '): ' . json_encode($warehouses));

        return array(
            'quantity'   => $total_quantity,
            'warehouses' => $warehouses,
        );
    }

    /**
     * Get relation by email
     *
     * @param string $email Email address
     * @return array|WP_Error Relation data or error
     */
    public function get_relation_by_email($email) {
        $this->logger->info('Searching for relation with email: ' . $email);

        $page      = 1;
        $page_size = 250;
        $needle    = strtolower(trim($email));

        do {
            $response = $this->get('relations', array('PageIndex' => $page, 'PageSize' => $page_size));

            if (is_wp_error($response)) {
                return $response;
            }

            if (empty($response['Data']) || !is_array($response['Data'])) {
                break;
            }

            foreach ($response['Data'] as $relation) {
                if (isset($relation['EmailAddress']) && strtolower(trim($relation['EmailAddress'])) === $needle) {
                    $this->logger->info('Found relation with code: ' . ($relation['RelationCode'] ?? 'N/A') . ' on page ' . $page);
                    return $relation;
                }
            }

            $fetched   = count($response['Data']);
            $total     = isset($response['TotalCount']) ? (int) $response['TotalCount'] : 0;
            $has_more  = $total > 0 ? ($page * $page_size) < $total : $fetched >= $page_size;
            $page++;
        } while ($has_more);

        $this->logger->info('No relation found with email: ' . $email);
        return null;
    }

    /**
     * Get relation by ID
     *
     * @param string $id Relation ID
     * @return array|WP_Error Relation data or error
     */
    public function get_relation_by_id($id) {
        $this->logger->info('Getting relation by ID: ' . $id);
        
        $response = $this->get('relations');
        
        if (is_wp_error($response)) {
            return $response;
        }

        if (empty($response['Data'])) {
            return null;
        }

        // Search for the relation with matching ID
        foreach ($response['Data'] as $relation) {
            if (isset($relation['Id']) && $relation['Id'] === $id) {
                $this->logger->info('Found relation with ID: ' . $id . ', IsDebtor: ' . ($relation['IsDebtor'] ? 'true' : 'false'));
                return $relation;
            }
        }

        return null;
    }

    /**
     * Get relations
     *
     * @return array|WP_Error Relations data or error
     */
    public function get_relations() {
        $this->logger->info('Fetching relations from Powerall API');
        $response = $this->get('relations');
        
        //get the 1st relation only id
        if (is_wp_error($response)) {
            return $response;
        }
        if (empty($response['Data'])) {
            return null;
        }
        return $response['Data'][0];
    }



    /**
     * Create a new relation
     *
     * @param array $customer_data Customer data
     * @return array|WP_Error Relation data or error
     */
    public function create_relation($customer_data) {
        $this->logger->info('Creating new relation with data: ' . json_encode($customer_data));

        // Resolve address fields — support both flat billing_address and nested customer.address structures.
        $address_line = $customer_data['billing_address']['address_1']
            ?? $customer_data['customer']['address']['street']
            ?? '';
        $zip_code = $customer_data['billing_address']['postcode']
            ?? $customer_data['customer']['address']['postcode']
            ?? '';
        $town = $customer_data['billing_address']['city']
            ?? $customer_data['customer']['address']['city']
            ?? '';
        $phone = $customer_data['phone']
            ?? $customer_data['customer']['phone']
            ?? '';

        $relation_data = array(
            'Name1'       => trim(($customer_data['customer']['first_name'] ?? '') . ' ' . ($customer_data['customer']['last_name'] ?? '')),
            'EmailAddress' => $customer_data['customer']['email'] ?? '',
            'AddressLine' => $address_line,
            'ZipCode'     => $zip_code,
            'Town'        => $town,
            'CountryCode' => 1, // Default to Netherlands
            'Type'        => 'Personal',
            'Phone'       => $phone,
            'PayVat'        => true,
            'DebtorVatCode' => 1,
        );


        $this->logger->info('Sending relation data to Powerall: ' . json_encode($relation_data));
        $response = $this->post('relations', $relation_data);

        if (is_wp_error($response)) {
            $this->logger->error('Failed to create relation: ' . $response->get_error_message());
            return $response;
        }

        // Log the full response for debugging
        $this->logger->info('Powerall response for relation creation: ' . json_encode($response));

        if (empty($response['Data'])) {
            $error = new \WP_Error('invalid_response', 'No relation data in response');
            $this->logger->error('Failed to create relation: ' . $error->get_error_message());
            return $error;
        }

        // Get the relation ID from the response
        $relation = isset($response['Data'][0]) && is_array($response['Data'][0])
            ? $response['Data'][0]
            : $response['Data'];
        if (empty($relation['Id'])) {
            $error = new \WP_Error('invalid_response', 'No relation ID in response');
            $this->logger->error('Failed to create relation: ' . $error->get_error_message());
            return $error;
        }

        $this->logger->info('Successfully created relation with ID: ' . $relation['Id']);
        return $relation;
    }

    /**
     * Get or create relation, with WP user meta caching to prevent duplicates.
     *
     * @param array $customer_data Customer data
     * @return array|WP_Error Relation data or error
     */
    public function get_or_create_relation($customer_data) {
        $email      = strtolower(trim($customer_data['customer']['email'] ?? ''));
        $wp_user_id = !empty($customer_data['customer_id']) ? (int) $customer_data['customer_id'] : 0;

        // 1. Check WP user meta cache first — avoids API round-trip for returning customers.
        if ($wp_user_id > 0) {
            $cached_relation_id = get_user_meta($wp_user_id, '_powerall_relation_id', true);
            if ($cached_relation_id) {
                $this->logger->info('Using cached Powerall relation ID ' . $cached_relation_id . ' for WP user ' . $wp_user_id);
                return array('Id' => $cached_relation_id);
            }
        }

        // 2. Search by email across all pages.
        $relation = $this->get_relation_by_email($email);

        if (is_wp_error($relation)) {
            return $relation;
        }

        // 3. Create if not found.
        if (!$relation) {
            $this->logger->info('No existing relation found for ' . $email . ', creating new one');
            $relation = $this->create_relation($customer_data);

            if (is_wp_error($relation)) {
                return $relation;
            }

            if (empty($relation['Id'])) {
                $error = new \WP_Error('invalid_response', 'No relation ID returned after create');
                $this->logger->error($error->get_error_message());
                return $error;
            }
        } else {
            $this->logger->info('Found existing relation with ID: ' . $relation['Id']);
        }

        // 4. Cache relation ID in WP user meta so future orders skip the search.
        if ($wp_user_id > 0 && !empty($relation['Id'])) {
            update_user_meta($wp_user_id, '_powerall_relation_id', $relation['Id']);
            $this->logger->info('Cached Powerall relation ID ' . $relation['Id'] . ' for WP user ' . $wp_user_id);
        }

        return $relation;
    }

    /**
     * Create order in Powerall API
     *
     * @param array $order_data Order data
     * @param bool $simulate Whether to simulate the order creation
     * @return array|WP_Error Order data or error
     */
    public function create_order($order_data, $simulate = false) {
        $lines = array();
        foreach (($order_data['items'] ?? array()) as $item) {
            $product_code = trim((string) ($item['sku'] ?? ''));
            if ($product_code === '') {
                return new \WP_Error('missing_product_code', __('A Powerall sales order line is missing ProductCode.', 'dsn-woo-powerall'));
            }

            $lines[] = array(
                'ProductCode' => substr($product_code, 0, 20),
                'QuantityOrdered' => (float) ($item['quantity'] ?? 0),
                'GrossPrice' => (float) ($item['gross_price'] ?? $item['price'] ?? 0),
                'PriceIncVat' => true,
            );
        }

        if (empty($lines)) {
            return new \WP_Error('missing_order_lines', __('The Powerall sales order has no product lines.', 'dsn-woo-powerall'));
        }

        $relation = $this->get_or_create_relation($order_data);
        if (is_wp_error($relation)) {
            return $relation;
        }

        if (empty($relation['Id'])) {
            return new \WP_Error('missing_relation_id', __('Powerall relation does not contain an ID.', 'dsn-woo-powerall'));
        }

        $relation_id = (string) $relation['Id'];
        $billing = isset($order_data['billing_address']) ? $order_data['billing_address'] : ($order_data['customer'] ?? array());
        $shipping = isset($order_data['shipping_address']) ? $order_data['shipping_address'] : ($order_data['shipping'] ?? $billing);
        $billing_address = isset($billing['address']) && is_array($billing['address']) ? $billing['address'] : $billing;
        $shipping_address = isset($shipping['address']) && is_array($shipping['address']) ? $shipping['address'] : $shipping;
        $order_number = absint($order_data['order_number'] ?? 0);
        if ($order_number === 0) {
            $order_number = absint($order_data['external_id'] ?? 0);
        }
        $order_date = !empty($order_data['date_created'])
            ? gmdate('Y-m-d', strtotime($order_data['date_created']))
            : current_time('Y-m-d');

        $sales_order = array(
            'RelationId' => $relation_id,
            'EntryNumber' => min(99999999, $order_number),
            'OrderDate' => $order_date,
            'ApplyWebshopSettings' => true,
            'Reference1' => substr('WooCommerce order ' . (string) ($order_data['order_number'] ?? $order_number), 0, 40),
            'EmailAddress' => substr((string) ($order_data['customer']['email'] ?? ''), 0, 75),
            'Phone' => substr((string) ($order_data['customer']['phone'] ?? ''), 0, 20),
            'InvoiceName1' => substr(trim((string) ($billing['first_name'] ?? '') . ' ' . (string) ($billing['last_name'] ?? '')), 0, 50),
            'InvoiceAddressLine' => substr((string) ($billing_address['street'] ?? $billing_address['address_1'] ?? ''), 0, 50),
            'InvoiceZipCode' => substr((string) ($billing_address['postcode'] ?? ''), 0, 9),
            'InvoiceTown' => substr((string) ($billing_address['city'] ?? ''), 0, 26),
            'DeliveryName1' => substr(trim((string) ($shipping['first_name'] ?? '') . ' ' . (string) ($shipping['last_name'] ?? '')), 0, 50),
            'DeliveryAddressLine' => substr((string) ($shipping_address['street'] ?? $shipping_address['address_1'] ?? ''), 0, 50),
            'DeliveryZipCode' => substr((string) ($shipping_address['postcode'] ?? ''), 0, 9),
            'DeliveryTown' => substr((string) ($shipping_address['city'] ?? ''), 0, 26),
            'InternalNote' => substr((string) ($order_data['notes'] ?? ''), 0, 20480),
            'Lines' => $lines,
        );

        $endpoint = $simulate ? 'sales-orders/simulate' : 'sales-orders';
        $this->logger->info(sprintf('Creating %s sales order with data: %s', 
            $simulate ? 'simulated' : 'actual',
            json_encode($sales_order)
        ));
        
        return $this->post($endpoint, $sales_order);
    }

        /**
     * Get WooCommerce Unique ID
     *
     * @param string $product_id Product ID
     */
    public function get_product_uid($product_id) {
        return get_post_meta($product_id, '_global_unique_id', true);
    }

}
