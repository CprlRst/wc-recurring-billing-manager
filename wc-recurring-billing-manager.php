<?php
/**
 * Plugin Name: WooCommerce Recurring Billing Manager
 * Description: Manages recurring subscriptions with URL whitelist management and invoicing
 * Version: 1.0.85
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

class WC_Recurring_Billing_Manager {
    
    private $table_name;
    private $user_urls_table;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'recurring_subscriptions';
        $this->user_urls_table = $wpdb->prefix . 'user_whitelist_urls';
        
        // Ensure database is up to date
        add_action('init', array($this, 'check_database_version'));
        
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        
        // Admin hooks
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_notices', array($this, 'admin_notices'));
        add_action('wp_ajax_repair_database', array($this, 'ajax_repair_database'));
        add_action('wp_ajax_manage_subscription', array($this, 'ajax_manage_subscription'));
        add_action('wp_ajax_create_invoice', array($this, 'ajax_create_invoice'));
        add_action('wp_ajax_refresh_whitelist', array($this, 'ajax_refresh_whitelist'));
        add_action('wp_ajax_remove_user_url', array($this, 'ajax_remove_user_url'));
        
        // Frontend hooks
        add_action('wp_ajax_submit_url', array($this, 'ajax_submit_url'));
        add_action('wp_ajax_nopriv_submit_url', array($this, 'ajax_submit_url'));
        
        // WooCommerce hooks
        add_action('woocommerce_account_menu_items', array($this, 'add_account_menu_item'));
        add_action('woocommerce_account_url-manager_endpoint', array($this, 'url_manager_content'));
        add_filter('woocommerce_account_menu_items', array($this, 'reorder_account_menu'));
        
        // WooCommerce integration - only load if WooCommerce is properly initialized
        add_action('woocommerce_init', array($this, 'woocommerce_integration'));
        
        // Hook into multiple order status changes to catch subscriptions
        add_action('woocommerce_order_status_completed', array($this, 'handle_subscription_purchase'));
        add_action('woocommerce_order_status_processing', array($this, 'handle_subscription_purchase'));
        add_action('woocommerce_payment_complete', array($this, 'handle_subscription_purchase'));
        add_action('woocommerce_thankyou', array($this, 'handle_subscription_purchase'));
        
        // Only add product hooks when in admin and editing products
        if (is_admin()) {
            add_action('admin_init', array($this, 'init_woocommerce_product_hooks'));
            
            // Add manual trigger for testing
            add_action('wp_ajax_test_subscription_creation', array($this, 'ajax_test_subscription_creation'));
        }
        
        // Cron hooks for recurring billing and URL cleanup
        add_action('wp', array($this, 'schedule_recurring_billing'));
        add_action('process_recurring_billing', array($this, 'process_recurring_payments'));
        add_action('process_url_cleanup', array($this, 'cleanup_expired_urls'));
        
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init_woocommerce_product_hooks() {
        // Only add hooks if WooCommerce is active and we're editing products
        if (!class_exists('WooCommerce') || !function_exists('wc_get_product')) {
            return;
        }
        
        global $pagenow;
        if ($pagenow === 'post.php' || $pagenow === 'post-new.php') {
            $post_type = isset($_GET['post_type']) ? $_GET['post_type'] : 
                        (isset($_GET['post']) ? get_post_type($_GET['post']) : '');
            
            if ($post_type === 'product' || (isset($_GET['post']) && get_post_type($_GET['post']) === 'product')) {
                // Add subscription fields to General tab instead of custom tab
                add_action('woocommerce_product_options_general_product_data', array($this, 'add_subscription_fields_to_general'));
                
                // Try multiple save hooks with different priorities
                add_action('woocommerce_process_product_meta', array($this, 'save_subscription_product_meta'), 1);
                add_action('save_post', array($this, 'save_subscription_product_meta'), 1);
                add_action('wp_insert_post_data', array($this, 'save_subscription_product_meta_early'), 1, 2);
            }
        }
    }
    
    // Even earlier save hook
    public function save_subscription_product_meta_early($data, $postarr) {
        if (isset($postarr['ID']) && $postarr['post_type'] === 'product') {
            error_log("WC Recurring Billing: wp_insert_post_data hook triggered for product ID: " . $postarr['ID']);
            $this->save_subscription_product_meta($postarr['ID']);
        }
        return $data;
    }
    
    public function check_database_version() {
        $this->update_database_if_needed();
    }
    
    public function init() {
        // Add rewrite endpoint for account page
        add_rewrite_endpoint('url-manager', EP_ROOT | EP_PAGES);
        
        // Flush rewrite rules if needed
        if (get_option('wc_recurring_billing_flush_rewrite_rules') === 'yes') {
            flush_rewrite_rules();
            delete_option('wc_recurring_billing_flush_rewrite_rules');
        }
    }
    
    public function activate() {
        $this->create_tables();
        $this->update_database_if_needed();
        add_option('wc_recurring_billing_flush_rewrite_rules', 'yes');
    }
    
    private function update_database_if_needed() {
        $current_version = get_option('wc_recurring_billing_db_version', '1.0');
        $new_version = '1.1';
        
        if (version_compare($current_version, $new_version, '<')) {
            $this->update_database_structure();
            update_option('wc_recurring_billing_db_version', $new_version);
        }
    }
    
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
    
    public function deactivate() {
        wp_clear_scheduled_hook('process_recurring_billing');
        wp_clear_scheduled_hook('process_url_cleanup');
    }
    
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
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
    
    public function admin_page() {
        global $wpdb;
        
        $subscriptions = $wpdb->get_results(
            "SELECT s.*, u.display_name, u.user_email 
             FROM $this->table_name s 
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID 
             ORDER BY s.created_at DESC"
        );
        
        ?>
        <div class="wrap">
            <h1>Recurring Billing Subscriptions</h1>
            
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
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Next Billing</th>
                        <th>Expires</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subscriptions as $subscription): ?>
                    <tr>
                        <td><?php echo $subscription->id; ?></td>
                        <td>
                            <?php echo $subscription->display_name; ?><br>
                            <small><?php echo $subscription->user_email; ?></small>
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
                                    data-action="<?php echo $subscription->status === 'active' ? 'pause' : 'activate'; ?>">
                                <?php echo $subscription->status === 'active' ? 'Pause' : 'Activate'; ?>
                            </button>
                            <button class="button create-invoice" data-id="<?php echo $subscription->id; ?>">
                                Create Invoice
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#create-subscription-form').on('submit', function(e) {
                e.preventDefault();
                
                $.post(ajaxurl, {
                    action: 'manage_subscription',
                    operation: 'create',
                    user_id: $('#user_id').val(),
                    subscription_type: $('#subscription_type').val(),
                    amount: $('#amount').val(),
                    nonce: '<?php echo wp_create_nonce('wc_recurring_billing_admin_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            });
            
            $('.manage-subscription').on('click', function() {
                var id = $(this).data('id');
                var action = $(this).data('action');
                
                $.post(ajaxurl, {
                    action: 'manage_subscription',
                    operation: action,
                    subscription_id: id,
                    nonce: '<?php echo wp_create_nonce('wc_recurring_billing_admin_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            });
            
            $('.create-invoice').on('click', function() {
                var id = $(this).data('id');
                
                $.post(ajaxurl, {
                    action: 'create_invoice',
                    subscription_id: id,
                    nonce: '<?php echo wp_create_nonce('wc_recurring_billing_admin_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('Invoice created successfully!');
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
        .status-paused { color: #ffb900; font-weight: bold; }
        .status-cancelled { color: #dc3232; font-weight: bold; }
        </style>
        <?php
    }
    
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
            
            <div class="current-whitelist" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
                <h2>Current Bricks Whitelist</h2>
                <div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; font-family: monospace; white-space: pre-wrap; max-height: 300px; overflow-y: auto;">
                    <?php echo $current_whitelist ? esc_html($current_whitelist) : 'No URLs in whitelist'; ?>
                </div>
                <button type="button" id="refresh-whitelist" class="button" style="margin-top: 10px;">Refresh Whitelist</button>
            </div>
            
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
    
    public function add_account_menu_item($items) {
        // Add URL Manager to account menu
        $items['url-manager'] = 'URL Manager';
        return $items;
    }
    
    public function reorder_account_menu($items) {
        // Reorder menu items
        $new_items = array();
        foreach ($items as $key => $item) {
            $new_items[$key] = $item;
            if ($key === 'dashboard') {
                $new_items['url-manager'] = 'URL Manager';
            }
        }
        return $new_items;
    }
    
    public function url_manager_content() {
        $user_id = get_current_user_id();
        
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
                <!-- Only show URL management if user has active subscription -->
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
                
                <script>
                jQuery(document).ready(function($) {
                    // URL validation function
                    function validateURL(url) {
                        var urlPattern = /^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/;
                        var httpPattern = /^https?:\/\//;
                        
                        if (!url) {
                            return { valid: false, message: 'Please enter a URL' };
                        }
                        
                        if (!httpPattern.test(url)) {
                            return { valid: false, message: 'URL must start with http:// or https://' };
                        }
                        
                        if (!urlPattern.test(url)) {
                            return { valid: false, message: 'Please enter a valid URL format' };
                        }
                        
                        try {
                            new URL(url);
                            return { valid: true, message: 'Valid URL ✓' };
                        } catch (e) {
                            return { valid: false, message: 'Invalid URL format' };
                        }
                    }
                    
                    // Real-time validation
                    $('#new_url').on('input keyup paste', function() {
                        var url = $(this).val().trim();
                        var validation = validateURL(url);
                        var messageDiv = $('#url-validation-message');
                        var submitButton = $('#submit-button');
                        
                        if (validation.valid) {
                            messageDiv.html('<span style="color: #28a745;">' + validation.message + '</span>');
                            submitButton.prop('disabled', false);
                            $(this).css('border-color', '#28a745');
                        } else {
                            messageDiv.html('<span style="color: #dc3545;">' + validation.message + '</span>');
                            submitButton.prop('disabled', true);
                            $(this).css('border-color', '#dc3545');
                        }
                        
                        if (!url) {
                            messageDiv.html('');
                            $(this).css('border-color', '');
                            submitButton.prop('disabled', true);
                        }
                    });
                    
                    // Trigger validation on page load if there's a value
                    if ($('#new_url').val()) {
                        $('#new_url').trigger('input');
                    }
                    
                    // Form submission
                    $('#url-submission-form').on('submit', function(e) {
                        e.preventDefault();
                        
                        var newUrl = $('#new_url').val().trim();
                        var validation = validateURL(newUrl);
                        
                        if (!validation.valid) {
                            $('#url-submission-result').html('<div class="woocommerce-error">' + validation.message + '</div>');
                            return;
                        }
                        
                        var isUpdate = <?php echo $user_url ? 'true' : 'false'; ?>;
                        
                        // Disable form during submission
                        $('#submit-button').prop('disabled', true).text('Processing...');
                        $('#url-submission-result').html('<div style="color: #666;">Submitting URL...</div>');
                        
                        $.post(wcRecurringBilling.ajax_url, {
                            action: 'submit_url',
                            new_url: newUrl,
                            subscription_id: <?php echo $active_subscription->id; ?>,
                            nonce: wcRecurringBilling.nonce
                        }, function(response) {
                            if (response.success) {
                                $('#url-submission-result').html('<div class="woocommerce-message">' + response.data + '</div>');
                                // Reload the page after 2 seconds to show updated URL
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            } else {
                                $('#url-submission-result').html('<div class="woocommerce-error">' + response.data + '</div>');
                                // Re-enable form
                                $('#submit-button').prop('disabled', false).text(isUpdate ? 'Update URL' : 'Submit URL');
                            }
                        }).fail(function() {
                            $('#url-submission-result').html('<div class="woocommerce-error">Network error. Please try again.</div>');
                            // Re-enable form
                            $('#submit-button').prop('disabled', false).text(isUpdate ? 'Update URL' : 'Submit URL');
                        });
                    });
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }
    
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
                        'created_at' => current_time('mysql') // Update timestamp for URL changes
                    ),
                    array('id' => $existing_url->id),
                    array('%s', '%s'),
                    array('%d')
                );
                
                if ($updated !== false) {
                    // Remove old URL from whitelist and add new one
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
    
    // Helper function for URL updates that removes old URL and adds new one
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
    
    // Helper function to update Bricks whitelist with all active URLs
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
        
        // Get all previously plugin-managed URLs (so we can remove ones that are no longer active)
        $all_plugin_urls = $wpdb->get_results(
            "SELECT DISTINCT url FROM $this->user_urls_table WHERE status IN ('active', 'expired', 'removed')"
        );
        
        $all_plugin_managed_urls = array();
        foreach ($all_plugin_urls as $url_obj) {
            $all_plugin_managed_urls[] = trim($url_obj->url);
        }
        
        // Remove any URLs that were previously managed by our plugin but are no longer active
        $preserved_urls = array();
        foreach ($existing_urls_array as $existing_url) {
            // Keep the URL if it's not managed by our plugin, or if it's still active in our plugin
            if (!in_array($existing_url, $all_plugin_managed_urls) || in_array($existing_url, $plugin_managed_urls)) {
                $preserved_urls[] = $existing_url;
            }
        }
        
        // Add any new plugin-managed URLs that aren't already in the list
        foreach ($plugin_managed_urls as $plugin_url) {
            if (!in_array($plugin_url, $preserved_urls)) {
                $preserved_urls[] = $plugin_url;
            }
        }
        
        // Update the whitelist (preserve existing + add plugin URLs)
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
            wp_send_json_success('Invoice created successfully.');
        } else {
            wp_send_json_error('Failed to create invoice.');
        }
    }
    
    public function ajax_refresh_whitelist() {
        check_ajax_referer('wc_recurring_billing_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }
        
        $this->update_bricks_whitelist();
        wp_send_json_success('Whitelist refreshed successfully.');
    }
    
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
    
    public function schedule_recurring_billing() {
        if (!wp_next_scheduled('process_recurring_billing')) {
            wp_schedule_event(time(), 'daily', 'process_recurring_billing');
        }
        if (!wp_next_scheduled('process_url_cleanup')) {
            wp_schedule_event(time(), 'daily', 'process_url_cleanup');
        }
    }
    
    public function process_recurring_payments() {
        global $wpdb;
        
        // Get subscriptions due for billing
        $due_subscriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $this->table_name 
             WHERE status = 'active' 
             AND next_billing_date <= %s",
            current_time('mysql')
        ));
        
        foreach ($due_subscriptions as $subscription) {
            // Create invoice
            $invoice_number = 'INV-' . date('Y') . '-' . str_pad($subscription->id, 6, '0', STR_PAD_LEFT) . '-' . time();
            
            $invoices_table = $wpdb->prefix . 'recurring_invoices';
            $wpdb->insert(
                $invoices_table,
                array(
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'invoice_number' => $invoice_number,
                    'amount' => $subscription->amount,
                    'status' => 'pending'
                ),
                array('%d', '%d', '%s', '%f', '%s')
            );
            
            // Update next billing date
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
            
            // Send email notification to user
            $user = get_user_by('ID', $subscription->user_id);
            if ($user) {
                $subject = 'New Invoice - ' . $invoice_number;
                $message = "Hello {$user->display_name},\n\n";
                $message .= "A new invoice has been generated for your subscription.\n";
                $message .= "Invoice Number: {$invoice_number}\n";
                $message .= "Amount: $" . number_format($subscription->amount, 2) . "\n\n";
                $message .= "Please log in to your account to view and pay the invoice.\n";
                
                wp_mail($user->user_email, $subject, $message);
            }
        }
    }
    
    public function cleanup_expired_urls() {
        global $wpdb;
        
        // Get expired subscriptions
        $expired_subscriptions = $wpdb->get_results(
            "SELECT id FROM $this->table_name 
             WHERE status != 'active' OR (expiry_date IS NOT NULL AND expiry_date <= NOW())"
        );
        
        foreach ($expired_subscriptions as $subscription) {
            // Remove URLs for expired subscriptions
            $wpdb->update(
                $this->user_urls_table,
                array('status' => 'expired'),
                array('subscription_id' => $subscription->id),
                array('%s'),
                array('%d')
            );
        }
        
        // Update the Bricks whitelist to remove expired URLs
        $this->update_bricks_whitelist();
    }
    
    // WooCommerce Integration Functions
    public function woocommerce_integration() {
        // Triple-check WooCommerce is fully loaded
        if (!class_exists('WooCommerce') || !function_exists('WC') || !WC()) {
            return;
        }
        
        // Add subscription product type - but only once
        if (!has_filter('product_type_selector', array($this, 'add_subscription_product_type'))) {
            add_filter('product_type_selector', array($this, 'add_subscription_product_type'));
        }
        
        // Register custom product class
        add_filter('woocommerce_product_class', array($this, 'get_subscription_product_class'), 10, 2);
        
        // Make recurring subscription products purchasable
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
            // Make it purchasable if it has a price
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
                    $supports = false; // Subscriptions are services, not virtual/downloadable
                    break;
            }
        }
        return $supports;
    }
    
    public function add_subscription_product_type($types) {
        // Ensure we have a valid types array
        if (!is_array($types)) {
            $types = array();
        }
        
        // Only add if it doesn't already exist
        if (!isset($types['recurring_subscription'])) {
            $types['recurring_subscription'] = 'Recurring Subscription';
        }
        
        return $types;
    }
    
    public function add_subscription_product_tab($tabs) {
        // Safety checks
        if (!is_array($tabs) || !is_admin() || !class_exists('WooCommerce')) {
            return $tabs;
        }
        
        // Only add if it doesn't already exist
        if (!isset($tabs['subscription'])) {
            $tabs['subscription'] = array(
                'label' => 'Subscription',
                'target' => 'subscription_product_data',
                'class' => array('show_if_recurring_subscription'),
            );
        }
        
        return $tabs;
    }
    
    public function add_subscription_fields_to_general() {
        global $post;
        
        // Safety checks
        if (!$post || !is_object($post) || $post->post_type !== 'product') {
            return;
        }
        
        // Get current values with more robust retrieval
        $is_subscription = get_post_meta($post->ID, '_is_subscription', true);
        $subscription_type = get_post_meta($post->ID, '_subscription_type', true);
        $subscription_duration = get_post_meta($post->ID, '_subscription_duration', true);
        
        // Set defaults if empty
        if (empty($subscription_type)) {
            $subscription_type = 'monthly';
        }
        
        // Debug output
        error_log("WC Recurring Billing: Loading product #" . $post->ID . " - is_subscription: '" . $is_subscription . "', type: '" . $subscription_type . "', duration: '" . $subscription_duration . "'");
        
        echo '<div class="options_group subscription_options" style="border-top: 1px solid #eee; padding-top: 15px; margin-top: 15px; background: #f8f9fa; padding: 15px; border-radius: 4px; border: 1px solid #dee2e6;">';
        
        // Subscription enable checkbox
        ?>
        <p class="form-field">
            <label for="_is_subscription">
                <input type="checkbox" id="_is_subscription" name="_is_subscription" value="yes" <?php checked($is_subscription, 'yes'); ?> />
                Enable Recurring Subscription
            </label>
            <span class="description">Check this box to enable recurring billing for this product</span>
        </p>
        
        <div class="subscription_fields" id="subscription_fields" style="background: #f9f9f9; padding: 15px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px;">
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
            
            <!-- Debug info for troubleshooting -->
            <div style="background: #fff; padding: 10px; margin: 10px 0; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 12px;">
                <strong>Debug Values (Live):</strong><br>
                <span class="debug-values-content">
                    is_subscription: '<?php echo esc_html($is_subscription); ?>'<br>
                    subscription_type: '<?php echo esc_html($subscription_type); ?>'<br>
                    subscription_duration: '<?php echo esc_html($subscription_duration); ?>'
                </span>
                
                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd;">
                    <button type="button" id="test-save-values" style="background: #007cba; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer;">
                        Test Current Values
                    </button>
                    <span id="test-result" style="margin-left: 10px; font-weight: bold;"></span>
                </div>
            </div>
        </div>
        
        <?php
        echo '</div>'; // options_group
        
        ?>
        <script>
        jQuery(document).ready(function($) {
            console.log('=== WC Recurring Billing Debug ===');
            console.log('Script loaded successfully');
            
            // Get the initial PHP values
            var phpIsSubscription = '<?php echo esc_js($is_subscription); ?>';
            var phpSubscriptionType = '<?php echo esc_js($subscription_type); ?>';
            var phpSubscriptionDuration = '<?php echo esc_js($subscription_duration); ?>';
            
            console.log('PHP values from server:', {
                is_subscription: phpIsSubscription,
                subscription_type: phpSubscriptionType,
                subscription_duration: phpSubscriptionDuration
            });
            
            // Wait for DOM to be fully ready
            setTimeout(function() {
                console.log('=== Element Check (after delay) ===');
                console.log('Checkbox found:', $('#_is_subscription').length);
                console.log('Fields div found:', $('#subscription_fields').length);
                console.log('Options group found:', $('.subscription_options').length);
                
                if ($('#_is_subscription').length > 0) {
                    // Set checkbox state based on PHP value
                    var shouldBeChecked = (phpIsSubscription === 'yes');
                    console.log('Should checkbox be checked?', shouldBeChecked);
                    
                    $('#_is_subscription').prop('checked', shouldBeChecked);
                    console.log('Checkbox state after setting:', $('#_is_subscription').is(':checked'));
                    
                    // Now show/hide fields based on correct state
                    if (shouldBeChecked) {
                        $('#subscription_fields').show();
                        console.log('Showing subscription fields (checkbox should be checked)');
                    } else {
                        $('#subscription_fields').hide();
                        console.log('Hiding subscription fields (checkbox should be unchecked)');
                    }
                } else {
                    console.log('ERROR: Checkbox not found!');
                }
                
                // Set other field values
                if ($('#_subscription_type').length > 0) {
                    $('#_subscription_type').val(phpSubscriptionType);
                    console.log('Set subscription type to:', phpSubscriptionType);
                }
                
                if ($('#_subscription_duration').length > 0) {
                    $('#_subscription_duration').val(phpSubscriptionDuration);
                    console.log('Set duration to:', phpSubscriptionDuration);
                }
                
                // Update debug display
                updateDebugValues();
                
            }, 100); // Short delay to ensure DOM is ready
            
            // Update debug values in real-time
            function updateDebugValues() {
                var isChecked = $('#_is_subscription').is(':checked');
                var subscriptionType = $('#_subscription_type').val();
                var subscriptionDuration = $('#_subscription_duration').val();
                
                var debugText = "is_subscription: '" + (isChecked ? 'yes' : 'no') + "'<br>";
                debugText += "subscription_type: '" + subscriptionType + "'<br>";
                debugText += "subscription_duration: '" + subscriptionDuration + "'";
                
                $('.debug-values-content').html(debugText);
                console.log('Debug values updated:', { isChecked, subscriptionType, subscriptionDuration });
            }
            
            function toggleSubscriptionFields() {
                var isChecked = $('#_is_subscription').is(':checked');
                console.log('toggleSubscriptionFields called, checkbox state:', isChecked);
                
                if (isChecked) {
                    $('#subscription_fields').slideDown(200);
                    console.log('Sliding down subscription fields');
                } else {
                    $('#subscription_fields').slideUp(200);
                    console.log('Sliding up subscription fields');
                }
                
                updateDebugValues();
            }
            
            // Test button functionality
            $('#test-save-values').click(function() {
                var isChecked = $('#_is_subscription').is(':checked');
                var subscriptionType = $('#_subscription_type').val();
                var subscriptionDuration = $('#_subscription_duration').val();
                
                var testData = {
                    is_subscription: isChecked ? 'yes' : 'no',
                    subscription_type: subscriptionType,
                    subscription_duration: subscriptionDuration
                };
                
                $('#test-result').html('Testing...').css('color', 'orange');
                
                console.log('=== TEST BUTTON CLICKED ===');
                console.log('Test data that would be saved:', testData);
                
                // Show what would be sent in the form
                var form = $('form#post')[0];
                if (form) {
                    var formData = new FormData(form);
                    console.log('Form data inspection:');
                    console.log('_is_subscription:', formData.get('_is_subscription'));
                    console.log('_subscription_type:', formData.get('_subscription_type')); 
                    console.log('_subscription_duration:', formData.get('_subscription_duration'));
                    console.log('_is_subscription_exists:', formData.get('_is_subscription_exists'));
                    console.log('_wc_recurring_billing_nonce:', formData.get('_wc_recurring_billing_nonce'));
                } else {
                    console.log('ERROR: Form not found!');
                }
                
                $('#test-result').html('✓ Data logged to console').css('color', 'green');
            });
            
            // Bind events with multiple selectors
            $(document).on('change click', '#_is_subscription', function() {
                console.log('Checkbox event triggered');
                toggleSubscriptionFields();
            });
            $(document).on('change keyup', '#_subscription_type, #_subscription_duration', updateDebugValues);
            
            console.log('=== Setup Complete ===');
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
        </style>
        <?php
    }
    
    public function save_subscription_product_meta($post_id) {
        // Add extensive logging to see if this function is even called
        error_log("============ WC Recurring Billing Save Function Called ============");
        error_log("WC Recurring Billing: save_subscription_product_meta called for post ID: " . $post_id);
        error_log("WC Recurring Billing: Current action/hook: " . current_action());
        error_log("WC Recurring Billing: Available POST keys: " . implode(', ', array_keys($_POST)));
        
        // Check if our hidden field exists
        if (isset($_POST['_is_subscription_exists'])) {
            error_log("WC Recurring Billing: Our hidden field found - this is our form submission");
        } else {
            error_log("WC Recurring Billing: Our hidden field NOT found - skipping save");
            return;
        }
        
        // Multiple security checks
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            error_log("WC Recurring Billing: Permission check failed");
            return;
        }
        
        // Verify this is a product
        if (get_post_type($post_id) !== 'product') {
            error_log("WC Recurring Billing: Not a product, post type is: " . get_post_type($post_id));
            return;
        }
        
        // Skip if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            error_log("WC Recurring Billing: Skipping autosave");
            return;
        }
        
        error_log("WC Recurring Billing: All checks passed, proceeding with save...");
        
        // Log exactly what we're receiving
        error_log("WC Recurring Billing: _is_subscription in POST: " . (isset($_POST['_is_subscription']) ? 'YES (value: ' . $_POST['_is_subscription'] . ')' : 'NO - checkbox unchecked'));
        error_log("WC Recurring Billing: _subscription_type in POST: " . (isset($_POST['_subscription_type']) ? $_POST['_subscription_type'] : 'NOT SET'));
        error_log("WC Recurring Billing: _subscription_duration in POST: " . (isset($_POST['_subscription_duration']) ? $_POST['_subscription_duration'] : 'NOT SET'));
        
        // Handle checkbox properly - if checkbox is unchecked, $_POST['_is_subscription'] won't exist
        if (isset($_POST['_is_subscription']) && $_POST['_is_subscription'] === 'yes') {
            $is_subscription = 'yes';
            error_log("WC Recurring Billing: Checkbox IS checked, setting to 'yes'");
        } else {
            $is_subscription = 'no';
            error_log("WC Recurring Billing: Checkbox NOT checked, setting to 'no'");
        }
        
        // Get current values before save for comparison
        $old_is_subscription = get_post_meta($post_id, '_is_subscription', true);
        $old_subscription_type = get_post_meta($post_id, '_subscription_type', true);
        $old_subscription_duration = get_post_meta($post_id, '_subscription_duration', true);
        
        error_log("WC Recurring Billing: OLD values - is_subscription: '$old_is_subscription', type: '$old_subscription_type', duration: '$old_subscription_duration'");
        
        // Save the subscription enable flag
        $result1 = update_post_meta($post_id, '_is_subscription', $is_subscription);
        error_log("WC Recurring Billing: update_post_meta for _is_subscription returned: " . ($result1 !== false ? 'SUCCESS' : 'FAILED'));
        
        // Save subscription type
        if (isset($_POST['_subscription_type'])) {
            $subscription_type = sanitize_text_field($_POST['_subscription_type']);
            if (in_array($subscription_type, ['monthly', 'yearly'])) {
                $result2 = update_post_meta($post_id, '_subscription_type', $subscription_type);
                error_log("WC Recurring Billing: update_post_meta for _subscription_type returned: " . ($result2 !== false ? 'SUCCESS' : 'FAILED'));
            }
        } else {
            update_post_meta($post_id, '_subscription_type', 'monthly');
            error_log("WC Recurring Billing: Set default _subscription_type to monthly");
        }
        
        // Save subscription duration
        if (isset($_POST['_subscription_duration'])) {
            $duration = sanitize_text_field($_POST['_subscription_duration']);
            $duration_int = ($duration === '') ? '' : intval($duration);
            $result3 = update_post_meta($post_id, '_subscription_duration', $duration_int);
            error_log("WC Recurring Billing: update_post_meta for _subscription_duration returned: " . ($result3 !== false ? 'SUCCESS' : 'FAILED'));
        } else {
            update_post_meta($post_id, '_subscription_duration', '');
            error_log("WC Recurring Billing: Set _subscription_duration to empty");
        }
        
        // Force cache clear
        wp_cache_delete($post_id, 'post_meta');
        clean_post_cache($post_id);
        
        // Immediate verification after save
        $new_is_subscription = get_post_meta($post_id, '_is_subscription', true);
        $new_subscription_type = get_post_meta($post_id, '_subscription_type', true);
        $new_subscription_duration = get_post_meta($post_id, '_subscription_duration', true);
        
        error_log("WC Recurring Billing: NEW values after save - is_subscription: '$new_is_subscription', type: '$new_subscription_type', duration: '$new_subscription_duration'");
        error_log("============ WC Recurring Billing Save Function Completed ============");
        
        // Add a success flag to session for debugging
        $_SESSION['wc_recurring_billing_last_save'] = array(
            'post_id' => $post_id,
            'timestamp' => time(),
            'values' => array(
                'is_subscription' => $new_is_subscription,
                'subscription_type' => $new_subscription_type,
                'subscription_duration' => $new_subscription_duration
            )
        );
    }
    
    // Test function for manual subscription creation
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
}

// Initialize the plugin
new WC_Recurring_Billing_Manager();