<?php
/**
 * Subscription Handler Class
 */
class WC_RBM_Subscription {
    
    /**
     * Database tables
     */
    private $tables;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->tables = WC_RBM()->database->get_table_names();
    }
    
    /**
     * Create a new subscription
     */
    public function create($data) {
        global $wpdb;
        
        // Set defaults
        $defaults = array(
            'user_id' => 0,
            'subscription_type' => 'monthly',
            'amount' => 0,
            'status' => 'active',
            'duration' => null
        );
        
        $data = wp_parse_args($data, $defaults);
        
        // Validate required fields
        if (!$data['user_id'] || !get_user_by('ID', $data['user_id'])) {
            throw new Exception(__('Invalid user ID.', 'wc-rbm'));
        }
        
        if (!in_array($data['subscription_type'], array('monthly', 'yearly'))) {
            throw new Exception(__('Invalid subscription type.', 'wc-rbm'));
        }
        
        if ($data['amount'] <= 0) {
            throw new Exception(__('Amount must be greater than zero.', 'wc-rbm'));
        }
        
        // Calculate dates
        $start_date = current_time('mysql');
        $next_billing_date = $this->calculate_next_billing_date($start_date, $data['subscription_type']);
        $expiry_date = null;
        
        if ($data['duration'] && $data['duration'] > 0) {
            $expiry_date = date('Y-m-d H:i:s', strtotime("+{$data['duration']} months"));
        }
        
        // Insert subscription
        $result = $wpdb->insert(
            $this->tables['subscriptions'],
            array(
                'user_id' => $data['user_id'],
                'subscription_type' => $data['subscription_type'],
                'amount' => $data['amount'],
                'status' => $data['status'],
                'start_date' => $start_date,
                'next_billing_date' => $next_billing_date,
                'expiry_date' => $expiry_date,
                'created_at' => $start_date
            ),
            array('%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s')
        );
        
        if (!$result) {
            throw new Exception(__('Failed to create subscription.', 'wc-rbm'));
        }
        
        $subscription_id = $wpdb->insert_id;
        
        // Log the creation
        $this->log_subscription_event($subscription_id, 'created', 'Subscription created');
        
        // Send welcome email
        $this->send_welcome_email($subscription_id);
        
        return $subscription_id;
    }
    
    /**
     * Update subscription status
     */
    public function update_status($subscription_id, $new_status) {
        global $wpdb;
        
        // Validate status
        $valid_statuses = array('active', 'paused', 'cancelled');
        if (!in_array($new_status, $valid_statuses)) {
            throw new Exception(__('Invalid subscription status.', 'wc-rbm'));
        }
        
        // Get current subscription
        $subscription = $this->get($subscription_id);
        if (!$subscription) {
            throw new Exception(__('Subscription not found.', 'wc-rbm'));
        }
        
        // Update status
        $result = $wpdb->update(
            $this->tables['subscriptions'],
            array(
                'status' => $new_status,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $subscription_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result === false) {
            throw new Exception(__('Failed to update subscription status.', 'wc-rbm'));
        }
        
        // Log the status change
        $this->log_subscription_event($subscription_id, 'status_changed', 
            sprintf('Status changed from %s to %s', $subscription->status, $new_status));
        
        // Update URL status if needed
        if ($new_status !== 'active') {
            WC_RBM()->url_manager->cleanup_expired_urls();
        }
        
        return true;
    }
    
    /**
     * Delete subscription
     */
    public function delete($subscription_id) {
        global $wpdb;
        
        // Get subscription details
        $subscription = $this->get_with_user($subscription_id);
        if (!$subscription) {
            throw new Exception(__('Subscription not found.', 'wc-rbm'));
        }
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Get associated URLs for cleanup
            $urls = $wpdb->get_results($wpdb->prepare(
                "SELECT url FROM {$this->tables['user_urls']} 
                 WHERE subscription_id = %d AND status = 'active'",
                $subscription_id
            ));
            
            // Delete user URLs
            $deleted_urls = $wpdb->delete(
                $this->tables['user_urls'],
                array('subscription_id' => $subscription_id),
                array('%d')
            );
            
            // Delete invoices
            $deleted_invoices = $wpdb->delete(
                $this->tables['invoices'],
                array('subscription_id' => $subscription_id),
                array('%d')
            );
            
            // Delete subscription
            $deleted = $wpdb->delete(
                $this->tables['subscriptions'],
                array('id' => $subscription_id),
                array('%d')
            );
            
            if (!$deleted) {
                throw new Exception(__('Failed to delete subscription.', 'wc-rbm'));
            }
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            // Update Bricks whitelist if URLs were removed
            if (!empty($urls)) {
                WC_RBM()->url_manager->remove_urls_from_whitelist($urls);
            }
            
            // Log deletion
            error_log(sprintf(
                'WC RBM: Deleted subscription #%d for user %s. URLs removed: %d, Invoices removed: %d',
                $subscription_id,
                $subscription->user_email,
                count($urls),
                $deleted_invoices
            ));
            
            return array(
                'subscription_id' => $subscription_id,
                'urls_removed' => count($urls),
                'invoices_removed' => $deleted_invoices
            );
            
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }
    
    /**
     * Get subscription by ID
     */
    public function get($subscription_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->tables['subscriptions']} WHERE id = %d",
            $subscription_id
        ));
    }
    
    /**
     * Get subscription with user details
     */
    public function get_with_user($subscription_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, u.display_name, u.user_email, u.user_login
             FROM {$this->tables['subscriptions']} s
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE s.id = %d",
            $subscription_id
        ));
    }
    
    /**
     * Get user's active subscription
     */
    public function get_user_active_subscription($user_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->tables['subscriptions']}
             WHERE user_id = %d AND status = 'active'
             AND (expiry_date IS NULL OR expiry_date > NOW())
             ORDER BY created_at DESC LIMIT 1",
            $user_id
        ));
    }
    
    /**
     * Get all user subscriptions
     */
    public function get_user_subscriptions($user_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->tables['subscriptions']}
             WHERE user_id = %d
             ORDER BY created_at DESC",
            $user_id
        ));
    }
    
    /**
     * Get subscriptions due for billing
     */
    public function get_due_subscriptions() {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->tables['subscriptions']}
             WHERE status = 'active' 
             AND next_billing_date <= %s
             AND (expiry_date IS NULL OR expiry_date > NOW())",
            current_time('mysql')
        ));
    }
    
    /**
     * Update billing dates after invoice creation
     */
    public function update_billing_dates($subscription_id) {
        global $wpdb;
        
        $subscription = $this->get($subscription_id);
        if (!$subscription) {
            return false;
        }
        
        $next_billing = $this->calculate_next_billing_date(
            $subscription->next_billing_date, 
            $subscription->subscription_type
        );
        
        return $wpdb->update(
            $this->tables['subscriptions'],
            array(
                'last_billing_date' => current_time('mysql'),
                'next_billing_date' => $next_billing,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $subscription_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }
    
    /**
     * Calculate next billing date
     */
    private function calculate_next_billing_date($from_date, $subscription_type) {
        $timestamp = strtotime($from_date);
        
        if ($subscription_type === 'monthly') {
            return date('Y-m-d H:i:s', strtotime('+1 month', $timestamp));
        } else {
            return date('Y-m-d H:i:s', strtotime('+1 year', $timestamp));
        }
    }
    
    /**
     * Check if user has active subscription
     */
    public function user_has_active($user_id) {
        global $wpdb;
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tables['subscriptions']}
             WHERE user_id = %d AND status = 'active'
             AND (expiry_date IS NULL OR expiry_date > NOW())",
            $user_id
        ));
        
        return $count > 0;
    }
    
    /**
     * Get subscription statistics
     */
    public function get_statistics() {
        global $wpdb;
        
        $stats = array();
        
        // Total subscriptions
        $stats['total'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tables['subscriptions']}"
        );
        
        // By status
        $status_counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count 
             FROM {$this->tables['subscriptions']} 
             GROUP BY status",
            OBJECT_K
        );
        
        $stats['active'] = isset($status_counts['active']) ? $status_counts['active']->count : 0;
        $stats['paused'] = isset($status_counts['paused']) ? $status_counts['paused']->count : 0;
        $stats['cancelled'] = isset($status_counts['cancelled']) ? $status_counts['cancelled']->count : 0;
        
        // By type
        $type_counts = $wpdb->get_results(
            "SELECT subscription_type, COUNT(*) as count 
             FROM {$this->tables['subscriptions']} 
             WHERE status = 'active'
             GROUP BY subscription_type",
            OBJECT_K
        );
        
        $stats['monthly'] = isset($type_counts['monthly']) ? $type_counts['monthly']->count : 0;
        $stats['yearly'] = isset($type_counts['yearly']) ? $type_counts['yearly']->count : 0;
        
        // Revenue
        $stats['monthly_revenue'] = $wpdb->get_var(
            "SELECT SUM(amount) FROM {$this->tables['subscriptions']} 
             WHERE status = 'active' AND subscription_type = 'monthly'"
        ) ?: 0;
        
        $stats['yearly_revenue'] = $wpdb->get_var(
            "SELECT SUM(amount) FROM {$this->tables['subscriptions']} 
             WHERE status = 'active' AND subscription_type = 'yearly'"
        ) ?: 0;
        
        return $stats;
    }
    
    /**
     * Get all subscriptions for export
     */
    public function get_all_for_export() {
        global $wpdb;
        
        return $wpdb->get_results(
            "SELECT 
                s.id,
                u.user_login,
                u.user_email,
                s.subscription_type,
                s.amount,
                s.status,
                s.start_date,
                s.next_billing_date,
                s.last_billing_date,
                s.expiry_date,
                s.created_at
             FROM {$this->tables['subscriptions']} s
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             ORDER BY s.created_at DESC",
            ARRAY_A
        );
    }
    
    /**
     * Send welcome email
     */
    private function send_welcome_email($subscription_id) {
        $subscription = $this->get_with_user($subscription_id);
        if (!$subscription || !$subscription->user_email) {
            return false;
        }
        
        $subject = __('Welcome to Your Subscription!', 'wc-rbm');
        
        $message = sprintf(
            __("Hello %s,\n\nThank you for your subscription!\n\n", 'wc-rbm'),
            $subscription->display_name
        );
        
        $message .= sprintf(
            __("Subscription Details:\n- Type: %s\n- Amount: $%.2f\n", 'wc-rbm'),
            ucfirst($subscription->subscription_type),
            $subscription->amount
        );
        
        if ($subscription->expiry_date) {
            $message .= sprintf(
                __("- Expires: %s\n", 'wc-rbm'),
                date_i18n(get_option('date_format'), strtotime($subscription->expiry_date))
            );
        } else {
            $message .= __("- Duration: Lifetime\n", 'wc-rbm');
        }
        
        $message .= sprintf(
            __("\nYou can now manage your URLs in your account:\n%s\n\n", 'wc-rbm'),
            wc_get_account_endpoint_url('url-manager')
        );
        
        $message .= __("Thank you for your business!", 'wc-rbm');
        
        return wp_mail($subscription->user_email, $subject, $message);
    }
    
    /**
     * Log subscription event
     */
    private function log_subscription_event($subscription_id, $event_type, $description) {
        // This could be extended to save to a separate events table
        error_log(sprintf(
            'WC RBM: Subscription #%d - %s: %s',
            $subscription_id,
            $event_type,
            $description
        ));
    }
}