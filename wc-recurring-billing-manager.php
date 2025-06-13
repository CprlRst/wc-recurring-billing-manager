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
define('WC_RBM_PLUGIN_FILE', __FILE__);

// Check if WooCommerce is active
function wc_rbm_check_woocommerce() {
    if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><?php _e('WooCommerce Recurring Billing Manager requires WooCommerce to be active.', 'wc-rbm'); ?></p>
            </div>
            <?php
        });
        return false;
    }
    return true;
}

// Main plugin class
class WC_Recurring_Billing_Manager {
    
    /**
     * Single instance of the class
     */
    protected static $_instance = null;
    
    /**
     * Component instances
     */
    private $components = array();
    
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
     * Constructor - private to force singleton
     */
    private function __construct() {
        // Load plugin files
        $this->load_dependencies();
        
        // Initialize hooks
        $this->init_hooks();
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Prevent unserializing
     */
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
    
    /**
     * Load required files
     */
    private function load_dependencies() {
        // Load class files
        $files = array(
            'includes/class-wc-rbm-database.php',
            'includes/class-wc-rbm-subscription.php',
            'includes/class-wc-rbm-invoice.php',
            'includes/class-wc-rbm-url-manager.php',
            'includes/class-wc-rbm-admin.php',
            'includes/class-wc-rbm-frontend.php',
            'includes/class-wc-rbm-ajax.php',
            'includes/class-wc-rbm-cron.php',
            'includes/class-wc-rbm-woocommerce.php',
        );
        
        foreach ($files as $file) {
            $filepath = WC_RBM_PLUGIN_DIR . $file;
            if (file_exists($filepath)) {
                require_once $filepath;
            } else {
                error_log('WC RBM: Missing file - ' . $file);
            }
        }
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook(WC_RBM_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(WC_RBM_PLUGIN_FILE, array($this, 'deactivate'));
        
        // Init hook
        add_action('init', array($this, 'init'), 0);
        
        // Initialize components after plugins loaded
        add_action('plugins_loaded', array($this, 'init_components'), 10);
        
        // Load textdomain
        add_action('plugins_loaded', array($this, 'load_textdomain'), 15);
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Initialize database component first
        if (!isset($this->components['database'])) {
            $this->components['database'] = new WC_RBM_Database();
        }
        
        // Create tables
        $this->components['database']->create_tables();
        
        // Schedule events
        if (class_exists('WC_RBM_Cron')) {
            $cron = new WC_RBM_Cron();
            $cron->schedule_events();
        }
        
        // Add rewrite rules
        add_rewrite_endpoint('url-manager', EP_ROOT | EP_PAGES);
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Unschedule events
        if (isset($this->components['cron'])) {
            $this->components['cron']->unschedule_events();
        } else if (class_exists('WC_RBM_Cron')) {
            $cron = new WC_RBM_Cron();
            $cron->unschedule_events();
        }
        
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
     * Initialize components
     */
    public function init_components() {
        // Check if WooCommerce is active
        if (!wc_rbm_check_woocommerce()) {
            return;
        }
        
        // Initialize components if not already done
        if (empty($this->components)) {
            try {
                $this->components['database'] = new WC_RBM_Database();
                $this->components['subscription'] = new WC_RBM_Subscription();
                $this->components['invoice'] = new WC_RBM_Invoice();
                $this->components['url_manager'] = new WC_RBM_URL_Manager();
                $this->components['admin'] = new WC_RBM_Admin();
                $this->components['frontend'] = new WC_RBM_Frontend();
                $this->components['ajax'] = new WC_RBM_Ajax();
                $this->components['cron'] = new WC_RBM_Cron();
                $this->components['woocommerce'] = new WC_RBM_WooCommerce();
                
                // Check if database needs update
                if (method_exists($this->components['database'], 'needs_update') && 
                    $this->components['database']->needs_update()) {
                    $this->components['database']->update();
                }
            } catch (Exception $e) {
                error_log('WC RBM: Error initializing components - ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('wc-rbm', false, dirname(plugin_basename(WC_RBM_PLUGIN_FILE)) . '/languages');
    }
    
    /**
     * Magic getter for component access
     */
    public function __get($name) {
        if (isset($this->components[$name])) {
            return $this->components[$name];
        }
        
        return null;
    }
    
    /**
     * Check if component exists
     */
    public function has_component($name) {
        return isset($this->components[$name]);
    }
}

/**
 * Main function to get plugin instance
 */
function WC_RBM() {
    return WC_Recurring_Billing_Manager::instance();
}

// Initialize the plugin only after plugins are loaded
add_action('plugins_loaded', function() {
    if (wc_rbm_check_woocommerce()) {
        WC_RBM();
    }
}, 1);