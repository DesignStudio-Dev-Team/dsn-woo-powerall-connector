<?php
namespace DSNWooPowerall;

class Admin_Settings {
    private const DAILY_SYNC_HOOK = 'dsn_woo_powerall_daily_sync';
    private const DAILY_SYNC_LAST_STATUS_OPTION = 'dsn_woo_powerall_daily_sync_last_status';
    private const LOG_RETENTION_DAYS = 7;

    /**
     * Logger instance
     *
     * @var Logger
     */
    private $logger;

    /**
     * Admin page hook suffixes (captured from add_menu_page / add_submenu_page return values).
     * Used by the main plugin class to scope admin asset enqueues to this plugin's screens.
     *
     * @var string
     */
    public $settings_page_hook = '';
    public $tools_page_hook = '';
    public $logs_page_hook = '';

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('update_option_' . Stock_Helper::EXCLUDED_WAREHOUSES_OPTION, array($this, 'recalculate_stock_after_exclusion_change'), 10, 2);
        $this->logger = new Logger();
    }

    /**
     * Add admin menu.
     *
     * @return void
     */
    public function add_admin_menu() {
        $this->settings_page_hook = add_menu_page(
            __('DSN Woo To Powerall', 'dsn-woo-powerall'),
            __('DSN Woo To Powerall', 'dsn-woo-powerall'),
            'manage_options',
            'dsn-woo-powerall',
            array($this, 'render_settings_page'),
            'dashicons-update',
            56
        );

        add_submenu_page(
            'dsn-woo-powerall',
            __('Settings', 'dsn-woo-powerall'),
            __('Settings', 'dsn-woo-powerall'),
            'manage_options',
            'dsn-woo-powerall',
            array($this, 'render_settings_page')
        );

        $this->tools_page_hook = add_submenu_page(
            'dsn-woo-powerall',
            __('Tools', 'dsn-woo-powerall'),
            __('Tools', 'dsn-woo-powerall'),
            'manage_options',
            'dsn-woo-powerall-tools',
            array($this, 'render_tools_page')
        );

        $this->logs_page_hook = add_submenu_page(
            'dsn-woo-powerall',
            __('Logs', 'dsn-woo-powerall'),
            __('Logs', 'dsn-woo-powerall'),
            'manage_options',
            'dsn-woo-powerall-logs',
            array($this, 'render_log_page')
        );
    }

    /**
     * Register settings.
     *
     * @return void
     */
    public function register_settings() {
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_tenant_name');
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_token');
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_sync_frequency');
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_use_sale_price');
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_stock_tracking_mode', array(
            'sanitize_callback' => array($this, 'sanitize_stock_tracking_mode'),
            'default' => Stock_Helper::DEFAULT_MODE,
        ));
        register_setting('dsn_woo_powerall_settings', Stock_Helper::EXCLUDED_WAREHOUSES_OPTION, array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_excluded_warehouses'),
            'default' => array(),
        ));
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_frontend_stock_enabled');
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_frontend_stock_display', array(
            'sanitize_callback' => array($this, 'sanitize_frontend_stock_display'),
            'default' => 'combined',
        ));
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_frontend_stock_position', array(
            'sanitize_callback' => array($this, 'sanitize_frontend_stock_position'),
            'default' => 'disabled',
        ));
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_sync_batch_size', array(
            'sanitize_callback' => array($this, 'sanitize_sync_batch_size'),
            'default' => Product_Sync::DEFAULT_BATCH_SIZE,
        ));
        register_setting('dsn_woo_powerall_settings', 'dsn_woo_powerall_sync_batch_delay', array(
            'sanitize_callback' => array($this, 'sanitize_sync_batch_delay'),
            'default' => Product_Sync::DEFAULT_BATCH_DELAY,
        ));

        add_settings_section(
            'dsn_woo_powerall_main_section',
            __('API Settings', 'dsn-woo-powerall'),
            array($this, 'render_section_info'),
            'dsn-woo-powerall'
        );

        add_settings_field(
            'dsn_woo_powerall_tenant_name',
            __('Tenant Name', 'dsn-woo-powerall'),
            array($this, 'render_tenant_name_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );

        add_settings_field(
            'dsn_woo_powerall_token',
            __('API Token', 'dsn-woo-powerall'),
            array($this, 'render_token_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );

        add_settings_field(
            'dsn_woo_powerall_sync_frequency',
            __('Sync Frequency', 'dsn-woo-powerall'),
            array($this, 'render_sync_frequency_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );

        add_settings_field(
            'dsn_woo_powerall_use_sale_price',
            __('Use Powerall Sale Price', 'dsn-woo-powerall'),
            array($this, 'render_use_sale_price_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );

        add_settings_field(
            'dsn_woo_powerall_stock_tracking_mode',
            __('Stock Tracking Mode', 'dsn-woo-powerall'),
            array($this, 'render_stock_tracking_mode_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );

        add_settings_field(
            Stock_Helper::EXCLUDED_WAREHOUSES_OPTION,
            __('Locations to Ignore', 'dsn-woo-powerall'),
            array($this, 'render_warehouse_inclusion_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );

        add_settings_field(
            'dsn_woo_powerall_frontend_stock_enabled',
            __('Show Stock on Frontend', 'dsn-woo-powerall'),
            array($this, 'render_frontend_stock_enabled_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );

        add_settings_field(
            'dsn_woo_powerall_frontend_stock_display',
            __('Frontend Stock Display Mode', 'dsn-woo-powerall'),
            array($this, 'render_frontend_stock_display_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );

        add_settings_field(
            'dsn_woo_powerall_frontend_stock_position',
            __('Auto Display Position', 'dsn-woo-powerall'),
            array($this, 'render_frontend_stock_position_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );

        add_settings_field(
            'dsn_woo_powerall_sync_batch_size',
            __('Sync Batch Size', 'dsn-woo-powerall'),
            array($this, 'render_sync_batch_size_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );

        add_settings_field(
            'dsn_woo_powerall_sync_batch_delay',
            __('Delay Between Batches', 'dsn-woo-powerall'),
            array($this, 'render_sync_batch_delay_field'),
            'dsn-woo-powerall',
            'dsn_woo_powerall_main_section'
        );
    }

    /**
     * Render settings page.
     *
     * @return void
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'dsn_woo_powerall_messages',
                'dsn_woo_powerall_message',
                __('Settings Saved', 'dsn-woo-powerall'),
                'updated'
            );
        }

        settings_errors('dsn_woo_powerall_messages');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('DSN Woo To Powerall — Settings', 'dsn-woo-powerall'); ?></h1>
            <?php $this->render_nav_tabs('settings'); ?>
            <form action="options.php" method="post">
                <?php
                settings_fields('dsn_woo_powerall_settings');
                do_settings_sections('dsn-woo-powerall');
                submit_button(__('Save Settings', 'dsn-woo-powerall'));
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render the dedicated Tools page (manual sync, warehouse rescan, connection test, cleanup).
     *
     * @return void
     */
    public function render_tools_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (
            isset($_POST['dsn_woo_powerall_manual_sync']) &&
            check_admin_referer('dsn_woo_powerall_manual_sync', 'dsn_woo_powerall_sync_nonce')
        ) {
            wp_safe_redirect($this->get_sync_progress_url(true));
            exit;
        }

        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : '';

        if (
            isset($_POST['dsn_woo_powerall_cleanup']) &&
            check_admin_referer('dsn_woo_powerall_cleanup_sale_prices', 'dsn_woo_powerall_cleanup_nonce')
        ) {
            require_once __DIR__ . '/class-product-sync.php';
            require_once __DIR__ . '/class-api-handler.php';
            $api_handler = new API_Handler();
            $product_sync = new Product_Sync($api_handler);
            $result = $product_sync->cleanup_remove_equal_sale_prices(100);
            if (is_array($result)) {
                $this->logger->info('Cleanup completed. Processed: ' . $result['processed'] . ' Updated: ' . $result['updated']);
                add_settings_error(
                    'dsn_woo_powerall_messages',
                    'dsn_woo_powerall_message',
                    sprintf(__('Cleanup completed. Processed: %d Updated: %d', 'dsn-woo-powerall'), $result['processed'], $result['updated']),
                    'updated'
                );
            } else {
                $this->logger->error('Cleanup failed or returned unexpected result.');
                add_settings_error(
                    'dsn_woo_powerall_messages',
                    'dsn_woo_powerall_message',
                    __('Cleanup failed. Check logs for details.', 'dsn-woo-powerall'),
                    'error'
                );
            }
        }

        if (
            isset($_POST['dsn_woo_powerall_scan_warehouses']) &&
            check_admin_referer('dsn_woo_powerall_scan_warehouses', 'dsn_woo_powerall_scan_warehouses_nonce')
        ) {
            $count = Stock_Helper::scan_known_warehouses();
            add_settings_error(
                'dsn_woo_powerall_messages',
                'dsn_woo_powerall_message',
                sprintf(
                    /* translators: %d: number of warehouse locations discovered */
                    __('Warehouse list refreshed. Found %d location(s).', 'dsn-woo-powerall'),
                    $count
                ),
                'updated'
            );
        }

        if (isset($_POST['run_tests']) && check_admin_referer('dsn_woo_powerall_run_tests')) {
            $test = new Test();
            $test->run_all_tests();
            add_settings_error(
                'dsn_woo_powerall_messages',
                'dsn_woo_powerall_message',
                __('Tests completed. Check the log for results.', 'dsn-woo-powerall'),
                'updated'
            );
        }

        $audit_report = null;
        if (
            isset($_POST['dsn_woo_powerall_audit_matches']) &&
            check_admin_referer('dsn_woo_powerall_audit_matches', 'dsn_woo_powerall_audit_matches_nonce')
        ) {
            $audit_report = $this->run_sku_match_audit();
            if (is_wp_error($audit_report)) {
                add_settings_error(
                    'dsn_woo_powerall_messages',
                    'dsn_woo_powerall_message',
                    $audit_report->get_error_message(),
                    'error'
                );
                $audit_report = null;
            } else {
                add_settings_error(
                    'dsn_woo_powerall_messages',
                    'dsn_woo_powerall_message',
                    sprintf(
                        /* translators: 1: matched count, 2: total Powerall records */
                        __('SKU audit complete. %1$d of %2$d Powerall records matched a WooCommerce product.', 'dsn-woo-powerall'),
                        $audit_report['matched_count'],
                        $audit_report['total']
                    ),
                    'updated'
                );
            }
        }

        settings_errors('dsn_woo_powerall_messages');

        if ($view === 'sync-progress') {
            $this->render_sync_progress_page();
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('DSN Woo To Powerall — Tools', 'dsn-woo-powerall'); ?></h1>
            <?php $this->render_nav_tabs('tools'); ?>

            <h2><?php esc_html_e('Manual Sync', 'dsn-woo-powerall'); ?></h2>
            <p><?php esc_html_e('Open the progress screen to start a manual product sync. The sync runs in smaller batches so you can watch progress and reduce timeout risk on slower hosting.', 'dsn-woo-powerall'); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field('dsn_woo_powerall_manual_sync', 'dsn_woo_powerall_sync_nonce'); ?>
                <input type="submit" name="dsn_woo_powerall_manual_sync" class="button button-primary" value="<?php esc_attr_e('Open Product Sync Progress', 'dsn-woo-powerall'); ?>">
                <a href="<?php echo esc_url($this->get_sync_progress_url(false)); ?>" class="button"><?php esc_html_e('View Current Progress', 'dsn-woo-powerall'); ?></a>
            </form>

            <hr>

            <h2><?php esc_html_e('Refresh Warehouse List', 'dsn-woo-powerall'); ?></h2>
            <p><?php esc_html_e('Rescan existing product data to rebuild the list of warehouse locations available in the settings. The list is also updated automatically during each sync.', 'dsn-woo-powerall'); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field('dsn_woo_powerall_scan_warehouses', 'dsn_woo_powerall_scan_warehouses_nonce'); ?>
                <input type="submit" name="dsn_woo_powerall_scan_warehouses" class="button button-secondary" value="<?php esc_attr_e('Refresh warehouse list', 'dsn-woo-powerall'); ?>">
            </form>

            <hr>

            <h2><?php esc_html_e('Test Connection', 'dsn-woo-powerall'); ?></h2>
            <p><?php esc_html_e('Run tests to verify the connection with Powerall CRM.', 'dsn-woo-powerall'); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field('dsn_woo_powerall_run_tests'); ?>
                <input type="submit" name="run_tests" class="button button-secondary" value="<?php esc_attr_e('Run Tests', 'dsn-woo-powerall'); ?>">
            </form>

            <hr>

            <h2><?php esc_html_e('Cleanup', 'dsn-woo-powerall'); ?></h2>
            <p><?php esc_html_e('Remove sale price when it is equal to the regular price across all products.', 'dsn-woo-powerall'); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field('dsn_woo_powerall_cleanup_sale_prices', 'dsn_woo_powerall_cleanup_nonce'); ?>
                <input type="submit" name="dsn_woo_powerall_cleanup" class="button button-secondary" value="<?php esc_attr_e('Cleanup sale prices', 'dsn-woo-powerall'); ?>">
            </form>

            <hr>

            <h2><?php esc_html_e('Audit SKU Matches', 'dsn-woo-powerall'); ?></h2>
            <p><?php esc_html_e('Fetch every product from Powerall and report which records fail to match a WooCommerce SKU. Match priority: ProductCode → EanCode.', 'dsn-woo-powerall'); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field('dsn_woo_powerall_audit_matches', 'dsn_woo_powerall_audit_matches_nonce'); ?>
                <input type="submit" name="dsn_woo_powerall_audit_matches" class="button button-secondary" value="<?php esc_attr_e('Run SKU audit', 'dsn-woo-powerall'); ?>">
            </form>

            <?php if (is_array($audit_report)) : ?>
                <?php $this->render_audit_report($audit_report); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Fetch every product from Powerall and compare against WooCommerce SKUs.
     * Returns a report array, or WP_Error on API failure.
     *
     * @return array{total:int, matched_count:int, matched_by_product_code:int, matched_by_ean_code:int, unmatched:array, duplicates:array}|\WP_Error
     */
    private function run_sku_match_audit() {
        require_once __DIR__ . '/class-api-handler.php';
        $api_handler = new API_Handler();
        $products    = $api_handler->get_products();

        if (is_wp_error($products)) {
            return $products;
        }
        if (!is_array($products)) {
            return new \WP_Error('invalid_response', __('Powerall returned an unexpected response.', 'dsn-woo-powerall'));
        }

        $report = array(
            'total'                   => count($products),
            'matched_count'           => 0,
            'matched_by_product_code' => 0,
            'matched_by_ean_code'     => 0,
            'missing_both_codes'      => 0,
            'unmatched'               => array(),
            'duplicates'              => array(),
        );

        foreach ($products as $record) {
            if (!is_array($record)) {
                continue;
            }

            $product_code = isset($record['ProductCode']) ? trim((string) $record['ProductCode']) : '';
            $ean_code     = isset($record['EanCode']) ? trim((string) $record['EanCode']) : '';
            $name         = isset($record['Description1']) ? (string) $record['Description1'] : '';

            if ($product_code === '' && $ean_code === '') {
                $report['missing_both_codes']++;
                $report['unmatched'][] = array(
                    'product_code' => '',
                    'ean_code'     => '',
                    'name'         => $name,
                    'reason'       => __('Powerall record has no ProductCode and no EanCode', 'dsn-woo-powerall'),
                );
                continue;
            }

            $matched_by = '';
            $product_id = 0;

            if ($product_code !== '') {
                $id = $this->lookup_woo_id_by_sku($product_code);
                if ($id > 0) {
                    $matched_by = 'ProductCode';
                    $product_id = $id;
                    $report['matched_by_product_code']++;
                }
            }

            if (!$product_id && $ean_code !== '' && $ean_code !== $product_code) {
                $id = $this->lookup_woo_id_by_sku($ean_code);
                if ($id > 0) {
                    $matched_by = 'EanCode';
                    $product_id = $id;
                    $report['matched_by_ean_code']++;
                }
            }

            if ($product_id) {
                $report['matched_count']++;
                continue;
            }

            $report['unmatched'][] = array(
                'product_code' => $product_code,
                'ean_code'     => $ean_code,
                'name'         => $name,
                'reason'       => __('No WooCommerce product with a matching SKU', 'dsn-woo-powerall'),
            );
        }

        return $report;
    }

    /**
     * Thin wrapper around wc_get_product_id_by_sku with a postmeta fallback so
     * the audit works even if WooCommerce helpers aren't loaded yet.
     *
     * @param string $sku
     * @return int
     */
    private function lookup_woo_id_by_sku($sku) {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return 0;
        }

        if (function_exists('wc_get_product_id_by_sku')) {
            $id = (int) wc_get_product_id_by_sku($sku);
            if ($id > 0) {
                return $id;
            }
        }

        global $wpdb;
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1",
            $sku
        ));

        return $row ? (int) $row : 0;
    }

    /**
     * Render the SKU match audit report beneath the Tools form.
     *
     * @param array $report
     * @return void
     */
    private function render_audit_report(array $report): void {
        $preview = array_slice($report['unmatched'], 0, 100);
        $extra   = max(0, count($report['unmatched']) - count($preview));
        ?>
        <div style="margin-top: 20px; background: #fff; padding: 16px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h3 style="margin-top: 0;"><?php esc_html_e('Audit results', 'dsn-woo-powerall'); ?></h3>
            <ul style="margin: 0 0 12px 18px;">
                <li><?php printf(esc_html__('Total Powerall records: %d', 'dsn-woo-powerall'), (int) $report['total']); ?></li>
                <li><?php printf(esc_html__('Matched via ProductCode: %d', 'dsn-woo-powerall'), (int) $report['matched_by_product_code']); ?></li>
                <li><?php printf(esc_html__('Matched via EanCode: %d', 'dsn-woo-powerall'), (int) $report['matched_by_ean_code']); ?></li>
                <li><?php printf(esc_html__('Missing both codes: %d', 'dsn-woo-powerall'), (int) $report['missing_both_codes']); ?></li>
                <li><strong><?php printf(esc_html__('Unmatched: %d', 'dsn-woo-powerall'), (int) count($report['unmatched'])); ?></strong></li>
            </ul>

            <?php if (empty($preview)) : ?>
                <p><em><?php esc_html_e('Every Powerall record found a matching WooCommerce SKU.', 'dsn-woo-powerall'); ?></em></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('ProductCode', 'dsn-woo-powerall'); ?></th>
                            <th><?php esc_html_e('EanCode', 'dsn-woo-powerall'); ?></th>
                            <th><?php esc_html_e('Name', 'dsn-woo-powerall'); ?></th>
                            <th><?php esc_html_e('Reason', 'dsn-woo-powerall'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview as $row) : ?>
                            <tr>
                                <td><code><?php echo esc_html($row['product_code'] !== '' ? $row['product_code'] : '—'); ?></code></td>
                                <td><code><?php echo esc_html($row['ean_code'] !== '' ? $row['ean_code'] : '—'); ?></code></td>
                                <td><?php echo esc_html($row['name']); ?></td>
                                <td><?php echo esc_html($row['reason']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($extra > 0) : ?>
                    <p><em><?php printf(esc_html__('Showing first %1$d of %2$d unmatched records. Remaining %3$d hidden — see plugin log for full detail.', 'dsn-woo-powerall'), count($preview), (int) count($report['unmatched']), $extra); ?></em></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the dedicated Log Viewer page.
     *
     * @return void
     */
    public function render_log_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->handle_daily_sync_cron_action();

        if (isset($_POST['clear_log']) && check_admin_referer('dsn_woo_powerall_clear_log')) {
            $this->logger->clear_log();
            delete_transient('dsn_github_latest_release_DesignStudio-Dev-Team_dsn-woo-powerall-connector');

            add_settings_error(
                'dsn_woo_powerall_messages',
                'dsn_woo_powerall_message',
                __('Log cleared and update cache reset successfully.', 'dsn-woo-powerall'),
                'updated'
            );
        }

        $this->prune_log_file_to_recent_days();
        $log_days = $this->get_recent_log_days();
        $selected_log_day = isset($_GET['log_day']) ? sanitize_text_field(wp_unslash($_GET['log_day'])) : $log_days[0]['date'];
        if (!in_array($selected_log_day, wp_list_pluck($log_days, 'date'), true)) {
            $selected_log_day = $log_days[0]['date'];
        }

        settings_errors('dsn_woo_powerall_messages');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('DSN Woo To Powerall — Logs', 'dsn-woo-powerall'); ?></h1>
            <?php $this->render_nav_tabs('logs'); ?>
            <p><?php esc_html_e('View the latest API requests and responses.', 'dsn-woo-powerall'); ?></p>

            <?php $this->render_daily_sync_cron_status(); ?>

            <form method="post" action="">
                <?php wp_nonce_field('dsn_woo_powerall_clear_log'); ?>
                <input type="submit" name="clear_log" class="button" value="<?php esc_attr_e('Clear Log', 'dsn-woo-powerall'); ?>">
            </form>

            <div class="log-viewer" style="margin-top: 20px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <?php $this->render_log_day_tabs($log_days, $selected_log_day); ?>
                <pre style="white-space: pre-wrap; word-wrap: break-word; max-height: 600px; overflow-y: auto; margin-top: 16px;"><?php echo esc_html($this->get_log_contents($selected_log_day)); ?></pre>
            </div>
        </div>
        <?php
    }

    /**
     * Render daily product sync cron diagnostics.
     *
     * @return void
     */
    private function render_daily_sync_cron_status() {
        $hook = self::DAILY_SYNC_HOOK;
        $next_run = wp_next_scheduled($hook);
        $schedule = $next_run ? (wp_get_schedule($hook) ?: __('Unknown', 'dsn-woo-powerall')) : __('Not scheduled', 'dsn-woo-powerall');
        $last_status = get_option(self::DAILY_SYNC_LAST_STATUS_OPTION, array());
        $status = isset($last_status['status']) ? (string) $last_status['status'] : __('Never recorded', 'dsn-woo-powerall');
        $message = isset($last_status['message']) ? (string) $last_status['message'] : __('No cron run has been recorded yet.', 'dsn-woo-powerall');
        $completed_at = isset($last_status['completed_at']) && $last_status['completed_at'] !== '' ? (string) $last_status['completed_at'] : __('Never recorded', 'dsn-woo-powerall');
        $duration = isset($last_status['duration_seconds']) ? absint($last_status['duration_seconds']) : 0;
        $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $trigger_status = $wp_cron_disabled
            ? __('External server cron required', 'dsn-woo-powerall')
            : __('WordPress visitor-triggered cron enabled', 'dsn-woo-powerall');
        ?>
        <div style="margin: 16px 0 20px; background: #fff; padding: 16px 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h2 style="margin-top: 0;"><?php esc_html_e('Daily Sync Cron Status', 'dsn-woo-powerall'); ?></h2>
            <table class="widefat striped" style="max-width: 900px;">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Hook', 'dsn-woo-powerall'); ?></th>
                        <td><code><?php echo esc_html($hook); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Schedule', 'dsn-woo-powerall'); ?></th>
                        <td><?php echo esc_html($schedule); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Next Run', 'dsn-woo-powerall'); ?></th>
                        <td>
                            <?php if ($next_run) : ?>
                                <span
                                    id="dsn-daily-sync-next-run"
                                    data-timestamp="<?php echo esc_attr($next_run); ?>"
                                    data-fallback="<?php echo esc_attr(wp_date('Y-m-d H:i:s T', $next_run)); ?>"
                                >
                                    <?php echo esc_html(wp_date('Y-m-d H:i:s T', $next_run)); ?>
                                </span>
                            <?php else : ?>
                                <?php esc_html_e('Not scheduled', 'dsn-woo-powerall'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Last Status', 'dsn-woo-powerall'); ?></th>
                        <td><?php echo esc_html($status); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Last Completed', 'dsn-woo-powerall'); ?></th>
                        <td><?php echo esc_html($completed_at); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Last Duration', 'dsn-woo-powerall'); ?></th>
                        <td><?php echo esc_html(sprintf(_n('%d second', '%d seconds', $duration, 'dsn-woo-powerall'), $duration)); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Cron Trigger Mode', 'dsn-woo-powerall'); ?></th>
                        <td><?php echo esc_html($trigger_status); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Last Message', 'dsn-woo-powerall'); ?></th>
                        <td><?php echo esc_html($message); ?></td>
                    </tr>
                </tbody>
            </table>
            <form method="post" action="" style="margin-top: 12px;">
                <?php wp_nonce_field('dsn_woo_powerall_daily_sync_cron_action'); ?>
                <input type="hidden" name="dsn_woo_powerall_cron_action" value="repair_daily_sync">
                <input type="submit" class="button button-primary" value="<?php esc_attr_e('Enable / Repair Daily Sync Cron', 'dsn-woo-powerall'); ?>">
            </form>
            <?php if ($wp_cron_disabled) : ?>
                <p class="description"><?php esc_html_e('The plugin daily sync can still be scheduled. This site has WordPress visitor-triggered cron disabled, so the scheduled event depends on a server cron calling wp-cron.php.', 'dsn-woo-powerall'); ?></p>
            <?php endif; ?>
        </div>
        <?php if ($next_run) : ?>
            <script>
                (function() {
                    var nextRun = document.getElementById('dsn-daily-sync-next-run');
                    if (!nextRun || !nextRun.dataset.timestamp) {
                        return;
                    }

                    var timestamp = parseInt(nextRun.dataset.timestamp, 10);
                    if (!timestamp) {
                        return;
                    }

                    var date = new Date(timestamp * 1000);
                    var timeZone = '';

                    try {
                        timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
                        nextRun.textContent = date.toLocaleString(undefined, {
                            year: 'numeric',
                            month: 'short',
                            day: 'numeric',
                            hour: 'numeric',
                            minute: '2-digit',
                            second: '2-digit',
                            timeZoneName: 'short'
                        }) + (timeZone ? ' (' + timeZone + ')' : '');
                    } catch (error) {
                        nextRun.textContent = nextRun.dataset.fallback;
                    }
                })();
            </script>
        <?php endif; ?>
        <?php
    }

    /**
     * Handle daily sync cron repair action.
     *
     * @return void
     */
    private function handle_daily_sync_cron_action() {
        if (!isset($_POST['dsn_woo_powerall_cron_action'])) {
            return;
        }

        if (!check_admin_referer('dsn_woo_powerall_daily_sync_cron_action')) {
            return;
        }

        if (sanitize_key(wp_unslash($_POST['dsn_woo_powerall_cron_action'])) !== 'repair_daily_sync') {
            return;
        }

        $hook = self::DAILY_SYNC_HOOK;
        $current_schedule = wp_get_schedule($hook);

        if ($current_schedule && $current_schedule !== 'daily') {
            wp_clear_scheduled_hook($hook);
            $current_schedule = false;
        }

        if (!$current_schedule) {
            $scheduled = wp_schedule_event(time() + MINUTE_IN_SECONDS, 'daily', $hook);

            if (!$scheduled) {
                $this->logger->error('Daily product sync cron repair failed: WordPress did not schedule the event.');
                add_settings_error(
                    'dsn_woo_powerall_messages',
                    'dsn_woo_powerall_daily_sync_cron_error',
                    __('Daily sync cron could not be scheduled. Check the log for details.', 'dsn-woo-powerall'),
                    'error'
                );
                return;
            }
        }

        $next_run = wp_next_scheduled($hook);
        $this->logger->info(sprintf(
            'Daily product sync cron repair requested from admin. Schedule: %s | Next run: %s UTC | WP cron disabled: %s',
            wp_get_schedule($hook) ?: 'unknown',
            $next_run ? gmdate('Y-m-d H:i:s', $next_run) : 'not scheduled',
            (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? 'yes' : 'no'
        ));

        add_settings_error(
            'dsn_woo_powerall_messages',
            'dsn_woo_powerall_daily_sync_cron_repaired',
            __('Daily sync cron is scheduled. If this site uses server cron, make sure the server is calling wp-cron.php.', 'dsn-woo-powerall'),
            'updated'
        );
    }

    /**
     * Get the Log Viewer page URL.
     *
     * @return string
     */
    private function get_log_page_url() {
        return add_query_arg(array('page' => 'dsn-woo-powerall-logs'), admin_url('admin.php'));
    }

    /**
     * Render section info.
     *
     * @return void
     */
    public function render_section_info() {
        echo '<p>' . esc_html__('Configure your Powerall CRM API settings below. You can find these settings in your Powerall CRM account.', 'dsn-woo-powerall') . '</p>';
    }

    /**
     * Render tenant name field.
     *
     * @return void
     */
    public function render_tenant_name_field() {
        $tenant_name = get_option('dsn_woo_powerall_tenant_name');
        ?>
        <input type="text"
               id="dsn_woo_powerall_tenant_name"
               name="dsn_woo_powerall_tenant_name"
               value="<?php echo esc_attr($tenant_name); ?>"
               class="regular-text">
        <p class="description">
            <?php esc_html_e('Enter your Powerall CRM tenant name. This is your organization identifier in Powerall CRM.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render token field.
     *
     * @return void
     */
    public function render_token_field() {
        $token = get_option('dsn_woo_powerall_token');
        ?>
        <input type="password"
               id="dsn_woo_powerall_token"
               name="dsn_woo_powerall_token"
               value="<?php echo esc_attr($token); ?>"
               class="regular-text">
        <p class="description">
            <?php esc_html_e('Enter your Powerall CRM API token. You can generate this in your Powerall CRM account settings.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render sync frequency field.
     *
     * @return void
     */
    public function render_sync_frequency_field() {
        $sync_frequency = get_option('dsn_woo_powerall_sync_frequency', 'daily');
        ?>
        <select id="dsn_woo_powerall_sync_frequency" name="dsn_woo_powerall_sync_frequency">
            <option value="hourly" <?php selected($sync_frequency, 'hourly'); ?>><?php esc_html_e('Hourly', 'dsn-woo-powerall'); ?></option>
            <option value="twicedaily" <?php selected($sync_frequency, 'twicedaily'); ?>><?php esc_html_e('Twice Daily', 'dsn-woo-powerall'); ?></option>
            <option value="daily" <?php selected($sync_frequency, 'daily'); ?>><?php esc_html_e('Daily', 'dsn-woo-powerall'); ?></option>
        </select>
        <p class="description">
            <?php esc_html_e('How often should the plugin sync products with Powerall CRM?', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render use sale price field.
     *
     * @return void
     */
    public function render_use_sale_price_field() {
        $use_sale_price = get_option('dsn_woo_powerall_use_sale_price', '1');
        ?>
        <input type="checkbox"
               id="dsn_woo_powerall_use_sale_price"
               name="dsn_woo_powerall_use_sale_price"
               value="1"
               <?php checked(1, $use_sale_price, true); ?>>
        <p class="description">
            <?php esc_html_e('Enable to use the sale price from Powerall CRM.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render stock tracking mode field.
     *
     * @return void
     */
    public function render_stock_tracking_mode_field() {
        $stock_mode = Stock_Helper::get_selected_mode();
        ?>
        <select id="dsn_woo_powerall_stock_tracking_mode" name="dsn_woo_powerall_stock_tracking_mode">
            <?php foreach (Stock_Helper::get_available_modes() as $mode_key => $label) : ?>
                <option value="<?php echo esc_attr($mode_key); ?>" <?php selected($stock_mode, $mode_key); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e('Choose which stock type from Powerall to use for WooCommerce inventory. The default behavior sums FreeStock across every Powerall location.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render sync batch size field.
     *
     * @return void
     */
    public function render_sync_batch_size_field() {
        $batch_size = Product_Sync::get_batch_size();
        ?>
        <input type="number"
               id="dsn_woo_powerall_sync_batch_size"
               name="dsn_woo_powerall_sync_batch_size"
               value="<?php echo esc_attr($batch_size); ?>"
               class="small-text"
               min="1"
               max="200"
               step="1">
        <p class="description">
            <?php esc_html_e('How many Powerall products should be processed per request. Lower this if your hosting is timing out.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render sync batch delay field.
     *
     * @return void
     */
    public function render_sync_batch_delay_field() {
        $delay_seconds = Product_Sync::get_batch_delay_seconds();
        ?>
        <input type="number"
               id="dsn_woo_powerall_sync_batch_delay"
               name="dsn_woo_powerall_sync_batch_delay"
               value="<?php echo esc_attr($delay_seconds); ?>"
               class="small-text"
               min="0"
               max="30"
               step="1">
        <p class="description">
            <?php esc_html_e('Pause this many seconds between batches. This is used for both manual progress syncs and the standard sync loop.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render frontend stock enabled toggle field.
     *
     * @return void
     */
    public function render_frontend_stock_enabled_field() {
        $enabled = get_option('dsn_woo_powerall_frontend_stock_enabled', '');
        ?>
        <input type="checkbox"
               id="dsn_woo_powerall_frontend_stock_enabled"
               name="dsn_woo_powerall_frontend_stock_enabled"
               value="1"
               <?php checked(1, $enabled); ?>>
        <p class="description">
            <?php esc_html_e('Enable the [dsn_powerall_stock] shortcode and load its assets on the frontend.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render frontend stock display mode field.
     *
     * @return void
     */
    public function render_frontend_stock_display_field() {
        $mode = get_option('dsn_woo_powerall_frontend_stock_display', 'combined');
        ?>
        <select id="dsn_woo_powerall_frontend_stock_display" name="dsn_woo_powerall_frontend_stock_display">
            <option value="combined" <?php selected($mode, 'combined'); ?>>
                <?php esc_html_e('Combined (single total)', 'dsn-woo-powerall'); ?>
            </option>
            <option value="per_warehouse" <?php selected($mode, 'per_warehouse'); ?>>
                <?php esc_html_e('Per warehouse (list with location names)', 'dsn-woo-powerall'); ?>
            </option>
        </select>
        <p class="description">
            <?php esc_html_e('Combined shows the total stock number. Per warehouse lists each Powerall location individually — if there are more than 3 locations a "Show more" link will open a modal with all of them.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render auto display position field.
     *
     * @return void
     */
    public function render_frontend_stock_position_field() {
        $position = get_option('dsn_woo_powerall_frontend_stock_position', 'disabled');
        $options  = array(
            'disabled'           => __('Disabled (shortcode only)', 'dsn-woo-powerall'),
            'below_title'        => __('Below the product title', 'dsn-woo-powerall'),
            'after_price'        => __('After the price', 'dsn-woo-powerall'),
            'before_add_to_cart' => __('Before the Add to Cart button', 'dsn-woo-powerall'),
        );
        ?>
        <select id="dsn_woo_powerall_frontend_stock_position" name="dsn_woo_powerall_frontend_stock_position">
            <?php foreach ($options as $value => $label) : ?>
            <option value="<?php echo esc_attr($value); ?>" <?php selected($position, $value); ?>>
                <?php echo esc_html($label); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e('Automatically inject the stock widget into single product pages at the chosen position. Select "Disabled" to rely solely on the [dsn_powerall_stock] shortcode.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Render the searchable multi-select (pill) for warehouse locations to ignore.
     *
     * @return void
     */
    public function render_warehouse_inclusion_field() {
        $known       = Stock_Helper::get_known_warehouses();
        $excluded    = Stock_Helper::get_excluded_warehouses();
        $option      = Stock_Helper::EXCLUDED_WAREHOUSES_OPTION;
        $placeholder = __('Search locations to ignore…', 'dsn-woo-powerall');
        ?>
        <input type="hidden" name="<?php echo esc_attr($option); ?>[__present]" value="1">
        <?php if (empty($known)) : ?>
            <p><em><?php esc_html_e('No warehouse locations discovered yet. Run a sync or click "Refresh warehouse list" below to scan existing product data.', 'dsn-woo-powerall'); ?></em></p>
        <?php else : ?>
            <div class="dsn-picker" id="dsn-excluded-warehouses-picker">
                <select id="dsn-excluded-warehouses"
                        class="dsn-picker__native"
                        name="<?php echo esc_attr($option); ?>[excluded][]"
                        multiple="multiple"
                        data-placeholder="<?php echo esc_attr($placeholder); ?>">
                    <?php foreach ($known as $name) : ?>
                        <option value="<?php echo esc_attr($name); ?>" <?php selected(in_array($name, $excluded, true)); ?>>
                            <?php echo esc_html($name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <p class="description">
            <?php esc_html_e('Select the Powerall locations you want to ignore. Selected locations are removed from the WooCommerce stock total and hidden on the per-warehouse frontend display. Locations are identified by their Powerall Description. Use the "Refresh warehouse list" button below to rescan after new locations appear.', 'dsn-woo-powerall'); ?>
        </p>
        <?php
    }

    /**
     * Sanitize the excluded-warehouses submission.
     * Only names that match a currently-known warehouse are accepted.
     *
     * @param mixed $value
     * @return array<int, string>
     */
    public function sanitize_excluded_warehouses($value) {
        // When no form sentinel is present (e.g. REST or programmatic update with
        // a plain array), accept a flat array of warehouse name strings directly.
        if (is_array($value) && !isset($value['__present'])) {
            // If every element is a string it is already a flat exclusion list.
            $all_strings = array_reduce($value, function ($carry, $item) {
                return $carry && is_string($item);
            }, true);
            if ($all_strings) {
                return array_values(array_unique(array_filter(array_map('trim', $value), function ($v) {
                    return $v !== '';
                })));
            }
            // Not a flat list and no sentinel — keep previous value.
            return Stock_Helper::get_excluded_warehouses();
        }

        if (!is_array($value)) {
            return Stock_Helper::get_excluded_warehouses();
        }

        // Standard form POST: $value = ['__present' => '1', 'excluded' => [...]]
        $excluded = isset($value['excluded']) && is_array($value['excluded'])
            ? array_values(array_unique(array_filter(array_map(function ($v) {
                return trim((string) $v);
            }, $value['excluded']), function ($v) {
                return $v !== '';
            })))
            : array();

        $this->logger->info('Saving excluded warehouses: ' . wp_json_encode($excluded));

        return $excluded;
    }

    /**
     * Sanitize frontend stock display mode.
     *
     * @param string $value
     * @return string
     */
    public function sanitize_frontend_stock_display($value) {
        return in_array($value, array('combined', 'per_warehouse'), true) ? $value : 'combined';
    }

    /**
     * Sanitize auto display position.
     *
     * @param string $value
     * @return string
     */
    public function sanitize_frontend_stock_position($value) {
        $valid = array('disabled', 'below_title', 'after_price', 'before_add_to_cart');
        return in_array($value, $valid, true) ? $value : 'disabled';
    }

    /**
     * Sanitize stock tracking mode.
     *
     * @param string $value
     * @return string
     */
    public function sanitize_stock_tracking_mode($value) {
        return Stock_Helper::normalize_mode($value);
    }

    /**
     * Sanitize sync batch size.
     *
     * @param mixed $value
     * @return int
     */
    public function sanitize_sync_batch_size($value) {
        $value = absint($value);

        return min(200, max(1, $value ?: Product_Sync::DEFAULT_BATCH_SIZE));
    }

    /**
     * Sanitize sync batch delay.
     *
     * @param mixed $value
     * @return int
     */
    public function sanitize_sync_batch_delay($value) {
        $value = absint($value);

        return min(30, max(0, $value));
    }

    /**
     * Render the dedicated sync progress page.
     *
     * @return void
     */
    private function render_sync_progress_page() {
        $auto_start = isset($_GET['start']) ? '1' : '0';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Product Sync Progress', 'dsn-woo-powerall'); ?></h1>
            <?php $this->render_nav_tabs('tools'); ?>
            <p><?php echo esc_html(sprintf(__('Products are processed in batches of %1$d with a %2$d second pause between batches to reduce timeout risk.', 'dsn-woo-powerall'), Product_Sync::get_batch_size(), Product_Sync::get_batch_delay_seconds())); ?></p>

            <div id="dsn-woo-powerall-sync-app" data-auto-start="<?php echo esc_attr($auto_start); ?>" style="max-width: 920px;">
                <div style="background:#fff; border:1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding:24px;">
                    <p>
                        <button type="button" class="button button-primary" id="dsn-woo-powerall-start-sync"><?php esc_html_e('Start Sync', 'dsn-woo-powerall'); ?></button>
                    </p>

                    <div style="background:#f0f0f1; width:100%; height:18px; border-radius:4px; overflow:hidden;">
                        <div class="dsn-sync-progress-bar" style="width:0%; height:100%; background:#2271b1;"></div>
                    </div>

                    <p class="dsn-sync-status" style="margin:12px 0 0;"><?php esc_html_e('Idle', 'dsn-woo-powerall'); ?></p>
                    <p class="dsn-sync-last-message" style="margin:8px 0 0; color:#50575e;"><?php esc_html_e('Manual sync has not been started yet.', 'dsn-woo-powerall'); ?></p>

                    <table class="widefat striped" style="margin-top:20px;">
                        <tbody>
                            <tr>
                                <td><strong><?php esc_html_e('Run ID', 'dsn-woo-powerall'); ?></strong></td>
                                <td class="dsn-sync-run-id">-</td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Batch', 'dsn-woo-powerall'); ?></strong></td>
                                <td><span class="dsn-sync-current-batch">0</span> / <span class="dsn-sync-total-batches">0</span></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Products', 'dsn-woo-powerall'); ?></strong></td>
                                <td>
                                    <span class="dsn-sync-processed">0</span> / <span class="dsn-sync-total">0</span>
                                    <?php esc_html_e('processed', 'dsn-woo-powerall'); ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Results', 'dsn-woo-powerall'); ?></strong></td>
                                <td>
                                    <?php esc_html_e('Updated:', 'dsn-woo-powerall'); ?> <span class="dsn-sync-updated">0</span>
                                    |
                                    <?php esc_html_e('Unchanged:', 'dsn-woo-powerall'); ?> <span class="dsn-sync-synced">0</span>
                                    |
                                    <?php esc_html_e('Skipped:', 'dsn-woo-powerall'); ?> <span class="dsn-sync-skipped">0</span>
                                    |
                                    <?php esc_html_e('Failed:', 'dsn-woo-powerall'); ?> <span class="dsn-sync-failed">0</span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Batch Size / Delay', 'dsn-woo-powerall'); ?></strong></td>
                                <td><span class="dsn-sync-batch-size"><?php echo esc_html(Product_Sync::get_batch_size()); ?></span> / <span class="dsn-sync-delay"><?php echo esc_html(Product_Sync::get_batch_delay_seconds()); ?></span>s</td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Last Product', 'dsn-woo-powerall'); ?></strong></td>
                                <td class="dsn-sync-last-product">-</td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Started / Completed', 'dsn-woo-powerall'); ?></strong></td>
                                <td><span class="dsn-sync-started-at">-</span> / <span class="dsn-sync-completed-at">-</span></td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="dsn-sync-errors" style="display:none; margin-top:20px;">
                        <h2 style="margin:0 0 8px;"><?php esc_html_e('Recent Errors', 'dsn-woo-powerall'); ?></h2>
                        <ul style="margin:0; padding-left:18px;"></ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render tabs for the recent log days.
     *
     * @param array<int, array{date: string, label: string}> $log_days
     * @param string $selected_day
     * @return void
     */
    private function render_log_day_tabs(array $log_days, $selected_day) {
        ?>
        <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Log days', 'dsn-woo-powerall'); ?>">
            <?php foreach ($log_days as $day) : ?>
                <?php
                $is_active = $day['date'] === $selected_day;
                $url = add_query_arg(
                    array(
                        'page' => 'dsn-woo-powerall-logs',
                        'log_day' => $day['date'],
                    ),
                    admin_url('admin.php')
                );
                ?>
                <a class="nav-tab <?php echo $is_active ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($url); ?>">
                    <?php echo esc_html($day['label']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /**
     * Get the recent log days shown as tabs.
     *
     * @return array<int, array{date: string, label: string}>
     */
    private function get_recent_log_days() {
        $days = array();
        $now = current_time('timestamp');

        for ($i = 0; $i < self::LOG_RETENTION_DAYS; $i++) {
            $timestamp = $now - ($i * DAY_IN_SECONDS);
            $date = wp_date('Y-m-d', $timestamp);
            $days[] = array(
                'date' => $date,
                'label' => $i === 0 ? __('Today', 'dsn-woo-powerall') : wp_date('M j', $timestamp),
            );
        }

        return $days;
    }

    /**
     * Get the contents of the log file for a selected day, newest first.
     *
     * @param string $selected_day
     * @return string
     */
    private function get_log_contents($selected_day) {
        $log_file = $this->logger->get_log_file_path();

        if (!file_exists($log_file)) {
            return __('No log entries found.', 'dsn-woo-powerall');
        }

        $contents = file_get_contents($log_file);
        if ($contents === false) {
            return __('Unable to read the log file.', 'dsn-woo-powerall');
        }

        $entries = $this->split_log_entries($contents);
        if (empty($entries)) {
            return __('No log entries found.', 'dsn-woo-powerall');
        }

        $entries_for_day = array_filter($entries, function ($entry) use ($selected_day) {
            return $this->get_log_entry_date($entry) === $selected_day;
        });

        if (empty($entries_for_day)) {
            return sprintf(
                /* translators: %s: log date */
                __('No log entries found for %s.', 'dsn-woo-powerall'),
                $selected_day
            );
        }

        return implode("\n", array_reverse(array_map('trim', $entries_for_day)));
    }

    /**
     * Keep only the configured number of recent days in the log file.
     *
     * @return void
     */
    private function prune_log_file_to_recent_days() {
        $log_file = $this->logger->get_log_file_path();

        if (!file_exists($log_file)) {
            return;
        }

        $contents = file_get_contents($log_file);
        if ($contents === false || trim($contents) === '') {
            return;
        }

        $entries = $this->split_log_entries($contents);
        if (empty($entries)) {
            return;
        }

        $recent_days = wp_list_pluck($this->get_recent_log_days(), 'date');
        $retained_entries = array_filter($entries, function ($entry) use ($recent_days) {
            return in_array($this->get_log_entry_date($entry), $recent_days, true);
        });

        if (count($retained_entries) === count($entries)) {
            return;
        }

        file_put_contents($log_file, implode("\n", array_map('trim', $retained_entries)) . "\n");
    }

    /**
     * Split raw log contents into timestamped entries.
     *
     * @param string $contents
     * @return array<int, string>
     */
    private function split_log_entries($contents) {
        $entries = preg_split('/(?=^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \[[A-Z]+\])/m', trim($contents), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($entries) ? $entries : array();
    }

    /**
     * Get the date portion from a log entry timestamp.
     *
     * @param string $entry
     * @return string
     */
    private function get_log_entry_date($entry) {
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2}) /', $entry, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Get the main settings page URL.
     *
     * @return string
     */
    private function get_settings_page_url() {
        return admin_url('admin.php?page=dsn-woo-powerall');
    }

    /**
     * Get the sync progress page URL.
     *
     * @param bool $auto_start
     * @return string
     */
    private function get_sync_progress_url($auto_start = false) {
        $args = array(
            'page' => 'dsn-woo-powerall-tools',
            'view' => 'sync-progress',
        );

        if ($auto_start) {
            $args['start'] = 1;
        }

        return add_query_arg($args, admin_url('admin.php'));
    }

    /**
     * Get the Tools page URL.
     *
     * @return string
     */
    private function get_tools_page_url() {
        return add_query_arg(array('page' => 'dsn-woo-powerall-tools'), admin_url('admin.php'));
    }

    /**
     * Render the WP-style nav tab bar shared across the plugin's admin screens.
     *
     * @param string $active One of: settings, tools, logs.
     * @return void
     */
    private function render_nav_tabs($active) {
        $tabs = array(
            'settings' => array(
                'label' => __('Settings', 'dsn-woo-powerall'),
                'url'   => $this->get_settings_page_url(),
            ),
            'tools'    => array(
                'label' => __('Tools', 'dsn-woo-powerall'),
                'url'   => $this->get_tools_page_url(),
            ),
            'logs'     => array(
                'label' => __('Logs', 'dsn-woo-powerall'),
                'url'   => $this->get_log_page_url(),
            ),
        );
        ?>
        <h2 class="nav-tab-wrapper">
            <?php foreach ($tabs as $key => $tab) :
                $classes = 'nav-tab' . ($active === $key ? ' nav-tab-active' : '');
                ?>
                <a href="<?php echo esc_url($tab['url']); ?>" class="<?php echo esc_attr($classes); ?>">
                    <?php echo esc_html($tab['label']); ?>
                </a>
            <?php endforeach; ?>
        </h2>
        <?php
    }

    /**
     * Recalculate WooCommerce stock for every product that has Powerall warehouse
     * data whenever the excluded-warehouses list is changed.
     *
     * Fires on `update_option_{option}` so it runs automatically after the
     * settings page save.
     *
     * @param array $old_value Previous exclusion list.
     * @param array $new_value Updated exclusion list.
     * @return void
     */
    public function recalculate_stock_after_exclusion_change($old_value, $new_value) {
        // Normalize both to arrays for a reliable comparison.
        $old = is_array($old_value) ? $old_value : array();
        $new = is_array($new_value) ? $new_value : array();

        sort($old);
        sort($new);

        if ($old === $new) {
            return;
        }

        $this->logger->info('Excluded warehouses changed — recalculating product stock.');

        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_powerall_stock_warehouses'"
        );

        if (empty($rows)) {
            $this->logger->info('No products with warehouse data found. Nothing to recalculate.');
            return;
        }

        $updated = 0;

        foreach ($rows as $row) {
            $product_id = (int) $row->post_id;
            $warehouses = json_decode($row->meta_value, true);

            if (!is_array($warehouses) || empty($warehouses)) {
                continue;
            }

            $new_stock = Stock_Helper::format_stock_quantity(
                Stock_Helper::calculate_total_stock($warehouses)
            );
            $new_stock_status = floatval($new_stock) > 0 ? 'instock' : 'outofstock';

            // Use the WooCommerce product API so the inventory tab, HPOS,
            // and all WC caches are properly updated.
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            $product->set_manage_stock(true);
            $product->set_stock_quantity($new_stock);
            $product->set_stock_status($new_stock_status);
            $product->save();

            // Sync stock to WPML translations if present.
            $trid = apply_filters('wpml_element_trid', null, $product_id, 'post_product');
            if ($trid) {
                $translations = apply_filters('wpml_get_element_translations', null, $trid, 'post_product');
                if (is_array($translations)) {
                    foreach ($translations as $translation) {
                        $translated_id = (int) ($translation->element_id ?? 0);
                        if (!$translated_id || $translated_id === $product_id) {
                            continue;
                        }
                        $translated_product = wc_get_product($translated_id);
                        if (!$translated_product) {
                            continue;
                        }
                        $translated_product->set_manage_stock(true);
                        $translated_product->set_stock_quantity($new_stock);
                        $translated_product->set_stock_status($new_stock_status);
                        $translated_product->save();
                    }
                }
            }

            $updated++;
        }

        $this->logger->info(sprintf('Stock recalculation complete. Updated %d product(s).', $updated));
    }
}
