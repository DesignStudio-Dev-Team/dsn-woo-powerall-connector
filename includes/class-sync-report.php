<?php
namespace DSNWooPowerall;

/**
 * Daily email listing the price changes and errors of the daily product sync.
 *
 * The daily cron run records every product whose regular or sale price really
 * changed, every product that failed to save, run-level errors (the run could
 * not start, a batch crashed) and purchasable products newly found not to be
 * in Powerall (Missing_Products_Check). When the run completes, one email goes to
 * the failure alert recipients and the report is cleared. Nothing is sent when
 * nothing changed and nothing failed.
 *
 * A run can fail and be retried several times a day, so the report collects
 * across runs until one completes. If no run completes, the next day's run
 * sends what was collected before it starts.
 */
class Sync_Report {
    public const OPTION = 'dsn_woo_powerall_sync_report';

    // Keeps the option a sensible size if something rewrites every price at once.
    private const MAX_ENTRIES = 2000;
    private const MAX_EMAIL_ROWS = 500;
    // A report this old belongs to a run that never completed.
    private const STALE_AFTER_SECONDS = 20 * HOUR_IN_SECONDS;

    /**
     * @var Logger
     */
    private $logger;

    public function __construct() {
        $this->logger = new Logger();
    }

    /**
     * Record a product whose regular or sale price changed. A product changed
     * twice keeps its first "from" and latest "to", and drops out if it ends
     * where it started.
     *
     * @param \WC_Product $product Product after the change
     * @param string $sku SKU the sync matched on
     * @param string $regular_from Regular price before
     * @param string $sale_from Sale price before
     * @return void
     */
    public function record_price_change($product, $sku, $regular_from, $sale_from) {
        $regular_to = (string) $product->get_regular_price();
        $sale_to = (string) $product->get_sale_price();
        $product_id = $product->get_id();

        $report = $this->read();
        if (isset($report['changes'][$product_id])) {
            $regular_from = $report['changes'][$product_id]['regular_from'];
            $sale_from = $report['changes'][$product_id]['sale_from'];
        }

        if (!self::price_differs($regular_from, $regular_to) && !self::price_differs($sale_from, $sale_to)) {
            if (isset($report['changes'][$product_id])) {
                unset($report['changes'][$product_id]);
                $this->save($report);
            }
            return;
        }

        if (!isset($report['changes'][$product_id]) && count($report['changes']) >= self::MAX_ENTRIES) {
            $report['dropped']++;
            $this->save($report);
            return;
        }

        $report['changes'][$product_id] = array(
            'edit_id' => $product->get_parent_id() ?: $product_id,
            'sku' => (string) $sku,
            'name' => $product->get_name(),
            'regular_from' => (string) $regular_from,
            'regular_to' => $regular_to,
            'sale_from' => (string) $sale_from,
            'sale_to' => $sale_to,
            'time' => time(),
        );
        $this->save($report);
    }

    /**
     * Record a product the sync could not update.
     *
     * @param array $result sync_single_product() result
     * @return void
     */
    public function record_product_failure(array $result) {
        $report = $this->read();
        $key = md5(($result['sku'] ?? '') . '|' . ($result['message'] ?? ''));

        if (!isset($report['failures'][$key]) && count($report['failures']) >= self::MAX_ENTRIES) {
            $report['dropped']++;
        } else {
            $report['failures'][$key] = array(
                'edit_id' => (int) ($result['product_id'] ?? 0),
                'sku' => (string) ($result['sku'] ?? ''),
                'name' => (string) ($result['product_name'] ?? ''),
                'message' => (string) ($result['message'] ?? ''),
                'time' => time(),
            );
        }
        $this->save($report);
    }

    /**
     * Record an error that stopped or broke a whole sync run.
     *
     * @param string $message Error message
     * @return void
     */
    public function record_run_error($message) {
        $report = $this->read();
        $key = md5((string) $message);

        if (isset($report['errors'][$key])) {
            $report['errors'][$key]['count']++;
            $report['errors'][$key]['time'] = time();
        } else {
            $report['errors'][$key] = array(
                'message' => (string) $message,
                'count' => 1,
                'time' => time(),
            );
        }
        $this->save($report);
    }

    /**
     * Record purchasable products that are newly found not to be in Powerall.
     *
     * @param array $products Missing_Products_Check entries (edit_id, name, sku, reason)
     * @param int $total How many purchasable products are not in Powerall in all
     * @return void
     */
    public function record_missing_products(array $products, $total) {
        $report = $this->read();

        foreach ($products as $product) {
            $key = $product['edit_id'] . '|' . $product['sku'] . '|' . $product['name'];
            if (!isset($report['missing'][$key]) && count($report['missing']) >= self::MAX_ENTRIES) {
                $report['dropped']++;
                continue;
            }
            $report['missing'][$key] = $product;
        }
        $report['missing_total'] = (int) $total;

        $this->save($report);
    }

    /**
     * Send what an earlier, never-completed run collected.
     *
     * @return void
     */
    public function send_if_stale() {
        $report = $this->read();
        if ($report['since'] && time() - $report['since'] >= self::STALE_AFTER_SECONDS) {
            $this->send();
        }
    }

    /**
     * Email the report if it has anything in it, then clear it.
     *
     * @return bool Whether an email was sent
     */
    public function send() {
        $report = $this->read();
        if (self::is_empty($report)) {
            $this->clear();
            return false;
        }

        $recipients = (new Sync_Notifier())->get_recipients();
        if (!$this->send_email($report, $recipients)) {
            // Keep the report so the next completed run tries again.
            $this->logger->error(sprintf('Could not send the Powerall sync report to %s.', implode(', ', $recipients)));
            return false;
        }

        $this->clear();
        $this->logger->info(sprintf(
            'Powerall sync report sent to %s. Price changes: %d | Failed products: %d | Errors: %d | New not in Powerall: %d',
            implode(', ', $recipients),
            count($report['changes']),
            count($report['failures']),
            count($report['errors']),
            count($report['missing'])
        ));

        return true;
    }

    /**
     * Email the current report without clearing it (WP-CLI previews and test sends).
     *
     * @param string[] $recipients Email addresses
     * @return bool|null Whether the mail was accepted, or null when the report is empty
     */
    public function send_preview($recipients) {
        $report = $this->read();
        if (self::is_empty($report)) {
            return null;
        }

        return $this->send_email($report, $recipients);
    }

    /**
     * @return array
     */
    public function read() {
        $report = get_option(self::OPTION, array());
        if (!is_array($report)) {
            $report = array();
        }

        return wp_parse_args($report, array(
            'since' => 0,
            'changes' => array(),
            'failures' => array(),
            'errors' => array(),
            'missing' => array(),
            'missing_total' => 0,
            'dropped' => 0,
        ));
    }

    /**
     * @param array $report
     * @return void
     */
    private function save(array $report) {
        if (!$report['since'] && !self::is_empty($report)) {
            $report['since'] = time();
        }

        update_option(self::OPTION, $report, false);
    }

    /**
     * @return void
     */
    private function clear() {
        delete_option(self::OPTION);
    }

    /**
     * @param array $report
     * @return bool
     */
    private static function is_empty(array $report) {
        return empty($report['changes']) && empty($report['failures']) && empty($report['errors']) && empty($report['missing']) && !$report['dropped'];
    }

    /**
     * @param string $from
     * @param string $to
     * @return bool
     */
    private static function price_differs($from, $to) {
        if ($from === '' || $to === '') {
            return $from !== $to;
        }

        return abs(floatval($from) - floatval($to)) >= 0.0001;
    }

    /**
     * @param array $report
     * @param string[] $recipients
     * @return bool
     */
    private function send_email(array $report, $recipients) {
        $parts = array();
        if (!empty($report['errors'])) {
            $parts[] = sprintf(_n('%d error', '%d errors', count($report['errors']), 'dsn-woo-powerall'), count($report['errors']));
        }
        if (!empty($report['failures'])) {
            $parts[] = sprintf(_n('%d product failed', '%d products failed', count($report['failures']), 'dsn-woo-powerall'), count($report['failures']));
        }
        if (!empty($report['changes'])) {
            $parts[] = sprintf(_n('%d price change', '%d price changes', count($report['changes']), 'dsn-woo-powerall'), count($report['changes']));
        }
        if (!empty($report['missing'])) {
            $parts[] = sprintf(_n('%d new product not in Powerall', '%d new products not in Powerall', count($report['missing']), 'dsn-woo-powerall'), count($report['missing']));
        }

        $subject = sprintf(
            '[%s] Powerall daily sync: %s',
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            $parts ? implode(', ', $parts) : __('report', 'dsn-woo-powerall')
        );

        $body = $this->render_body($report);

        if (function_exists('WC') && WC()->mailer()) {
            $mailer = WC()->mailer();
            $html = $mailer->wrap_message(__('Powerall daily sync', 'dsn-woo-powerall'), $body);
            return (bool) $mailer->send(implode(',', $recipients), $subject, $html, array('Content-Type: text/html; charset=UTF-8'));
        }

        return (bool) wp_mail($recipients, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
    }

    /**
     * @param array $report
     * @return string HTML
     */
    private function render_body(array $report) {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $since = $report['since'] ? wp_date('Y-m-d H:i', (int) $report['since']) : '';
        $changes = array_values($report['changes']);
        usort($changes, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        $cell = 'style="text-align:left;vertical-align:top;border-bottom:1px solid #eee;padding:6px"';

        ob_start();
        ?>
        <p><?php echo esc_html(sprintf('This is what the daily Powerall product sync changed or could not do on %s%s.', $site, $since ? ' since ' . $since : '')); ?></p>

        <?php if (!empty($report['errors'])) : ?>
            <h2 style="margin-top:24px">Sync errors</h2>
            <p>These stopped or interrupted a sync run. The sync retries by itself, so prices may still have been updated afterwards.</p>
            <?php foreach ($report['errors'] as $error) : ?>
                <p style="background:#f6f6f6;padding:10px 12px;border-left:3px solid #c0392b;font-family:monospace;font-size:13px">
                    <?php echo esc_html($error['message']); ?><br>
                    <span style="color:#777"><?php
                    echo esc_html(wp_date('Y-m-d H:i', (int) $error['time']));
                    if ($error['count'] > 1) {
                        echo esc_html(sprintf(' · happened %d times', $error['count']));
                    }
                    ?></span>
                </p>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($report['failures'])) : ?>
            <h2 style="margin-top:24px">Products that could not be updated</h2>
            <table cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%">
                <tr><th <?php echo $cell; ?>>Product</th><th <?php echo $cell; ?>>SKU</th><th <?php echo $cell; ?>>Error</th></tr>
                <?php foreach (array_slice(array_values($report['failures']), 0, self::MAX_EMAIL_ROWS) as $failure) : ?>
                    <tr>
                        <td <?php echo $cell; ?>><?php echo $this->product_link($failure['edit_id'], $failure['name']); ?></td>
                        <td <?php echo $cell; ?>><?php echo esc_html($failure['sku']); ?></td>
                        <td <?php echo $cell; ?>><?php echo esc_html($failure['message']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php if (count($report['failures']) > self::MAX_EMAIL_ROWS) : ?>
                <p><?php echo esc_html(sprintf('And %d more; see the log.', count($report['failures']) - self::MAX_EMAIL_ROWS)); ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($changes)) : ?>
            <h2 style="margin-top:24px">Price changes</h2>
            <p>A dash means no price was set.</p>
            <table cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%">
                <tr><th <?php echo $cell; ?>>Product</th><th <?php echo $cell; ?>>SKU</th><th <?php echo $cell; ?>>Regular price</th><th <?php echo $cell; ?>>Sale price</th></tr>
                <?php foreach (array_slice($changes, 0, self::MAX_EMAIL_ROWS) as $change) : ?>
                    <tr>
                        <td <?php echo $cell; ?>><?php echo $this->product_link($change['edit_id'], $change['name']); ?></td>
                        <td <?php echo $cell; ?>><?php echo esc_html($change['sku']); ?></td>
                        <td <?php echo $cell; ?>><?php echo $this->price_change($change['regular_from'], $change['regular_to']); ?></td>
                        <td <?php echo $cell; ?>><?php echo $this->price_change($change['sale_from'], $change['sale_to']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php if (count($changes) > self::MAX_EMAIL_ROWS) : ?>
                <p><?php echo esc_html(sprintf('And %d more; see the log.', count($changes) - self::MAX_EMAIL_ROWS)); ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($report['missing'])) : ?>
            <?php $missing = array_values($report['missing']); ?>
            <h2 style="margin-top:24px">Not in Powerall</h2>
            <p><?php echo esc_html(sprintf(
                'These products can be bought on the website, but Powerall has no product with their SKU as product code or EAN. Their price and stock are not updated from Powerall, and they are left off orders sent to Powerall. Listed are the %d found since the last report; %d products are not in Powerall in all.',
                count($missing),
                max(count($missing), (int) $report['missing_total'])
            )); ?></p>
            <p>To fix one, add it to Powerall, or set its SKU in WooCommerce to its Powerall product code.</p>
            <table cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%">
                <tr><th <?php echo $cell; ?>>Product</th><th <?php echo $cell; ?>>SKU</th></tr>
                <?php foreach (array_slice($missing, 0, self::MAX_EMAIL_ROWS) as $product) : ?>
                    <tr>
                        <td <?php echo $cell; ?>><?php echo $this->product_link($product['edit_id'], $product['name']); ?></td>
                        <td <?php echo $cell; ?>><?php echo $product['reason'] === 'no_sku' ? '<span style="color:#777">No SKU</span>' : esc_html($product['sku']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php if (count($missing) > self::MAX_EMAIL_ROWS) : ?>
                <p><?php echo esc_html(sprintf('And %d more; see the log.', count($missing) - self::MAX_EMAIL_ROWS)); ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($report['dropped']) : ?>
            <p><?php echo esc_html(sprintf('%d more changes or failures were not kept for this email because there were too many; see the log.', $report['dropped'])); ?></p>
        <?php endif; ?>

        <p style="margin-top:24px;color:#777;font-size:12px">
            Sent by the DSN WooCommerce to Powerall Connector after the daily product sync. Technical details are in <?php echo esc_html($this->logger->get_log_file_path()); ?>.
        </p>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param int $edit_id Product ID (parent for variations)
     * @param string $name Product name
     * @return string HTML
     */
    private function product_link($edit_id, $name) {
        $name = $name !== '' ? $name : '#' . $edit_id;
        if (!$edit_id) {
            return esc_html($name);
        }

        // get_edit_post_link() returns nothing in cron, where there is no logged-in user.
        return '<a href="' . esc_url(admin_url('post.php?post=' . (int) $edit_id . '&action=edit')) . '">' . esc_html($name) . '</a>';
    }

    /**
     * @param string $from
     * @param string $to
     * @return string HTML
     */
    private function price_change($from, $to) {
        $format = function ($price) {
            return $price === '' ? '–' : wp_strip_all_tags(wc_price($price));
        };

        if (!self::price_differs($from, $to)) {
            return '<span style="color:#777">' . esc_html($format($to)) . '</span>';
        }

        return esc_html($format($from) . ' → ' . $format($to));
    }
}
