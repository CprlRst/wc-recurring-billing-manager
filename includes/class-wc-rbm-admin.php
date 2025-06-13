<?php
/**
 * Admin Handler Class
 */
class WC_RBM_Admin {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Admin notices
        add_action('admin_notices', array($this, 'admin_notices'));
        
        // Screen options
        add_filter('set-screen-option', array($this, 'set_screen_option'), 10, 3);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Main menu
        $main_page = add_menu_page(
            __('Recurring Billing', 'wc-rbm'),
            __('Recurring Billing', 'wc-rbm'),
            'manage_options',
            'wc-rbm',
            array($this, 'subscriptions_page'),
            'dashicons-money-alt',
            30
        );
        
        // Submenu - Subscriptions
        add_submenu_page(
            'wc-rbm',
            __('Subscriptions', 'wc-rbm'),
            __('Subscriptions', 'wc-rbm'),
            'manage_options',
            'wc-rbm',
            array($this, 'subscriptions_page')
        );
        
        // Submenu - Invoices
        add_submenu_page(
            'wc-rbm',
            __('Invoices', 'wc-rbm'),
            __('Invoices', 'wc-rbm'),
            'manage_options',
            'wc-rbm-invoices',
            array($this, 'invoices_page')
        );
        
        // Submenu - URL Management
        add_submenu_page(
            'wc-rbm',
            __('URL Management', 'wc-rbm'),
            __('URL Management', 'wc-rbm'),
            'manage_options',
            'wc-rbm-urls',
            array($this, 'url_management_page')
        );
        
        // Submenu - Settings
        add_submenu_page(
            'wc-rbm',
            __('Settings', 'wc-rbm'),
            __('Settings', 'wc-rbm'),
            'manage_options',
            'wc-rbm-settings',
            array($this, 'settings_page')
        );
        
        // Add screen options
        add_action("load-$main_page", array($this, 'add_screen_options'));
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_scripts($hook) {
        // Only load on our pages
        if (strpos($hook, 'wc-rbm') === false) {
            return;
        }
        
        // Enqueue admin styles
        wp_enqueue_style(
            'wc-rbm-admin',
            WC_RBM_PLUGIN_URL . 'assets/admin.css',
            array(),
            WC_RBM_VERSION
        );
        
        // Enqueue admin scripts
        wp_enqueue_script(
            'wc-rbm-admin',
            WC_RBM_PLUGIN_URL . 'assets/admin.js',
            array('jquery'),
            WC_RBM_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('wc-rbm-admin', 'wcRecurringBillingAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_rbm_admin_nonce'),
            'i18n' => array(
                'confirm_delete' => __('Are you sure you want to delete this subscription?', 'wc-rbm'),
                'confirm_pause' => __('Are you sure you want to pause this subscription?', 'wc-rbm'),
                'confirm_activate' => __('Are you sure you want to activate this subscription?', 'wc-rbm'),
                'processing' => __('Processing...', 'wc-rbm'),
                'success' => __('Success!', 'wc-rbm'),
                'error' => __('Error', 'wc-rbm')
            )
        ));
    }
    
    /**
     * Show admin notices
     */
    public function admin_notices() {
        // Check if database needs update
        if (WC_RBM()->database->needs_update()) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong><?php _e('WooCommerce Recurring Billing Manager:', 'wc-rbm'); ?></strong>
                    <?php _e('Database update required.', 'wc-rbm'); ?>
                    <button type="button" id="wc-rbm-update-db" class="button button-primary" style="margin-left: 10px;">
                        <?php _e('Update Database', 'wc-rbm'); ?>
                    </button>
                </p>
            </div>
            <script>
            jQuery('#wc-rbm-update-db').on('click', function() {
                jQuery(this).text('<?php _e('Updating...', 'wc-rbm'); ?>').prop('disabled', true);
                jQuery.post(ajaxurl, {
                    action: 'wc_rbm_update_database',
                    nonce: '<?php echo wp_create_nonce('wc_rbm_update_db'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            });
            </script>
            <?php
        }
    }
    
    /**
     * Subscriptions page
     */
    public function subscriptions_page() {
        // Get subscriptions with user info
        global $wpdb;
        $tables = WC_RBM()->database->get_table_names();
        
        $subscriptions = $wpdb->get_results(
            "SELECT s.*, u.display_name, u.user_email, uu.url as current_url
             FROM {$tables['subscriptions']} s 
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID 
             LEFT JOIN {$tables['user_urls']} uu ON s.id = uu.subscription_id AND uu.status = 'active'
             ORDER BY s.created_at DESC"
        );
        
        // Get statistics
        $stats = WC_RBM()->subscription->get_statistics();
        
        include WC_RBM_PLUGIN_DIR . 'templates/admin/subscriptions.php';
    }
    
    /**
     * Invoices page
     */
    public function invoices_page() {
        // Get invoices with details
        global $wpdb;
        $tables = WC_RBM()->database->get_table_names();
        
        $invoices = $wpdb->get_results(
            "SELECT i.*, s.subscription_type, u.display_name, u.user_email 
             FROM {$tables['invoices']} i 
             LEFT JOIN {$tables['subscriptions']} s ON i.subscription_id = s.id 
             LEFT JOIN {$wpdb->users} u ON i.user_id = u.ID 
             ORDER BY i.created_at DESC"
        );
        
        // Get statistics
        $stats = WC_RBM()->invoice->get_statistics();
        
        include WC_RBM_PLUGIN_DIR . 'templates/admin/invoices.php';
    }
    
    /**
     * URL Management page
     */
    public function url_management_page() {
        // Get current Bricks whitelist
        $bricks_settings = get_option('bricks_global_settings', '');
        $current_whitelist = '';
        
        if ($bricks_settings) {
            $settings = maybe_unserialize($bricks_settings);
            if (is_array($settings) && isset($settings['myTemplatesWhitelist'])) {
                $current_whitelist = $settings['myTemplatesWhitelist'];
            }
        }
        
        // Get all user URLs
        global $wpdb;
        $tables = WC_RBM()->database->get_table_names();
        
        $user_urls = $wpdb->get_results(
            "SELECT u.*, s.subscription_type, s.status as sub_status, s.expiry_date, 
                    usr.display_name, usr.user_email
             FROM {$tables['user_urls']} u
             LEFT JOIN {$tables['subscriptions']} s ON u.subscription_id = s.id
             LEFT JOIN {$wpdb->users} usr ON u.user_id = usr.ID
             ORDER BY u.created_at DESC"
        );
        
        // Get statistics
        $stats = WC_RBM()->url_manager->get_statistics();
        
        include WC_RBM_PLUGIN_DIR . 'templates/admin/url-management.php';
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        // Handle form submission
        if (isset($_POST['wc_rbm_settings_nonce']) && 
            wp_verify_nonce($_POST['wc_rbm_settings_nonce'], 'wc_rbm_save_settings')) {
            
            $this->save_settings();
            
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 __('Settings saved successfully.', 'wc-rbm') . '</p></div>';
        }
        
        // Get current settings
        $settings = $this->get_settings();
        
        include WC_RBM_PLUGIN_DIR . 'templates/admin/settings.php';
    }
    
    /**
     * Get plugin settings
     */
    private function get_settings() {
        $defaults = array(
            'email_notifications' => 'yes',
            'invoice_prefix' => 'INV',
            'invoice_due_days' => 7,
            'enable_auto_billing' => 'yes',
            'debug_mode' => 'no'
        );
        
        $settings = get_option('wc_rbm_settings', array());
        return wp_parse_args($settings, $defaults);
    }
    
    /**
     * Save plugin settings
     */
    private function save_settings() {
        $settings = array();
        
        // Sanitize and save settings
        $settings['email_notifications'] = isset($_POST['email_notifications']) ? 'yes' : 'no';
        $settings['invoice_prefix'] = sanitize_text_field($_POST['invoice_prefix'] ?? 'INV');
        $settings['invoice_due_days'] = absint($_POST['invoice_due_days'] ?? 7);
        $settings['enable_auto_billing'] = isset($_POST['enable_auto_billing']) ? 'yes' : 'no';
        $settings['debug_mode'] = isset($_POST['debug_mode']) ? 'yes' : 'no';
        
        update_option('wc_rbm_settings', $settings);
        
        // Clear any caches
        wp_cache_flush();
    }
    
    /**
     * Add screen options
     */
    public function add_screen_options() {
        $option = 'per_page';
        $args = array(
            'label' => __('Items per page', 'wc-rbm'),
            'default' => 20,
            'option' => 'wc_rbm_items_per_page'
        );
        
        add_screen_option($option, $args);
    }
    
    /**
     * Set screen option value
     */
    public function set_screen_option($status, $option, $value) {
        if ('wc_rbm_items_per_page' === $option) {
            return $value;
        }
        
        return $status;
    }
    
    /**
     * Get items per page
     */
    public function get_items_per_page() {
        $user = get_current_user_id();
        $screen = get_current_screen();
        $option = $screen->get_option('per_page', 'option');
        $per_page = get_user_meta($user, $option, true);
        
        if (empty($per_page) || $per_page < 1) {
            $per_page = $screen->get_option('per_page', 'default');
        }
        
        return $per_page;
    }
}