<?php
/**
 * Frontend Handler Class
 */
class WC_RBM_Frontend {
    
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
        // Scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // WooCommerce account integration
        add_filter('woocommerce_account_menu_items', array($this, 'add_account_menu_item'));
        add_action('woocommerce_account_url-manager_endpoint', array($this, 'url_manager_content'));
        add_filter('woocommerce_account_menu_items', array($this, 'reorder_account_menu'), 99);
        
        // Add query vars
        add_filter('query_vars', array($this, 'add_query_vars'));
    }
    
    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts() {
        if (!is_account_page()) {
            return;
        }
        
        // Enqueue styles
        wp_enqueue_style(
            'wc-rbm-frontend',
            WC_RBM_PLUGIN_URL . 'assets/frontend.css',
            array(),
            WC_RBM_VERSION
        );
        
        // Enqueue scripts
        wp_enqueue_script(
            'wc-rbm-frontend',
            WC_RBM_PLUGIN_URL . 'assets/frontend.js',
            array('jquery'),
            WC_RBM_VERSION,
            true
        );
        
        // Get user's active subscription
        $user_id = get_current_user_id();
        $subscription = null;
        
        if ($user_id) {
            $subscription = WC_RBM()->subscription->get_user_active_subscription($user_id);
        }
        
        // Localize script with proper data
        wp_localize_script('wc-rbm-frontend', 'wcRecurringBilling', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_rbm_frontend_nonce'),
            'subscription_id' => $subscription ? $subscription->id : null,
            'user_id' => $user_id,
            'i18n' => array(
                'confirm_update' => __('Are you sure you want to update your URL?', 'wc-rbm'),
                'confirm_submit' => __('Are you sure you want to submit this URL?', 'wc-rbm'),
                'invalid_url' => __('Please enter a valid URL starting with http:// or https://', 'wc-rbm'),
                'submitting' => __('Submitting...', 'wc-rbm'),
                'updating' => __('Updating...', 'wc-rbm'),
                'success' => __('Success!', 'wc-rbm'),
                'error' => __('Error', 'wc-rbm')
            )
        ));
    }
    
    /**
     * Add URL Manager to account menu
     */
    public function add_account_menu_item($items) {
        $items['url-manager'] = __('URL Manager', 'wc-rbm');
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
                $new_items['url-manager'] = __('URL Manager', 'wc-rbm');
            }
        }
        
        return $new_items;
    }
    
    /**
     * Add query vars
     */
    public function add_query_vars($vars) {
        $vars[] = 'url-manager';
        return $vars;
    }
    
    /**
     * Display URL manager content (FIXED - No debug code)
     */
    public function url_manager_content() {
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            echo '<p>' . __('Please log in to manage your URLs.', 'wc-rbm') . '</p>';
            return;
        }
        
        // Get user's active subscription
        $subscription = WC_RBM()->subscription->get_user_active_subscription($user_id);
        
        ?>
        <div class="woocommerce-account-url-manager">
            <h3><?php _e('URL Manager', 'wc-rbm'); ?></h3>
            
            <?php if (!$subscription): ?>
                <div class="woocommerce-message woocommerce-message--info">
                    <p><strong><?php _e('No Active Subscription', 'wc-rbm'); ?></strong></p>
                    <p><?php _e('You need an active subscription to manage URLs. Please purchase a subscription to get started.', 'wc-rbm'); ?></p>
                    <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" class="button">
                        <?php _e('Browse Subscriptions', 'wc-rbm'); ?>
                    </a>
                </div>
            <?php else: ?>
                <?php
                // Get user's current URL
                $user_url = WC_RBM()->url_manager->get_user_url($user_id, $subscription->id);
                ?>
                
                <div class="subscription-info">
                    <p><?php _e('You can submit one URL per subscription to be added to the templates whitelist.', 'wc-rbm'); ?></p>
                    <p class="subscription-details">
                        <strong><?php _e('Subscription Type:', 'wc-rbm'); ?></strong> 
                        <?php echo ucfirst($subscription->subscription_type); ?> - 
                        $<?php echo number_format($subscription->amount, 2); ?>/<?php echo $subscription->subscription_type === 'monthly' ? __('month', 'wc-rbm') : __('year', 'wc-rbm'); ?>
                    </p>
                    <p class="subscription-expiry">
                        <strong><?php _e('Expires:', 'wc-rbm'); ?></strong> 
                        <?php 
                        if ($subscription->expiry_date) {
                            echo date_i18n(get_option('date_format'), strtotime($subscription->expiry_date));
                        } else {
                            _e('Never (lifetime)', 'wc-rbm');
                        }
                        ?>
                    </p>
                </div>
                
                <?php if ($user_url): ?>
                    <div class="current-url">
                        <h4><?php _e('Your Current Whitelisted URL:', 'wc-rbm'); ?></h4>
                        <div class="urls-display">
                            <?php echo esc_html($user_url->url); ?>
                        </div>
                        <small class="url-submitted-date">
                            <?php 
                            printf(
                                __('Submitted on %s', 'wc-rbm'),
                                date_i18n(get_option('date_format'), strtotime($user_url->created_at))
                            );
                            ?>
                        </small>
                    </div>
                    
                    <div class="warning-message">
                        <p><strong><?php _e('Want to change your URL?', 'wc-rbm'); ?></strong></p>
                        <p><?php _e('You can update your whitelisted URL below. This will replace your current URL.', 'wc-rbm'); ?></p>
                    </div>
                <?php endif; ?>
                
                <form id="url-submission-form">
                    <div class="form-row">
                        <label for="new_url">
                            <?php echo $user_url ? __('Update URL:', 'wc-rbm') : __('Submit Your URL:', 'wc-rbm'); ?>
                        </label>
                        <input type="url" 
                               id="new_url" 
                               name="new_url" 
                               class="form-control" 
                               placeholder="https://yourdomain.com/" 
                               required 
                               pattern="https?://.+"
                               title="<?php esc_attr_e('Please enter a valid URL starting with http:// or https://', 'wc-rbm'); ?>">
                        <div id="url-validation-message"></div>
                    </div>
                    <div class="form-row">
                        <button type="submit" class="button" id="submit-button" disabled>
                            <?php echo $user_url ? __('Update URL', 'wc-rbm') : __('Submit URL', 'wc-rbm'); ?>
                        </button>
                    </div>
                </form>
                
                <div id="url-submission-result"></div>
                
                <?php if ($user_url): ?>
                    <div class="url-history">
                        <h4><?php _e('URL Guidelines', 'wc-rbm'); ?></h4>
                        <ul>
                            <li><?php _e('Only one URL is allowed per subscription', 'wc-rbm'); ?></li>
                            <li><?php _e('URLs must start with http:// or https://', 'wc-rbm'); ?></li>
                            <li><?php _e('Changes take effect immediately', 'wc-rbm'); ?></li>
                            <li><?php _e('Your URL will remain whitelisted while your subscription is active', 'wc-rbm'); ?></li>
                        </ul>
                    </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
        <?php
    }
}