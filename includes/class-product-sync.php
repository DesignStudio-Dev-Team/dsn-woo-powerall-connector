<?php
namespace DSNWooPowerall;

class Product_Sync {
    public const MANUAL_SYNC_STATE_OPTION = 'dsn_woo_powerall_manual_sync_state';
    public const CRON_SYNC_STATE_OPTION = 'dsn_woo_powerall_cron_sync_state';
    public const DEFAULT_BATCH_SIZE = 25;
    public const DEFAULT_BATCH_DELAY = 1;
    private const MAX_STORED_ERRORS = 5;

    /**
     * Logger instance
     *
     * @var Logger
     */
    private $logger;

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
        $this->logger = new \DSNWooPowerall\Logger();
    }

    /**
     * Get the configured batch size for sync requests.
     *
     * @return int
     */
    public static function get_batch_size() {
        $batch_size = absint(get_option('dsn_woo_powerall_sync_batch_size', self::DEFAULT_BATCH_SIZE));

        return min(200, max(1, $batch_size));
    }

    /**
     * Get the configured delay between sync batches in seconds.
     *
     * @return int
     */
    public static function get_batch_delay_seconds() {
        $delay_seconds = absint(get_option('dsn_woo_powerall_sync_batch_delay', self::DEFAULT_BATCH_DELAY));

        return min(30, max(0, $delay_seconds));
    }

    /**
     * Sync products from Powerall CRM to WooCommerce.
     *
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function sync_products() {
        $this->logger->info('Starting product sync from Powerall CRM');

        $product_list = $this->get_product_list();
        if (is_wp_error($product_list)) {
            $this->logger->error('Failed to fetch products: ' . $product_list->get_error_message());
            return $product_list;
        }

        $summary = $this->process_product_list_in_batches(
            $product_list,
            self::get_batch_size(),
            self::get_batch_delay_seconds()
        );

        $this->logger->info(sprintf(
            'Product sync completed. Processed: %d Updated: %d Unchanged: %d Skipped: %d Failed: %d',
            $summary['processed'],
            $summary['updated'],
            $summary['synced'],
            $summary['skipped'],
            $summary['failed']
        ));

        return true;
    }

    /**
     * Start a manual sync run that can be processed in AJAX batches.
     *
     * @return array|WP_Error
     */
    public function start_manual_sync_run() {
        $previous_state = $this->read_manual_sync_state();
        if (!empty($previous_state['run_id'])) {
            $this->delete_manual_sync_cache($previous_state['run_id']);
        }

        $product_list = $this->get_product_list();
        if (is_wp_error($product_list)) {
            $this->logger->error('Unable to start manual sync: ' . $product_list->get_error_message());
            return $product_list;
        }

        $run_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('dsn_sync_', true);
        $cache_result = $this->store_manual_sync_cache($run_id, $product_list);
        if (is_wp_error($cache_result)) {
            $this->logger->error('Unable to cache manual sync payload: ' . $cache_result->get_error_message());
            return $cache_result;
        }

        $state = array_merge($this->get_default_manual_sync_state(), array(
            'run_id' => $run_id,
            'status' => count($product_list) > 0 ? 'running' : 'completed',
            'started_at' => current_time('mysql'),
            'completed_at' => count($product_list) > 0 ? '' : current_time('mysql'),
            'batch_size' => self::get_batch_size(),
            'delay_seconds' => self::get_batch_delay_seconds(),
            'total_products' => count($product_list),
            'last_message' => count($product_list) > 0
                ? __('Manual sync queued. Processing will begin shortly.', 'dsn-woo-powerall')
                : __('No products were returned by Powerall.', 'dsn-woo-powerall'),
        ));

        $this->save_manual_sync_state($state);
        $this->logger->info('Manual product sync started. Run ID: ' . $run_id . ' | Products: ' . $state['total_products']);

        if ($state['status'] === 'completed') {
            $this->delete_manual_sync_cache($run_id);
        }

        return $this->prepare_manual_sync_state_response($state);
    }

    /**
     * Process the next manual sync batch for a run.
     *
     * @param string $run_id
     * @return array|WP_Error
     */
    public function process_manual_sync_batch($run_id) {
        $state = $this->read_manual_sync_state();

        if (empty($state['run_id']) || empty($run_id)) {
            return new \WP_Error('manual_sync_missing', __('No manual sync run was found.', 'dsn-woo-powerall'));
        }

        if ($state['run_id'] !== $run_id) {
            return new \WP_Error('manual_sync_mismatch', __('The requested sync run no longer matches the saved sync state.', 'dsn-woo-powerall'));
        }

        if ($state['status'] === 'completed') {
            return $this->prepare_manual_sync_state_response($state);
        }

        $offset = max(0, intval($state['current_offset']));
        $total_products = max(0, intval($state['total_products']));

        if ($total_products === 0 || $offset >= $total_products) {
            $state = $this->complete_manual_sync_state($state, __('Product sync completed.', 'dsn-woo-powerall'));
            return $this->prepare_manual_sync_state_response($state);
        }

        $batch_size = max(1, intval($state['batch_size']));
        $batch = $this->get_manual_sync_batch_from_cache($run_id, $offset, $batch_size);
        if (is_wp_error($batch)) {
            $this->logger->error('Unable to load manual sync batch: ' . $batch->get_error_message());
            return $batch;
        }

        if (empty($batch)) {
            $state = $this->complete_manual_sync_state(
                $state,
                __('Product sync completed, but the cached batch data ended sooner than expected.', 'dsn-woo-powerall')
            );
            return $this->prepare_manual_sync_state_response($state);
        }

        $batch_summary = $this->process_product_batch($batch);

        $state['processed'] += $batch_summary['processed'];
        $state['updated'] += $batch_summary['updated'];
        $state['synced'] += $batch_summary['synced'];
        $state['skipped'] += $batch_summary['skipped'];
        $state['failed'] += $batch_summary['failed'];
        $state['current_offset'] = min($total_products, $offset + count($batch));

        if (!empty($batch_summary['last_result'])) {
            $state['last_sku'] = $batch_summary['last_result']['sku'] ?? '';
            $state['last_product_name'] = $batch_summary['last_result']['product_name'] ?? '';
        }

        if (!empty($batch_summary['errors'])) {
            $state['recent_errors'] = array_slice(
                array_merge($state['recent_errors'], $batch_summary['errors']),
                -self::MAX_STORED_ERRORS
            );
        }

        if ($state['current_offset'] >= $total_products) {
            $state = $this->complete_manual_sync_state($state, __('Product sync completed.', 'dsn-woo-powerall'));
        } else {
            $state['status'] = 'running';
            $state['last_message'] = sprintf(
                __('Processed %1$d of %2$d products.', 'dsn-woo-powerall'),
                $state['processed'],
                $total_products
            );
            $this->save_manual_sync_state($state);
        }

        return $this->prepare_manual_sync_state_response($state, array(
            'batch_processed' => $batch_summary['processed'],
            'batch_updated' => $batch_summary['updated'],
            'batch_synced' => $batch_summary['synced'],
            'batch_skipped' => $batch_summary['skipped'],
            'batch_failed' => $batch_summary['failed'],
        ));
    }

    /**
     * Get the current manual sync state.
     *
     * @return array
     */
    public function get_manual_sync_state() {
        return $this->prepare_manual_sync_state_response($this->read_manual_sync_state());
    }

    /**
     * Start the daily cron sync as a persisted batch run.
     *
     * @return array|WP_Error
     */
    public function start_cron_sync_run() {
        $previous_state = $this->read_cron_sync_state();
        if (!empty($previous_state['run_id']) && $previous_state['status'] === 'running') {
            $this->logger->warning('Daily cron product sync start skipped because a cron sync is already running. Run ID: ' . $previous_state['run_id']);
            return $this->prepare_manual_sync_state_response($previous_state);
        }

        if (!empty($previous_state['run_id'])) {
            $this->delete_manual_sync_cache($previous_state['run_id']);
        }

        $product_list = $this->get_product_list();
        if (is_wp_error($product_list)) {
            $this->logger->error('Unable to start daily cron product sync: ' . $product_list->get_error_message());
            return $product_list;
        }

        $run_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('dsn_cron_sync_', true);
        $cache_result = $this->store_manual_sync_cache($run_id, $product_list);
        if (is_wp_error($cache_result)) {
            $this->logger->error('Unable to cache daily cron sync payload: ' . $cache_result->get_error_message());
            return $cache_result;
        }

        $batch_size = self::DEFAULT_BATCH_SIZE;
        $state = array_merge($this->get_default_manual_sync_state(), array(
            'run_id' => $run_id,
            'status' => count($product_list) > 0 ? 'running' : 'completed',
            'started_at' => current_time('mysql'),
            'completed_at' => count($product_list) > 0 ? '' : current_time('mysql'),
            'batch_size' => $batch_size,
            'delay_seconds' => max(1, self::get_batch_delay_seconds()),
            'total_products' => count($product_list),
            'last_message' => count($product_list) > 0
                ? __('Daily cron sync queued. Processing batches will begin shortly.', 'dsn-woo-powerall')
                : __('No products were returned by Powerall.', 'dsn-woo-powerall'),
        ));

        $this->save_cron_sync_state($state);
        $this->logger->info('Daily cron product sync started. Run ID: ' . $run_id . ' | Products: ' . $state['total_products'] . ' | Batch size: ' . $state['batch_size']);

        if ($state['status'] === 'completed') {
            $this->delete_manual_sync_cache($run_id);
        }

        return $this->prepare_manual_sync_state_response($state);
    }

    /**
     * Process the next daily cron sync batch.
     *
     * @return array|WP_Error
     */
    public function process_cron_sync_batch() {
        $state = $this->read_cron_sync_state();

        if (empty($state['run_id'])) {
            return new \WP_Error('cron_sync_missing', __('No daily cron sync run was found.', 'dsn-woo-powerall'));
        }

        if ($state['status'] === 'completed') {
            return $this->prepare_manual_sync_state_response($state);
        }

        $offset = max(0, intval($state['current_offset']));
        $total_products = max(0, intval($state['total_products']));

        if ($total_products === 0 || $offset >= $total_products) {
            $state = $this->complete_cron_sync_state($state, __('Daily cron product sync completed.', 'dsn-woo-powerall'));
            return $this->prepare_manual_sync_state_response($state);
        }

        $batch_size = max(1, intval($state['batch_size']));
        $batch = $this->get_manual_sync_batch_from_cache($state['run_id'], $offset, $batch_size);
        if (is_wp_error($batch)) {
            $this->logger->error('Unable to load daily cron sync batch: ' . $batch->get_error_message());
            return $batch;
        }

        if (empty($batch)) {
            $state = $this->complete_cron_sync_state(
                $state,
                __('Daily cron product sync completed, but the cached batch data ended sooner than expected.', 'dsn-woo-powerall')
            );
            return $this->prepare_manual_sync_state_response($state);
        }

        $batch_number = (int) floor($offset / $batch_size) + 1;
        $this->logger->info(sprintf(
            'Daily cron product sync processing batch %d. Offset: %d | Count: %d | Total: %d',
            $batch_number,
            $offset,
            count($batch),
            $total_products
        ));

        $batch_summary = $this->process_product_batch($batch);

        $state['processed'] += $batch_summary['processed'];
        $state['updated'] += $batch_summary['updated'];
        $state['synced'] += $batch_summary['synced'];
        $state['skipped'] += $batch_summary['skipped'];
        $state['failed'] += $batch_summary['failed'];
        $state['current_offset'] = min($total_products, $offset + count($batch));

        if (!empty($batch_summary['last_result'])) {
            $state['last_sku'] = $batch_summary['last_result']['sku'] ?? '';
            $state['last_product_name'] = $batch_summary['last_result']['product_name'] ?? '';
        }

        if (!empty($batch_summary['errors'])) {
            $state['recent_errors'] = array_slice(
                array_merge($state['recent_errors'], $batch_summary['errors']),
                -self::MAX_STORED_ERRORS
            );
        }

        if ($state['current_offset'] >= $total_products) {
            $state = $this->complete_cron_sync_state($state, __('Daily cron product sync completed.', 'dsn-woo-powerall'));
        } else {
            $state['status'] = 'running';
            $state['last_message'] = sprintf(
                __('Daily cron sync processed %1$d of %2$d products.', 'dsn-woo-powerall'),
                $state['processed'],
                $total_products
            );
            $this->save_cron_sync_state($state);
        }

        return $this->prepare_manual_sync_state_response($state, array(
            'batch_processed' => $batch_summary['processed'],
            'batch_updated' => $batch_summary['updated'],
            'batch_synced' => $batch_summary['synced'],
            'batch_skipped' => $batch_summary['skipped'],
            'batch_failed' => $batch_summary['failed'],
        ));
    }

    /**
     * Discard a failed cron run so a new product-list fetch can start cleanly.
     *
     * @return void
     */
    public function reset_cron_sync_run() {
        $state = $this->read_cron_sync_state();

        if (!empty($state['run_id'])) {
            $this->delete_manual_sync_cache($state['run_id']);
        }

        delete_option(self::CRON_SYNC_STATE_OPTION);
        $this->logger->warning('Daily cron product sync state was reset so a fresh run can be started.');
    }

    /**
     * Check whether a persisted daily cron sync still needs processing.
     *
     * @return bool
     */
    public function has_running_cron_sync_run() {
        $state = $this->read_cron_sync_state();

        return !empty($state['run_id']) && $state['status'] === 'running';
    }

    /**
     * Sync a single product from Powerall CRM to WooCommerce.
     *
     * @param array $product_data Product data from Powerall CRM
     * @return array<string, mixed>
     */
    private function sync_single_product($product_data) {
        $ean          = isset($product_data['EanCode']) ? trim((string) $product_data['EanCode']) : '';
        $product_code = isset($product_data['ProductCode']) ? trim((string) $product_data['ProductCode']) : '';
        $product_name = isset($product_data['Description1']) ? (string) $product_data['Description1'] : '';

        // Lookup order: Powerall ProductCode first, then EanCode as fallback.
        // Store owners typically use ProductCode as the Woo SKU; EanCode (barcode)
        // is sometimes empty on Powerall records or mismatched, which previously
        // caused those products to silently skip during sync.
        $sku = $product_code !== '' ? $product_code : $ean;

        $result = array(
            'status' => 'skipped',
            'sku' => $sku,
            'product_code' => $product_code,
            'ean_code' => $ean,
            'product_name' => $product_name,
            'product_id' => 0,
            'message' => '',
            'matched_by' => '',
        );

        if ($product_code === '' && $ean === '') {
            $result['message'] = 'Product missing both ProductCode and EanCode, skipping. ' . $product_name;
            $this->logger->warning($result['message']);
            return $result;
        }

        $this->logger->info('Syncing product. ProductCode: ' . $product_code . ' | EanCode: ' . $ean);

        $match = $this->find_woo_product_for_powerall_record($product_code, $ean);
        if (!$match) {
            $result['message'] = sprintf(
                'No WooCommerce product found for Powerall record (ProductCode: %s, EanCode: %s, Name: %s). Ensure the Woo SKU matches one of these.',
                $product_code !== '' ? $product_code : '-',
                $ean !== '' ? $ean : '-',
                $product_name
            );
            $this->logger->warning($result['message']);
            return $result;
        }

        $product_id          = $match['product_id'];
        $result['product_id'] = $product_id;
        $result['matched_by'] = $match['matched_by'];
        $result['sku']        = $match['matched_value'];

        $result['product_id'] = $product_id;

        // Always persist raw warehouse data for the frontend stock display shortcode,
        // regardless of whether price/stock values changed this run.
        if (!empty($product_data['StockPerWarehouse']) && is_array($product_data['StockPerWarehouse'])) {
            update_post_meta($product_id, '_powerall_stock_warehouses', wp_json_encode($product_data['StockPerWarehouse']));
            Stock_Helper::register_known_warehouses($product_data['StockPerWarehouse']);
        }

        $product = wc_get_product($product_id);

        if (!$product) {
            $error = new \WP_Error('invalid_product', __('Product not found in WooCommerce.', 'dsn-woo-powerall'));
            $result['status'] = 'failed';
            $result['message'] = $error->get_error_message();
            $this->logger->error('Product with ID ' . $product_id . ' not found in WooCommerce.');
            return $result;
        }

        $old_stock = $product->get_stock_quantity();
        $old_stock_status = $product->get_stock_status();
        $old_price = $product->get_sale_price();
        $new_price = $old_price;
        $price_changed = false;
        $manage_stock_changed = !$product->managing_stock();
        $stock_changed = false;
        $stock_status_changed = false;

        $use_powerall_price = get_option('dsn_woo_powerall_use_sale_price', '1');
        if ($use_powerall_price) {
            // Use PromotionalPrice when present and non-zero, otherwise fall back to SalesPrice.
            $promotional_price = $product_data['PromotionalPrice'] ?? '';
            $has_promo_price   = $promotional_price !== '' && $promotional_price !== null && floatval($promotional_price) > 0;

            $new_price = $has_promo_price ? $promotional_price : ($product_data['SalesPrice'] ?? '');
            $price_includes_vat = isset($product_data['SalesPriceIsIncVat'])
                ? filter_var($product_data['SalesPriceIsIncVat'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : null;

            if ($has_promo_price) {
                $this->logger->info('Using PromotionalPrice for SKU ' . $sku . ': ' . $promotional_price);
            }

            if ($price_includes_vat === false && $new_price !== '' && $new_price !== null) {
                $new_price = floatval($new_price) * 1.21;
            }

            if ($new_price !== '' && $new_price !== null) {
                $new_price = round(floatval($new_price), 2);
            }

            $has_console_promo = false;
            $console_id = get_post_meta($product_id, 'console_id', true);

            $this->logger->info('SKU and WordPress IDs: ' . $sku . ': ' . $product_id);
            if ($console_id) {
                $console_path = trailingslashit(WP_CONTENT_DIR) . 'plugins/syndified/website-content/json/Product/' . $console_id . '.json';
                if (file_exists($console_path) && is_readable($console_path)) {
                    $this->logger->info('Found console JSON for product SKU ' . $sku . ': ' . $console_path);
                    $json = file_get_contents($console_path);
                    $data = json_decode($json, true);
                    if (is_array($data)) {
                        if (isset($data['spp']) && $data['spp'] !== '' && $data['spp'] !== null) {
                            $has_console_promo = true;
                            $this->logger->info('Console promo (spp) detected for SKU ' . $sku . ' (console id: ' . $console_id . ')');
                        } elseif (isset($data['prices']['spp']) && $data['prices']['spp'] !== '') {
                            $has_console_promo = true;
                            $this->logger->info('Console promo (prices.spp) detected for SKU ' . $sku . ' (console id: ' . $console_id . ')');
                        }
                    } else {
                        $this->logger->warning('Console JSON for SKU ' . $sku . ' could not be decoded: ' . $console_path);
                    }
                } else {
                    $this->logger->info('No console JSON file found for SKU ' . $sku . ' at expected path: ' . $console_path);
                }
            } else {
                $this->logger->info('No console id found for SKU ' . $sku . ', continuing with sale price logic');
            }

            if (!$has_console_promo && $new_price !== '' && $new_price !== null) {
                $old_price_num = $old_price !== '' && $old_price !== null ? floatval($old_price) : null;
                $new_price_num = floatval($new_price);
                if ($old_price_num === null || abs($old_price_num - $new_price_num) >= 0.0001) {

                    $this->logger->info ('set_sale_price ' . $new_price . ' for SKU ' . $sku);

                    if ($product->get_regular_price () >= $new_price || !$product->get_sale_price()) {

                        delete_post_meta ($product_id, '_regular_price');
                        delete_post_meta ($product_id, '_price');
                        delete_post_meta ($product_id, '_sale_price');
                        $product->set_sale_price('');
                        $product->set_regular_price($new_price); // set your price

                        $product->save();

                        wc_update_product_lookup_tables($product_id);

                        $this->logger->info ('aaa get_regular_price: ' . json_encode ($product->get_regular_price ()));
                        $this->logger->info ('---');


                    } else {
                        $product->set_sale_price($new_price);
                        $product->save();
                        $this->logger->info ('bbb get_regular_price: ' . json_encode ($product->get_regular_price ()));
                        $this->logger->info ('---');
                    }


                    //$product->set_sale_price($new_price);
                    $price_changed = true;
                }
            } elseif ($has_console_promo) {
                $this->logger->info('Console promo present; skipping sale price update for SKU ' . $sku);
            }
        }

        $product->set_manage_stock(true);

        $new_stock = Stock_Helper::format_stock_quantity(
            Stock_Helper::calculate_total_stock_from_product_data($product_data)
        );
        $new_stock_num = floatval($new_stock);
        $old_stock_num = $old_stock !== '' && $old_stock !== null ? floatval($old_stock) : null;

        if ($old_stock_num === null || abs($old_stock_num - $new_stock_num) >= 0.0001) {
            $product->set_stock_quantity($new_stock);
            $stock_changed = true;
        }

        $new_stock_status = $new_stock_num > 0 ? 'instock' : 'outofstock';
        if ($old_stock_status !== $new_stock_status) {
            $product->set_stock_status($new_stock_status);
            $stock_status_changed = true;
        }

        $has_changes = $price_changed || $manage_stock_changed || $stock_changed || $stock_status_changed;

        // Build the change map once — reused for both the "no changes" path and
        // the updated path when persisting the sync snapshot meta below.
        $changes = array();
        if ($price_changed) {
            $changes['price'] = array('from' => $old_price, 'to' => $new_price);
        }
        if ($stock_changed) {
            $changes['stock'] = array('from' => $old_stock, 'to' => $new_stock);
        }
        if ($stock_status_changed) {
            $changes['stock_status'] = array('from' => $old_stock_status, 'to' => $new_stock_status);
        }
        if ($manage_stock_changed) {
            $changes['manage_stock_enabled'] = true;
        }

        if (!$has_changes) {
            $result['status'] = 'synced';
            $result['message'] = 'No changes for product SKU: ' . $sku;
            $this->logger->info($result['message']);
            $this->record_sync_snapshot($product_id, 'synced', $changes, $product_data, $new_price, $new_stock, $new_stock_status, $result['message']);
            return $result;
        }

        if ($price_changed || $stock_changed) {
            $log_file = dirname(__FILE__) . '/../product_changes_log.txt';
            file_put_contents(
                $log_file,
                $this->build_product_change_log_entry($product, $product_data, $old_price, $new_price, $old_stock, $new_stock),
                FILE_APPEND
            );
        }

        $saved_product_id = $product->save();

        if (is_wp_error($saved_product_id)) {
            $result['status'] = 'failed';
            $result['message'] = $saved_product_id->get_error_message();
            $this->logger->error('Failed to save product SKU: ' . $sku . ' - ' . $saved_product_id->get_error_message());
            $this->record_sync_snapshot($product_id, 'failed', $changes, $product_data, $new_price, $new_stock, $new_stock_status, $result['message']);
            return $result;
        }

        // Propagate price and stock meta to all WPML-translated versions of this product.
        // WooCommerce only saves meta to the original post; translated posts get stale values.
        if ($price_changed || $stock_changed) {
            $this->sync_meta_to_wpml_translations(
                $saved_product_id,
                $price_changed ? $new_price : null,
                $stock_changed ? $new_stock : null,
                $new_stock_status
            );
        }

        $result['status'] = 'updated';
        $result['product_id'] = $saved_product_id;
        $result['message'] = 'Product sync complete for SKU: ' . $sku;

        $this->logger->info(sprintf(
            'Product updated. SKU: %s | Price: %s→%s | Stock: %s→%s',
            $sku,
            $old_price,
            $new_price,
            $old_stock,
            $new_stock
        ));
        $this->logger->info($result['message']);

        $this->record_sync_snapshot($saved_product_id, 'updated', $changes, $product_data, $new_price, $new_stock, $new_stock_status, $result['message']);

        return $result;
    }

    /**
     * Persist a compact sync snapshot on the product so the admin meta box
     * can show "what happened last time Powerall looked at this product".
     *
     * Writes five meta keys (all read-only from the UI's perspective):
     *   _dsn_powerall_last_sync_at        UTC unix timestamp
     *   _dsn_powerall_last_sync_result    'updated' | 'synced' | 'failed'
     *   _dsn_powerall_last_sync_changes   JSON, empty object when nothing changed
     *   _dsn_powerall_last_sync_snapshot  JSON: product_code, sales_price, etc.
     *   _dsn_powerall_last_sync_message   last status line
     *
     * Never throws — on any error we log and bail to avoid blocking the sync.
     *
     * @param int    $product_id       Post ID to tag with the snapshot.
     * @param string $result           One of 'updated' | 'synced' | 'failed'.
     * @param array  $changes          Map of what changed this run (can be empty).
     * @param array  $product_data     Raw Powerall record used for this run.
     * @param mixed  $new_price        Resolved new sale price (possibly unchanged).
     * @param mixed  $new_stock        Resolved new stock total.
     * @param string $new_stock_status 'instock' | 'outofstock'.
     * @param string $message          Status message persisted alongside.
     * @return void
     */
    private function record_sync_snapshot(
        int $product_id,
        string $result,
        array $changes,
        array $product_data,
        $new_price,
        $new_stock,
        string $new_stock_status,
        string $message
    ): void {
        try {
            update_post_meta($product_id, '_dsn_powerall_last_sync_at', time());
            update_post_meta($product_id, '_dsn_powerall_last_sync_result', $result);
            update_post_meta($product_id, '_dsn_powerall_last_sync_message', $message);
            update_post_meta(
                $product_id,
                '_dsn_powerall_last_sync_changes',
                wp_json_encode($changes ?: new \stdClass())
            );

            $snapshot = array(
                'product_code'         => isset($product_data['ProductCode']) ? (string) $product_data['ProductCode'] : '',
                'sku'                  => isset($product_data['EanCode']) ? (string) $product_data['EanCode'] : '',
                'product_name'         => isset($product_data['Description1']) ? (string) $product_data['Description1'] : '',
                'sales_price'          => $product_data['SalesPrice'] ?? null,
                'promotional_price'    => $product_data['PromotionalPrice'] ?? null,
                'sales_price_inc_vat'  => isset($product_data['SalesPriceIsIncVat']) ? (bool) $product_data['SalesPriceIsIncVat'] : null,
                'applied_price'        => $new_price,
                'applied_stock'        => $new_stock,
                'applied_stock_status' => $new_stock_status,
                'stock_mode'           => Stock_Helper::get_selected_mode(),
                'warehouse_count'      => isset($product_data['StockPerWarehouse']) && is_array($product_data['StockPerWarehouse'])
                    ? count($product_data['StockPerWarehouse'])
                    : 0,
            );
            update_post_meta(
                $product_id,
                '_dsn_powerall_last_sync_snapshot',
                wp_json_encode($snapshot)
            );
        } catch (\Throwable $e) {
            if (isset($this->logger)) {
                $this->logger->warning('Failed to record Powerall sync snapshot for product ' . $product_id . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Build the external change log entry for a product update.
     *
     * @param \WC_Product $product
     * @param array $product_data
     * @param mixed $old_price
     * @param mixed $new_price
     * @param mixed $old_stock
     * @param mixed $new_stock
     * @return string
     */
    /**
     * When WPML is active, push updated price and stock meta to every translated
     * version of the product. WooCommerce only writes meta to the original post,
     * leaving translated posts with stale values that show the wrong price/stock
     * on language-specific pages.
     *
     * @param int        $product_id      The original (source-language) product ID.
     * @param string|null $new_sale_price  Pass null to skip price update.
     * @param mixed       $new_stock       Pass null to skip stock update.
     * @param string      $new_stock_status 'instock' | 'outofstock'
     * @return void
     */
    private function sync_meta_to_wpml_translations(
        int $product_id,
        $new_sale_price,
        $new_stock,
        string $new_stock_status
    ): void {
        // Bail early if WPML is not loaded.
        $trid = apply_filters('wpml_element_trid', null, $product_id, 'post_product');
        if (!$trid) {
            return;
        }

        $translations = apply_filters('wpml_get_element_translations', null, $trid, 'post_product');
        if (!is_array($translations) || empty($translations)) {
            return;
        }

        $synced = array();

        foreach ($translations as $translation) {
            $translated_id = (int) ($translation->element_id ?? 0);

            // Skip the original — WooCommerce already saved it.
            if (!$translated_id || $translated_id === $product_id) {
                continue;
            }

            if ($new_sale_price !== null) {
                $sale = $new_sale_price !== '' ? wc_format_decimal($new_sale_price) : '';

                update_post_meta($translated_id, '_sale_price', $sale);

                // _price is the effective price WooCommerce displays.
                // When a sale price is set it should match; otherwise keep regular price.
                if ($sale !== '') {
                    update_post_meta($translated_id, '_price', $sale);
                } else {
                    // Sale removed — restore _price to the regular price.
                    $regular = get_post_meta($translated_id, '_regular_price', true);
                    update_post_meta($translated_id, '_price', $regular);
                }
            }

            if ($new_stock !== null) {
                update_post_meta($translated_id, '_stock', $new_stock);
                update_post_meta($translated_id, '_stock_status', $new_stock_status);
                update_post_meta($translated_id, '_manage_stock', 'yes');
                wc_delete_product_transients($translated_id);
            }

            $synced[] = $translated_id;
        }

        if (!empty($synced)) {
            $this->logger->info(sprintf(
                'WPML: synced price/stock to translated product IDs [%s] for original ID %d',
                implode(', ', $synced),
                $product_id
            ));
        }
    }

    private function build_product_change_log_entry($product, array $product_data, $old_price, $new_price, $old_stock, $new_stock) {
        $product_code = isset($product_data['ProductCode']) ? $product_data['ProductCode'] : ($product->get_sku() ?: 'N/A');
        $product_name = isset($product_data['Description1']) ? $product_data['Description1'] : ($product->get_name() ?: 'N/A');

        return sprintf(
            "[%s] ProductCode: %s | Name: %s | SKU: %s | Old Price: %s | New Price: %s | Old Stock: %s | New Stock: %s\n",
            date('Y-m-d H:i:s'),
            $product_code,
            $product_name,
            $product->get_sku(),
            $old_price,
            $new_price,
            $old_stock,
            $new_stock
        );
    }

    /**
     * Get WooCommerce product ID by SKU.
     *
     * @param string $sku
     * @return int|false
     */
    private function get_woo_product_id_by_sku($sku) {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return false;
        }

        // Prefer the Woo helper when available — it handles HPOS/modern schemas.
        if (function_exists('wc_get_product_id_by_sku')) {
            $id = (int) wc_get_product_id_by_sku($sku);
            if ($id > 0) {
                return $id;
            }
        }

        global $wpdb;

        $product_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_sku'
            AND meta_value = %s
            LIMIT 1",
            $sku
        ));

        return $product_id ? (int) $product_id : false;
    }

    /**
     * Resolve a WooCommerce product for a Powerall record.
     *
     * Tries the provided candidates in order, returning on the first match.
     * ProductCode is checked before EanCode because most stores set Woo SKU
     * to the Powerall ProductCode; EanCode (barcode) is a secondary fallback
     * and is sometimes empty on Powerall records.
     *
     * @param string $product_code Powerall ProductCode
     * @param string $ean_code     Powerall EanCode (barcode)
     * @return array{product_id:int, matched_by:string, matched_value:string}|null
     */
    private function find_woo_product_for_powerall_record(string $product_code, string $ean_code): ?array {
        $candidates = array();
        if ($product_code !== '') {
            $candidates[] = array('field' => 'ProductCode', 'value' => $product_code);
        }
        if ($ean_code !== '' && $ean_code !== $product_code) {
            $candidates[] = array('field' => 'EanCode', 'value' => $ean_code);
        }

        foreach ($candidates as $candidate) {
            $product_id = $this->get_woo_product_id_by_sku($candidate['value']);
            if ($product_id) {
                return array(
                    'product_id'    => (int) $product_id,
                    'matched_by'    => $candidate['field'],
                    'matched_value' => $candidate['value'],
                );
            }
        }

        return null;
    }

    /**
     * Check product stock in Powerall CRM before purchase.
     *
     * @param int $product_id WooCommerce product ID
     * @param int $quantity Requested quantity
     * @return bool|WP_Error True if stock available, WP_Error if not
     */
    public function check_stock_before_purchase($product_id, $quantity) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return new \WP_Error('invalid_product', __('Invalid product.', 'dsn-woo-powerall'));
        }

        $sku = $product->get_sku();
        if (!$sku) {
            return new \WP_Error('no_sku', __('Product does not have a SKU.', 'dsn-woo-powerall'));
        }

        $stock_data = $this->api_handler->get_product_stock($sku);
        if (is_wp_error($stock_data)) {
            return $stock_data;
        }

        if ($stock_data['quantity'] < $quantity) {
            return new \WP_Error(
                'insufficient_stock',
                sprintf(
                    __('Sorry, we do not have enough "%s" in stock. Only %d available.', 'dsn-woo-powerall'),
                    $product->get_name(),
                    $stock_data['quantity']
                )
            );
        }

        return true;
    }

    /**
     * Iterate all WooCommerce products and remove sale price when equal to regular price.
     * Processes products in batches to avoid timeouts.
     *
     * @param int $batch_size Number of products to process per batch
     * @return array Summary of changes: ['processed' => int, 'updated' => int]
     */
    public function cleanup_remove_equal_sale_prices($batch_size = 100) {
        $this->logger->info('Starting cleanup: remove equal sale prices');
        $paged = 1;
        $updated = 0;
        $processed = 0;

        do {
            $args = array(
                'post_type' => array('product', 'product_variation'),
                'posts_per_page' => $batch_size,
                'paged' => $paged,
                'fields' => 'ids',
            );

            $query = new \WP_Query($args);
            $ids = $query->posts;
            $count = count($ids);
            if ($count === 0) {
                break;
            }

            foreach ($ids as $pid) {
                $processed++;
                $prod = wc_get_product($pid);
                if (!$prod) {
                    continue;
                }

                if ($prod->is_type('variable')) {
                    foreach ($prod->get_children() as $var_id) {
                        $variation = wc_get_product($var_id);
                        if (!$variation) {
                            continue;
                        }
                        $regular = $variation->get_regular_price();
                        $sale = $variation->get_sale_price();

                        if ($sale !== '' && $sale !== null) {
                            $sale = round(floatval($sale), 2);
                        }

                        $regular_num = $regular !== '' ? floatval($regular) : null;
                        $sale_num = $sale !== '' ? floatval($sale) : null;
                        if ($sale_num !== null && $regular_num !== null && abs($sale_num - $regular_num) < 0.0001) {
                            $variation->set_sale_price('');
                            $variation->save();
                            $updated++;
                            $this->logger->info('Removed sale price for variation ID ' . $var_id . ' (regular == sale)');
                        }
                    }
                } else {
                    $regular = $prod->get_regular_price();
                    $sale = $prod->get_sale_price();
                    if ($sale !== '' && $sale !== null) {
                        $sale = round(floatval($sale), 2);
                    }

                    $regular_num = $regular !== '' ? floatval($regular) : null;
                    $sale_num = $sale !== '' ? floatval($sale) : null;
                    if ($sale_num !== null && $regular_num !== null && abs($sale_num - $regular_num) < 0.0001) {
                        $prod->set_sale_price('');
                        $prod->save();
                        $updated++;
                        $this->logger->info('Removed sale price for product ID ' . $pid . ' (regular == sale)');
                    }
                }
            }

            wp_reset_postdata();
            $paged++;
            if (function_exists('set_time_limit')) {
                @set_time_limit(30);
            }
        } while ($count === $batch_size);

        $this->logger->info('Cleanup completed. Processed: ' . $processed . ' Updated: ' . $updated);
        return array('processed' => $processed, 'updated' => $updated);
    }

    /**
     * Process a single page of products and remove sale prices equal to regular price.
     * Returns counts for the page.
     *
     * @param int $paged
     * @param int $batch_size
     * @return array ['processed' => int, 'updated' => int]
     */
    public function cleanup_remove_equal_sale_prices_page($paged = 1, $batch_size = 100) {
        $updated = 0;
        $processed = 0;

        $args = array(
            'post_type' => array('product', 'product_variation'),
            'posts_per_page' => $batch_size,
            'paged' => $paged,
            'fields' => 'ids',
            'no_found_rows' => true,
        );

        $query = new \WP_Query($args);
        $ids = $query->posts;

        foreach ($ids as $pid) {
            $processed++;
            $prod = wc_get_product($pid);
            if (!$prod) {
                continue;
            }

            if ($prod->is_type('variable')) {
                foreach ($prod->get_children() as $var_id) {
                    $variation = wc_get_product($var_id);
                    if (!$variation) {
                        continue;
                    }
                    $regular = $variation->get_regular_price();
                    $sale = $variation->get_sale_price();

                    if ($sale !== '' && $sale !== null) {
                        $sale = round(floatval($sale), 2);
                    }

                    $regular_num = $regular !== '' ? floatval($regular) : null;
                    $sale_num = $sale !== '' ? floatval($sale) : null;
                    if ($sale_num !== null && $regular_num !== null && abs($sale_num - $regular_num) < 0.0001) {
                        $variation->set_sale_price('');
                        $variation->save();
                        $updated++;
                        $this->logger->info('Removed sale price for variation ID ' . $var_id . ' (regular == sale)');
                    }
                }
            } else {
                $regular = $prod->get_regular_price();
                $sale = $prod->get_sale_price();

                if ($sale !== '' && $sale !== null) {
                    $sale = round(floatval($sale), 2);
                }

                $regular_num = $regular !== '' ? floatval($regular) : null;
                $sale_num = $sale !== '' ? floatval($sale) : null;
                if ($sale_num !== null && $regular_num !== null && abs($sale_num - $regular_num) < 0.0001) {
                    $prod->set_sale_price('');
                    $prod->save();
                    $updated++;
                    $this->logger->info('Removed sale price for product ID ' . $pid . ' (regular == sale)');
                }
            }
        }

        wp_reset_postdata();
        return array('processed' => $processed, 'updated' => $updated);
    }

    /**
     * Get the product list from the Powerall API response.
     *
     * @return array|WP_Error
     */
    private function get_product_list() {
        $products = $this->api_handler->get_products();

        if (is_wp_error($products)) {
            return $products;
        }

        $product_list = isset($products['Data']) ? $products['Data'] : $products;

        if (!is_array($product_list)) {
            return new \WP_Error('invalid_products_response', __('Invalid product list returned by Powerall.', 'dsn-woo-powerall'));
        }

        return array_values($product_list);
    }

    /**
     * Process a full product list in batches.
     *
     * @param array $product_list
     * @param int $batch_size
     * @param int $delay_seconds
     * @return array
     */
    private function process_product_list_in_batches(array $product_list, $batch_size, $delay_seconds) {
        $summary = $this->create_sync_summary();
        $total = count($product_list);

        $this->logger->info('Total products to sync: ' . $total . ' | Batch size: ' . $batch_size);

        for ($offset = 0; $offset < $total; $offset += $batch_size) {
            $batch = array_slice($product_list, $offset, $batch_size);
            $batch_number = intval(floor($offset / $batch_size)) + 1;

            $this->logger->info('Processing batch ' . $batch_number . ' (' . count($batch) . ' products)');
            $batch_summary = $this->process_product_batch($batch);
            $summary = $this->merge_sync_summaries($summary, $batch_summary);

            if ($offset + $batch_size < $total && $delay_seconds > 0) {
                $this->logger->info('Batch complete, sleeping ' . $delay_seconds . ' second(s) before next batch...');
                sleep($delay_seconds);
            }

            if (function_exists('set_time_limit')) {
                @set_time_limit(30);
            }
        }

        return $summary;
    }

    /**
     * Process one batch of products and return summary counters.
     *
     * @param array $products
     * @return array
     */
    private function process_product_batch(array $products) {
        $summary = $this->create_sync_summary();

        foreach ($products as $product_data) {
            $item_result = $this->sync_single_product($product_data);

            $summary['processed']++;
            $summary['last_result'] = $item_result;

            switch ($item_result['status']) {
                case 'updated':
                    $summary['updated']++;
                    break;
                case 'failed':
                    $summary['failed']++;
                    if (!empty($item_result['message'])) {
                        $summary['errors'][] = $item_result['message'];
                    }
                    break;
                case 'skipped':
                    $summary['skipped']++;
                    break;
                case 'synced':
                default:
                    $summary['synced']++;
                    break;
            }

            if (function_exists('set_time_limit')) {
                @set_time_limit(20);
            }
        }

        return $summary;
    }

    /**
     * Create an empty sync summary.
     *
     * @return array
     */
    private function create_sync_summary() {
        return array(
            'processed' => 0,
            'updated' => 0,
            'synced' => 0,
            'skipped' => 0,
            'failed' => 0,
            'last_result' => null,
            'errors' => array(),
        );
    }

    /**
     * Merge batch counters into the full sync summary.
     *
     * @param array $summary
     * @param array $batch_summary
     * @return array
     */
    private function merge_sync_summaries(array $summary, array $batch_summary) {
        $summary['processed'] += $batch_summary['processed'];
        $summary['updated'] += $batch_summary['updated'];
        $summary['synced'] += $batch_summary['synced'];
        $summary['skipped'] += $batch_summary['skipped'];
        $summary['failed'] += $batch_summary['failed'];
        $summary['last_result'] = $batch_summary['last_result'];
        $summary['errors'] = array_slice(
            array_merge($summary['errors'], $batch_summary['errors']),
            -self::MAX_STORED_ERRORS
        );

        return $summary;
    }

    /**
     * Get the default manual sync state.
     *
     * @return array
     */
    private function get_default_manual_sync_state() {
        return array(
            'run_id' => '',
            'status' => 'idle',
            'started_at' => '',
            'completed_at' => '',
            'batch_size' => self::get_batch_size(),
            'delay_seconds' => self::get_batch_delay_seconds(),
            'total_products' => 0,
            'processed' => 0,
            'updated' => 0,
            'synced' => 0,
            'skipped' => 0,
            'failed' => 0,
            'current_offset' => 0,
            'last_message' => __('Manual sync has not been started yet.', 'dsn-woo-powerall'),
            'last_sku' => '',
            'last_product_name' => '',
            'recent_errors' => array(),
        );
    }

    /**
     * Read the saved manual sync state.
     *
     * @return array
     */
    private function read_manual_sync_state() {
        $state = get_option(self::MANUAL_SYNC_STATE_OPTION, array());

        if (!is_array($state)) {
            $state = array();
        }

        return wp_parse_args($state, $this->get_default_manual_sync_state());
    }

    /**
     * Save the manual sync state.
     *
     * @param array $state
     * @return void
     */
    private function save_manual_sync_state(array $state) {
        update_option(self::MANUAL_SYNC_STATE_OPTION, $state, false);
    }

    /**
     * Read the saved cron sync state.
     *
     * @return array
     */
    private function read_cron_sync_state() {
        $state = get_option(self::CRON_SYNC_STATE_OPTION, array());

        if (!is_array($state)) {
            $state = array();
        }

        return wp_parse_args($state, $this->get_default_manual_sync_state());
    }

    /**
     * Save the cron sync state.
     *
     * @param array $state
     * @return void
     */
    private function save_cron_sync_state(array $state) {
        update_option(self::CRON_SYNC_STATE_OPTION, $state, false);
    }

    /**
     * Prepare the manual sync state for the UI response.
     *
     * @param array $state
     * @param array $extra
     * @return array
     */
    private function prepare_manual_sync_state_response(array $state, array $extra = array()) {
        $state = wp_parse_args($state, $this->get_default_manual_sync_state());

        $total_products = max(0, intval($state['total_products']));
        $processed = max(0, intval($state['processed']));
        $batch_size = max(1, intval($state['batch_size']));
        $offset = max(0, intval($state['current_offset']));
        $total_batches = $total_products > 0 ? (int) ceil($total_products / $batch_size) : 0;
        $progress = $total_products > 0 ? round(($processed / $total_products) * 100, 2) : ($state['status'] === 'completed' ? 100 : 0);

        if ($total_batches === 0) {
            $current_batch = 0;
        } elseif ($state['status'] === 'completed') {
            $current_batch = $total_batches;
        } else {
            $current_batch = min($total_batches, (int) floor($offset / $batch_size) + 1);
        }

        return array_merge($state, array(
            'progress_percentage' => $progress,
            'remaining' => max(0, $total_products - $processed),
            'total_batches' => $total_batches,
            'current_batch' => $current_batch,
        ), $extra);
    }

    /**
     * Mark a manual sync as completed and clean up its cache file.
     *
     * @param array $state
     * @param string $message
     * @return array
     */
    private function complete_manual_sync_state(array $state, $message = '') {
        $state['status'] = 'completed';
        $state['completed_at'] = current_time('mysql');
        $state['current_offset'] = max(intval($state['current_offset']), intval($state['total_products']));
        if ($message !== '') {
            $state['last_message'] = $message;
        }

        $this->save_manual_sync_state($state);
        $this->delete_manual_sync_cache($state['run_id']);

        return $state;
    }

    /**
     * Mark a cron sync as completed and clean up its cache file.
     *
     * @param array $state
     * @param string $message
     * @return array
     */
    private function complete_cron_sync_state(array $state, $message = '') {
        $state['status'] = 'completed';
        $state['completed_at'] = current_time('mysql');
        $state['current_offset'] = max(intval($state['current_offset']), intval($state['total_products']));
        if ($message !== '') {
            $state['last_message'] = $message;
        }

        $this->save_cron_sync_state($state);
        $this->delete_manual_sync_cache($state['run_id']);

        $this->logger->info(sprintf(
            'Daily cron product sync completed. Processed: %d Updated: %d Unchanged: %d Skipped: %d Failed: %d',
            $state['processed'],
            $state['updated'],
            $state['synced'],
            $state['skipped'],
            $state['failed']
        ));

        return $state;
    }

    /**
     * Get the directory used for manual sync cache files.
     *
     * @return string
     */
    private function get_manual_sync_cache_directory() {
        $upload_dir = wp_upload_dir();

        return trailingslashit($upload_dir['basedir']) . 'dsn-woo-powerall-sync-cache';
    }

    /**
     * Get the file path for a manual sync cache file.
     *
     * @param string $run_id
     * @return string
     */
    private function get_manual_sync_cache_file($run_id) {
        return trailingslashit($this->get_manual_sync_cache_directory()) . sanitize_file_name($run_id) . '.ndjson';
    }

    /**
     * Cache the fetched product list to disk for manual batch processing.
     *
     * @param string $run_id
     * @param array $product_list
     * @return true|WP_Error
     */
    private function store_manual_sync_cache($run_id, array $product_list) {
        $cache_directory = $this->get_manual_sync_cache_directory();
        if (!file_exists($cache_directory) && !wp_mkdir_p($cache_directory)) {
            return new \WP_Error('manual_sync_cache_dir', __('Unable to create the manual sync cache directory.', 'dsn-woo-powerall'));
        }

        $handle = fopen($this->get_manual_sync_cache_file($run_id), 'wb');
        if ($handle === false) {
            return new \WP_Error('manual_sync_cache_write', __('Unable to open the manual sync cache file for writing.', 'dsn-woo-powerall'));
        }

        foreach ($product_list as $product_data) {
            $line = wp_json_encode($product_data);
            if ($line === false || fwrite($handle, $line . PHP_EOL) === false) {
                fclose($handle);
                return new \WP_Error('manual_sync_cache_write', __('Unable to write the manual sync cache file.', 'dsn-woo-powerall'));
            }
        }

        fclose($handle);

        return true;
    }

    /**
     * Read a batch of product data from the manual sync cache.
     *
     * @param string $run_id
     * @param int $offset
     * @param int $limit
     * @return array|WP_Error
     */
    private function get_manual_sync_batch_from_cache($run_id, $offset, $limit) {
        $cache_file = $this->get_manual_sync_cache_file($run_id);

        if (!file_exists($cache_file) || !is_readable($cache_file)) {
            return new \WP_Error('manual_sync_cache_missing', __('The manual sync cache file could not be found.', 'dsn-woo-powerall'));
        }

        $batch = array();
        $file = new \SplFileObject($cache_file, 'r');
        $file->seek(max(0, $offset));

        while (!$file->eof() && count($batch) < $limit) {
            $line = trim((string) $file->current());
            $file->next();

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                return new \WP_Error('manual_sync_cache_invalid', __('The manual sync cache data is invalid.', 'dsn-woo-powerall'));
            }

            $batch[] = $decoded;
        }

        return $batch;
    }

    /**
     * Delete the cached manual sync file for a run.
     *
     * @param string $run_id
     * @return void
     */
    private function delete_manual_sync_cache($run_id) {
        if (empty($run_id)) {
            return;
        }

        $cache_file = $this->get_manual_sync_cache_file($run_id);
        if (file_exists($cache_file)) {
            @unlink($cache_file);
        }
    }
}
