<?php
/**
 * WooCommerce Integration Handler Class
 */
class WC_RBM_WooCommerce {
    
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
        // Product type registration
        add_action('init', array($this, 'register_product_type'));
        add_filter('product_type_selector', array($this, 'add_product_type'));
        add_filter('woocommerce_product_class', array($this, 'product_class'), 10, 2);
        
        // Product data tabs
        add_filter('woocommerce_product_data_tabs', array($this, 'product_data_tabs'));
        add_action('woocommerce_product_data_panels', array($this, 'product_data_panels'));
        
        // Save product data
        add_action('woocommerce_process_product_meta', array($this, 'save_product_data'));
        
        // Order processing
        add_action('woocommerce_order_status_completed', array($this, 'process_subscription_order'));
        add_action('woocommerce_order_status_processing', array($this, 'process_subscription_order'));
        add_action('woocommerce_payment_complete', array($this, 'process_subscription_order'));
        
        // Thank you page
        add_action('woocommerce_thankyou', array($this, 'thankyou_page_content'), 20);
        
        // Cart display
        add_filter('woocommerce_cart_item_name', array($this, 'cart_item_name'), 10, 3);
        
        // Checkout validation
        add_action('woocommerce_checkout_process', array($this, 'checkout_validation'));
        
        // Payment processing
        add_action('init', array($this, 'handle_invoice_payment'));
    }
    
    /**
     * Register custom product type
     */
    public function register_product_type() {
        if (!class_exists('WC_Product')) {
            return;
        }
        
        // Include product class
        require_once WC_RBM_PLUGIN_DIR . 'includes/class-wc-product-recurring-subscription.php';
    }
    
    /**
     * Add product type to selector
     */
    public function add_product_type($types) {
        $types['recurring_subscription'] = __('Recurring Subscription', 'wc-rbm');
        return $types;
    }
    
    /**
     * Filter product class
     */
    public function product_class($classname, $product_type) {
        if ($product_type === 'recurring_subscription') {
            return 'WC_Product_Recurring_Subscription';
        }
        return $classname;
    }
    
    /**
     * Add product data tabs
     */
    public function product_data_tabs($tabs) {
        // Modify existing tabs for recurring subscription
        $tabs['general']['class'][] = 'show_if_recurring_subscription';
        $tabs['inventory']['class'][] = 'hide_if_recurring_subscription';
        $tabs['shipping']['class'][] = 'hide_if_recurring_subscription';
        
        // Add subscription tab
        $tabs['recurring_subscription'] = array(
            'label' => __('Subscription', 'wc-rbm'),
            'target' => 'recurring_subscription_data',
            'class' => array('show_if_recurring_subscription'),
            'priority' => 15
        );
        
        return $tabs;
    }
    
    /**
     * Add product data panels
     */
    public function product_data_panels() {
        global $post;
        
        // Get product meta
        $is_subscription = get_post_meta($post->ID, '_is_subscription', true);
        $subscription_type = get_post_meta($post->ID, '_subscription_type', true) ?: 'monthly';
        $subscription_duration = get_post_meta($post->ID, '_subscription_duration', true);
        $trial_days = get_post_meta($post->ID, '_subscription_trial_days', true);
        $setup_fee = get_post_meta($post->ID, '_subscription_setup_fee', true);
        
        ?>
        <div id="recurring_subscription_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <h4><?php _e('Subscription Settings', 'wc-rbm'); ?></h4>
                
                <?php
                // Billing interval
                woocommerce_wp_select(array(
                    'id' => '_subscription_type',
                    'label' => __('Billing Interval', 'wc-rbm'),
                    'options' => array(
                        'monthly' => __('Monthly', 'wc-rbm'),
                        'yearly' => __('Yearly', 'wc-rbm')
                    ),
                    'value' => $subscription_type,
                    'desc_tip' => true,
                    'description' => __('How often customers will be billed.', 'wc-rbm')
                ));
                
                // Duration
                woocommerce_wp_text_input(array(
                    'id' => '_subscription_duration',
                    'label' => __('Duration (months)', 'wc-rbm'),
                    'placeholder' => __('Leave empty for lifetime', 'wc-rbm'),
                    'type' => 'number',
                    'custom_attributes' => array(
                        'step' => '1',
                        'min' => '0'
                    ),
                    'value' => $subscription_duration,
                    'desc_tip' => true,
                    'description' => __('How long the subscription lasts in months. Leave empty for lifetime subscription.', 'wc-rbm')
                ));
                
                // Trial period
                woocommerce_wp_text_input(array(
                    'id' => '_subscription_trial_days',
                    'label' => __('Trial Period (days)', 'wc-rbm'),
                    'placeholder' => '0',
                    'type' => 'number',
                    'custom_attributes' => array(
                        'step' => '1',
                        'min' => '0'
                    ),
                    'value' => $trial_days,
                    'desc_tip' => true,
                    'description' => __('Number of trial days before billing starts.', 'wc-rbm')
                ));
                
                // Setup fee
                woocommerce_wp_text_input(array(
                    'id' => '_subscription_setup_fee',
                    'label' => __('Setup Fee', 'wc-rbm') . ' (' . get_woocommerce_currency_symbol() . ')',
                    'placeholder' => '0.00',
                    'type' => 'text',
                    'class' => 'wc_input_price',
                    'value' => $setup_fee,
                    'desc_tip' => true,
                    'description' => __('One-time setup fee charged with first payment.', 'wc-rbm')
                ));
                ?>
            </div>
            
            <div class="options_group">
                <h4><?php _e('URL Management', 'wc-rbm'); ?></h4>
                
                <?php
                // Enable URL management
                woocommerce_wp_checkbox(array(
                    'id' => '_enable_url_management',
                    'label' => __('Enable URL Management', 'wc-rbm'),
                    'description' => __('Allow customers to submit URLs for whitelist.', 'wc-rbm'),
                    'cbvalue' => 'yes',
                    'value' => get_post_meta($post->ID, '_enable_url_management', true) ?: 'yes'
                ));
                
                // Max URLs
                woocommerce_wp_text_input(array(
                    'id' => '_max_urls',
                    'label' => __('Maximum URLs', 'wc-rbm'),
                    'placeholder' => '1',
                    'type' => 'number',
                    'custom_attributes' => array(
                        'step' => '1',
                        'min' => '1'
                    ),
                    'value' => get_post_meta($post->ID, '_max_urls', true) ?: '1',
                    'desc_tip' => true,
                    'description' => __('Maximum number of URLs customer can submit.', 'wc-rbm')
                ));
                ?>
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(function($) {
            // Show/hide panels based on product type
            $('#product-type').on('change', function() {
                var productType = $(this).val();
                
                if (productType === 'recurring_subscription') {
                    $('.show_if_recurring_subscription').show();
                    $('.hide_if_recurring_subscription').hide();
                    
                    // Set virtual by default
                    $('#_virtual').prop('checked', true).trigger('change');
                } else {
                    $('.show_if_recurring_subscription').hide();
                    $('.hide_if_recurring_subscription').show();
                }
            }).trigger('change');
        });
        </script>
        <?php
    }
    
    /**
     * Save product data
     */
    public function save_product_data($post_id) {
        // Check product type
        $product_type = empty($_POST['product-type']) ? 'simple' : sanitize_text_field($_POST['product-type']);
        
        if ($product_type === 'recurring_subscription') {
            // Mark as subscription
            update_post_meta($post_id, '_is_subscription', 'yes');
            
            // Save subscription settings
            $fields = array(
                '_subscription_type' => 'sanitize_text_field',
                '_subscription_duration' => 'absint',
                '_subscription_trial_days' => 'absint',
                '_subscription_setup_fee' => 'wc_format_decimal',
                '_enable_url_management' => 'sanitize_text_field',
                '_max_urls' => 'absint'
            );
            
            foreach ($fields as $field => $sanitize_callback) {
                if (isset($_POST[$field])) {
                    $value = call_user_func($sanitize_callback, $_POST[$field]);
                    update_post_meta($post_id, $field, $value);
                }
            }
            
            // Set as virtual by default
            update_post_meta($post_id, '_virtual', 'yes');
            update_post_meta($post_id, '_downloadable', 'no');
        } else {
            // Not a subscription
            update_post_meta($post_id, '_is_subscription', 'no');
        }
    }
    
    /**
     * Process subscription order
     */
    public function process_subscription_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Check if already processed
        if ($order->get_meta('_subscription_created')) {
            return;
        }
        
        $user_id = $order->get_user_id();
        if (!$user_id) {
            return;
        }
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            
            if (!$product || !$this->is_subscription_product($product)) {
                continue;
            }
            
            try {
                // Get subscription details
                $subscription_data = array(
                    'user_id' => $user_id,
                    'subscription_type' => $product->get_meta('_subscription_type') ?: 'monthly',
                    'amount' => $item->get_total() / $item->get_quantity(),
                    'duration' => $product->get_meta('_subscription_duration')
                );
                
                // Handle trial period
                $trial_days = $product->get_meta('_subscription_trial_days');
                if ($trial_days > 0) {
                    $subscription_data['trial_days'] = $trial_days;
                }
                
                // Create subscription
                $subscription_id = WC_RBM()->subscription->create($subscription_data);
                
                if ($subscription_id) {
                    // Add order note
                    $order->add_order_note(sprintf(
                        __('Recurring subscription #%d created for %s', 'wc-rbm'),
                        $subscription_id,
                        $product->get_name()
                    ));
                    
                    // Mark as processed
                    $order->update_meta_data('_subscription_created', 'yes');
                    $order->update_meta_data('_subscription_id', $subscription_id);
                    $order->save();
                }
                
            } catch (Exception $e) {
                $order->add_order_note(sprintf(
                    __('Failed to create subscription: %s', 'wc-rbm'),
                    $e->getMessage()
                ));
            }
        }
    }
    
    /**
     * Check if product is subscription
     */
    private function is_subscription_product($product) {
        if (!$product) {
            return false;
        }
        
        // Check by product type
        if ($product->get_type() === 'recurring_subscription') {
            return true;
        }
        
        // Check by meta
        return $product->get_meta('_is_subscription') === 'yes';
    }
    
    /**
     * Modify cart item name
     */
    public function cart_item_name($name, $cart_item, $cart_item_key) {
        $product = $cart_item['data'];
        
        if ($this->is_subscription_product($product)) {
            $subscription_type = $product->get_meta('_subscription_type') ?: 'monthly';
            $interval = $subscription_type === 'monthly' ? __('/month', 'wc-rbm') : __('/year', 'wc-rbm');
            
            $name .= ' <small class="subscription-interval">' . esc_html($interval) . '</small>';
        }
        
        return $name;
    }
    
    /**
     * Checkout validation
     */
    public function checkout_validation() {
        // Check if cart contains subscription products
        $has_subscription = false;
        $has_regular = false;
        
        foreach (WC()->cart->get_cart() as $cart_item) {
            if ($this->is_subscription_product($cart_item['data'])) {
                $has_subscription = true;
            } else {
                $has_regular = true;
            }
        }
        
        // Don't allow mixing subscription and regular products
        if ($has_subscription && $has_regular) {
            wc_add_notice(
                __('Subscription products cannot be purchased with regular products. Please purchase them separately.', 'wc-rbm'),
                'error'
            );
        }
        
        // Require user account for subscriptions
        if ($has_subscription && !is_user_logged_in() && !WC()->checkout->is_registration_enabled()) {
            wc_add_notice(
                __('You must create an account to purchase subscription products.', 'wc-rbm'),
                'error'
            );
        }
    }
    
    /**
     * Thank you page content
     */
    public function thankyou_page_content($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Check if order contains subscriptions
        $subscription_id = $order->get_meta('_subscription_id');
        if (!$subscription_id) {
            return;
        }
        
        ?>
        <div class="wc-rbm-subscription-info">
            <h2><?php _e('Subscription Activated!', 'wc-rbm'); ?></h2>
            <p><?php _e('Your subscription has been successfully activated.', 'wc-rbm'); ?></p>
            
            <div class="subscription-details">
                <h3><?php _e('What\'s Next?', 'wc-rbm'); ?></h3>
                <ul>
                    <li><?php _e('Access the URL Manager in your account dashboard', 'wc-rbm'); ?></li>
                    <li><?php _e('Submit your website URL for whitelist approval', 'wc-rbm'); ?></li>
                    <li><?php _e('Manage your subscription and billing information', 'wc-rbm'); ?></li>
                </ul>
                
                <p>
                    <a href="<?php echo esc_url(wc_get_account_endpoint_url('url-manager')); ?>" 
                       class="button button-primary">
                        <?php _e('Go to URL Manager', 'wc-rbm'); ?>
                    </a>
                </p>
            </div>
        </div>
        
        <style>
        .wc-rbm-subscription-info {
            background: #f0f8ff;
            border: 1px solid #0073aa;
            border-radius: 5px;
            padding: 20px;
            margin: 20px 0;
        }
        .wc-rbm-subscription-info h2 {
            color: #0073aa;
            margin-top: 0;
        }
        .subscription-details {
            background: white;
            padding: 15px;
            border-radius: 3px;
            margin-top: 15px;
        }
        </style>
        <?php
    }
    
    /**
     * Handle invoice payment requests
     */
    public function handle_invoice_payment() {
        if (!isset($_GET['pay_invoice']) || !isset($_GET['invoice_key'])) {
            return;
        }
        
        $invoice_id = absint($_GET['pay_invoice']);
        $invoice_key = sanitize_text_field($_GET['invoice_key']);
        
        try {
            // Get invoice
            $invoice = WC_RBM()->invoice->get($invoice_id);
            
            if (!$invoice || wp_hash($invoice->invoice_number) !== $invoice_key) {
                throw new Exception(__('Invalid invoice link.', 'wc-rbm'));
            }
            
            if ($invoice->status === 'paid') {
                throw new Exception(__('This invoice has already been paid.', 'wc-rbm'));
            }
            
            // Create checkout session
            $this->create_invoice_checkout($invoice);
            
        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
            wp_redirect(home_url());
            exit;
        }
    }
    
    /**
     * Create checkout session for invoice
     */
    private function create_invoice_checkout($invoice) {
        // Clear cart
        WC()->cart->empty_cart();
        
        // Create virtual product for invoice
        $product = new WC_Product_Simple();
        $product->set_name(sprintf(__('Invoice Payment - %s', 'wc-rbm'), $invoice->invoice_number));
        $product->set_regular_price($invoice->amount);
        $product->set_virtual(true);
        $product->set_catalog_visibility('hidden');
        $product->save();
        
        // Add to cart
        WC()->cart->add_to_cart($product->get_id(), 1);
        
        // Store invoice ID in session
        WC()->session->set('wc_rbm_paying_invoice', $invoice->id);
        
        // Redirect to checkout
        wp_redirect(wc_get_checkout_url());
        exit;
    }
}