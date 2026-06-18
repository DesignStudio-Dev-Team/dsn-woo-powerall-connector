<?php
namespace DSNWooPowerall;

class Order_Sync {
    /**
     * API handler instance
     *
     * @var API_Handler
     */
    private $api_handler;

    /**
     * Constructor
     *
     * @param API_Handler $api_handler
     */
    public function __construct(API_Handler $api_handler) {
        $this->api_handler = $api_handler;
    }

    /**
     * Handle new WooCommerce order
     *
     * @param int $order_id WooCommerce order ID
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function handle_new_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new \WP_Error('invalid_order', __('Invalid order.', 'dsn-woo-powerall'));
        }

        if ($order->get_meta('_powerall_order_id')) {
            return true;
        }

        $order_data = $this->prepare_order_data($order);
        if (is_wp_error($order_data)) {
            $order->add_order_note(
                sprintf(
                    __('Failed to create order in Powerall CRM: %s', 'dsn-woo-powerall'),
                    $order_data->get_error_message()
                )
            );
            return $order_data;
        }

        $result = $this->api_handler->create_order($order_data);

        if (is_wp_error($result)) {
            $order->add_order_note(
                sprintf(
                    __('Failed to create order in Powerall CRM: %s', 'dsn-woo-powerall'),
                    $result->get_error_message()
                )
            );
            return $result;
        }

        $powerall_order = isset($result['Data'][0]) && is_array($result['Data'][0])
            ? $result['Data'][0]
            : (isset($result['Data']) && is_array($result['Data']) ? $result['Data'] : $result);
        $powerall_order_id = isset($powerall_order['Id']) ? (string) $powerall_order['Id'] : '';

        if ($powerall_order_id === '') {
            $error = new \WP_Error('invalid_order_response', __('Powerall did not return an order ID.', 'dsn-woo-powerall'));
            $order->add_order_note(
                sprintf(
                    __('Failed to create order in Powerall CRM: %s', 'dsn-woo-powerall'),
                    $error->get_error_message()
                )
            );
            return $error;
        }

        $order->update_meta_data('_powerall_order_id', $powerall_order_id);
        $order->save();

        $order->add_order_note(
            sprintf(
                __('Order created in Powerall CRM with ID: %s', 'dsn-woo-powerall'),
                $powerall_order_id
            )
        );

        return true;
    }

    /**
     * Handle order status change
     *
     * @param int $order_id WooCommerce order ID
     * @param string $old_status Old order status
     * @param string $new_status New order status
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function handle_order_status_change($order_id, $old_status, $new_status) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new \WP_Error('invalid_order', __('Invalid order.', 'dsn-woo-powerall'));
        }

        $powerall_order_id = $order->get_meta('_powerall_order_id');
        if (!$powerall_order_id) {
            return new \WP_Error('no_powerall_id', __('Order not synced with Powerall CRM.', 'dsn-woo-powerall'));
        }

        // Map WooCommerce status to Powerall CRM status
        $powerall_status = $this->map_order_status($new_status);

        // Update order status in Powerall CRM
        $result = $this->api_handler->update_order_status($powerall_order_id, $powerall_status);

        if (is_wp_error($result)) {
            // Log error
            $order->add_order_note(
                sprintf(
                    __('Failed to update order status in Powerall CRM: %s', 'dsn-woo-powerall'),
                    $result->get_error_message()
                )
            );
            return $result;
        }

        // Add success note
        $order->add_order_note(
            sprintf(
                __('Order status updated in Powerall CRM to: %s', 'dsn-woo-powerall'),
                $powerall_status
            )
        );

        return true;
    }

    /**
     * Prepare order data for Powerall CRM
     *
     * @param WC_Order $order WooCommerce order
     * @return array|\WP_Error Order data for Powerall CRM
     */
    private function prepare_order_data($order) {
        $items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $product_code = $product ? $this->get_powerall_product_code($product) : '';

            if ($product_code === '') {
                return new \WP_Error(
                    'missing_powerall_product_code',
                    sprintf(
                        /* translators: %s: order item name */
                        __('No Powerall ProductCode was found for order item "%s".', 'dsn-woo-powerall'),
                        $item->get_name()
                    )
                );
            }

            $quantity = (float) $item->get_quantity();
            $line_total = (float) $item->get_total();
            $line_tax = (float) $item->get_total_tax();
            $items[] = array(
                'sku' => $product_code,
                'quantity' => $quantity,
                'gross_price' => $quantity > 0 ? ($line_total + $line_tax) / $quantity : 0,
            );
        }

        if (empty($items)) {
            return new \WP_Error('missing_order_lines', __('The order does not contain any product lines for Powerall.', 'dsn-woo-powerall'));
        }

        // Get billing address using HPOS-compatible methods
        $billing_address = array(
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'address' => array(
                'street' => $order->get_billing_address_1(),
                'city' => $order->get_billing_city(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
            ),
        );

        // Get shipping address if different from billing
        if ($order->get_shipping_address_1() && $order->get_shipping_address_1() !== $order->get_billing_address_1()) {
            $shipping_address = array(
                'first_name' => $order->get_shipping_first_name(),
                'last_name' => $order->get_shipping_last_name(),
                'address' => array(
                    'street' => $order->get_shipping_address_1(),
                    'city' => $order->get_shipping_city(),
                    'postcode' => $order->get_shipping_postcode(),
                    'country' => $order->get_shipping_country(),
                ),
            );
        } else {
            $shipping_address = $billing_address;
        }

        return array(
            'external_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'customer_id' => $order->get_customer_id(), // WP user ID (0 for guests)
            'customer' => $billing_address,
            'billing_address' => $billing_address,
            'shipping_address' => $shipping_address,
            'items' => $items,
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'status' => $this->map_order_status($order->get_status()),
            'notes' => $order->get_customer_note(),
            'payment_method' => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),
            'date_created' => $order->get_date_created()->format('c'),
            'date_modified' => $order->get_date_modified()->format('c'),
        );
    }

    /**
     * Resolve the Powerall product code stored during sync, falling back to SKU.
     *
     * @param WC_Product $product WooCommerce product
     * @return string
     */
    private function get_powerall_product_code($product) {
        $snapshot = $product->get_meta('_dsn_powerall_last_sync_snapshot');
        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }

        if (is_array($snapshot) && !empty($snapshot['product_code'])) {
            return trim((string) $snapshot['product_code']);
        }

        return trim((string) $product->get_sku());
    }

    /**
     * Map WooCommerce order status to Powerall CRM status
     *
     * @param string $wc_status WooCommerce order status
     * @return string Powerall CRM order status
     */
    private function map_order_status($wc_status) {
        $status_map = array(
            'pending' => 'pending',
            'processing' => 'processing',
            'on-hold' => 'on_hold',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'failed' => 'failed',
        );

        return isset($status_map[$wc_status]) ? $status_map[$wc_status] : 'pending';
    }
}
