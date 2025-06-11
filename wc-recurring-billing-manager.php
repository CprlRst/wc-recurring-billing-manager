<?php
/**
 * Plugin Name: WooCommerce Recurring Billing Manager
 * Description: Manages recurring subscriptions with URL whitelist management and invoicing
 * Version: 1.0.90
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

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

/**
 * Initialize the custom product class when WooCommerce is loaded
 */
function wc_recurring_billing_init_product_class() {
    if (!class_exists('WC_Product')) {
        return;
    }
    
    /**
     * Custom WooCommerce Product Class for Recurring Subscriptions
     */
    class WC_Product_Recurring_Subscription extends WC_Product {
        
        /**
         * Get product type
         */
        public function get_type() {
            return 'recurring_subscription';
        }
        
        /**
         * Get subscription billing type (monthly/yearly)
         */
        public function get_subscription_type() {
            return $this->get_meta('_subscription_type', true) ?: 'monthly';
        }
        
        /**
         * Get subscription duration in months
         */
        public function get_subscription_duration() {
            return $this->get_meta('_subscription_duration', true) ?: '';
        }
        
        /**
         * Check if this product is a subscription
         */
        public function is_subscription() {
            return $this->get_meta('_is_subscription', true) === 'yes';
        }
        
        /**
         * Get formatted price with subscription info
         */
        public function get_price_html($price = '') {
            $price = parent::get_price_html($price);
            
            if ($this->is_subscription() && $price) {
                $subscription_type = $this->get_subscription_type();
                $duration = $this->get_subscription_duration();
                
                $interval_text = $subscription_type === 'monthly' ? ' / month' : ' / year';
                
                if ($duration) {
                    $duration_text = ' for ' . $duration . ' months';
                    $price .= '<small class="subscription-details">' . $interval_text . $duration_text . '</small>';
                } else {
                    $price .= '<small class="subscription-details">' . $interval_text . '</small>';
                }
            }
            
            return $price;
        }
        
        /**
         * Check if product is purchasable
         */
        public function is_purchasable() {
            $purchasable = true;
            
            if ($this->get_price() === '' || $this->get_price() <= 0) {
                $purchasable = false;
            }
            
            if (!$this->is_subscription()) {
                $purchasable = false;
            }
            
            return apply_filters('woocommerce_is_purchasable', $purchasable, $this);
        }
        
        /**
         * Get add to cart button text
         */
        public function add_to_cart_text() {
            return $this->is_purchasable() ? __('Subscribe Now', 'woocommerce') : __('Read more', 'woocommerce');
        }
        
        /**
         * Get single product add to cart button text
         */
        public function single_add_to_cart_text() {
            return __('Subscribe Now', 'woocommerce');
        }
    }
}

// Initialize the product class when WooCommerce is loaded
add_action('woocommerce_loaded', 'wc_recurring_billing_init_product_class');

/**
 * Main Plugin Class
 */
class WC_Recurring_Billing_Manager {
    
    private $table_name;
    private $user_urls_table;
    
    /**
     * Constructor - Initialize plugin
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'recurring_subscriptions';
        $this->user_urls_table = $wpdb->prefix . 'user_whitelist_urls';
        
        // Core initialization hooks
        add_action('init', array($this, 'init'));
        add_action('init', array($this, 'check_database_version'));
        
        // Script and style hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        
        // Admin hooks
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_notices', array($this, 'admin_notices'));
        
        // AJAX handlers
        $this->init_ajax_handlers();
        
        // WooCommerce integration
        add_action('woocommerce_init', array($this, 'woocommerce_integration'));
        $this->init_woocommerce_hooks();
        
        // Payment processing
        $this->init_payment_processing();
        add_action('init', array($this, 'init_payment_handlers'));
        add_action('init', array($this, 'add_payment_rewrite_rules'));
        add_filter('query_vars', array($this, 'handle_payment_queries'));
        
        // Cron hooks for recurring billing and URL cleanup
        add_action('wp', array($this, 'schedule_recurring_billing'));
        add_action('process_recurring_billing', array($this, 'process_recurring_payments'));
        add_action('process_url_cleanup', array($this, 'cleanup_expired_urls'));
        
        // Plugin activation/deactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Initialize AJAX handlers
     */
    private function init_ajax_handlers() {
        // Admin AJAX handlers
        add_action('wp_ajax_repair_database', array($this, 'ajax_repair_database'));
        add_action('wp_ajax_manage_subscription', array($this, 'ajax_manage_subscription'));
        add_action('wp_ajax_create_invoice', array($this, 'ajax_create_invoice'));
        add_action('wp_ajax_delete_subscription', array($this, 'ajax_delete_subscription')); // This is the key line for delete functionality
        add_action('wp_ajax_refresh_whitelist', array($this, 'ajax_refresh_whitelist'));
        add_action('wp_ajax_remove_user_url', array($this, 'ajax_remove_user_url'));
        add_action('wp_ajax_test_subscription_creation', array($this, 'ajax_test_subscription_creation'));
        // Add this line in the init_ajax_handlers() method
add_action('wp_ajax_test_connection', array($this, 'ajax_test_connection'));
        
        // Frontend AJAX handlers
        add_action('wp_ajax_submit_url', array($this, 'ajax_submit_url'));
        add_action('wp_ajax_nopriv_submit_url', array($this, 'ajax_submit_url'));
    }
    
    /**
     * Initialize WooCommerce hooks
     */
    private function init_woocommerce_hooks() {
        // Account menu integration
        add_action('woocommerce_account_menu_items', array($this, 'add_account_menu_item'));
        add_action('woocommerce_account_url-manager_endpoint', array($this, 'url_manager_content'));
        add_filter('woocommerce_account_menu_items', array($this, 'reorder_account_menu'));
        
        // Order processing hooks
        add_action('woocommerce_order_status_completed', array($this, 'handle_subscription_purchase'));
        add_action('woocommerce_order_status_processing', array($this, 'handle_subscription_purchase'));
        add_action('woocommerce_payment_complete', array($this, 'handle_subscription_purchase'));
        add_action('woocommerce_thankyou', array($this, 'handle_subscription_purchase'));
        
        // Product editor hooks (only in admin)
        if (is_admin()) {
            add_action('admin_init', array($this, 'init_woocommerce_product_hooks'));
        }
    }
    
    /**
     * Initialize WooCommerce product editor hooks
     */
    public function init_woocommerce_product_hooks() {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_product')) {
            return;
        }
        
        global $pagenow;
        if ($pagenow === 'post.php' || $pagenow === 'post-new.php') {
            $post_type = isset($_GET['post_type']) ? $_GET['post_type'] : 
                        (isset($_GET['post']) ? get_post_type($_GET['post']) : '');
            
            if ($post_type === 'product' || (isset($_GET['post']) && get_post_type($_GET['post']) === 'product')) {
                // Add subscription fields to General tab
                add_action('woocommerce_product_options_general_product_data', array($this, 'add_subscription_fields_to_general'));
                
                // Save hooks with different priorities
                add_action('woocommerce_process_product_meta', array($this, 'save_subscription_product_meta'), 1);
                add_action('save_post', array($this, 'save_subscription_product_meta'), 1);
                add_action('wp_insert_post_data', array($this, 'save_subscription_product_meta_early'), 1, 2);
            }
        }
    }
    
    /**
     * Early save hook for product meta
     */
    public function save_subscription_product_meta_early($data, $postarr) {
        if (isset($postarr['ID']) && $postarr['post_type'] === 'product') {
            error_log("WC Recurring Billing: wp_insert_post_data hook triggered for product ID: " . $postarr['ID']);
            $this->save_subscription_product_meta($postarr['ID']);
        }
        return $data;
    }
    
    /**
     * Check and update database version
     */
    public function check_database_version() {
        $this->update_database_if_needed();
    }
    
    /**
     * Plugin initialization
     */
    public function init() {
        // Add rewrite endpoint for account page
        add_rewrite_endpoint('url-manager', EP_ROOT | EP_PAGES);
        
        // Flush rewrite rules if needed
        if (get_option('wc_recurring_billing_flush_rewrite_rules') === 'yes') {
            flush_rewrite_rules();
            delete_option('wc_recurring_billing_flush_rewrite_rules');
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        $this->create_tables();
        $this->update_database_if_needed();
        add_option('wc_recurring_billing_flush_rewrite_rules', 'yes');
    }
    
    /**
     * Update database structure if needed
     */
    private function update_database_if_needed() {
        $current_version = get_option('wc_recurring_billing_db_version', '1.0');
        $new_version = '1.1';
        
        if (version_compare($current_version, $new_version, '<')) {
            $this->update_database_structure();
            update_option('wc_recurring_billing_db_version', $new_version);
        }
    }
    
    /**
     * Update database structure for newer versions
     */
    private function update_database_structure() {
        global $wpdb;
        
        // Check if expiry_date column exists
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM $this->table_name LIKE %s",
            'expiry_date'
        ));
        
        if (empty($column_exists)) {
            // Add expiry_date column
            $wpdb->query("ALTER TABLE $this->table_name ADD COLUMN expiry_date datetime NULL AFTER last_billing_date");
            $wpdb->query("ALTER TABLE $this->table_name ADD KEY expiry_date (expiry_date)");
        }
        
        // Check if user_urls_table exists
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $this->user_urls_table
        ));
        
        if (!$table_exists) {
            // Create the user URLs table
            $charset_collate = $wpdb->get_charset_collate();
            $sql_urls = "CREATE TABLE $this->user_urls_table (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                user_id bigint(20) NOT NULL,
                subscription_id mediumint(9) NOT NULL,
                url varchar(255) NOT NULL,
                status varchar(20) DEFAULT 'active',
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY subscription_id (subscription_id),
                KEY status (status),
                UNIQUE KEY user_subscription (user_id, subscription_id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql_urls);
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        wp_clear_scheduled_hook('process_recurring_billing');
        wp_clear_scheduled_hook('process_url_cleanup');
    }
    
    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Create subscriptions table
        $sql = "CREATE TABLE $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            subscription_type varchar(20) NOT NULL,
            amount decimal(10,2) NOT NULL,
            status varchar(20) DEFAULT 'active',
            start_date datetime DEFAULT CURRENT_TIMESTAMP,
            next_billing_date datetime NOT NULL,
            last_billing_date datetime NULL,
            expiry_date datetime NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY next_billing_date (next_billing_date),
            KEY expiry_date (expiry_date)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Create invoices table
        $invoices_table = $wpdb->prefix . 'recurring_invoices';
        $sql_invoices = "CREATE TABLE $invoices_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            subscription_id mediumint(9) NOT NULL,
            user_id bigint(20) NOT NULL,
            invoice_number varchar(50) NOT NULL,
            amount decimal(10,2) NOT NULL,
            status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            paid_at datetime NULL,
            PRIMARY KEY (id),
            KEY subscription_id (subscription_id),
            KEY user_id (user_id),
            KEY status (status),
            UNIQUE KEY invoice_number (invoice_number)
        ) $charset_collate;";
        
        dbDelta($sql_invoices);
        
        // Create user URLs table
        $sql_urls = "CREATE TABLE $this->user_urls_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            subscription_id mediumint(9) NOT NULL,
            url varchar(255) NOT NULL,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY subscription_id (subscription_id),
            KEY status (status),
            UNIQUE KEY user_subscription (user_id, subscription_id)
        ) $charset_collate;";
        
        dbDelta($sql_urls);
    }
    
    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts() {
        if (is_account_page()) {
            wp_enqueue_script('jquery');
            wp_enqueue_script('wc-recurring-billing', plugin_dir_url(__FILE__) . 'assets/frontend.js', array('jquery'), '1.0.0', true);
            wp_localize_script('wc-recurring-billing', 'wcRecurringBilling', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_recurring_billing_nonce')
            ));
            wp_enqueue_style('wc-recurring-billing', plugin_dir_url(__FILE__) . 'assets/frontend.css', array(), '1.0.0');
        }
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function admin_enqueue_scripts($hook) {
        if (strpos($hook, 'recurring-billing') !== false) {
            wp_enqueue_script('jquery');
            wp_enqueue_script('wc-recurring-billing-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', array('jquery'), '1.0.0', true);
            wp_localize_script('wc-recurring-billing-admin', 'wcRecurringBillingAdmin', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_recurring_billing_admin_nonce')
            ));
        }
    }
    
    /**
     * Show admin notices for database issues
     */
    public function admin_notices() {
        // Check if database needs repair
        global $wpdb;
        
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM $this->table_name LIKE %s",
            'expiry_date'
        ));
        
        if (empty($column_exists)) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p><strong>WC Recurring Billing:</strong> Database needs to be updated. 
                <button type="button" id="repair-database" class="button button-primary" style="margin-left: 10px;">
                    Fix Database
                </button></p>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                $('#repair-database').on('click', function() {
                    $(this).text('Fixing...').prop('disabled', true);
                    
                    $.post(ajaxurl, {
                        action: 'repair_database',
                        nonce: '<?php echo wp_create_nonce('repair_database_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                            $('#repair-database').text('Fix Database').prop('disabled', false);
                        }
                    });
                });
            });
            </script>
            <?php
        }
    }
    
    /**
     * AJAX handler for database repair
     */
    public function ajax_repair_database() {
        check_ajax_referer('repair_database_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }
        
        try {
            $this->create_tables();
            $this->update_database_structure();
            wp_send_json_success('Database repaired successfully.');
        } catch (Exception $e) {
            wp_send_json_error('Error repairing database: ' . $e->getMessage());
        }
    }
    
    /**
     * Add admin menu pages
     */
    public function admin_menu() {
        add_menu_page(
            'Recurring Billing',
            'Recurring Billing',
            'manage_options',
            'recurring-billing',
            array($this, 'admin_page'),
            'dashicons-money-alt',
            30
        );
        
        add_submenu_page(
            'recurring-billing',
            'Subscriptions',
            'Subscriptions',
            'manage_options',
            'recurring-billing',
            array($this, 'admin_page')
        );
        
        add_submenu_page(
            'recurring-billing',
            'Invoices',
            'Invoices',
            'manage_options',
            'recurring-billing-invoices',
            array($this, 'invoices_page')
        );
        
        add_submenu_page(
            'recurring-billing',
            'URL Management',
            'URL Management',
            'manage_options',
            'recurring-billing-urls',
            array($this, 'url_management_page')
        );
    }
    
    /**
     * Main admin page for subscriptions
     */
    public function admin_page() {
        global $wpdb;
        
        // Get subscriptions with associated URLs
        $subscriptions = $wpdb->get_results(
            "SELECT s.*, u.display_name, u.user_email, uu.url as current_url
             FROM $this->table_name s 
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID 
             LEFT JOIN $this->user_urls_table uu ON s.id = uu.subscription_id AND uu.status = 'active'
             ORDER BY s.created_at DESC"
        );
        
        ?>
        <div class="wrap">
            <h1>Recurring Billing Subscriptions</h1>
            
            <!-- Subscription Creation Form -->
            <div class="subscription-form" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
                <h2>Create New Subscription</h2>
                <form id="create-subscription-form">
                    <table class="form-table">
                        <tr>
                            <th><label for="user_id">User</label></th>
                            <td>
                                <select name="user_id" id="user_id" required>
                                    <option value="">Select User</option>
                                    <?php
                                    $users = get_users();
                                    foreach ($users as $user) {
                                        echo '<option value="' . $user->ID . '">' . $user->display_name . ' (' . $user->user_email . ')</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="subscription_type">Billing Interval</label></th>
                            <td>
                                <select name="subscription_type" id="subscription_type" required>
                                    <option value="monthly">Monthly</option>
                                    <option value="yearly">Yearly</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="amount">Amount</label></th>
                            <td><input type="number" name="amount" id="amount" step="0.01" min="0" required /></td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" class="button-primary" value="Create Subscription" />
                    </p>
                </form>
            </div>
            
            <!-- Subscriptions Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 50px;">ID</th>
                        <th style="width: 150px;">User</th>
                        <th style="width: 200px;">Current URL</th>
                        <th style="width: 80px;">Type</th>
                        <th style="width: 80px;">Amount</th>
                        <th style="width: 80px;">Status</th>
                        <th style="width: 120px;">Next Billing</th>
                        <th style="width: 90px;">Expires</th>
                        <th style="width: 220px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subscriptions as $subscription): ?>
                    <tr id="subscription-row-<?php echo $subscription->id; ?>">
                        <td><?php echo $subscription->id; ?></td>
                        <td>
                            <?php echo esc_html($subscription->display_name); ?><br>
                            <small><?php echo esc_html($subscription->user_email); ?></small>
                        </td>
                        <td style="word-break: break-all; font-family: monospace; font-size: 12px;">
                            <?php if ($subscription->current_url): ?>
                                <a href="<?php echo esc_url($subscription->current_url); ?>" target="_blank" title="Visit URL">
                                    <?php echo esc_html($subscription->current_url); ?>
                                </a>
                            <?php else: ?>
                                <span style="color: #666; font-style: italic;">No URL submitted</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo ucfirst($subscription->subscription_type); ?></td>
                        <td>$<?php echo number_format($subscription->amount, 2); ?></td>
                        <td>
                            <span class="status-<?php echo $subscription->status; ?>">
                                <?php echo ucfirst($subscription->status); ?>
                            </span>
                        </td>
                        <td><?php echo date('Y-m-d H:i', strtotime($subscription->next_billing_date)); ?></td>
                        <td>
                            <?php 
                            if ($subscription->expiry_date) {
                                echo date('Y-m-d', strtotime($subscription->expiry_date));
                            } else {
                                echo 'Lifetime';
                            }
                            ?>
                        </td>
                        <td>
                            <button class="button manage-subscription" 
                                    data-id="<?php echo $subscription->id; ?>" 
                                    data-action="<?php echo $subscription->status === 'active' ? 'pause' : 'activate'; ?>"
                                    title="<?php echo $subscription->status === 'active' ? 'Pause subscription' : 'Activate subscription'; ?>">
                                <?php echo $subscription->status === 'active' ? 'Pause' : 'Activate'; ?>
                            </button>
                            <button class="button create-invoice" 
                                    data-id="<?php echo $subscription->id; ?>"
                                    title="Create new invoice">
                                Invoice
                            </button>
                            <button class="button button-link-delete delete-subscription" 
                                    data-id="<?php echo $subscription->id; ?>" 
                                    data-user="<?php echo esc_attr($subscription->display_name); ?>"
                                    title="Permanently delete subscription and URL"
                                    style="color: #d63638; text-decoration: none;">
                                Delete
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <style>
        .status-active { color: #46b450; font-weight: bold; }
        .status-paused { color: #ffb900; font-weight: bold; }
        .status-cancelled { color: #dc3232; font-weight: bold; }
        .subscription-details {
            display: block;
            color: #666;
            font-weight: normal;
            font-size: 0.9em;
        }
        </style>
        <?php
    }
    
    /**
     * Invoices page
     */
    public function invoices_page() {
        global $wpdb;
        
        $invoices_table = $wpdb->prefix . 'recurring_invoices';
        $invoices = $wpdb->get_results(
            "SELECT i.*, s.subscription_type, u.display_name, u.user_email 
             FROM $invoices_table i 
             LEFT JOIN $this->table_name s ON i.subscription_id = s.id 
             LEFT JOIN {$wpdb->users} u ON i.user_id = u.ID 
             ORDER BY i.created_at DESC"
        );
        
        ?>
        <div class="wrap">
            <h1>Invoices</h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>User</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Paid</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $invoice): ?>
                    <tr>
                        <td><?php echo $invoice->invoice_number; ?></td>
                        <td>
                            <?php echo $invoice->display_name; ?><br>
                            <small><?php echo $invoice->user_email; ?></small>
                        </td>
                        <td>$<?php echo number_format($invoice->amount, 2); ?></td>
                        <td>
                            <span class="status-<?php echo $invoice->status; ?>">
                                <?php echo ucfirst($invoice->status); ?>
                            </span>
                        </td>
                        <td><?php echo date('Y-m-d H:i', strtotime($invoice->created_at)); ?></td>
                        <td><?php echo $invoice->paid_at ? date('Y-m-d H:i', strtotime($invoice->paid_at)) : '-'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * URL management page
     */
    public function url_management_page() {
        global $wpdb;
        
        // Get current Bricks whitelist
        $bricks_settings = get_option('bricks_global_settings', '');
        $current_whitelist = '';
        if ($bricks_settings) {
            $settings = maybe_unserialize($bricks_settings);
            if (is_array($settings) && isset($settings['myTemplatesWhitelist'])) {
                $current_whitelist = $settings['myTemplatesWhitelist'];
            }
        }
        
        // Get all user URLs with subscription info
        $user_urls = $wpdb->get_results(
            "SELECT u.*, s.subscription_type, s.status as sub_status, s.expiry_date, 
                    usr.display_name, usr.user_email
             FROM $this->user_urls_table u
             LEFT JOIN $this->table_name s ON u.subscription_id = s.id
             LEFT JOIN {$wpdb->users} usr ON u.user_id = usr.ID
             ORDER BY u.created_at DESC"
        );
        
        ?>
        <div class="wrap">
            <h1>URL Management</h1>
            
            <!-- Current Bricks Whitelist Display -->
            <div class="current-whitelist" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
                <h2>Current Bricks Whitelist</h2>
                <div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; font-family: monospace; white-space: pre-wrap; max-height: 300px; overflow-y: auto;">
                    <?php echo $current_whitelist ? esc_html($current_whitelist) : 'No URLs in whitelist'; ?>
                </div>
                <button type="button" id="refresh-whitelist" class="button" style="margin-top: 10px;">Refresh Whitelist</button>
            </div>
            
            <!-- User Submitted URLs Table -->
            <h2>User Submitted URLs</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>URL</th>
                        <th>Subscription Type</th>
                        <th>Status</th>
                        <th>Expires</th>
                        <th>Submitted</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($user_urls as $url_record): ?>
                    <tr>
                        <td>
                            <?php echo esc_html($url_record->display_name); ?><br>
                            <small><?php echo esc_html($url_record->user_email); ?></small>
                        </td>
                        <td style="word-break: break-all;">
                            <a href="<?php echo esc_url($url_record->url); ?>" target="_blank">
                                <?php echo esc_html($url_record->url); ?>
                            </a>
                        </td>
                        <td><?php echo ucfirst($url_record->subscription_type); ?></td>
                        <td>
                            <span class="status-<?php echo $url_record->status; ?>">
                                <?php echo ucfirst($url_record->status); ?>
                            </span>
                            <?php if ($url_record->sub_status !== 'active'): ?>
                                <br><small style="color: #dc3232;">(Subscription: <?php echo ucfirst($url_record->sub_status); ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                            if ($url_record->expiry_date) {
                                echo date('Y-m-d', strtotime($url_record->expiry_date));
                                if (strtotime($url_record->expiry_date) < time()) {
                                    echo '<br><small style="color: #dc3232;">Expired</small>';
                                }
                            } else {
                                echo 'Lifetime';
                            }
                            ?>
                        </td>
                        <td><?php echo date('Y-m-d H:i', strtotime($url_record->created_at)); ?></td>
                        <td>
                            <?php if ($url_record->status === 'active'): ?>
                                <button class="button remove-url" data-id="<?php echo $url_record->id; ?>">
                                    Remove
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#refresh-whitelist').on('click', function() {
                $.post(ajaxurl, {
                    action: 'refresh_whitelist',
                    nonce: '<?php echo wp_create_nonce('wc_recurring_billing_admin_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error refreshing whitelist: ' + response.data);
                    }
                });
            });
            
            $('.remove-url').on('click', function() {
                var id = $(this).data('id');
                
                if (!confirm('Remove this URL from the whitelist?')) {
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'remove_user_url',
                    url_id: id,
                    nonce: '<?php echo wp_create_nonce('wc_recurring_billing_admin_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('URL removed successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            });
        });
        </script>
        
        <style>
        .status-active { color: #46b450; font-weight: bold; }
        .status-expired { color: #dc3232; font-weight: bold; }
        .status-inactive { color: #ffb900; font-weight: bold; }
        </style>
        <?php
    }
    
    /**
     * AJAX handler for subscription management
     */
    public function ajax_manage_subscription() {
        check_ajax_referer('wc_recurring_billing_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }
        
        global $wpdb;
        $operation = sanitize_text_field($_POST['operation']);
        
        switch ($operation) {
            case 'create':
                $user_id = intval($_POST['user_id']);
                $subscription_type = sanitize_text_field($_POST['subscription_type']);
                $amount = floatval($_POST['amount']);
                
                if (!$user_id || !in_array($subscription_type, ['monthly', 'yearly']) || $amount <= 0) {
                    wp_send_json_error('Invalid parameters.');
                }
                
                $next_billing = $subscription_type === 'monthly' 
                    ? date('Y-m-d H:i:s', strtotime('+1 month'))
                    : date('Y-m-d H:i:s', strtotime('+1 year'));
                
                $result = $wpdb->insert(
                    $this->table_name,
                    array(
                        'user_id' => $user_id,
                        'subscription_type' => $subscription_type,
                        'amount' => $amount,
                        'next_billing_date' => $next_billing,
                        'status' => 'active'
                    ),
                    array('%d', '%s', '%f', '%s', '%s')
                );
                
                if ($result) {
                    wp_send_json_success('Subscription created successfully.');
                } else {
                    wp_send_json_error('Failed to create subscription.');
                }
                break;
                
            case 'pause':
            case 'activate':
                $subscription_id = intval($_POST['subscription_id']);
                $status = $operation === 'pause' ? 'paused' : 'active';
                
                $result = $wpdb->update(
                    $this->table_name,
                    array('status' => $status),
                    array('id' => $subscription_id),
                    array('%s'),
                    array('%d')
                );
                
                if ($result !== false) {
                    wp_send_json_success('Subscription updated successfully.');
                } else {
                    wp_send_json_error('Failed to update subscription.');
                }
                break;
                
            default:
                wp_send_json_error('Invalid operation.');
        }
    }
    
    /**
     * AJAX handler for creating invoices
     */
    public function ajax_create_invoice() {
        check_ajax_referer('wc_recurring_billing_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }
        
        global $wpdb;
        $subscription_id = intval($_POST['subscription_id']);
        
        // Get subscription details
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE id = %d",
            $subscription_id
        ));
        
        if (!$subscription) {
            wp_send_json_error('Subscription not found.');
        }
        
        // Generate invoice number
        $invoice_number = 'INV-' . date('Y') . '-' . str_pad($subscription_id, 6, '0', STR_PAD_LEFT) . '-' . time();
        
        // Insert invoice
        $invoices_table = $wpdb->prefix . 'recurring_invoices';
        $result = $wpdb->insert(
            $invoices_table,
            array(
                'subscription_id' => $subscription_id,
                'user_id' => $subscription->user_id,
                'invoice_number' => $invoice_number,
                'amount' => $subscription->amount,
                'status' => 'pending'
            ),
            array('%d', '%d', '%s', '%f', '%s')
        );
        
        if ($result) {
            // Send invoice email
            $invoice_id = $wpdb->insert_id;
            $this->send_invoice_email($invoice_id);
            wp_send_json_success('Invoice created successfully.');
        } else {
            wp_send_json_error('Failed to create invoice.');
        }
    }
    
    /**
     * AJAX handler for deleting subscriptions (FIXED)
     */
    public function ajax_delete_subscription() {
        // Verify nonce for security
        check_ajax_referer('wc_recurring_billing_admin_nonce', 'nonce');
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }
        
        global $wpdb;
        $subscription_id = intval($_POST['subscription_id']);
        
        if (!$subscription_id) {
            wp_send_json_error('Invalid subscription ID.');
        }
        
        // Get subscription details for logging and cleanup
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, u.display_name, u.user_email 
             FROM $this->table_name s 
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID 
             WHERE s.id = %d",
            $subscription_id
        ));
        
        if (!$subscription) {
            wp_send_json_error('Subscription not found.');
        }
        
        try {
            // Begin deletion process
            
            // 1. Get URLs associated with this subscription for whitelist cleanup
            $urls_to_remove = $wpdb->get_results($wpdb->prepare(
                "SELECT url FROM $this->user_urls_table WHERE subscription_id = %d AND status = 'active'",
                $subscription_id
            ));
            
            // 2. Delete user URLs
            $deleted_urls = $wpdb->delete(
                $this->user_urls_table,
                array('subscription_id' => $subscription_id),
                array('%d')
            );
            
            // 3. Delete invoices
            $invoices_table = $wpdb->prefix . 'recurring_invoices';
            $deleted_invoices = $wpdb->delete(
                $invoices_table,
                array('subscription_id' => $subscription_id),
                array('%d')
            );
            
            // 4. Delete the subscription
            $deleted_subscription = $wpdb->delete(
                $this->table_name,
                array('id' => $subscription_id),
                array('%d')
            );
            
            if ($deleted_subscription === false) {
                wp_send_json_error('Failed to delete subscription from database.');
            }
            
            // 5. Update Bricks whitelist to remove the URLs
            if (!empty($urls_to_remove)) {
                $this->remove_urls_from_bricks_whitelist($urls_to_remove);
            }
            
            // Log the deletion for audit purposes
            error_log("WC Recurring Billing: Subscription #{$subscription_id} deleted by admin. User: {$subscription->display_name} ({$subscription->user_email}). URLs removed: " . count($urls_to_remove) . ". Invoices removed: {$deleted_invoices}.");
            
            wp_send_json_success(array(
                'message' => 'Subscription and all associated data deleted successfully.',
                'details' => array(
                    'subscription_id' => $subscription_id,
                    'urls_removed' => count($urls_to_remove),
                    'invoices_removed' => $deleted_invoices
                )
            ));
            
        } catch (Exception $e) {
            error_log("WC Recurring Billing: Error deleting subscription #{$subscription_id}: " . $e->getMessage());
            wp_send_json_error('Error deleting subscription: ' . $e->getMessage());
        }
    }
    
    /**
     * Remove specific URLs from Bricks whitelist
     */
    private function remove_urls_from_bricks_whitelist($urls_to_remove) {
        // Get current Bricks settings
        $bricks_settings = get_option('bricks_global_settings', '');
        $settings = maybe_unserialize($bricks_settings);
        
        if (!is_array($settings)) {
            return; // No settings to update
        }
        
        // Get existing URLs from Bricks
        $existing_urls = isset($settings['myTemplatesWhitelist']) ? $settings['myTemplatesWhitelist'] : '';
        $existing_urls_array = array_filter(array_map('trim', explode("\n", $existing_urls)));
        
        // Remove the specified URLs
        $urls_to_remove_array = array();
        foreach ($urls_to_remove as $url_obj) {
            $urls_to_remove_array[] = trim($url_obj->url);
        }
        
        $filtered_urls = array_filter($existing_urls_array, function($url) use ($urls_to_remove_array) {
            return !in_array(trim($url), $urls_to_remove_array);
        });
        
        // Update the whitelist
        $settings['myTemplatesWhitelist'] = implode("\n", $filtered_urls);
        
        // Save to database
        global $wpdb;
        $serialized_settings = serialize($settings);
        
        $wpdb->update(
            $wpdb->options,
            array('option_value' => $serialized_settings),
            array('option_name' => 'bricks_global_settings'),
            array('%s'),
            array('%s')
        );
        
        wp_cache_delete('bricks_global_settings', 'options');
    }
    
    /**
     * AJAX handler for refreshing whitelist
     */
    public function ajax_refresh_whitelist() {
        check_ajax_referer('wc_recurring_billing_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }
        
        $this->update_bricks_whitelist();
        wp_send_json_success('Whitelist refreshed successfully.');
    }
    
    /**
     * AJAX handler for removing user URLs
     */
    public function ajax_remove_user_url() {
        check_ajax_referer('wc_recurring_billing_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }
        
        $url_id = intval($_POST['url_id']);
        
        global $wpdb;
        $result = $wpdb->update(
            $this->user_urls_table,
            array('status' => 'removed'),
            array('id' => $url_id),
            array('%s'),
            array('%d')
        );
        
        if ($result !== false) {
            $this->update_bricks_whitelist();
            wp_send_json_success('URL removed successfully.');
        } else {
            wp_send_json_error('Failed to remove URL.');
        }
    }
    
    // [Rest of the methods continue with proper commenting...]
    // I'll continue with the remaining methods in a structured way
    
    /**
     * Add URL Manager to WooCommerce account menu
     */
    public function add_account_menu_item($items) {
        $items['url-manager'] = 'URL Manager';
        return $items;
    }
    
    /**
     * Reorder account menu items
     */
    public function reorder_account_menu($items) {
        $new_items = array();
        foreach ($items as $key => $item) {
            $new_items[$key] = $item;
            if ($key === 'dashboard') {
                $new_items['url-manager'] = 'URL Manager';
            }
        }
        return $new_items;
    }
    
    /**
     * Display URL manager content on account page
     */
    public function url_manager_content() {
    $user_id = get_current_user_id();
    
    // TEMPORARY DEBUG - Show user info
    echo '<div style="background: yellow; padding: 10px; margin: 10px 0; border: 2px solid red;">';
    echo '<strong>DEBUG INFO:</strong><br>';
    echo 'Current User ID: ' . $user_id . '<br>';
    echo 'Current User Login: ' . wp_get_current_user()->user_login . '<br>';
    echo '</div>';
    
    // Continue with existing code...
    global $wpdb;
    $active_subscription = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $this->table_name 
         WHERE user_id = %d AND status = 'active' 
         AND (expiry_date IS NULL OR expiry_date > NOW())
         ORDER BY created_at DESC LIMIT 1",
        $user_id
    ));
    
    // More debug info
    echo '<div style="background: cyan; padding: 10px; margin: 10px 0; border: 2px solid blue;">';
    echo '<strong>SUBSCRIPTION QUERY DEBUG:</strong><br>';
    echo 'Query: SELECT * FROM ' . $this->table_name . ' WHERE user_id = ' . $user_id . ' AND status = \'active\'<br>';
    echo 'Found subscription: ' . ($active_subscription ? 'YES' : 'NO') . '<br>';
    if ($active_subscription) {
        echo 'Subscription ID: ' . $active_subscription->id . '<br>';
        echo 'Status: ' . $active_subscription->status . '<br>';
        echo 'Expiry: ' . ($active_subscription->expiry_date ?: 'Never') . '<br>';
    }
    echo '</div>';
    
    // Rest of your existing code...
        
        // Check if user has an active subscription
        global $wpdb;
        $active_subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name 
             WHERE user_id = %d AND status = 'active' 
             AND (expiry_date IS NULL OR expiry_date > NOW())
             ORDER BY created_at DESC LIMIT 1",
            $user_id
        ));
        
        ?>
        <div class="woocommerce-account-url-manager">
            <h3>URL Manager</h3>
            
            <?php if (!$active_subscription): ?>
                <div class="woocommerce-message woocommerce-message--info" style="background: #e1f5fe; border-left: 4px solid #01579b; padding: 15px; margin: 20px 0;">
                    <p><strong>No Active Subscription</strong></p>
                    <p>You need an active subscription to manage URLs. Please purchase a subscription to get started.</p>
                    <a href="<?php echo wc_get_page_permalink('shop'); ?>" class="button">Browse Subscriptions</a>
                </div>
            <?php else: ?>
                <?php
                // Get user's current URL
                $user_url = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $this->user_urls_table 
                     WHERE user_id = %d AND subscription_id = %d AND status = 'active'",
                    $user_id, $active_subscription->id
                ));
                ?>
                
                <p>You can submit one URL per subscription to be added to the templates whitelist.</p>
                
                <?php if ($user_url): ?>
                    <div class="current-url" style="background: #d4edda; padding: 15px; margin: 20px 0; border-left: 4px solid #28a745; border-radius: 4px;">
                        <h4 style="color: #155724; margin-top: 0;">Your Current Whitelisted URL:</h4>
                        <div style="font-family: monospace; background: #fff; padding: 10px; border: 1px solid #c3e6cb; border-radius: 4px; word-break: break-all;">
                            <?php echo esc_html($user_url->url); ?>
                        </div>
                        <small style="color: #155724;">Subscription expires: <?php echo $active_subscription->expiry_date ? date('F j, Y', strtotime($active_subscription->expiry_date)) : 'Never (lifetime)'; ?></small>
                    </div>
                    
                    <div style="background: #fff3cd; padding: 15px; margin: 20px 0; border-left: 4px solid #ffc107; border-radius: 4px;">
                        <p><strong>Want to change your URL?</strong></p>
                        <p>You can update your whitelisted URL below. This will replace your current URL.</p>
                    </div>
                <?php endif; ?>
                
                <form id="url-submission-form" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 6px;">
                    <div class="form-row">
                        <label for="new_url"><?php echo $user_url ? 'Update URL:' : 'Submit Your URL:'; ?></label>
                        <input type="url" id="new_url" name="new_url" class="form-control" 
                               placeholder="https://yourdomain.com/" required 
                               value="<?php echo $user_url ? esc_attr($user_url->url) : ''; ?>"
                               style="width: 100%; padding: 10px; margin: 10px 0;"
                               pattern="https?://.+"
                               title="Please enter a valid URL starting with http:// or https://">
                        <div id="url-validation-message" style="margin-top: 5px; font-size: 14px;"></div>
                    </div>
                    <div class="form-row">
                        <button type="submit" class="button" id="submit-button" 
                                style="background: #0073aa; color: white; padding: 10px 20px; border: none;" disabled>
                            <?php echo $user_url ? 'Update URL' : 'Submit URL'; ?>
                        </button>
                    </div>
                </form>
                
                <div id="url-submission-result" style="margin: 20px 0;"></div>
                
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * AJAX handler for URL submission
     */
    public function ajax_submit_url() {
        try {
            check_ajax_referer('wc_recurring_billing_nonce', 'nonce');
            
            if (!is_user_logged_in()) {
                wp_send_json_error('You must be logged in to submit URLs.');
            }
            
            $new_url = esc_url_raw($_POST['new_url']);
            $subscription_id = intval($_POST['subscription_id']);
            
            if (!$new_url) {
                wp_send_json_error('Please provide a valid URL.');
            }
            
            if (!$subscription_id) {
                wp_send_json_error('Invalid subscription.');
            }
            
            $user_id = get_current_user_id();
            global $wpdb;
            
            // Verify the subscription belongs to the user and is active
            $subscription = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $this->table_name 
                 WHERE id = %d AND user_id = %d AND status = 'active'
                 AND (expiry_date IS NULL OR expiry_date > NOW())",
                $subscription_id, $user_id
            ));
            
            if (!$subscription) {
                wp_send_json_error('Invalid or expired subscription.');
            }
            
            // Check if user already has a URL for this subscription
            $existing_url = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $this->user_urls_table 
                 WHERE user_id = %d AND subscription_id = %d AND status = 'active'",
                $user_id, $subscription_id
            ));
            
            // Store the old URL for removal from whitelist
            $old_url = $existing_url ? $existing_url->url : null;
            
            if ($existing_url) {
                // Update existing URL
                $updated = $wpdb->update(
                    $this->user_urls_table,
                    array(
                        'url' => $new_url,
                        'created_at' => current_time('mysql')
                    ),
                    array('id' => $existing_url->id),
                    array('%s', '%s'),
                    array('%d')
                );
                
                if ($updated !== false) {
                    $this->update_bricks_whitelist_with_change($old_url, $new_url);
                    wp_send_json_success('URL has been successfully updated in the whitelist.');
                } else {
                    wp_send_json_error('Failed to update URL.');
                }
            } else {
                // Insert new URL
                $inserted = $wpdb->insert(
                    $this->user_urls_table,
                    array(
                        'user_id' => $user_id,
                        'subscription_id' => $subscription_id,
                        'url' => $new_url,
                        'status' => 'active'
                    ),
                    array('%d', '%d', '%s', '%s')
                );
                
                if ($inserted) {
                    $this->update_bricks_whitelist();
                    wp_send_json_success('URL has been successfully added to the whitelist.');
                } else {
                    wp_send_json_error('Failed to add URL.');
                }
            }
            
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Helper function for URL updates that removes old URL and adds new one
     */
    private function update_bricks_whitelist_with_change($old_url, $new_url) {
        // Get current Bricks settings
        $bricks_settings = get_option('bricks_global_settings', '');
        $settings = maybe_unserialize($bricks_settings);
        
        if (!is_array($settings)) {
            $settings = array();
        }
        
        // Get existing URLs from Bricks
        $existing_urls = isset($settings['myTemplatesWhitelist']) ? $settings['myTemplatesWhitelist'] : '';
        $existing_urls_array = array_filter(array_map('trim', explode("\n", $existing_urls)));
        
        // Remove old URL if it exists
        if ($old_url) {
            $existing_urls_array = array_filter($existing_urls_array, function($url) use ($old_url) {
                return trim($url) !== trim($old_url);
            });
        }
        
        // Add new URL if it's not already there
        if ($new_url && !in_array(trim($new_url), $existing_urls_array)) {
            $existing_urls_array[] = trim($new_url);
        }
        
        // Update the whitelist
        $settings['myTemplatesWhitelist'] = implode("\n", array_filter($existing_urls_array));
        
        // Save to database
        global $wpdb;
        $serialized_settings = serialize($settings);
        
        $wpdb->update(
            $wpdb->options,
            array('option_value' => $serialized_settings),
            array('option_name' => 'bricks_global_settings'),
            array('%s'),
            array('%s')
        );
        
        wp_cache_delete('bricks_global_settings', 'options');
    }
    
    /**
     * Helper function to update Bricks whitelist with all active URLs
     */
    private function update_bricks_whitelist() {
        global $wpdb;
        
        // Get all active URLs from our plugin
        $plugin_urls = $wpdb->get_results(
            "SELECT u.url FROM $this->user_urls_table u
             INNER JOIN $this->table_name s ON u.subscription_id = s.id
             WHERE u.status = 'active' AND s.status = 'active'
             AND (s.expiry_date IS NULL OR s.expiry_date > NOW())
             ORDER BY u.created_at ASC"
        );
        
        $plugin_managed_urls = array();
        foreach ($plugin_urls as $url_obj) {
            $plugin_managed_urls[] = trim($url_obj->url);
        }
        
        // Get current Bricks settings
        $bricks_settings = get_option('bricks_global_settings', '');
        $settings = maybe_unserialize($bricks_settings);
        
        if (!is_array($settings)) {
            $settings = array();
        }
        
        // Get existing URLs from Bricks
        $existing_urls = isset($settings['myTemplatesWhitelist']) ? $settings['myTemplatesWhitelist'] : '';
        $existing_urls_array = array_filter(array_map('trim', explode("\n", $existing_urls)));
        
        // Get all previously plugin-managed URLs
        $all_plugin_urls = $wpdb->get_results(
            "SELECT DISTINCT url FROM $this->user_urls_table WHERE status IN ('active', 'expired', 'removed')"
        );
        
        $all_plugin_managed_urls = array();
        foreach ($all_plugin_urls as $url_obj) {
            $all_plugin_managed_urls[] = trim($url_obj->url);
        }
        
        // Remove URLs that were previously managed by our plugin but are no longer active
        $preserved_urls = array();
        foreach ($existing_urls_array as $existing_url) {
            if (!in_array($existing_url, $all_plugin_managed_urls) || in_array($existing_url, $plugin_managed_urls)) {
                $preserved_urls[] = $existing_url;
            }
        }
        
        // Add new plugin-managed URLs that aren't already in the list
        foreach ($plugin_managed_urls as $plugin_url) {
            if (!in_array($plugin_url, $preserved_urls)) {
                $preserved_urls[] = $plugin_url;
            }
        }
        
        // Update the whitelist
        $settings['myTemplatesWhitelist'] = implode("\n", array_filter($preserved_urls));
        
        // Save to database
        $serialized_settings = serialize($settings);
        
        $wpdb->update(
            $wpdb->options,
            array('option_value' => $serialized_settings),
            array('option_name' => 'bricks_global_settings'),
            array('%s'),
            array('%s')
        );
        
        wp_cache_delete('bricks_global_settings', 'options');
    }
    
    // [Additional methods would continue here with similar commenting structure...]
    // For brevity, I'll indicate that the remaining methods follow the same pattern
    
    /**
     * WooCommerce Integration Methods
     * These methods handle product types, order processing, and subscription creation
     */
    
    public function woocommerce_integration() {
        if (!class_exists('WooCommerce') || !function_exists('WC') || !WC()) {
            return;
        }
        
        if (!has_filter('product_type_selector', array($this, 'add_subscription_product_type'))) {
            add_filter('product_type_selector', array($this, 'add_subscription_product_type'));
        }
        
        add_filter('woocommerce_product_class', array($this, 'get_subscription_product_class'), 10, 2);
        add_filter('woocommerce_is_purchasable', array($this, 'make_subscription_purchasable'), 10, 2);
        add_filter('woocommerce_product_supports', array($this, 'subscription_product_supports'), 10, 3);
    }
    
    public function get_subscription_product_class($classname, $product_type) {
        if ($product_type === 'recurring_subscription') {
            $classname = 'WC_Product_Recurring_Subscription';
        }
        return $classname;
    }
    
    public function make_subscription_purchasable($purchasable, $product) {
        if ($product && $product->get_type() === 'recurring_subscription') {
            $purchasable = $product->get_price() !== '' && $product->get_price() > 0;
        }
        return $purchasable;
    }
    
    public function subscription_product_supports($supports, $feature, $product) {
        if ($product && $product->get_type() === 'recurring_subscription') {
            switch ($feature) {
                case 'ajax_add_to_cart':
                    $supports = true;
                    break;
                case 'virtual':
                case 'downloadable':
                    $supports = false;
                    break;
            }
        }
        return $supports;
    }
    
    public function add_subscription_product_type($types) {
        if (!is_array($types)) {
            $types = array();
        }
        
        if (!isset($types['recurring_subscription'])) {
            $types['recurring_subscription'] = 'Recurring Subscription';
        }
        
        return $types;
    }
    
    // [Continue with remaining methods following the same commenting pattern...]
    
    /**
     * Schedule recurring billing and cleanup tasks
     */
    public function schedule_recurring_billing() {
        if (!wp_next_scheduled('process_recurring_billing')) {
            wp_schedule_event(time(), 'daily', 'process_recurring_billing');
        }
        if (!wp_next_scheduled('process_url_cleanup')) {
            wp_schedule_event(time(), 'daily', 'process_url_cleanup');
        }
    }
    
    /**
     * Process recurring payments (cron job)
     */
    public function process_recurring_payments() {
        global $wpdb;
        
        $due_subscriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $this->table_name 
             WHERE status = 'active' 
             AND next_billing_date <= %s",
            current_time('mysql')
        ));
        
        foreach ($due_subscriptions as $subscription) {
            $invoice_id = $this->create_invoice_with_payment($subscription->id, true);
            
            if ($invoice_id) {
                $next_billing = $subscription->subscription_type === 'monthly' 
                    ? date('Y-m-d H:i:s', strtotime($subscription->next_billing_date . ' +1 month'))
                    : date('Y-m-d H:i:s', strtotime($subscription->next_billing_date . ' +1 year'));
                
                $wpdb->update(
                    $this->table_name,
                    array(
                        'next_billing_date' => $next_billing,
                        'last_billing_date' => current_time('mysql')
                    ),
                    array('id' => $subscription->id),
                    array('%s', '%s'),
                    array('%d')
                );
            }
        }
    }
    
    /**
     * Clean up expired URLs (cron job)
     */
    public function cleanup_expired_urls() {
        global $wpdb;
        
        $expired_subscriptions = $wpdb->get_results(
            "SELECT id FROM $this->table_name 
             WHERE status != 'active' OR (expiry_date IS NOT NULL AND expiry_date <= NOW())"
        );
        
        foreach ($expired_subscriptions as $subscription) {
            $wpdb->update(
                $this->user_urls_table,
                array('status' => 'expired'),
                array('subscription_id' => $subscription->id),
                array('%s'),
                array('%d')
            );
        }
        
        $this->update_bricks_whitelist();
    }
    
    /**
     * Add subscription fields to product general tab
     */
    public function add_subscription_fields_to_general() {
        global $post;
        
        if (!$post || !is_object($post) || $post->post_type !== 'product') {
            return;
        }
        
        $is_subscription = get_post_meta($post->ID, '_is_subscription', true);
        $subscription_type = get_post_meta($post->ID, '_subscription_type', true);
        $subscription_duration = get_post_meta($post->ID, '_subscription_duration', true);
        
        if (empty($subscription_type)) {
            $subscription_type = 'monthly';
        }
        
        // Add nonce field for security
        wp_nonce_field('wc_recurring_billing_save', '_wc_recurring_billing_nonce');
        
        // Add hidden field to detect our form submission
        echo '<input type="hidden" name="_is_subscription_exists" value="1">';
        
        echo '<div class="options_group subscription_options" style="border-top: 1px solid #eee; padding-top: 15px; margin-top: 15px; background: #f8f9fa; padding: 15px; border-radius: 4px; border: 1px solid #dee2e6;">';
        
        ?>
        <p class="form-field">
            <label for="_is_subscription">
                <input type="checkbox" id="_is_subscription" name="_is_subscription" value="yes" <?php checked($is_subscription, 'yes'); ?> />
                Enable Recurring Subscription
            </label>
            <span class="description">Check this box to enable recurring billing for this product</span>
        </p>
        
        <div class="subscription_fields" id="subscription_fields" style="background: #f9f9f9; padding: 15px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; <?php echo ($is_subscription !== 'yes') ? 'display: none;' : ''; ?>">
            <h4 style="margin: 0 0 15px 0; color: #0073aa; border-bottom: 1px solid #ddd; padding-bottom: 8px;">Subscription Settings</h4>
            
            <p class="form-field">
                <label for="_subscription_type">Billing Interval</label>
                <select name="_subscription_type" id="_subscription_type" style="width: 100%;">
                    <option value="monthly" <?php selected($subscription_type, 'monthly'); ?>>Monthly</option>
                    <option value="yearly" <?php selected($subscription_type, 'yearly'); ?>>Yearly</option>
                </select>
                <span class="description">How often the customer will be billed</span>
            </p>
            
            <p class="form-field">
                <label for="_subscription_duration">Duration (months)</label>
                <input type="number" name="_subscription_duration" id="_subscription_duration" 
                       value="<?php echo esc_attr($subscription_duration); ?>" 
                       placeholder="Leave empty for lifetime" 
                       style="width: 100%;" step="1" min="0" />
                <span class="description">How long the subscription lasts in months. Leave empty for lifetime subscription.</span>
            </p>
            
            <div style="background: #e7f3ff; padding: 10px; margin: 10px 0; border-left: 4px solid #0073aa; border-radius: 4px;">
                <strong>💡 Examples:</strong><br>
                • Monthly $9.99 = Customer pays $9.99 every month<br>
                • Yearly $99.99 = Customer pays $99.99 every year<br>
                • Duration: 12 months = Subscription ends after 12 months<br>
                • Duration: (empty) = Lifetime subscription
            </div>
            
            <div style="background: #fff; padding: 10px; margin: 10px 0; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 12px;">
                <strong>Preview:</strong><br>
                <span id="subscription-preview">Configure settings above to see preview</span>
            </div>
        </div>
        
        <?php
        echo '</div>';
        
        ?>
        <script>
        jQuery(document).ready(function($) {
            
            function updatePreview() {
                var isChecked = $('#_is_subscription').is(':checked');
                var price = $('#_regular_price').val() || '0.00';
                var type = $('#_subscription_type').val();
                var duration = $('#_subscription_duration').val();
                
                if (isChecked) {
                    var preview = ' + parseFloat(price).toFixed(2) + ' per ' + type.replace('ly', '');
                    if (duration && duration > 0) {
                        preview += ' for ' + duration + ' months';
                    } else {
                        preview += ' (lifetime)';
                    }
                    $('#subscription-preview').html(preview);
                } else {
                    $('#subscription-preview').html('Subscription not enabled');
                }
            }
            
            function toggleSubscriptionFields() {
                var isChecked = $('#_is_subscription').is(':checked');
                
                if (isChecked) {
                    $('#subscription_fields').slideDown(200);
                } else {
                    $('#subscription_fields').slideUp(200);
                }
                
                updatePreview();
            }
            
            // Initialize
            toggleSubscriptionFields();
            updatePreview();
            
            // Bind events
            $('#_is_subscription').on('change', toggleSubscriptionFields);
            $('#_subscription_type, #_subscription_duration, #_regular_price').on('change keyup', updatePreview);
        });
        </script>
        
        <style>
        .subscription_options {
            border: 1px solid #dee2e6 !important;
        }
        .subscription_fields {
            animation: fadeIn 0.3s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .subscription_fields h4 {
            color: #0073aa !important;
        }
        .subscription-details {
            display: block;
            color: #666;
            font-weight: normal;
            font-size: 0.9em;
        }
        </style>
        <?php
    }
    
    /**
     * Save subscription product meta data
     */
    public function save_subscription_product_meta($post_id) {
        
        // Verify nonce for security
        if (!isset($_POST['_wc_recurring_billing_nonce']) || 
            !wp_verify_nonce($_POST['_wc_recurring_billing_nonce'], 'wc_recurring_billing_save')) {
            return;
        }
        
        // Check if our form was submitted
        if (!isset($_POST['_is_subscription_exists'])) {
            return;
        }
        
        // Security checks
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            return;
        }
        
        if (get_post_type($post_id) !== 'product') {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Save subscription settings
        $is_subscription = isset($_POST['_is_subscription']) && $_POST['_is_subscription'] === 'yes' ? 'yes' : 'no';
        update_post_meta($post_id, '_is_subscription', $is_subscription);
        
        if (isset($_POST['_subscription_type'])) {
            $subscription_type = sanitize_text_field($_POST['_subscription_type']);
            if (in_array($subscription_type, ['monthly', 'yearly'])) {
                update_post_meta($post_id, '_subscription_type', $subscription_type);
            }
        } else {
            update_post_meta($post_id, '_subscription_type', 'monthly');
        }
        
        if (isset($_POST['_subscription_duration'])) {
            $duration = sanitize_text_field($_POST['_subscription_duration']);
            $duration_int = ($duration === '') ? '' : intval($duration);
            update_post_meta($post_id, '_subscription_duration', $duration_int);
        } else {
            update_post_meta($post_id, '_subscription_duration', '');
        }
        
        // Clear cache
        wp_cache_delete($post_id, 'post_meta');
        clean_post_cache($post_id);
        
        error_log("WC Recurring Billing: Product #" . $post_id . " saved successfully");
    }
    
    /**
     * Test function for manual subscription creation
     */
    public function ajax_test_subscription_creation() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        
        if (!$order_id) {
            wp_die('No order ID provided');
        }
        
        echo "<h2>Testing Subscription Creation for Order #" . $order_id . "</h2>";
        echo "<pre>";
        
        // Trigger the subscription creation
        $this->handle_subscription_purchase($order_id);
        
        echo "</pre>";
        echo "<p><a href='" . admin_url('admin.php?page=wc-recurring-billing') . "'>Back to Subscriptions</a></p>";
        
        wp_die();
    }
    
    /**
     * Handle subscription purchase when order is completed
     */
    public function handle_subscription_purchase($order_id) {
        // Make sure WooCommerce is available
        if (!function_exists('wc_get_order')) {
            error_log("WC Recurring Billing: WooCommerce not available");
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log("WC Recurring Billing: Could not get order #" . $order_id);
            return;
        }
        
        // Add debug logging
        error_log("WC Recurring Billing: Processing order #" . $order_id . " with status: " . $order->get_status());
        
        $found_subscription = false;
        
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            if (!$product) {
                error_log("WC Recurring Billing: No product found for item #" . $item_id);
                continue;
            }
            
            $product_id = $product->get_id();
            error_log("WC Recurring Billing: Checking product #" . $product_id . " - " . $product->get_name());
            
            // Check if this product is a subscription (using meta field instead of product type)
            $is_subscription = get_post_meta($product_id, '_is_subscription', true);
            $subscription_type = get_post_meta($product_id, '_subscription_type', true);
            $subscription_duration = get_post_meta($product_id, '_subscription_duration', true);
            
            error_log("WC Recurring Billing: Product #" . $product_id . " meta - is_subscription: '" . $is_subscription . "', type: '" . $subscription_type . "', duration: '" . $subscription_duration . "'");
            
            if ($is_subscription === 'yes') {
                $found_subscription = true;
                $user_id = $order->get_user_id();
                
                if (!$user_id) {
                    error_log("WC Recurring Billing: No user ID for order #" . $order_id);
                    continue;
                }
                
                $amount = $item->get_total();
                
                error_log("WC Recurring Billing: Creating subscription for user #" . $user_id . " - Type: '" . $subscription_type . "' - Amount: " . $amount);
                
                // Validate subscription type
                if (!in_array($subscription_type, ['monthly', 'yearly'])) {
                    $subscription_type = 'monthly'; // Default fallback
                    error_log("WC Recurring Billing: Invalid subscription type, defaulting to monthly");
                }
                
                // Calculate next billing date
                $next_billing = $subscription_type === 'monthly' 
                    ? date('Y-m-d H:i:s', strtotime('+1 month'))
                    : date('Y-m-d H:i:s', strtotime('+1 year'));
                
                // Calculate expiry date
                $expiry_date = null;
                if ($subscription_duration && $subscription_duration > 0) {
                    $expiry_date = date('Y-m-d H:i:s', strtotime("+{$subscription_duration} months"));
                }
                
                error_log("WC Recurring Billing: Next billing: " . $next_billing . ", Expiry: " . ($expiry_date ?: 'never'));
                
                // Create subscription
                global $wpdb;
                
                // Check if table exists
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
                if (!$table_exists) {
                    error_log("WC Recurring Billing: Table {$this->table_name} does not exist!");
                    $this->create_tables(); // Try to create it
                }
                
                $result = $wpdb->insert(
                    $this->table_name,
                    array(
                        'user_id' => $user_id,
                        'subscription_type' => $subscription_type,
                        'amount' => $amount,
                        'next_billing_date' => $next_billing,
                        'expiry_date' => $expiry_date,
                        'status' => 'active'
                    ),
                    array('%d', '%s', '%f', '%s', '%s', '%s')
                );
                
                if ($result) {
                    $subscription_id = $wpdb->insert_id;
                    error_log("WC Recurring Billing: Subscription created successfully with ID: " . $subscription_id);
                    
                    // Send welcome email if subscription was created successfully
                    $user = get_user_by('ID', $user_id);
                    if ($user && $user->user_email) {
                        $subject = 'Welcome to Your Subscription!';
                        $message = "Hello {$user->display_name},\n\n";
                        $message .= "Thank you for purchasing a subscription!\n";
                        $message .= "Product: {$product->get_name()}\n";
                        $message .= "Subscription Type: " . ucfirst($subscription_type) . "\n";
                        $message .= "Amount: $" . number_format($amount, 2) . "\n";
                        if ($expiry_date) {
                            $message .= "Expires: " . date('F j, Y', strtotime($expiry_date)) . "\n";
                        } else {
                            $message .= "Duration: Lifetime\n";
                        }
                        $message .= "\nYou can now log into your account and submit your URL in the URL Manager section.\n";
                        
                        $email_sent = wp_mail($user->user_email, $subject, $message);
                        error_log("WC Recurring Billing: Welcome email " . ($email_sent ? 'sent' : 'failed') . " to " . $user->user_email);
                    }
                    
                    // Add order note
                    $order->add_order_note("Recurring subscription created successfully for {$product->get_name()} (ID: {$subscription_id})");
                } else {
                    error_log("WC Recurring Billing: Failed to create subscription - DB Error: " . $wpdb->last_error);
                    error_log("WC Recurring Billing: Last query: " . $wpdb->last_query);
                }
            }
        }
        
        if (!$found_subscription) {
            error_log("WC Recurring Billing: No subscription products found in order #" . $order_id);
        }
    }
    
    /**
     * Initialize payment processing hooks
     */
    public function init_payment_processing() {
        add_action('woocommerce_payment_complete', array($this, 'process_subscription_payment'), 10, 1);
        add_action('woocommerce_order_status_completed', array($this, 'process_subscription_payment'), 10, 1);
        add_action('woocommerce_thankyou', array($this, 'add_subscription_info_to_thankyou'), 20, 1);
    }
    
    /**
     * Process payment for subscription invoices
     */
    public function process_subscription_payment($order_id) {
        global $wpdb;
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        $invoices_table = $wpdb->prefix . 'recurring_invoices';
        
        // Check if this order was created for an invoice
        $invoice_id = $order->get_meta('_recurring_invoice_id');
        
        if ($invoice_id) {
            // Mark invoice as paid
            $wpdb->update(
                $invoices_table,
                array(
                    'status' => 'paid',
                    'paid_at' => current_time('mysql')
                ),
                array('id' => $invoice_id),
                array('%s', '%s'),
                array('%d')
            );
            
            error_log("WC Recurring Billing: Invoice #" . $invoice_id . " marked as paid");
        }
    }
    
    /**
     * Add subscription information to thank you page
     */
    public function add_subscription_info_to_thankyou($order_id) {
        global $wpdb;
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Check if order contains subscription products
        $has_subscription = false;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && get_post_meta($product->get_id(), '_is_subscription', true) === 'yes') {
                $has_subscription = true;
                break;
            }
        }
        
        if ($has_subscription) {
            ?>
            <div class="subscription-info" style="background: #e7f3ff; padding: 20px; margin: 20px 0; border-left: 4px solid #0073aa; border-radius: 4px;">
                <h3 style="color: #0073aa; margin-top: 0;">🎉 Subscription Activated!</h3>
                <p><strong>Great news!</strong> Your subscription has been activated and you now have access to:</p>
                <ul>
                    <li>✅ URL Manager in your account dashboard</li>
                    <li>✅ Template whitelist management</li>
                    <li>✅ Automatic billing management</li>
                </ul>
                <p><a href="<?php echo wc_get_account_endpoint_url('url-manager'); ?>" class="button">Manage Your URLs</a></p>
            </div>
            <?php
        }
    }
    
    /**
     * Create invoice with payment link
     */
    public function create_invoice_with_payment($subscription_id, $send_email = true) {
        global $wpdb;
        
        // Get subscription details
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE id = %d",
            $subscription_id
        ));
        
        if (!$subscription) {
            return false;
        }
        
        // Generate invoice number
        $invoice_number = 'INV-' . date('Y') . '-' . str_pad($subscription_id, 6, '0', STR_PAD_LEFT) . '-' . time();
        
        // Insert invoice
        $invoices_table = $wpdb->prefix . 'recurring_invoices';
        $result = $wpdb->insert(
            $invoices_table,
            array(
                'subscription_id' => $subscription_id,
                'user_id' => $subscription->user_id,
                'invoice_number' => $invoice_number,
                'amount' => $subscription->amount,
                'status' => 'pending'
            ),
            array('%d', '%d', '%s', '%f', '%s')
        );
        
        if ($result) {
            $invoice_id = $wpdb->insert_id;
            
            if ($send_email) {
                $this->send_invoice_email($invoice_id);
            }
            
            return $invoice_id;
        }
        
        return false;
    }
    
    /**
     * Send invoice email with payment link
     */
    public function send_invoice_email($invoice_id) {
        global $wpdb;
        
        $invoices_table = $wpdb->prefix . 'recurring_invoices';
        $invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT i.*, s.subscription_type, u.display_name, u.user_email 
             FROM $invoices_table i 
             LEFT JOIN $this->table_name s ON i.subscription_id = s.id 
             LEFT JOIN {$wpdb->users} u ON i.user_id = u.ID 
             WHERE i.id = %d",
            $invoice_id
        ));
        
        if (!$invoice) {
            return false;
        }
        
        // Create payment URL
        $payment_url = add_query_arg(array(
            'pay_invoice' => $invoice_id,
            'invoice_key' => wp_hash($invoice->invoice_number)
        ), home_url());
        
        $subject = 'Invoice ' . $invoice->invoice_number . ' - Payment Due';
        
        $message = "Hello {$invoice->display_name},\n\n";
        $message .= "Your subscription invoice is ready:\n\n";
        $message .= "Invoice Number: {$invoice->invoice_number}\n";
        $message .= "Amount: $" . number_format($invoice->amount, 2) . "\n";
        $message .= "Subscription: " . ucfirst($invoice->subscription_type) . "\n";
        $message .= "Due Date: " . date('F j, Y', strtotime($invoice->created_at . ' +7 days')) . "\n\n";
        $message .= "Click here to pay online:\n";
        $message .= $payment_url . "\n\n";
        $message .= "Or log into your account to manage your subscription:\n";
        $message .= wc_get_account_endpoint_url('url-manager') . "\n\n";
        $message .= "Thank you for your business!";
        
        return wp_mail($invoice->user_email, $subject, $message);
    }
    
    /**
     * Initialize payment handlers
     */
    public function init_payment_handlers() {
        add_action('init', array($this, 'handle_invoice_payment_requests'));
        add_action('wp', array($this, 'process_invoice_payment_page'));
        add_action('woocommerce_checkout_process', array($this, 'validate_invoice_payment'));
        add_action('woocommerce_checkout_order_processed', array($this, 'link_order_to_invoice'));
    }
    
    /**
     * Handle invoice payment URLs
     */
    public function handle_invoice_payment_requests() {
        if (isset($_GET['pay_invoice']) && isset($_GET['invoice_key'])) {
            $this->display_invoice_payment_page();
            exit;
        }
    }
    
    /**
     * Display invoice payment page
     */
    public function display_invoice_payment_page() {
        $invoice_id = intval($_GET['pay_invoice']);
        $invoice_key = sanitize_text_field($_GET['invoice_key']);
        
        global $wpdb;
        $invoices_table = $wpdb->prefix . 'recurring_invoices';
        
        // Get invoice details
        $invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT i.*, s.subscription_type, u.display_name, u.user_email 
             FROM $invoices_table i 
             LEFT JOIN $this->table_name s ON i.subscription_id = s.id 
             LEFT JOIN {$wpdb->users} u ON i.user_id = u.ID 
             WHERE i.id = %d",
            $invoice_id
        ));
        
        if (!$invoice || wp_hash($invoice->invoice_number) !== $invoice_key) {
            wp_die('Invalid invoice link.');
        }
        
        if ($invoice->status === 'paid') {
            wp_die('This invoice has already been paid.');
        }
        
        get_header();
        ?>
        <div class="invoice-payment-page" style="max-width: 800px; margin: 40px auto; padding: 20px;">
            <div class="invoice-header" style="background: #f8f9fa; padding: 30px; border-radius: 8px; margin-bottom: 30px; text-align: center;">
                <h1 style="color: #0073aa; margin: 0 0 10px 0;">Invoice Payment</h1>
                <p style="color: #666; margin: 0;">Invoice #<?php echo esc_html($invoice->invoice_number); ?></p>
            </div>
            
            <div class="invoice-details" style="background: white; padding: 30px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 30px;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div>
                        <h3 style="margin: 0 0 15px 0; color: #333;">Invoice Details</h3>
                        <p><strong>Customer:</strong> <?php echo esc_html($invoice->display_name); ?></p>
                        <p><strong>Email:</strong> <?php echo esc_html($invoice->user_email); ?></p>
                        <p><strong>Subscription:</strong> <?php echo ucfirst($invoice->subscription_type); ?></p>
                        <p><strong>Invoice Date:</strong> <?php echo date('F j, Y', strtotime($invoice->created_at)); ?></p>
                    </div>
                    <div style="text-align: right;">
                        <h3 style="margin: 0 0 15px 0; color: #333;">Amount Due</h3>
                        <div style="font-size: 36px; font-weight: bold; color: #0073aa; margin: 20px 0;">
                            $<?php echo number_format($invoice->amount, 2); ?>
                        </div>
                        <p style="color: #666;">Due: <?php echo date('F j, Y', strtotime($invoice->created_at . ' +7 days')); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="payment-options" style="background: white; padding: 30px; border: 1px solid #ddd; border-radius: 8px;">
                <h3 style="margin: 0 0 20px 0; color: #333;">Payment Options</h3>
                
                <!-- Credit Card Payment Option -->
                <div class="payment-method" style="border: 1px solid #ddd; border-radius: 6px; padding: 20px; margin-bottom: 15px;">
                    <h4 style="margin: 0 0 10px 0;">💳 Pay with Credit Card</h4>
                    <p style="color: #666; margin: 0 0 15px 0;">Secure payment processing through our checkout system</p>
                    <form method="post" action="<?php echo esc_url(home_url()); ?>">
                        <input type="hidden" name="invoice_payment" value="<?php echo $invoice_id; ?>">
                        <input type="hidden" name="invoice_key" value="<?php echo esc_attr($invoice_key); ?>">
                        <?php wp_nonce_field('pay_invoice_' . $invoice_id, 'invoice_nonce'); ?>
                        <button type="submit" class="button" style="background: #0073aa; color: white; padding: 12px 24px; border: none; border-radius: 4px; font-size: 16px; cursor: pointer;">
                            Pay $<?php echo number_format($invoice->amount, 2); ?> Now
                        </button>
                    </form>
                </div>
                
                <!-- Bank Transfer Option -->
                <div class="payment-method" style="border: 1px solid #ddd; border-radius: 6px; padding: 20px;">
                    <h4 style="margin: 0 0 10px 0;">🏦 Bank Transfer</h4>
                    <p style="color: #666; margin: 0 0 15px 0;">Send payment directly to our bank account</p>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 4px; font-family: monospace;">
                        <p><strong>Account Name:</strong> Your Business Name</p>
                        <p><strong>Account Number:</strong> 1234567890</p>
                        <p><strong>Routing Number:</strong> 123456789</p>
                        <p><strong>Reference:</strong> <?php echo esc_html($invoice->invoice_number); ?></p>
                    </div>
                    <p style="margin-top: 15px; color: #666; font-size: 14px;">
                        <em>Please include the invoice number as reference. Payment processing may take 2-3 business days.</em>
                    </p>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 30px;">
                <p style="color: #666;">Questions? <a href="mailto:support@yoursite.com">Contact Support</a></p>
            </div>
        </div>
        
        <style>
        @media (max-width: 768px) {
            .invoice-payment-page {
                margin: 20px auto;
                padding: 10px;
            }
            .invoice-details > div {
                grid-template-columns: 1fr !important;
            }
        }
        </style>
        <?php
        get_footer();
    }
    
    /**
     * Process invoice payment through WooCommerce checkout
     */
    public function process_invoice_payment_page() {
        if (!isset($_POST['invoice_payment']) || !isset($_POST['invoice_key'])) {
            return;
        }
        
        $invoice_id = intval($_POST['invoice_payment']);
        $invoice_key = sanitize_text_field($_POST['invoice_key']);
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['invoice_nonce'], 'pay_invoice_' . $invoice_id)) {
            wc_add_notice('Security check failed.', 'error');
            return;
        }
        
        // Get invoice
        global $wpdb;
        $invoices_table = $wpdb->prefix . 'recurring_invoices';
        $invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $invoices_table WHERE id = %d",
            $invoice_id
        ));
        
        if (!$invoice || wp_hash($invoice->invoice_number) !== $invoice_key) {
            wc_add_notice('Invalid invoice.', 'error');
            return;
        }
        
        if ($invoice->status === 'paid') {
            wc_add_notice('This invoice has already been paid.', 'error');
            return;
        }
        
        // Create a temporary product for the invoice
        $this->create_invoice_payment_order($invoice);
    }
    
    /**
     * Create WooCommerce order for invoice payment
     */
    public function create_invoice_payment_order($invoice) {
        // Clear cart first
        WC()->cart->empty_cart();
        
        // Create a virtual product for the invoice
        $product = new WC_Product_Simple();
        $product->set_name('Invoice Payment - ' . $invoice->invoice_number);
        $product->set_regular_price($invoice->amount);
        $product->set_virtual(true);
        $product->set_catalog_visibility('hidden');
        $product->save();
        
        // Add to cart
        WC()->cart->add_to_cart($product->get_id(), 1);
        
        // Store invoice ID in session
        WC()->session->set('paying_invoice_id', $invoice->id);
        
        // Redirect to checkout
        wp_redirect(wc_get_checkout_url());
        exit;
    }
    
    /**
     * Link completed order to invoice
     */
    public function link_order_to_invoice($order_id) {
        $invoice_id = WC()->session->get('paying_invoice_id');
        
        if ($invoice_id) {
            global $wpdb;
            $invoices_table = $wpdb->prefix . 'recurring_invoices';
            
            // Update invoice status
            $wpdb->update(
                $invoices_table,
                array(
                    'status' => 'paid',
                    'paid_at' => current_time('mysql')
                ),
                array('id' => $invoice_id),
                array('%s', '%s'),
                array('%d')
            );
            
            // Add order meta
            $order = wc_get_order($order_id);
            $order->update_meta_data('_recurring_invoice_id', $invoice_id);
            $order->save();
            
            // Clear session
            WC()->session->__unset('paying_invoice_id');
            
            // Send confirmation email
            $this->send_payment_confirmation_email($invoice_id, $order_id);
        }
    }
    
    /**
     * Send payment confirmation email
     */
    public function send_payment_confirmation_email($invoice_id, $order_id) {
        global $wpdb;
        $invoices_table = $wpdb->prefix . 'recurring_invoices';
        
        $invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT i.*, u.display_name, u.user_email 
             FROM $invoices_table i 
             LEFT JOIN {$wpdb->users} u ON i.user_id = u.ID 
             WHERE i.id = %d",
            $invoice_id
        ));
        
        if (!$invoice) {
            return;
        }
        
        $subject = 'Payment Confirmation - Invoice ' . $invoice->invoice_number;
        
        $message = "Hello {$invoice->display_name},\n\n";
        $message .= "Thank you! Your payment has been received.\n\n";
        $message .= "Payment Details:\n";
        $message .= "Invoice: {$invoice->invoice_number}\n";
        $message .= "Amount: $" . number_format($invoice->amount, 2) . "\n";
        $message .= "Order: #{$order_id}\n";
        $message .= "Paid: " . date('F j, Y \a\t g:i A') . "\n\n";
        $message .= "Your subscription continues to be active.\n";
        $message .= "Manage your account: " . wc_get_account_endpoint_url('url-manager') . "\n\n";
        $message .= "Thank you for your business!";
        
        wp_mail($invoice->user_email, $subject, $message);
    }
    
    /**
     * Add custom rewrite rules for payment handlers
     */
    public function add_payment_rewrite_rules() {
        add_rewrite_rule('^invoice-payment/([0-9]+)/?', 'index.php?pay_invoice=$matches[1]', 'top');
    }
    
    /**
     * Handle payment rewrite queries
     */
    public function handle_payment_queries($vars) {
        $vars[] = 'pay_invoice';
        return $vars;
    }
    
    /**
     * Get subscription status with expiry check
     */
    public function get_subscription_status($subscription_id) {
        global $wpdb;
        
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE id = %d",
            $subscription_id
        ));
        
        if (!$subscription) {
            return 'not_found';
        }
        
        // Check if expired
        if ($subscription->expiry_date && strtotime($subscription->expiry_date) < time()) {
            return 'expired';
        }
        
        return $subscription->status;
    }
    
    /**
     * Check if user has active subscription
     */
    public function user_has_active_subscription($user_id) {
        global $wpdb;
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $this->table_name 
             WHERE user_id = %d AND status = 'active' 
             AND (expiry_date IS NULL OR expiry_date > NOW())",
            $user_id
        ));
        
        return $count > 0;
    }
    
    /**
     * Get user's subscription info
     */
    public function get_user_subscription_info($user_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name 
             WHERE user_id = %d AND status = 'active' 
             AND (expiry_date IS NULL OR expiry_date > NOW())
             ORDER BY created_at DESC LIMIT 1",
            $user_id
        ));
    }
    
    /**
     * Validate invoice payment (placeholder for additional validation)
     */
    public function validate_invoice_payment() {
        // Add any additional checkout validation here if needed
    }
}

// Initialize the plugin
new WC_Recurring_Billing_Manager();