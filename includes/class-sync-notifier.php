<?php
namespace DSNWooPowerall;

/**
 * Emails an alert when a paid order is not in Powerall.
 *
 * Orders are sent to Powerall when they are paid; failures are recorded on
 * the order and alerted right away (or once the order is paid, for a manual
 * resend of an unpaid order). A daily
 * check also catches paid orders that never reached Powerall for any other
 * reason (for example the checkout hook not firing, or a PHP crash).
 */
class Sync_Notifier {
    public const DEFAULT_RECIPIENT = 'stihl@designstudio.com';
    public const RECIPIENT_OPTION = 'dsn_woo_powerall_failure_alert_email';
    public const CHECK_HOOK = 'dsn_woo_powerall_unsynced_orders_check';

    public const ERROR_META = '_powerall_sync_error';
    public const ATTEMPTED_META = '_powerall_sync_attempted';
    public const PARTIAL_META = '_powerall_partial_sync';
    private const NOTIFIED_META = '_powerall_failure_notified';

    // Mollie keeps unpaid bank transfers in on-hold, so on-hold does not count as paid.
    private const PAID_STATUSES = array('processing', 'completed');
    private const CHECKOUT_SOURCES = array('checkout', 'store-api');
    private const CHECK_LOOKBACK_DAYS = 14;
    // Leave time for the customer to finish paying before calling an order missing.
    private const CHECK_MIN_AGE_SECONDS = HOUR_IN_SECONDS;

    /**
     * @var Logger
     */
    private $logger;

    public function __construct() {
        $this->logger = new Logger();
    }

    /**
     * Remember why an order could not be sent, and alert right away if it is already paid.
     *
     * @param \WC_Order $order Order
     * @param \WP_Error $error Failure
     * @return void
     */
    public function record_failure($order, $error) {
        $order->update_meta_data(self::ERROR_META, array(
            'code' => $error->get_error_code(),
            'message' => $error->get_error_message(),
            'data' => $error->get_error_data(),
            'time' => time(),
        ));
        $order->save();

        if ($order->has_status(self::PAID_STATUSES)) {
            $this->maybe_notify($order);
        }
    }

    /**
     * The order reached Powerall without some products; alert once it is paid.
     *
     * @param \WC_Order $order Order
     * @return void
     */
    public function record_partial($order) {
        if ($order->has_status(self::PAID_STATUSES)) {
            $this->maybe_notify($order);
        }
    }

    /**
     * Clear a recorded failure after a successful send.
     *
     * @param \WC_Order $order Order
     * @return void
     */
    public function clear_failure($order) {
        $order->delete_meta_data(self::ERROR_META);
        $order->save();
    }

    /**
     * woocommerce_order_status_changed: alert when an unsynced order becomes paid.
     *
     * @param int $order_id Order ID
     * @param string $old_status Previous status
     * @param string $new_status New status
     * @param \WC_Order|null $order Order
     * @return void
     */
    public function handle_status_change($order_id, $old_status, $new_status, $order = null) {
        if (!in_array($new_status, self::PAID_STATUSES, true)) {
            return;
        }

        // Reload instead of using the passed object: the Powerall sync runs on an
        // earlier status hook and saves its result to a different instance.
        $order = wc_get_order($order_id);
        if ($order) {
            $this->maybe_notify($order);
        }
    }

    /**
     * Daily safety net: alert about recent paid orders that have no Powerall order ID.
     *
     * @return void
     */
    public function run_daily_check() {
        $orders = $this->find_unsynced_orders(time() - self::CHECK_LOOKBACK_DAYS * DAY_IN_SECONDS);

        $alerted = 0;
        foreach ($orders as $order) {
            if ($this->maybe_notify($order)) {
                $alerted++;
            }
        }

        $this->logger->info(sprintf('Unsynced order check: %d unsynced paid order(s) found, %d alert(s) sent.', count($orders), $alerted));
    }

    /**
     * Paid checkout orders without a Powerall order ID.
     *
     * @param int $since Unix timestamp of the oldest order creation date to include
     * @return \WC_Order[]
     */
    public function find_unsynced_orders($since) {
        $orders = wc_get_orders(array(
            'status' => self::PAID_STATUSES,
            'date_created' => (int) $since . '...' . (time() - self::CHECK_MIN_AGE_SECONDS),
            'limit' => -1,
            'type' => 'shop_order',
            'orderby' => 'date',
            'order' => 'ASC',
        ));

        return array_values(array_filter($orders, array($this, 'needs_alert')));
    }

    /**
     * Send the alert once per order, unless the order is in Powerall.
     *
     * @param \WC_Order $order Order
     * @return bool Whether an alert was sent
     */
    public function maybe_notify($order) {
        if (!$this->needs_alert($order) || $order->get_meta(self::NOTIFIED_META)) {
            return false;
        }

        $recipients = $this->get_recipients();
        $failure = $this->get_failure($order);

        if (!$this->send_alert($order, $recipients)) {
            $this->logger->error(sprintf('Could not send the Powerall failure alert for order #%s to %s.', $order->get_order_number(), implode(', ', $recipients)));
            return false;
        }

        $order->update_meta_data(self::NOTIFIED_META, time());
        $order->save();
        $order->add_order_note(sprintf(
            /* translators: %s: email addresses */
            __('Powerall failure alert emailed to %s.', 'dsn-woo-powerall'),
            implode(', ', $recipients)
        ));
        $this->logger->info(sprintf('Powerall failure alert sent for order #%s (%s).', $order->get_order_number(), $failure['code']));

        return true;
    }

    /**
     * Email the alert for an order without recording it as notified.
     * Used by maybe_notify() and by the WP-CLI command for test sends.
     *
     * @param \WC_Order $order Order
     * @param string[] $recipients Email addresses
     * @return bool Whether the mail was accepted for delivery
     */
    public function send_alert($order, $recipients) {
        return $this->send_email($order, $this->get_failure($order), $recipients);
    }

    /**
     * Whether an order is a paid checkout order that Powerall does not have,
     * or has without some of its products.
     *
     * @param \WC_Order $order Order
     * @return bool
     */
    private function needs_alert($order) {
        if ($order->get_meta('_powerall_order_id') && !$order->get_meta(self::PARTIAL_META)) {
            return false;
        }

        // Orders entered by staff in wp-admin are not sent to Powerall automatically.
        return in_array($order->get_created_via(), self::CHECKOUT_SOURCES, true)
            && $order->has_status(self::PAID_STATUSES);
    }

    /**
     * Why the order is not in Powerall: stored error, older order note, or neither.
     *
     * @param \WC_Order $order Order
     * @return array Failure (code, message, optional time)
     */
    public function get_failure($order) {
        $partial = $order->get_meta(self::PARTIAL_META);
        if (is_array($partial) && !empty($partial['skipped'])) {
            return array(
                'code' => 'partial_order',
                'message' => sprintf(
                    'Powerall order %s was created without %d product line(s) that are not in Powerall.',
                    !empty($partial['entry_number']) ? $partial['entry_number'] : ($partial['powerall_order_id'] ?? ''),
                    count($partial['skipped'])
                ),
                'data' => array('skipped' => $partial['skipped']),
                'time' => $partial['time'] ?? 0,
            );
        }

        $failure = $order->get_meta(self::ERROR_META);
        if (is_array($failure) && !empty($failure['code'])) {
            return $failure;
        }

        $failure = $this->get_failure_from_order_notes($order);
        if ($failure) {
            return $failure;
        }

        return $order->get_meta(self::ATTEMPTED_META)
            ? array('code' => 'sync_not_finished', 'message' => __('The plugin started sending this order but never finished or recorded a result.', 'dsn-woo-powerall'))
            : array('code' => 'never_sent', 'message' => __('The plugin never tried to send this order to Powerall.', 'dsn-woo-powerall'));
    }

    /**
     * Orders that failed before failures were stored in meta only have an order note.
     *
     * @param \WC_Order $order Order
     * @return array|null Failure (code, message, time) or null
     */
    private function get_failure_from_order_notes($order) {
        $prefix = 'Failed to create order in Powerall CRM: ';
        $notes = wc_get_order_notes(array('order_id' => $order->get_id(), 'type' => 'internal'));

        // Notes come newest first.
        foreach ($notes as $note) {
            if (strpos($note->content, $prefix) !== 0) {
                continue;
            }

            $message = substr($note->content, strlen($prefix));
            return array(
                'code' => $this->guess_code_from_message($message),
                'message' => $message,
                'time' => $note->date_created ? $note->date_created->getTimestamp() : 0,
            );
        }

        return null;
    }

    /**
     * Map an old order-note message back to an error code for explain().
     *
     * @param string $message Error message
     * @return string
     */
    private function guess_code_from_message($message) {
        $known = array(
            'No Powerall ProductCode was found' => 'missing_powerall_product_code',
            'Customer creation was stopped to prevent a duplicate' => 'incomplete_relation_lookup',
            'customer email address is required' => 'missing_customer_email',
            'Powerall did not return an order ID' => 'invalid_order_response',
            'tenant name and token are required' => 'api_credentials_missing',
        );

        foreach ($known as $needle => $code) {
            if (stripos($message, $needle) !== false) {
                return $code;
            }
        }

        return 'api_error';
    }

    /**
     * @return string[]
     */
    public function get_recipients() {
        $setting = (string) get_option(self::RECIPIENT_OPTION, self::DEFAULT_RECIPIENT);
        $recipients = array_filter(array_map('trim', explode(',', $setting)), 'is_email');
        if (empty($recipients)) {
            $recipients = array(self::DEFAULT_RECIPIENT);
        }

        return (array) apply_filters('dsn_woo_powerall_failure_alert_recipients', array_values($recipients), $this);
    }

    /**
     * @param \WC_Order $order Order
     * @param array $failure Recorded failure (code, message)
     * @param string[] $recipients Email addresses
     * @return bool
     */
    private function send_email($order, $failure, $recipients) {
        $explanation = $this->explain($failure['code'], $failure['message'], $order->get_order_number(), isset($failure['data']) && is_array($failure['data']) ? $failure['data'] : array());
        $partial = $failure['code'] === 'partial_order';
        $subject = sprintf(
            $partial ? '[%s] Order #%s is incomplete in Powerall: %s' : '[%s] Order #%s is not in Powerall: %s',
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            $order->get_order_number(),
            $explanation['title']
        );

        $body = $this->render_body($order, $failure, $explanation);

        if (function_exists('WC') && WC()->mailer()) {
            $mailer = WC()->mailer();
            $html = $mailer->wrap_message($partial ? __('Order incomplete in Powerall', 'dsn-woo-powerall') : __('Order not sent to Powerall', 'dsn-woo-powerall'), $body);
            return (bool) $mailer->send(implode(',', $recipients), $subject, $html, array('Content-Type: text/html; charset=UTF-8'));
        }

        return (bool) wp_mail($recipients, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
    }

    /**
     * @param \WC_Order $order Order
     * @param array $failure Recorded failure
     * @param array $explanation Output of explain()
     * @return string HTML
     */
    private function render_body($order, $failure, $explanation) {
        $date = $order->get_date_created();
        $paid = $order->get_date_paid();
        $customer = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $company = $order->get_billing_company();

        $items = array();
        foreach ($order->get_items() as $item) {
            $product = is_callable(array($item, 'get_product')) ? $item->get_product() : null;
            $items[] = sprintf(
                '%s &times; %s%s',
                esc_html((string) $item->get_quantity()),
                esc_html($item->get_name()),
                $product && $product->get_sku() ? ' <span style="color:#777">(SKU ' . esc_html($product->get_sku()) . ')</span>' : ''
            );
        }

        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $skipped = isset($failure['data']['skipped']) && is_array($failure['data']['skipped']) ? $failure['data']['skipped'] : array();
        if ($failure['code'] === 'partial_order') {
            $intro = sprintf('A paid order on %s was added to Powerall, but without the products listed below because they are not in Powerall. Everything else in the order is in Powerall.', $site);
        } elseif ($failure['code'] === 'no_valid_lines') {
            $intro = sprintf('A paid order on %s could not be added to Powerall because none of its products are in Powerall. The customer was added to Powerall, so only the order itself is missing.', $site);
        } else {
            $intro = sprintf('A paid order on %s could not be added to Powerall. It is safe in WooCommerce, but Powerall does not have it yet.', $site);
        }

        ob_start();
        ?>
        <p><?php echo esc_html($intro); ?></p>

        <?php if (!empty($skipped)) : ?>
            <h2 style="margin-top:24px">Not in Powerall</h2>
            <ul>
                <?php foreach ($skipped as $line) : ?>
                    <li><?php echo esc_html(sprintf(
                        '%s × %s (%s)%s',
                        wc_format_decimal($line['quantity'] ?? 0),
                        $line['name'] ?? '',
                        wp_strip_all_tags(wc_price($line['total'] ?? 0, array('currency' => $order->get_currency()))),
                        ($line['reason'] ?? '') === 'no_product_code' ? ' – no Powerall product code in WooCommerce' : ' – product code ' . ($line['product_code'] ?? '') . ' does not exist in Powerall'
                    )); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h2 style="margin-top:24px">What went wrong</h2>
        <p><strong><?php echo esc_html($explanation['title']); ?></strong></p>
        <p><?php echo esc_html($explanation['meaning']); ?></p>
        <p style="background:#f6f6f6;padding:10px 12px;border-left:3px solid #c0392b;font-family:monospace;font-size:13px">
            <?php echo esc_html($failure['message']); ?><br>
            <span style="color:#777">Error code: <?php echo esc_html($failure['code']); ?><?php
            if (!empty($failure['time'])) {
                echo esc_html(' · ' . wp_date('Y-m-d H:i', (int) $failure['time']));
            }
            ?></span>
        </p>

        <h2 style="margin-top:24px">How to fix it</h2>
        <ol>
            <?php foreach ($explanation['options'] as $option) : ?>
                <li style="margin-bottom:6px"><?php echo esc_html($option); ?></li>
            <?php endforeach; ?>
        </ol>
        <?php if ($failure['code'] !== 'partial_order') : ?>
            <p>To resend: open the order in WooCommerce, choose <em>Send order to Powerall</em> in the <em>Order actions</em> box and click <em>Update</em>. If you enter the order in Powerall by hand instead, do not use the resend action as well, or Powerall will have it twice.</p>
        <?php endif; ?>

        <h2 style="margin-top:24px">Order details</h2>
        <table cellspacing="0" cellpadding="6" style="border-collapse:collapse;width:100%">
            <?php
            $rows = array(
                'Order' => '<a href="' . esc_url($order->get_edit_order_url()) . '">#' . esc_html($order->get_order_number()) . '</a>',
                'Placed' => $date ? esc_html(wp_date('Y-m-d H:i', $date->getTimestamp())) : '',
                'Paid' => $paid ? esc_html(wp_date('Y-m-d H:i', $paid->getTimestamp())) : esc_html(wc_get_order_status_name($order->get_status())),
                'Total' => wp_kses_post($order->get_formatted_order_total()),
                'Payment' => esc_html($order->get_payment_method_title()),
                'Customer' => esc_html($customer) . ($company ? ' (' . esc_html($company) . ')' : ''),
                'Email' => esc_html($order->get_billing_email()),
                'Phone' => esc_html($order->get_billing_phone()),
                'Products' => implode('<br>', $items),
            );
            foreach ($rows as $label => $value) :
                if ($value === '') {
                    continue;
                }
                ?>
                <tr>
                    <th style="text-align:left;vertical-align:top;border-bottom:1px solid #eee;width:110px"><?php echo esc_html($label); ?></th>
                    <td style="border-bottom:1px solid #eee"><?php echo $value; // Escaped above. ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <p style="margin-top:24px;color:#777;font-size:12px">
            Sent by the DSN WooCommerce to Powerall Connector. Technical details are in <?php echo esc_html((new Logger())->get_log_file_path()); ?>.
            This is the only alert you will get for this order.
        </p>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Plain-language explanation and fixes for each failure type.
     *
     * @param string $code Error code
     * @param string $message Error message
     * @param string $order_number Order number
     * @param array $data Extra error data (skipped lines, relation code)
     * @return array{title:string,meaning:string,options:string[]}
     */
    private function explain($code, $message, $order_number, $data = array()) {
        $manual = 'Or enter the order in Powerall by hand.';

        switch ($code) {
            case 'missing_powerall_product_code':
            case 'missing_product_code':
                return array(
                    'title' => 'A product is not linked to Powerall',
                    'meaning' => 'One of the products in this order has no Powerall product code, so Powerall would not know what was sold. This is normal for items that do not exist in Powerall, such as gift vouchers.',
                    'options' => array(
                        'If the product exists in Powerall: run the product sync (Powerall > Tools) or set the product\'s SKU to its Powerall product code, then resend the order.',
                        'If the product does not exist in Powerall (for example a gift voucher): enter the order in Powerall by hand.',
                    ),
                );

            case 'partial_order':
                return array(
                    'title' => 'Some products were left out',
                    'meaning' => 'The customer and the order are in Powerall, but the products listed above could not be added because Powerall does not have them. The Powerall order total is lower than what the customer paid. The Powerall order has an internal note listing the missing products.',
                    'options' => array(
                        'Add the missing products to the order in Powerall by hand (for example the gift voucher or the replacement article).',
                        'If a product should exist in Powerall, create it there or correct its product code in WooCommerce, so future orders go through completely.',
                        'Do not resend this order: the rest of it is already in Powerall.',
                    ),
                );

            case 'no_valid_lines':
                $relation = !empty($data['relation_code']) ? ' as relation ' . $data['relation_code'] : '';
                return array(
                    'title' => 'No products could be added',
                    'meaning' => 'None of the products in this order exist in Powerall, so no order could be created. The customer was created or found in Powerall' . $relation . '.',
                    'options' => array(
                        'Create the order in Powerall by hand for this customer, using the products listed above.',
                        'If the products should exist in Powerall, create them there or correct their product codes in WooCommerce.',
                    ),
                );

            case 'product_check_failed':
                return array(
                    'title' => 'The products could not be checked in Powerall',
                    'meaning' => 'Before sending, the plugin checks every product with Powerall. That check failed, so nothing was sent.',
                    'options' => array(
                        'Wait a few minutes and resend the order. Most of these problems are temporary.',
                        'If it fails again, check that Powerall is online.',
                        $manual,
                    ),
                );

            case 'incomplete_relation_lookup':
            case 'invalid_relation_lookup_response':
            case 'relation_creation_in_progress':
            case 'relation_cache_sync_in_progress':
                return array(
                    'title' => 'The customer could not be checked in Powerall',
                    'meaning' => 'Before creating a customer, the plugin checks whether Powerall already has one with this email address. That check could not be completed, so neither the customer nor the order was created. This prevents duplicate customers.',
                    'options' => array(
                        'Wait a few minutes and resend the order. Most of these problems are temporary.',
                        'If it fails again, check that Powerall is online and that the API tenant and token in the plugin settings are still valid.',
                        $manual,
                    ),
                );

            case 'missing_customer_email':
                return array(
                    'title' => 'The order has no customer email address',
                    'meaning' => 'Powerall customers are matched by email address, and this order has none.',
                    'options' => array(
                        'Add the customer\'s email address to the order\'s billing details, then resend the order.',
                        $manual,
                    ),
                );

            case 'api_credentials_missing':
                return array(
                    'title' => 'Powerall login details are missing',
                    'meaning' => 'The plugin has no Powerall tenant name or API token, so it cannot connect.',
                    'options' => array(
                        'Enter the tenant name and API token in the plugin settings, then resend the order.',
                    ),
                );

            case 'http_request_failed':
            case 'invalid_response':
                return array(
                    'title' => 'Powerall could not be reached',
                    'meaning' => 'Powerall did not answer, or sent back something unreadable. The order may or may not have arrived.',
                    'options' => array(
                        'First check in Powerall whether the order is there (search for "WooCommerce order ' . $order_number . '").',
                        'If it is not there, resend the order.',
                        'If this keeps happening, check whether Powerall has an outage.',
                    ),
                );

            case 'invalid_order_response':
                return array(
                    'title' => 'Powerall did not confirm the order',
                    'meaning' => 'Powerall answered, but did not return an order ID. The order may still have been created.',
                    'options' => array(
                        'Check in Powerall whether the order exists before doing anything else.',
                        'Only resend if it is not there.',
                    ),
                );

            case 'unexpected_exception':
                return array(
                    'title' => 'The plugin hit an unexpected error',
                    'meaning' => 'A PHP error stopped the order from being sent.',
                    'options' => array(
                        'Resend the order.',
                        'If it fails again, send this email to the developer together with the plugin log.',
                        $manual,
                    ),
                );

            case 'never_sent':
            case 'sync_not_finished':
                return array(
                    'title' => 'The order was never sent',
                    'meaning' => 'The order is paid, but the plugin has no record of sending it to Powerall. This can happen if the checkout was changed or a PHP error interrupted it.',
                    'options' => array(
                        'Check in Powerall whether the order is there.',
                        'If it is not there, resend the order.',
                        'If several orders show this, ask the developer to check that the checkout still triggers the Powerall connector.',
                    ),
                );
        }

        if (stripos($message, 'ProductCode') !== false || (stripos($message, 'Product ') !== false && stripos($message, 'does not exist') !== false)) {
            return array(
                'title' => 'Powerall does not recognise a product code',
                'meaning' => 'Powerall rejected the order because one of its product codes does not exist in Powerall.',
                'options' => array(
                    'Find the product code named in the error above in Powerall. If the product is missing or has a different code there, correct the SKU of the WooCommerce product to match.',
                    'Then resend the order.',
                    $manual,
                ),
            );
        }

        if (stripos($message, 'Unknown error') !== false) {
            return array(
                'title' => 'Powerall returned an unknown error',
                'meaning' => 'Powerall refused the order without saying why. The order may or may not have arrived.',
                'options' => array(
                    'Check in Powerall whether the order is there (search for "' . $order_number . '" or the customer\'s name).',
                    'If it is not there, resend the order. If the resend fails too, send this email to the developer together with the plugin log.',
                    $manual,
                ),
            );
        }

        return array(
            'title' => 'Powerall rejected the order',
            'meaning' => 'Powerall returned the error shown above.',
            'options' => array(
                'Read the error from Powerall above, correct the order or the product/customer data it mentions, then resend the order.',
                $manual,
            ),
        );
    }
}
