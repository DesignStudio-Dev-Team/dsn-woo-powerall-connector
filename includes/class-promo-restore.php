<?php
namespace DSNWooPowerall;

/**
 * One-off repair: put back Syndified promotion prices that the product sync cleared.
 *
 * Until 1.2.16 the product sync looked for Syndified's product JSON in the old
 * plugin folder, did not find it, and overwrote promotion sale prices with the
 * Powerall price, leaving the sale dates behind. Syndified's saved JSON still
 * has every promotion (spp, spsa, spea), so this applies them again the way
 * Syndified's own sync does (Content\Product: stihl_sale_price), and clears
 * sale dates left on products that no longer have a sale price.
 *
 * plan() only reads; apply() writes. Shown on the Tools page with ?fix=true.
 */
class Promo_Restore {
    /**
     * @var Logger
     */
    private $logger;

    /**
     * Brand console ID => whether Syndified applies its product promotions.
     *
     * @var array<int, bool>
     */
    private $brand_promotions = array();

    public function __construct() {
        $this->logger = new Logger();
    }

    /**
     * Work out what a restore would change, without changing anything.
     *
     * @return array{restore: array, clear_dates: array, skipped: array, counts: array}
     */
    public function plan() {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $plan = array(
            'restore' => array(),
            'clear_dates' => array(),
            'skipped' => array(),
            'counts' => array(
                'checked' => 0,
                'no_json' => 0,
                'no_promotion' => 0,
                'ended' => 0,
                'already_correct' => 0,
            ),
        );

        foreach ($this->get_syndified_products() as $product_id => $console_id) {
            $plan['counts']['checked']++;
            $this->plan_product($plan, $product_id, $console_id);
        }

        $restoring = array_column($plan['restore'], 'product_id');
        foreach ($this->get_products_with_leftover_dates() as $product_id) {
            if (in_array($product_id, $restoring, true)) {
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product || $product->get_sale_price('edit') !== '' || (!$product->get_date_on_sale_from('edit') && !$product->get_date_on_sale_to('edit'))) {
                continue;
            }

            $plan['clear_dates'][] = $this->describe($product, array(
                'start' => $this->timestamp_of($product->get_date_on_sale_from('edit')),
                'end' => $this->timestamp_of($product->get_date_on_sale_to('edit')),
            ));
        }

        return $plan;
    }

    /**
     * Apply a fresh plan: restore promotion prices and clear leftover sale dates.
     *
     * @return array The plan that was applied, with 'errors' added
     */
    public function apply() {
        $plan = $this->plan();
        $plan['errors'] = array();
        $plan['restored'] = 0;
        $plan['cleared'] = 0;

        $this->logger->info(sprintf(
            'Promotion restore started. Restoring: %d | Clearing leftover sale dates: %d',
            count($plan['restore']),
            count($plan['clear_dates'])
        ));

        foreach ($plan['restore'] as $item) {
            $product = wc_get_product($item['product_id']);
            if (!$product) {
                continue;
            }

            try {
                $product->set_sale_price(wc_format_decimal($item['new_sale']));
                $product->set_date_on_sale_from($item['start'] ?: '');
                $product->set_date_on_sale_to($item['end'] ?: '');
                $product->save();
                $plan['restored']++;
                $this->logger->info(sprintf(
                    'Promotion restored for product %d (SKU %s): sale price %s → %s, %s to %s',
                    $item['product_id'],
                    $item['sku'],
                    $item['current_sale'] !== '' ? $item['current_sale'] : '(none)',
                    $item['new_sale'],
                    $item['start'] ? gmdate('Y-m-d H:i', $item['start']) . ' UTC' : '-',
                    $item['end'] ? gmdate('Y-m-d H:i', $item['end']) . ' UTC' : '-'
                ));
            } catch (\Throwable $e) {
                $plan['errors'][] = sprintf('%s (SKU %s): %s', $item['name'], $item['sku'], $e->getMessage());
                $this->logger->error('Promotion restore failed for product ' . $item['product_id'] . ': ' . $e->getMessage());
            }
        }

        foreach ($plan['clear_dates'] as $item) {
            $product = wc_get_product($item['product_id']);
            if (!$product) {
                continue;
            }

            try {
                $product->set_date_on_sale_from('');
                $product->set_date_on_sale_to('');
                $product->save();
                $plan['cleared']++;
                $this->logger->info(sprintf('Leftover sale dates cleared for product %d (SKU %s).', $item['product_id'], $item['sku']));
            } catch (\Throwable $e) {
                $plan['errors'][] = sprintf('%s (SKU %s): %s', $item['name'], $item['sku'], $e->getMessage());
                $this->logger->error('Clearing sale dates failed for product ' . $item['product_id'] . ': ' . $e->getMessage());
            }
        }

        $this->logger->info('Promotion restore finished. Errors: ' . count($plan['errors']));

        return $plan;
    }

    /**
     * @param array $plan
     * @param int $product_id
     * @param string $console_id
     * @return void
     */
    private function plan_product(array &$plan, $product_id, $console_id) {
        $json = $this->read_json(Product_Sync::find_syndified_json_path('Product', $console_id));
        if ($json === null) {
            $plan['counts']['no_json']++;
            return;
        }

        // Syndified uses isset(), so a null spp means no promotion.
        if (!isset($json['spp']) || $json['spp'] === '' || !is_numeric($json['spp'])) {
            $plan['counts']['no_promotion']++;
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        $start = $this->parse_utc($json['spsa'] ?? null);
        $end = $this->parse_utc($json['spea'] ?? null);
        $new_sale = (string) $json['spp'];
        $details = array('new_sale' => $new_sale, 'start' => $start, 'end' => $end);

        if (!$this->brand_promotions_enabled((int) ($json['brand_id'] ?? 0))) {
            $plan['skipped'][] = $this->describe($product, $details + array('reason' => __('Promotions are switched off for this brand in Syndified.', 'dsn-woo-powerall')));
            return;
        }

        if ($end && $end < time()) {
            $plan['counts']['ended']++;
            return;
        }

        if ($product->is_type('variable')) {
            $plan['skipped'][] = $this->describe($product, $details + array('reason' => __('Variable product: WooCommerce sets sale prices per variation, so set this promotion on the variations by hand.', 'dsn-woo-powerall')));
            return;
        }

        $regular = $product->get_regular_price('edit');
        if ($regular === '' || floatval($new_sale) >= floatval($regular)) {
            $plan['skipped'][] = $this->describe($product, $details + array('reason' => __('The promotion price is not below the regular price.', 'dsn-woo-powerall')));
            return;
        }

        $current_sale = $product->get_sale_price('edit');
        $same_price = $current_sale !== '' && abs(floatval($current_sale) - floatval($new_sale)) < 0.005;
        $same_dates = $this->timestamp_of($product->get_date_on_sale_from('edit')) === $start
            && $this->timestamp_of($product->get_date_on_sale_to('edit')) === $end;
        if ($same_price && $same_dates) {
            $plan['counts']['already_correct']++;
            return;
        }

        $plan['restore'][] = $this->describe($product, $details);
    }

    /**
     * @param \WC_Product $product
     * @param array $details
     * @return array
     */
    private function describe($product, array $details) {
        return array_merge(array(
            'product_id' => $product->get_id(),
            'edit_id' => $product->get_parent_id() ?: $product->get_id(),
            'name' => $product->get_name(),
            'sku' => (string) $product->get_sku(),
            'regular' => (string) $product->get_regular_price('edit'),
            'current_sale' => (string) $product->get_sale_price('edit'),
            'new_sale' => '',
            'start' => null,
            'end' => null,
            'reason' => '',
        ), $details);
    }

    /**
     * Products linked to a Syndified console product.
     *
     * @return array<int, string> Product ID => console ID
     */
    private function get_syndified_products() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = 'console_id'
            AND pm.meta_value <> ''
            AND p.post_type = 'product'
            AND p.post_status NOT IN ('trash', 'auto-draft')"
        );

        $products = array();
        foreach ($rows as $row) {
            $products[(int) $row->post_id] = sanitize_file_name((string) $row->meta_value);
        }

        return $products;
    }

    /**
     * Products and variations with sale dates but no sale price.
     *
     * @return int[]
     */
    private function get_products_with_leftover_dates() {
        global $wpdb;

        return array_map('intval', $wpdb->get_col(
            "SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} dates ON dates.post_id = p.ID
                AND dates.meta_key IN ('_sale_price_dates_from', '_sale_price_dates_to')
                AND dates.meta_value <> ''
            LEFT JOIN {$wpdb->postmeta} sale ON sale.post_id = p.ID AND sale.meta_key = '_sale_price'
            WHERE p.post_type IN ('product', 'product_variation')
            AND p.post_status NOT IN ('trash', 'auto-draft')
            AND (sale.meta_value IS NULL OR sale.meta_value = '')"
        ));
    }

    /**
     * Whether Syndified applies product promotions for a brand (enable_product_promotions).
     *
     * @param int $brand_id
     * @return bool
     */
    private function brand_promotions_enabled($brand_id) {
        if (!isset($this->brand_promotions[$brand_id])) {
            $brand = $brand_id ? $this->read_json(Product_Sync::find_syndified_json_path('Brand', $brand_id)) : null;
            $this->brand_promotions[$brand_id] = !empty($brand['enable_product_promotions']);
        }

        return $this->brand_promotions[$brand_id];
    }

    /**
     * @param string $path
     * @return array|null
     */
    private function read_json($path) {
        if (!file_exists($path) || !is_readable($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Syndified's promotion dates are UTC date-time strings.
     *
     * @param mixed $value
     * @return int|null
     */
    private function parse_utc($value) {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTime($value, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @param \WC_DateTime|null $date
     * @return int|null
     */
    private function timestamp_of($date) {
        return $date ? $date->getTimestamp() : null;
    }
}
