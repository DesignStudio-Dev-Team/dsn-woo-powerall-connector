<?php
namespace DSNWooPowerall;

class HPOS_Compatibility {
    /**
     * Initialize HPOS compatibility
     */
    public function __construct() {
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
    }

    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', DSN_WOO_POWERALL_PLUGIN_FILE, true);
        }
    }
} 