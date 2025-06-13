<?php
/**
 * Custom WooCommerce Product Class for Recurring Subscriptions
 */
class WC_Product_Recurring_Subscription extends WC_Product_Simple {
    
    /**
     * Initialize product
     */
    public function __construct($product = 0) {
        $this->product_type = 'recurring_subscription';
        parent::__construct($product);
    }
    
    /**
     * Get product type
     */
    public function get_type() {
        return 'recurring_subscription';
    }
    
    /**
     * Get subscription type (monthly/yearly)
     */
    public function get_subscription_type() {
        return $this->get_meta('_subscription_type') ?: 'monthly';
    }
    
    /**
     * Get subscription duration in months
     */
    public function get_subscription_duration() {
        return $this->get_meta('_subscription_duration') ?: '';
    }
    
    /**
     * Get trial days
     */
    public function get_trial_days() {
        return absint($this->get_meta('_subscription_trial_days'));
    }
    
    /**
     * Get setup fee
     */
    public function get_setup_fee() {
        return floatval($this->get_meta('_subscription_setup_fee'));
    }
    
    /**
     * Check if URL management is enabled
     */
    public function is_url_management_enabled() {
        return $this->get_meta('_enable_url_management') !== 'no';
    }
    
    /**
     * Get maximum URLs allowed
     */
    public function get_max_urls() {
        return absint($this->get_meta('_max_urls')) ?: 1;
    }
    
    /**
     * Get the add to cart button text
     */
    public function add_to_cart_text() {
        return apply_filters('woocommerce_product_add_to_cart_text', __('Subscribe Now', 'wc-rbm'), $this);
    }
    
    /**
     * Get the single add to cart text
     */
    public function single_add_to_cart_text() {
        return apply_filters('woocommerce_product_single_add_to_cart_text', __('Subscribe Now', 'wc-rbm'), $this);
    }
    
    /**
     * Returns false if the product cannot be bought
     */
    public function is_purchasable() {
        return apply_filters('woocommerce_is_purchasable', $this->exists() && $this->get_price() !== '', $this);
    }
    
    /**
     * Returns whether or not the product is in stock
     */
    public function is_in_stock() {
        return apply_filters('woocommerce_product_is_in_stock', true, $this);
    }
    
    /**
     * Get price suffix
     */
    public function get_price_suffix($price = '', $qty = 1) {
        $suffix = parent::get_price_suffix($price, $qty);
        
        // Add subscription interval
        $subscription_type = $this->get_subscription_type();
        $interval = $subscription_type === 'monthly' ? __('/month', 'wc-rbm') : __('/year', 'wc-rbm');
        
        return ' ' . $interval . $suffix;
    }
    
    /**
     * Get price HTML
     */
    public function get_price_html($price = '') {
        if ($this->get_price() === '') {
            return apply_filters('woocommerce_empty_price_html', '', $this);
        }
        
        $display_price = wc_get_price_to_display($this);
        $setup_fee = $this->get_setup_fee();
        $trial_days = $this->get_trial_days();
        
        // Build price HTML
        $price_html = wc_price($display_price);
        
        // Add interval
        $subscription_type = $this->get_subscription_type();
        $interval = $subscription_type === 'monthly' ? __('/month', 'wc-rbm') : __('/year', 'wc-rbm');
        $price_html .= ' <span class="subscription-interval">' . $interval . '</span>';
        
        // Add duration if limited
        $duration = $this->get_subscription_duration();
        if ($duration) {
            $price_html .= ' <span class="subscription-duration">' . 
                          sprintf(__('for %d months', 'wc-rbm'), $duration) . '</span>';
        }
        
        // Add trial info
        if ($trial_days > 0) {
            $price_html .= '<br><span class="subscription-trial">' . 
                          sprintf(__('%d-day free trial', 'wc-rbm'), $trial_days) . '</span>';
        }
        
        // Add setup fee
        if ($setup_fee > 0) {
            $price_html .= '<br><span class="subscription-setup-fee">' . 
                          sprintf(__('+ %s setup fee', 'wc-rbm'), wc_price($setup_fee)) . '</span>';
        }
        
        return apply_filters('woocommerce_get_price_html', $price_html, $this);
    }
    
    /**
     * Get internal type (used for JS)
     */
    public function get_internal_type() {
        return 'recurring_subscription';
    }
    
    /**
     * Returns whether or not the product needs shipping
     */
    public function needs_shipping() {
        return false;
    }
    
    /**
     * Returns whether or not the product is virtual
     */
    public function is_virtual() {
        return true;
    }
    
    /**
     * Get subscription details for display
     */
    public function get_subscription_details() {
        $details = array();
        
        $details['type'] = $this->get_subscription_type();
        $details['amount'] = $this->get_price();
        $details['duration'] = $this->get_subscription_duration();
        $details['trial_days'] = $this->get_trial_days();
        $details['setup_fee'] = $this->get_setup_fee();
        
        return apply_filters('wc_rbm_subscription_details', $details, $this);
    }
}