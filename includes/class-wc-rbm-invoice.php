<?php
/**
 * Invoice Handler Class
 */
class WC_RBM_Invoice {
    
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
     * Create invoice from subscription
     */
    public function create_from_subscription($subscription_id, $send_email = true) {
        global $wpdb;
        
        // Get subscription
        $subscription = WC_RBM()->subscription->get($subscription_id);
        if (!$subscription) {
            throw new Exception(__('Subscription not found.', 'wc-rbm'));
        }
        
        // Check if subscription is active
        if ($subscription->status !== 'active') {
            throw new Exception(__('Cannot create invoice for inactive subscription.', 'wc-rbm'));
        }
        
        // Generate unique invoice number
        $invoice_number = $this->generate_invoice_number($subscription_id);
        
        // Create invoice
        $result = $wpdb->insert(
            $this->tables['invoices'],
            array(
                'subscription_id' => $subscription_id,
                'user_id' => $subscription->user_id,
                'invoice_number' => $invoice_number,
                'amount' => $subscription->amount,
                'status' => 'pending',
                'created_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%f', '%s', '%s')
        );
        
        if (!$result) {
            throw new Exception(__('Failed to create invoice.', 'wc-rbm'));
        }
        
        $invoice_id = $wpdb->insert_id;
        
        // Send email if requested
        if ($send_email) {
            $this->send_invoice_email($invoice_id);
        }
        
        // Log invoice creation
        error_log(sprintf(
            'WC RBM: Created invoice #%s for subscription #%d',
            $invoice_number,
            $subscription_id
        ));
        
        return $invoice_id;
    }
    
    /**
     * Generate unique invoice number
     */
    private function generate_invoice_number($subscription_id) {
        $prefix = apply_filters('wc_rbm_invoice_number_prefix', 'INV');
        $year = date('Y');
        $random = wp_generate_password(6, false, false);
        
        return sprintf('%s-%s-%06d-%s', 
            $prefix, 
            $year, 
            $subscription_id, 
            strtoupper($random)
        );
    }
    
    /**
     * Get invoice by ID
     */
    public function get($invoice_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->tables['invoices']} WHERE id = %d",
            $invoice_id
        ));
    }
    
    /**
     * Get invoice with details
     */
    public function get_with_details($invoice_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT i.*, s.subscription_type, u.display_name, u.user_email, u.user_login
             FROM {$this->tables['invoices']} i
             LEFT JOIN {$this->tables['subscriptions']} s ON i.subscription_id = s.id
             LEFT JOIN {$wpdb->users} u ON i.user_id = u.ID
             WHERE i.id = %d",
            $invoice_id
        ));
    }
    
    /**
     * Mark invoice as paid
     */
    public function mark_as_paid($invoice_id, $payment_method = '', $transaction_id = '') {
        global $wpdb;
        
        $result = $wpdb->update(
            $this->tables['invoices'],
            array(
                'status' => 'paid',
                'paid_at' => current_time('mysql'),
                'payment_method' => $payment_method,
                'transaction_id' => $transaction_id
            ),
            array('id' => $invoice_id),
            array('%s', '%s', '%s', '%s'),
            array('%d')
        );
        
        if ($result === false) {
            throw new Exception(__('Failed to update invoice status.', 'wc-rbm'));
        }
        
        // Send payment confirmation
        $this->send_payment_confirmation($invoice_id);
        
        return true;
    }
    
    /**
     * Send invoice email
     */
    public function send_invoice_email($invoice_id) {
        $invoice = $this->get_with_details($invoice_id);
        if (!$invoice || !$invoice->user_email) {
            return false;
        }
        
        // Create payment URL
        $payment_url = $this->get_payment_url($invoice);
        
        $subject = sprintf(
            __('Invoice %s - Payment Due', 'wc-rbm'),
            $invoice->invoice_number
        );
        
        // Build email content
        $message = $this->build_invoice_email_content($invoice, $payment_url);
        
        // Set HTML headers
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        // Apply filters
        $subject = apply_filters('wc_rbm_invoice_email_subject', $subject, $invoice);
        $message = apply_filters('wc_rbm_invoice_email_content', $message, $invoice);
        $headers = apply_filters('wc_rbm_invoice_email_headers', $headers, $invoice);
        
        return wp_mail($invoice->user_email, $subject, $message, $headers);
    }
    
    /**
     * Build invoice email content
     */
    private function build_invoice_email_content($invoice, $payment_url) {
        ob_start();
        ?>
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <div style="background: #f7f7f7; padding: 30px; text-align: center;">
                <h1 style="color: #333; margin: 0;"><?php _e('Invoice', 'wc-rbm'); ?></h1>
            </div>
            
            <div style="padding: 30px; background: #fff;">
                <p><?php printf(__('Hello %s,', 'wc-rbm'), $invoice->display_name); ?></p>
                
                <p><?php _e('Your subscription invoice is ready:', 'wc-rbm'); ?></p>
                
                <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                    <tr>
                        <td style="padding: 10px 0; border-bottom: 1px solid #eee;">
                            <strong><?php _e('Invoice Number:', 'wc-rbm'); ?></strong>
                        </td>
                        <td style="padding: 10px 0; border-bottom: 1px solid #eee; text-align: right;">
                            <?php echo esc_html($invoice->invoice_number); ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 10px 0; border-bottom: 1px solid #eee;">
                            <strong><?php _e('Amount:', 'wc-rbm'); ?></strong>
                        </td>
                        <td style="padding: 10px 0; border-bottom: 1px solid #eee; text-align: right;">
                            <?php echo wc_price($invoice->amount); ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 10px 0; border-bottom: 1px solid #eee;">
                            <strong><?php _e('Subscription:', 'wc-rbm'); ?></strong>
                        </td>
                        <td style="padding: 10px 0; border-bottom: 1px solid #eee; text-align: right;">
                            <?php echo ucfirst($invoice->subscription_type); ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 10px 0;">
                            <strong><?php _e('Due Date:', 'wc-rbm'); ?></strong>
                        </td>
                        <td style="padding: 10px 0; text-align: right;">
                            <?php echo date_i18n(get_option('date_format'), strtotime($invoice->created_at . ' +7 days')); ?>
                        </td>
                    </tr>
                </table>
                
                <div style="text-align: center; margin: 30px 0;">
                    <a href="<?php echo esc_url($payment_url); ?>" 
                       style="display: inline-block; padding: 15px 30px; background: #0073aa; color: white; text-decoration: none; border-radius: 5px;">
                        <?php _e('Pay Now', 'wc-rbm'); ?>
                    </a>
                </div>
                
                <p style="color: #666; font-size: 14px;">
                    <?php _e('Or copy and paste this link into your browser:', 'wc-rbm'); ?><br>
                    <a href="<?php echo esc_url($payment_url); ?>" style="color: #0073aa;">
                        <?php echo esc_url($payment_url); ?>
                    </a>
                </p>
            </div>
            
            <div style="background: #f7f7f7; padding: 20px; text-align: center; color: #666; font-size: 12px;">
                <p><?php _e('Thank you for your business!', 'wc-rbm'); ?></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Send payment confirmation
     */
    private function send_payment_confirmation($invoice_id) {
        $invoice = $this->get_with_details($invoice_id);
        if (!$invoice || !$invoice->user_email) {
            return false;
        }
        
        $subject = sprintf(
            __('Payment Confirmation - Invoice %s', 'wc-rbm'),
            $invoice->invoice_number
        );
        
        $message = $this->build_confirmation_email_content($invoice);
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        return wp_mail($invoice->user_email, $subject, $message, $headers);
    }
    
    /**
     * Build payment confirmation email content
     */
    private function build_confirmation_email_content($invoice) {
        ob_start();
        ?>
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <div style="background: #4CAF50; padding: 30px; text-align: center;">
                <h1 style="color: white; margin: 0;"><?php _e('Payment Received', 'wc-rbm'); ?></h1>
            </div>
            
            <div style="padding: 30px; background: #fff;">
                <p><?php printf(__('Hello %s,', 'wc-rbm'), $invoice->display_name); ?></p>
                
                <p><?php _e('Thank you! Your payment has been received.', 'wc-rbm'); ?></p>
                
                <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                    <tr>
                        <td style="padding: 10px 0; border-bottom: 1px solid #eee;">
                            <strong><?php _e('Invoice:', 'wc-rbm'); ?></strong>
                        </td>
                        <td style="padding: 10px 0; border-bottom: 1px solid #eee; text-align: right;">
                            <?php echo esc_html($invoice->invoice_number); ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 10px 0; border-bottom: 1px solid #eee;">
                            <strong><?php _e('Amount:', 'wc-rbm'); ?></strong>
                        </td>
                        <td style="padding: 10px 0; border-bottom: 1px solid #eee; text-align: right;">
                            <?php echo wc_price($invoice->amount); ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 10px 0;">
                            <strong><?php _e('Paid:', 'wc-rbm'); ?></strong>
                        </td>
                        <td style="padding: 10px 0; text-align: right;">
                            <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format')); ?>
                        </td>
                    </tr>
                </table>
                
                <p><?php _e('Your subscription continues to be active.', 'wc-rbm'); ?></p>
                
                <div style="text-align: center; margin: 30px 0;">
                    <a href="<?php echo esc_url(wc_get_account_endpoint_url('url-manager')); ?>" 
                       style="display: inline-block; padding: 15px 30px; background: #0073aa; color: white; text-decoration: none; border-radius: 5px;">
                        <?php _e('Manage Your Account', 'wc-rbm'); ?>
                    </a>
                </div>
            </div>
            
            <div style="background: #f7f7f7; padding: 20px; text-align: center; color: #666; font-size: 12px;">
                <p><?php _e('Thank you for your business!', 'wc-rbm'); ?></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get payment URL for invoice
     */
    private function get_payment_url($invoice) {
        return add_query_arg(array(
            'pay_invoice' => $invoice->id,
            'invoice_key' => wp_hash($invoice->invoice_number)
        ), home_url());
    }
    
    /**
     * Process recurring payments
     */
    public function process_recurring_payments() {
        $due_subscriptions = WC_RBM()->subscription->get_due_subscriptions();
        $processed = 0;
        
        foreach ($due_subscriptions as $subscription) {
            try {
                // Create invoice
                $invoice_id = $this->create_from_subscription($subscription->id, true);
                
                if ($invoice_id) {
                    // Update billing dates
                    WC_RBM()->subscription->update_billing_dates($subscription->id);
                    $processed++;
                }
            } catch (Exception $e) {
                error_log(sprintf(
                    'WC RBM: Failed to create invoice for subscription #%d: %s',
                    $subscription->id,
                    $e->getMessage()
                ));
            }
        }
        
        if ($processed > 0) {
            error_log(sprintf(
                'WC RBM: Processed %d recurring payments',
                $processed
            ));
        }
        
        return $processed;
    }
    
    /**
     * Get invoice statistics
     */
    public function get_statistics() {
        global $wpdb;
        
        $stats = array();
        
        // Total invoices
        $stats['total'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tables['invoices']}"
        );
        
        // By status
        $status_counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count 
             FROM {$this->tables['invoices']} 
             GROUP BY status",
            OBJECT_K
        );
        
        $stats['pending'] = isset($status_counts['pending']) ? $status_counts['pending']->count : 0;
        $stats['paid'] = isset($status_counts['paid']) ? $status_counts['paid']->count : 0;
        $stats['failed'] = isset($status_counts['failed']) ? $status_counts['failed']->count : 0;
        
        // Revenue
        $stats['total_revenue'] = $wpdb->get_var(
            "SELECT SUM(amount) FROM {$this->tables['invoices']} WHERE status = 'paid'"
        ) ?: 0;
        
        // This month's revenue
        $stats['monthly_revenue'] = $wpdb->get_var(
            "SELECT SUM(amount) FROM {$this->tables['invoices']} 
             WHERE status = 'paid' AND MONTH(paid_at) = MONTH(CURRENT_DATE()) 
             AND YEAR(paid_at) = YEAR(CURRENT_DATE())"
        ) ?: 0;
        
        return $stats;
    }
    
    /**
     * Get all invoices for export
     */
    public function get_all_for_export() {
        global $wpdb;
        
        return $wpdb->get_results(
            "SELECT 
                i.id,
                i.invoice_number,
                u.user_login,
                u.user_email,
                s.subscription_type,
                i.amount,
                i.status,
                i.payment_method,
                i.transaction_id,
                i.created_at,
                i.paid_at
             FROM {$this->tables['invoices']} i
             LEFT JOIN {$this->tables['subscriptions']} s ON i.subscription_id = s.id
             LEFT JOIN {$wpdb->users} u ON i.user_id = u.ID
             ORDER BY i.created_at DESC",
            ARRAY_A
        );
    }
}