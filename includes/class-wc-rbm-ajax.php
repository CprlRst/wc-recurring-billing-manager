<?php
/**
 * AJAX Handler Class
 */
class WC_RBM_Ajax {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize AJAX hooks
     */
    private function init_hooks() {
        // Admin AJAX
        add_action('wp_ajax_wc_rbm_manage_subscription', array($this, 'manage_subscription'));
        add_action('wp_ajax_wc_rbm_create_invoice', array($this, 'create_invoice'));
        add_action('wp_ajax_wc_rbm_delete_subscription', array($this, 'delete_subscription'));
        add_action('wp_ajax_wc_rbm_refresh_whitelist', array($this, 'refresh_whitelist'));
        add_action('wp_ajax_wc_rbm_export_data', array($this, 'export_data'));
        
        // Frontend AJAX
        add_action('wp_ajax_wc_rbm_submit_url', array($this, 'submit_url'));
        add_action('wp_ajax_nopriv_wc_rbm_submit_url', array($this, 'submit_url'));
    }
    
    /**
     * Manage subscription (create/pause/activate)
     */
    public function manage_subscription() {
        // Verify nonce
        if (!check_ajax_referer('wc_rbm_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed.', 'wc-rbm'));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'wc-rbm'));
        }
        
        $operation = sanitize_text_field($_POST['operation'] ?? '');
        
        try {
            switch ($operation) {
                case 'create':
                    $this->create_subscription();
                    break;
                    
                case 'pause':
                case 'activate':
                    $this->toggle_subscription_status($operation);
                    break;
                    
                default:
                    wp_send_json_error(__('Invalid operation.', 'wc-rbm'));
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Create new subscription
     */
    private function create_subscription() {
        $user_id = absint($_POST['user_id'] ?? 0);
        $subscription_type = sanitize_text_field($_POST['subscription_type'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $duration = absint($_POST['duration'] ?? 0);
        
        // Validate input
        if (!$user_id || !get_user_by('ID', $user_id)) {
            throw new Exception(__('Invalid user selected.', 'wc-rbm'));
        }
        
        if (!in_array($subscription_type, ['monthly', 'yearly'])) {
            throw new Exception(__('Invalid subscription type.', 'wc-rbm'));
        }
        
        if ($amount <= 0) {
            throw new Exception(__('Amount must be greater than zero.', 'wc-rbm'));
        }
        
        // Create subscription
        $subscription_id = WC_RBM()->subscription->create(array(
            'user_id' => $user_id,
            'subscription_type' => $subscription_type,
            'amount' => $amount,
            'duration' => $duration
        ));
        
        if ($subscription_id) {
            wp_send_json_success(array(
                'message' => __('Subscription created successfully.', 'wc-rbm'),
                'subscription_id' => $subscription_id
            ));
        } else {
            throw new Exception(__('Failed to create subscription.', 'wc-rbm'));
        }
    }
    
    /**
     * Toggle subscription status
     */
    private function toggle_subscription_status($operation) {
        $subscription_id = absint($_POST['subscription_id'] ?? 0);
        
        if (!$subscription_id) {
            throw new Exception(__('Invalid subscription ID.', 'wc-rbm'));
        }
        
        $new_status = $operation === 'pause' ? 'paused' : 'active';
        $result = WC_RBM()->subscription->update_status($subscription_id, $new_status);
        
        if ($result) {
            wp_send_json_success(array(
                'message' => sprintf(__('Subscription %s successfully.', 'wc-rbm'), $operation . 'd'),
                'new_status' => $new_status
            ));
        } else {
            throw new Exception(__('Failed to update subscription status.', 'wc-rbm'));
        }
    }
    
    /**
     * Delete subscription
     */
    public function delete_subscription() {
        // Verify nonce
        if (!check_ajax_referer('wc_rbm_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed.', 'wc-rbm'));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'wc-rbm'));
        }
        
        $subscription_id = absint($_POST['subscription_id'] ?? 0);
        
        if (!$subscription_id) {
            wp_send_json_error(__('Invalid subscription ID.', 'wc-rbm'));
        }
        
        try {
            $result = WC_RBM()->subscription->delete($subscription_id);
            
            if ($result) {
                wp_send_json_success(array(
                    'message' => __('Subscription deleted successfully.', 'wc-rbm'),
                    'details' => $result
                ));
            } else {
                throw new Exception(__('Failed to delete subscription.', 'wc-rbm'));
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Create invoice
     */
    public function create_invoice() {
        // Verify nonce
        if (!check_ajax_referer('wc_rbm_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed.', 'wc-rbm'));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'wc-rbm'));
        }
        
        $subscription_id = absint($_POST['subscription_id'] ?? 0);
        
        if (!$subscription_id) {
            wp_send_json_error(__('Invalid subscription ID.', 'wc-rbm'));
        }
        
        try {
            $invoice_id = WC_RBM()->invoice->create_from_subscription($subscription_id);
            
            if ($invoice_id) {
                wp_send_json_success(array(
                    'message' => __('Invoice created and email sent successfully.', 'wc-rbm'),
                    'invoice_id' => $invoice_id
                ));
            } else {
                throw new Exception(__('Failed to create invoice.', 'wc-rbm'));
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Submit URL (Frontend) - FIXED SECURITY ISSUE
     */
    public function submit_url() {
        // Verify nonce
        if (!check_ajax_referer('wc_rbm_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed.', 'wc-rbm'));
        }
        
        // Check if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(__('You must be logged in to submit URLs.', 'wc-rbm'));
        }
        
        $user_id = get_current_user_id();
        $new_url = esc_url_raw($_POST['new_url'] ?? '');
        
        // Validate URL
        if (!$new_url || !filter_var($new_url, FILTER_VALIDATE_URL)) {
            wp_send_json_error(__('Please provide a valid URL.', 'wc-rbm'));
        }
        
        try {
            // Get user's active subscription
            $subscription = WC_RBM()->subscription->get_user_active_subscription($user_id);
            
            if (!$subscription) {
                wp_send_json_error(__('You need an active subscription to submit URLs.', 'wc-rbm'));
            }
            
            // Submit URL
            $result = WC_RBM()->url_manager->submit_url($user_id, $subscription->id, $new_url);
            
            if ($result['success']) {
                wp_send_json_success($result['message']);
            } else {
                wp_send_json_error($result['message']);
            }
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Refresh whitelist
     */
    public function refresh_whitelist() {
        // Verify nonce
        if (!check_ajax_referer('wc_rbm_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed.', 'wc-rbm'));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'wc-rbm'));
        }
        
        try {
            WC_RBM()->url_manager->update_bricks_whitelist();
            wp_send_json_success(__('Whitelist refreshed successfully.', 'wc-rbm'));
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Export data (new feature)
     */
    public function export_data() {
        // Verify nonce
        if (!check_ajax_referer('wc_rbm_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed.', 'wc-rbm'));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'wc-rbm'));
        }
        
        $export_type = sanitize_text_field($_POST['export_type'] ?? 'subscriptions');
        
        try {
            $data = array();
            
            switch ($export_type) {
                case 'subscriptions':
                    $data = WC_RBM()->subscription->get_all_for_export();
                    break;
                    
                case 'invoices':
                    $data = WC_RBM()->invoice->get_all_for_export();
                    break;
                    
                case 'urls':
                    $data = WC_RBM()->url_manager->get_all_for_export();
                    break;
                    
                default:
                    throw new Exception(__('Invalid export type.', 'wc-rbm'));
            }
            
            wp_send_json_success(array(
                'data' => $data,
                'filename' => 'wc-rbm-' . $export_type . '-' . date('Y-m-d') . '.csv'
            ));
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}