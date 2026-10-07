<?php
namespace DSNWooPowerall;

class Order_Sync {
    /**
     * Timestamp from which orders are sent on payment instead of at checkout.
     */
    public const SEND_ON_PAYMENT_SINCE_OPTION = 'dsn_woo_powerall_send_on_payment_since';

    /**
     * API handler instance
     *
     * @var API_Handler
     */
    private $api_handler;

    /**
     * Failure alert sender
     *
     * @var Sync_Notifier|null
     */
    private $notifier;

    /**
     * Constructor
     *
     * @param API_Handler $api_handler
     * @param Sync_Notifier|null $notifier
     */
    public function __construct(API_Handler $api_handler, ?Sync_Notifier $notifier = null) {
        $this->api_handler = $api_handler;
        $this->notifier = $notifier;
    }

    /**
     * Handle new WooCommerce order
     *
     * @param int|\WC_Order $order_id WooCommerce order ID or order
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

        $order->update_meta_data(Sync_Notifier::ATTEMPTED_META, time());
        $order->save();

        try {
            $result = $this->send_order($order);
        } catch (\Throwable $e) {
            $result = new \WP_Error('unexpected_exception', $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
            $order->add_order_note(
                sprintf(
                    __('Failed to create order in Powerall CRM: %s', 'dsn-woo-powerall'),
                    $result->get_error_message()
                )
            );
        }

        if ($this->notifier) {
            if (is_wp_error($result)) {
                $this->notifier->record_failure($order, $result);
            } else {
                $this->notifier->clear_failure($order);
            }
        }

        return $result;
    }

    /**
     * Send an order automatically the first time it becomes paid.
     *
     * Later status changes (e.g. processing -> completed) never retry on their
     * own: staff may have entered a failed order in Powerall by hand by then.
     * Use the "Send order to Powerall" order action to retry.
     *
     * @param int $order_id WooCommerce order ID
     * @return bool|WP_Error|null Result, or null when skipped
     */
    public function handle_paid_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_powerall_order_id') || $order->get_meta(Sync_Notifier::ATTEMPTED_META)) {
            return null;
        }

        // Orders entered by staff in wp-admin are not sent automatically.
        if (!in_array($order->get_created_via(), array('checkout', 'store-api'), true)) {
            return null;
        }

        // Orders placed before send-on-payment went live were already handled
        // at checkout; several were entered in Powerall by hand since.
        $since = (int) get_option(self::SEND_ON_PAYMENT_SINCE_OPTION, 0);
        $created = $order->get_date_created();
        if (!$since || !$created || $created->getTimestamp() < $since) {
            return null;
        }

        return $this->handle_new_order($order);
    }

    /**
     * Offer a manual resend in the order edit screen while the order is not in Powerall.
     *
     * @param array $actions Order actions
     * @param \WC_Order|null $order Order being edited
     * @return array
     */
    public function add_resend_order_action($actions, $order = null) {
        if ($order instanceof \WC_Order && !$order->get_meta('_powerall_order_id')) {
            $actions['dsn_powerall_send_order'] = __('Send order to Powerall', 'dsn-woo-powerall');
        }

        return $actions;
    }

    /**
     * Run the "Send order to Powerall" order action.
     *
     * @param \WC_Order $order Order
     * @return void
     */
    public function handle_resend_order_action($order) {
        $order->add_order_note(sprintf(
            /* translators: %s: user display name */
            __('Manual send to Powerall requested by %s.', 'dsn-woo-powerall'),
            wp_get_current_user()->display_name
        ));

        $this->handle_new_order($order->get_id());
    }

    /**
     * Create the customer (if needed) and the sales order in Powerall.
     *
     * Products Powerall does not know are left out instead of blocking the
     * whole order; they are listed in the Powerall order's internal note and
     * reported by the failure alert. If no product is left, only the
     * customer is created.
     *
     * @param \WC_Order $order WooCommerce order
     * @return bool|WP_Error True on success (possibly partial), WP_Error on failure
     */
    private function send_order($order) {
        $order_data = $this->prepare_order_data($order);
        if (is_wp_error($order_data)) {
            return $this->fail($order, $order_data);
        }

        $order_data = $this->skip_products_unknown_to_powerall($order_data);
        if (is_wp_error($order_data)) {
            return $this->fail($order, $order_data);
        }

        $customer_note = $order_data['notes'];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if (empty($order_data['items'])) {
                return $this->create_customer_only($order, $order_data);
            }

            $order_data['notes'] = trim($this->get_skipped_products_note($order_data['skipped']) . "\n\n" . $customer_note);
            $result = $this->api_handler->create_order($order_data);
            if (!is_wp_error($result)) {
                break;
            }

            // A product can disappear from Powerall between the check and the
            // order. Nothing was created on a rejection, so drop it and retry once.
            $rejected_code = $this->get_rejected_product_code($result);
            if ($attempt > 0 || $rejected_code === '' || !$this->skip_item($order_data, $rejected_code, 'not_in_powerall')) {
                return $this->fail($order, $result);
            }
        }

        $powerall_order = isset($result['Data'][0]) && is_array($result['Data'][0])
            ? $result['Data'][0]
            : (isset($result['Data']) && is_array($result['Data']) ? $result['Data'] : $result);
        $powerall_order_id = isset($powerall_order['Id']) ? (string) $powerall_order['Id'] : '';

        if ($powerall_order_id === '') {
            return $this->fail($order, new \WP_Error('invalid_order_response', __('Powerall did not return an order ID.', 'dsn-woo-powerall')));
        }

        $order->update_meta_data('_powerall_order_id', $powerall_order_id);
        if (!empty($order_data['skipped'])) {
            $order->update_meta_data(Sync_Notifier::PARTIAL_META, array(
                'powerall_order_id' => $powerall_order_id,
                'entry_number' => isset($powerall_order['EntryNumber']) ? (string) $powerall_order['EntryNumber'] : '',
                'skipped' => $order_data['skipped'],
                'time' => time(),
            ));
        }
        $order->save();

        $order->add_order_note(
            sprintf(
                __('Order created in Powerall CRM with ID: %s', 'dsn-woo-powerall'),
                $powerall_order_id
            )
        );

        if (!empty($order_data['skipped'])) {
            $order->add_order_note(sprintf(
                /* translators: %s: list of products */
                __('Not sent to Powerall because these products are not in Powerall: %s', 'dsn-woo-powerall'),
                $this->describe_skipped($order_data['skipped'])
            ));

            if ($this->notifier) {
                $this->notifier->record_partial($order);
            }
        }

        return true;
    }

    /**
     * No product can be ordered in Powerall: create the customer anyway, so
     * staff only have to add the order lines by hand.
     *
     * @param \WC_Order $order WooCommerce order
     * @param array $order_data Prepared order data
     * @return WP_Error Always an error, because the order itself was not created
     */
    private function create_customer_only($order, $order_data) {
        $relation = $this->api_handler->get_or_create_relation($order_data);
        if (is_wp_error($relation)) {
            return $this->fail($order, $relation);
        }

        $order->update_meta_data('_powerall_relation_id', (string) $relation['Id']);
        $order->save();

        $relation_label = !empty($relation['RelationCode']) ? (string) $relation['RelationCode'] : (string) $relation['Id'];

        return $this->fail($order, new \WP_Error(
            'no_valid_lines',
            sprintf(
                /* translators: 1: list of products, 2: Powerall relation code */
                __('None of the products in this order are in Powerall (%1$s). The customer is in Powerall as relation %2$s, but no order was created.', 'dsn-woo-powerall'),
                $this->describe_skipped($order_data['skipped']),
                $relation_label
            ),
            array('skipped' => $order_data['skipped'], 'relation_code' => $relation_label)
        ));
    }

    /**
     * Ask Powerall about every product code and move unknown ones to "skipped".
     *
     * @param array $order_data Prepared order data
     * @return array|WP_Error Order data, or an error when Powerall could not be asked
     */
    private function skip_products_unknown_to_powerall($order_data) {
        foreach ($order_data['items'] as $item) {
            $exists = $this->api_handler->product_exists($item['sku']);
            if (is_wp_error($exists)) {
                return new \WP_Error(
                    'product_check_failed',
                    sprintf(
                        /* translators: 1: product code, 2: error message */
                        __('Could not check product %1$s in Powerall: %2$s', 'dsn-woo-powerall'),
                        $item['sku'],
                        $exists->get_error_message()
                    )
                );
            }

            if (!$exists) {
                $this->skip_item($order_data, $item['sku'], 'not_in_powerall');
            }
        }

        return $order_data;
    }

    /**
     * Move the line with this product code from items to skipped.
     *
     * @param array $order_data Prepared order data (modified)
     * @param string $product_code Powerall ProductCode
     * @param string $reason no_product_code|not_in_powerall
     * @return bool Whether a line was moved
     */
    private function skip_item(&$order_data, $product_code, $reason) {
        foreach ($order_data['items'] as $index => $item) {
            if (strcasecmp($item['sku'], $product_code) !== 0) {
                continue;
            }

            $order_data['skipped'][] = array(
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'total' => $item['gross_price'] * $item['quantity'],
                'product_code' => $item['sku'],
                'reason' => $reason,
            );
            unset($order_data['items'][$index]);
            $order_data['items'] = array_values($order_data['items']);
            return true;
        }

        return false;
    }

    /**
     * Pull the product code out of Powerall's "Product X does not exist" error.
     *
     * @param WP_Error $error Error from create_order()
     * @return string
     */
    private function get_rejected_product_code($error) {
        return preg_match('/Product (\S+) does not exist/', $error->get_error_message(), $matches) ? $matches[1] : '';
    }

    /**
     * Internal note for the Powerall order listing the products left out.
     *
     * @param array $skipped Skipped lines
     * @return string
     */
    private function get_skipped_products_note($skipped) {
        if (empty($skipped)) {
            return '';
        }

        $lines = array(__('INCOMPLETE WEB ORDER - these products are not in Powerall and were left out:', 'dsn-woo-powerall'));
        foreach ($skipped as $line) {
            $lines[] = sprintf('- %s x %s (%s)', $line['quantity'], $line['name'], wc_format_decimal($line['total'], 2));
        }

        return implode("\n", $lines);
    }

    /**
     * Short "2 x Name, 1 x Name" list for notes and messages.
     *
     * @param array $skipped Skipped lines
     * @return string
     */
    private function describe_skipped($skipped) {
        return implode(', ', array_map(function ($line) {
            return sprintf('%s x "%s"', $line['quantity'], $line['name']);
        }, $skipped));
    }

    /**
     * Add the standard failure note and return the error.
     *
     * @param \WC_Order $order WooCommerce order
     * @param WP_Error $error Failure
     * @return WP_Error
     */
    private function fail($order, $error) {
        $order->add_order_note(
            sprintf(
                __('Failed to create order in Powerall CRM: %s', 'dsn-woo-powerall'),
                $error->get_error_message()
            )
        );

        return $error;
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
        $skipped = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $product_code = $product ? $this->get_powerall_product_code($product) : '';

            $quantity = (float) $item->get_quantity();
            $line_total = (float) $item->get_total();
            $line_tax = (float) $item->get_total_tax();

            // Items without a Powerall code (e.g. gift vouchers) are left out, not fatal.
            if ($product_code === '') {
                $skipped[] = array(
                    'name' => $item->get_name(),
                    'quantity' => $quantity,
                    'total' => $line_total + $line_tax,
                    'product_code' => '',
                    'reason' => 'no_product_code',
                );
                continue;
            }

            $items[] = array(
                'sku' => $product_code,
                'name' => $item->get_name(),
                'quantity' => $quantity,
                'gross_price' => $quantity > 0 ? ($line_total + $line_tax) / $quantity : 0,
            );
        }

        if (empty($items) && empty($skipped)) {
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
            'skipped' => $skipped,
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
        // Product sync writes the snapshot to the original-language product
        // only, so a WPML translation has to read it from its source.
        $candidates = array($product);
        $source = $this->get_source_language_product($product);
        if ($source) {
            $candidates[] = $source;
        }

        foreach ($candidates as $candidate) {
            $snapshot = $candidate->get_meta('_dsn_powerall_last_sync_snapshot');
            if (is_string($snapshot)) {
                $snapshot = json_decode($snapshot, true);
            }

            if (is_array($snapshot) && !empty($snapshot['product_code'])) {
                return trim((string) $snapshot['product_code']);
            }
        }

        return trim((string) $product->get_sku());
    }

    /**
     * Return the WPML default-language version of a translated product.
     *
     * @param WC_Product $product WooCommerce product
     * @return WC_Product|null Source product, or null when WPML is inactive or the product is the source
     */
    private function get_source_language_product($product) {
        $default_lang = apply_filters('wpml_default_language', null);
        if (!$default_lang) {
            return null;
        }

        $element_type = $product->is_type('variation') ? 'product_variation' : 'product';
        $source_id = (int) apply_filters('wpml_object_id', $product->get_id(), $element_type, false, $default_lang);
        if (!$source_id || $source_id === $product->get_id()) {
            return null;
        }

        $source = wc_get_product($source_id);
        return $source ?: null;
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
