<?php
namespace DSNWooPowerall;

/**
 * Daily check for WooCommerce products that can be bought but are not in Powerall.
 *
 * A product counts as in Powerall when its SKU matches a Powerall ProductCode
 * or EanCode, the same rule the product sync uses. Products not in Powerall get
 * no price or stock updates and are left off orders sent to Powerall.
 *
 * Only products that are new to the list since the previous check are
 * reported, so the daily email does not repeat the same products every day.
 */
class Missing_Products_Check {
    public const KNOWN_OPTION = 'dsn_woo_powerall_not_in_powerall';

    /**
     * @var Logger
     */
    private $logger;

    public function __construct() {
        $this->logger = new Logger();
    }

    /**
     * Find purchasable products not in Powerall and add the new ones to the daily report.
     *
     * @param array<string, bool> $powerall_codes Every Powerall ProductCode and EanCode, as keys
     * @return void
     */
    public function run(array $powerall_codes) {
        // An empty list means Powerall returned nothing, not that every product is missing.
        if (empty($powerall_codes)) {
            $this->logger->warning('Not-in-Powerall check skipped: no Powerall product codes were available.');
            return;
        }

        $missing = $this->find_missing_products($powerall_codes);

        $known = get_option(self::KNOWN_OPTION, array());
        $known = is_array($known) ? $known : array();
        $new = array_diff_key($missing, $known);

        // Forget products that were fixed, so they are reported again if they go missing again.
        update_option(self::KNOWN_OPTION, array_fill_keys(array_keys($missing), true), false);

        $this->logger->info(sprintf('Not-in-Powerall check: %d purchasable product(s) not in Powerall, %d new.', count($missing), count($new)));

        if (!empty($new)) {
            (new Sync_Report())->record_missing_products(array_values($new), count($missing));
        }
    }

    /**
     * @param array<string, bool> $powerall_codes
     * @return array<int, array> Missing products keyed by product ID
     */
    private function find_missing_products(array $powerall_codes) {
        global $wpdb;

        // SKUs come straight from the database so only unmatched products are loaded.
        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_type, sku.meta_value AS sku, parent_sku.meta_value AS parent_sku
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} parent_sku ON parent_sku.post_id = p.post_parent AND parent_sku.meta_key = '_sku'
            WHERE p.post_type IN ('product', 'product_variation')
            AND p.post_status = 'publish'"
        );

        $missing = array();
        foreach ($rows as $row) {
            $sku = trim((string) $row->sku);
            // Variations without their own SKU use the parent's, as WooCommerce does.
            if ($sku === '' && $row->post_type === 'product_variation') {
                $sku = trim((string) $row->parent_sku);
            }

            if ($sku !== '' && isset($powerall_codes[$sku])) {
                continue;
            }

            $product = wc_get_product((int) $row->ID);
            // Variable and grouped products are bought through their variations or children;
            // external products are bought elsewhere.
            if (!$product || $product->is_type(array('variable', 'grouped', 'external'))) {
                continue;
            }

            if (!$product->is_purchasable() || !$product->is_in_stock() || $this->is_translation($product)) {
                continue;
            }

            $missing[$product->get_id()] = array(
                'edit_id' => $product->get_parent_id() ?: $product->get_id(),
                'name' => $product->get_name(),
                'sku' => $sku,
                'reason' => $sku === '' ? 'no_sku' : 'not_in_powerall',
            );
        }

        return $missing;
    }

    /**
     * Whether the product is a WPML translation of another product.
     *
     * @param \WC_Product $product
     * @return bool
     */
    private function is_translation($product) {
        $default_lang = apply_filters('wpml_default_language', null);
        if (!$default_lang) {
            return false;
        }

        $element_type = $product->is_type('variation') ? 'product_variation' : 'product';
        $source_id = (int) apply_filters('wpml_object_id', $product->get_id(), $element_type, false, $default_lang);

        return $source_id && $source_id !== $product->get_id();
    }
}
