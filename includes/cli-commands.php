<?php
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('dsnwoo cleanup-sale-prices', function($args, $assoc_args) {
        $batch = isset($assoc_args['batch']) ? (int) $assoc_args['batch'] : 100;
        require_once __DIR__ . '/class-api-handler.php';
        require_once __DIR__ . '/class-product-sync.php';
        $api_handler = new \DSNWooPowerall\API_Handler();
        $sync = new \DSNWooPowerall\Product_Sync($api_handler);
        $result = $sync->cleanup_remove_equal_sale_prices($batch);
        if (is_array($result)) {
            WP_CLI::success(sprintf('Processed: %d Updated: %d', $result['processed'], $result['updated']));
        } else {
            WP_CLI::error('Cleanup failed or returned unexpected result.');
        }
    });

    /**
     * Refresh or inspect the local Powerall relation cache. Read-only towards Powerall.
     *
     * wp dsnwoo relation-cache             Incremental refresh (full load if never built)
     * wp dsnwoo relation-cache --full      Full reload from relations/export.JSON
     * wp dsnwoo relation-cache --status    Show sync state without calling Powerall
     * wp dsnwoo relation-cache --lookup=<email>  Look an email up in the cache
     */
    \WP_CLI::add_command('dsnwoo relation-cache', function($args, $assoc_args) {
        $cache = (new \DSNWooPowerall\API_Handler())->get_relation_cache();

        if (isset($assoc_args['status'])) {
            WP_CLI::log(wp_json_encode($cache->get_state(), JSON_PRETTY_PRINT));
            return;
        }

        if (isset($assoc_args['lookup'])) {
            $relation = $cache->find_by_email($assoc_args['lookup']);
            WP_CLI::log($relation ? wp_json_encode($relation) : 'Not in cache.');
            return;
        }

        $result = isset($assoc_args['full']) ? $cache->full_sync() : $cache->refresh();
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::success(wp_json_encode($result));
    });

    /**
     * Send (or preview) Powerall failure alerts for past orders to a chosen address.
     * Orders are NOT marked as notified, so the real alert still goes out later.
     *
     * wp dsnwoo failure-alerts --to=<emails> [--since=<Y-m-d>] [--orders=<ids>] [--all]
     * wp dsnwoo failure-alerts --preview-dir=<dir> [--since=<Y-m-d>] [--orders=<ids>] [--all]
     *
     * By default only orders with a recorded failure are included; --all adds
     * paid orders that have no failure record but no Powerall order ID either.
     */
    \WP_CLI::add_command('dsnwoo failure-alerts', function($args, $assoc_args) {
        $to = isset($assoc_args['to']) ? array_filter(array_map('trim', explode(',', $assoc_args['to'])), 'is_email') : array();
        $preview_dir = isset($assoc_args['preview-dir']) ? rtrim($assoc_args['preview-dir'], '/') : '';

        if (empty($to) && $preview_dir === '') {
            WP_CLI::error('Pass --to=<emails> to send, or --preview-dir=<dir> to write the emails as HTML files.');
        }

        $notifier = new \DSNWooPowerall\Sync_Notifier();
        if (!empty($assoc_args['orders'])) {
            $orders = array_filter(array_map('wc_get_order', array_map('intval', explode(',', $assoc_args['orders']))));
        } else {
            $since = isset($assoc_args['since']) ? strtotime($assoc_args['since']) : strtotime('-1 year');
            $orders = $notifier->find_unsynced_orders($since);
        }

        if ($preview_dir !== '') {
            wp_mkdir_p($preview_dir);
            add_filter('pre_wp_mail', function ($null, $atts) use ($preview_dir) {
                $file = $preview_dir . '/' . sanitize_file_name(preg_replace('/^.*Order #(\d+).*$/', 'order-$1', $atts['subject'])) . '.html';
                file_put_contents($file, '<!-- Subject: ' . esc_html($atts['subject']) . " -->\n" . $atts['message']);
                return true;
            }, 10, 2);
            $to = array('preview@localhost');
        }

        $sent = 0;
        foreach ($orders as $order) {
            $failure = $notifier->get_failure($order);
            if (!isset($assoc_args['all']) && in_array($failure['code'], array('never_sent', 'sync_not_finished'), true)) {
                continue;
            }

            if ($notifier->send_alert($order, $to)) {
                $sent++;
                WP_CLI::log(sprintf('#%s  %s', $order->get_order_number(), $failure['code']));
            } else {
                WP_CLI::warning(sprintf('#%s  mail was not accepted', $order->get_order_number()));
            }
        }

        WP_CLI::success(sprintf('%d alert(s) %s.', $sent, $preview_dir !== '' ? 'written to ' . $preview_dir : 'sent to ' . implode(', ', $to)));
    });
}
