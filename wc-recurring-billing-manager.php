<?php
/**
 * Plugin Name: WooCommerce Recurring Billing Manager
 * Description: Manages recurring subscriptions with URL whitelist management and invoicing
 * Version: 2.0.0
 * Author: Your Name
 * Requires at least: 5.0
 * Tested up to: 6.3
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_RBM_VERSION', '2.0.0');
define('WC_RBM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_RBM_PLUGIN_URL', plugin_dir_url(__FILE__));

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('WooCommerce Recurring Billing Manager requires WooCommerce to be active.', 'wc-rbm'); ?></p>
        </div>
        <?php
    });
    return;
}

// Include required files
require_once WC_RBM_PLUGIN_DIR . 'includes/class-wc-rbm-database.php';
require_once WC_RBM_PLUGIN_DIR . 'includes/class-wc-rbm-subscription.php';
require_once WC_RBM_PLUGIN_DIR . 'includes/class-wc-rbm-invoice.php';
require_once WC_RBM_PLUGIN_DIR . 'includes/class-wc-rbm-url-manager.php';
require_once WC_RBM_PLUGIN_DIR . 'includes/class-wc-rbm-admin.php';
require_once WC_RBM_PLUGIN_DIR . 'includes/class-wc-rbm-frontend.php';
require_once WC_RBM_PLUGIN_DIR . 'includes/class-wc-rbm-ajax.php';
require_once WC_RBM_PLUGIN_DIR . 'includes/class-wc-rbm-cron.php';
require_once WC_RBM_PLUGIN_DIR . 'includes/class-wc-rbm-woocommerce.php';

/**
 * Main Plugin Class
 */
class WC_Recurring_Billing_Manager {
    
    /**
     * Single instance of the class
     */
    protected static $_instance = null;
    
    /**
     * Database handler
     */
    public $database;
    
    /**
     * Subscription handler
     */
    public $subscription;
    
    /**
     * Invoice handler
     */
    public $invoice;
    
    /**
     * URL Manager handler
     */
    public $url_manager;
    
    /**
     * Admin handler
     */
    public $admin;
    
    /**
     * Frontend handler
     */
    public $frontend;
    
    /**
     * AJAX handler
     */
    public $ajax;
    
    /**
     * Cron handler
     */
    public $cron;
    
    /**
     * WooCommerce integration handler
     */
    public $woocommerce;
    
    /**
     * Main Instance
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    /**
     * Constructor
     */
    public function __construct() {
        // Initialize components
        $this->database = new WC_RBM_Database();
        $this->subscription = new WC_RBM_Subscription();
        $this->invoice = new WC_RBM_Invoice();
        $this->url_manager = new WC_RBM_URL_Manager();
        $this->admin = new WC_RBM_Admin();
        $this->frontend = new WC_RBM_Frontend();
        $this->ajax = new WC_RBM_Ajax();
        $this->cron = new WC_RBM_Cron();
        $this->woocommerce = new WC_RBM_WooCommerce();
        
        // Hook into WordPress
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Init hook
        add_action('init', array($this, 'init'));
        
        // Load textdomain
        add_action('plugins_loaded', array($this, 'load_textdomain'));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        $this->database->create_tables();
        $this->cron->schedule_events();
        
        // Add rewrite rules
        add_rewrite_endpoint('url-manager', EP_ROOT | EP_PAGES);
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        $this->cron->unschedule_events();
        flush_rewrite_rules();
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Add rewrite endpoint
        add_rewrite_endpoint('url-manager', EP_ROOT | EP_PAGES);
    }
    
    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('wc-rbm', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
}

/**
 * Main function to get plugin instance
 */
function WC_RBM() {
    return WC_Recurring_Billing_Manager::instance();
}

// Initialize the plugin
WC_RBM();

// Create the directory structure for the includes folder
// includes/class-wc-rbm-database.php - Already created above
// includes/class-wc-rbm-subscription.php
// includes/class-wc-rbm-invoice.php
// includes/class-wc-rbm-url-manager.php
// includes/class-wc-rbm-admin.php
// includes/class-wc-rbm-frontend.php
// includes/class-wc-rbm-ajax.php - Already created above
// includes/class-wc-rbm-cron.php
// includes/class-wc-rbm-woocommerce.php