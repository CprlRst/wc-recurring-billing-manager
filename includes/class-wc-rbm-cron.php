<?php
/**
 * Cron Handler Class
 */
class WC_RBM_Cron {
    
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
        // Schedule events
        add_action('wp', array($this, 'schedule_events'));
        
        // Cron event handlers
        add_action('wc_rbm_process_recurring_billing', array($this, 'process_recurring_billing'));
        add_action('wc_rbm_cleanup_expired_urls', array($this, 'cleanup_expired_urls'));
        add_action('wc_rbm_daily_maintenance', array($this, 'daily_maintenance'));
        
        // Custom cron schedules
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
    }
    
    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules($schedules) {
        // Add twice daily schedule
        $schedules['twice_daily'] = array(
            'interval' => 12 * HOUR_IN_SECONDS,
            'display' => __('Twice Daily', 'wc-rbm')
        );
        
        // Add every 6 hours schedule
        $schedules['every_six_hours'] = array(
            'interval' => 6 * HOUR_IN_SECONDS,
            'display' => __('Every 6 Hours', 'wc-rbm')
        );
        
        return $schedules;
    }
    
    /**
     * Schedule cron events
     */
    public function schedule_events() {
        // Process recurring billing - daily
        if (!wp_next_scheduled('wc_rbm_process_recurring_billing')) {
            wp_schedule_event(time(), 'daily', 'wc_rbm_process_recurring_billing');
        }
        
        // Cleanup expired URLs - twice daily
        if (!wp_next_scheduled('wc_rbm_cleanup_expired_urls')) {
            wp_schedule_event(time(), 'twice_daily', 'wc_rbm_cleanup_expired_urls');
        }
        
        // Daily maintenance - daily at 3 AM
        if (!wp_next_scheduled('wc_rbm_daily_maintenance')) {
            $timestamp = strtotime('today 3:00am');
            if ($timestamp < time()) {
                $timestamp = strtotime('tomorrow 3:00am');
            }
            wp_schedule_event($timestamp, 'daily', 'wc_rbm_daily_maintenance');
        }
    }
    
    /**
     * Unschedule all events
     */
    public function unschedule_events() {
        wp_clear_scheduled_hook('wc_rbm_process_recurring_billing');
        wp_clear_scheduled_hook('wc_rbm_cleanup_expired_urls');
        wp_clear_scheduled_hook('wc_rbm_daily_maintenance');
    }
    
    /**
     * Process recurring billing
     */
    public function process_recurring_billing() {
        // Log start
        $this->log('Starting recurring billing process');
        
        try {
            // Get settings
            $settings = get_option('wc_rbm_settings', array());
            $auto_billing_enabled = isset($settings['enable_auto_billing']) ? 
                                   $settings['enable_auto_billing'] === 'yes' : true;
            
            if (!$auto_billing_enabled) {
                $this->log('Auto billing is disabled');
                return;
            }
            
            // Process payments
            $processed = WC_RBM()->invoice->process_recurring_payments();
            
            $this->log(sprintf('Processed %d recurring payments', $processed));
            
        } catch (Exception $e) {
            $this->log('Error in recurring billing: ' . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Cleanup expired URLs
     */
    public function cleanup_expired_urls() {
        $this->log('Starting URL cleanup process');
        
        try {
            // Clean up expired URLs
            $cleaned = WC_RBM()->url_manager->cleanup_expired_urls();
            
            $this->log(sprintf('Cleaned up %d expired URLs', $cleaned));
            
        } catch (Exception $e) {
            $this->log('Error in URL cleanup: ' . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Daily maintenance tasks
     */
    public function daily_maintenance() {
        $this->log('Starting daily maintenance');
        
        try {
            // Clean up old logs (30 days)
            $this->cleanup_old_logs();
            
            // Optimize database tables
            $this->optimize_tables();
            
            // Send admin report if enabled
            $this->send_daily_report();
            
            // Check for expired subscriptions
            $this->check_expired_subscriptions();
            
            $this->log('Daily maintenance completed');
            
        } catch (Exception $e) {
            $this->log('Error in daily maintenance: ' . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Check for expired subscriptions
     */
    private function check_expired_subscriptions() {
        global $wpdb;
        $tables = WC_RBM()->database->get_table_names();
        
        // Find subscriptions that have expired
        $expired = $wpdb->query(
            "UPDATE {$tables['subscriptions']} 
             SET status = 'cancelled' 
             WHERE status = 'active' 
             AND expiry_date IS NOT NULL 
             AND expiry_date <= NOW()"
        );
        
        if ($expired > 0) {
            $this->log(sprintf('Cancelled %d expired subscriptions', $expired));
            
            // Trigger URL cleanup
            WC_RBM()->url_manager->cleanup_expired_urls();
        }
    }
    
    /**
     * Cleanup old logs
     */
    private function cleanup_old_logs() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/wc-rbm-logs/';
        
        if (!is_dir($log_dir)) {
            return;
        }
        
        $files = glob($log_dir . '*.log');
        $deleted = 0;
        
        foreach ($files as $file) {
            // Delete files older than 30 days
            if (filemtime($file) < strtotime('-30 days')) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }
        
        if ($deleted > 0) {
            $this->log(sprintf('Deleted %d old log files', $deleted));
        }
    }
    
    /**
     * Optimize database tables
     */
    private function optimize_tables() {
        global $wpdb;
        $tables = WC_RBM()->database->get_table_names();
        
        foreach ($tables as $table) {
            $wpdb->query("OPTIMIZE TABLE $table");
        }
        
        $this->log('Database tables optimized');
    }
    
    /**
     * Send daily report to admin
     */
    private function send_daily_report() {
        $settings = get_option('wc_rbm_settings', array());
        
        // Check if daily reports are enabled
        if (!isset($settings['send_daily_reports']) || $settings['send_daily_reports'] !== 'yes') {
            return;
        }
        
        // Get statistics
        $subscription_stats = WC_RBM()->subscription->get_statistics();
        $invoice_stats = WC_RBM()->invoice->get_statistics();
        $url_stats = WC_RBM()->url_manager->get_statistics();
        
        // Build email content
        $subject = sprintf(
            __('[%s] Daily Recurring Billing Report', 'wc-rbm'),
            get_bloginfo('name')
        );
        
        ob_start();
        ?>
        <h2><?php _e('Daily Recurring Billing Report', 'wc-rbm'); ?></h2>
        
        <h3><?php _e('Subscription Statistics', 'wc-rbm'); ?></h3>
        <ul>
            <li><?php printf(__('Total Subscriptions: %d', 'wc-rbm'), $subscription_stats['total']); ?></li>
            <li><?php printf(__('Active: %d', 'wc-rbm'), $subscription_stats['active']); ?></li>
            <li><?php printf(__('Monthly Revenue: %s', 'wc-rbm'), wc_price($subscription_stats['monthly_revenue'])); ?></li>
            <li><?php printf(__('Yearly Revenue: %s', 'wc-rbm'), wc_price($subscription_stats['yearly_revenue'])); ?></li>
        </ul>
        
        <h3><?php _e('Invoice Statistics', 'wc-rbm'); ?></h3>
        <ul>
            <li><?php printf(__('Total Invoices: %d', 'wc-rbm'), $invoice_stats['total']); ?></li>
            <li><?php printf(__('Pending: %d', 'wc-rbm'), $invoice_stats['pending']); ?></li>
            <li><?php printf(__('This Month Revenue: %s', 'wc-rbm'), wc_price($invoice_stats['monthly_revenue'])); ?></li>
        </ul>
        
        <h3><?php _e('URL Statistics', 'wc-rbm'); ?></h3>
        <ul>
            <li><?php printf(__('Active URLs: %d', 'wc-rbm'), $url_stats['active']); ?></li>
            <li><?php printf(__('Total URLs: %d', 'wc-rbm'), $url_stats['total']); ?></li>
        </ul>
        
        <p><?php _e('This is an automated report from your WooCommerce Recurring Billing Manager.', 'wc-rbm'); ?></p>
        <?php
        $message = ob_get_clean();
        
        // Send to admin email
        $admin_email = get_option('admin_email');
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        wp_mail($admin_email, $subject, $message, $headers);
        
        $this->log('Daily report sent to admin');
    }
    
    /**
     * Log cron events
     */
    private function log($message, $level = 'info') {
        $settings = get_option('wc_rbm_settings', array());
        
        // Check if debug mode is enabled
        if (!isset($settings['debug_mode']) || $settings['debug_mode'] !== 'yes') {
            return;
        }
        
        // Create log directory if needed
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/wc-rbm-logs/';
        
        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        // Write to log file
        $log_file = $log_dir . 'cron-' . date('Y-m-d') . '.log';
        $timestamp = current_time('mysql');
        $log_entry = sprintf('[%s] [%s] %s' . PHP_EOL, $timestamp, strtoupper($level), $message);
        
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        // Also log to error log if error level
        if ($level === 'error') {
            error_log('WC RBM Cron Error: ' . $message);
        }
    }
    
    /**
     * Get next scheduled time for an event
     */
    public function get_next_scheduled($hook) {
        $timestamp = wp_next_scheduled($hook);
        
        if ($timestamp) {
            return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
        }
        
        return __('Not scheduled', 'wc-rbm');
    }
    
    /**
     * Manually run a cron event
     */
    public function run_event($hook) {
        switch ($hook) {
            case 'wc_rbm_process_recurring_billing':
                $this->process_recurring_billing();
                break;
                
            case 'wc_rbm_cleanup_expired_urls':
                $this->cleanup_expired_urls();
                break;
                
            case 'wc_rbm_daily_maintenance':
                $this->daily_maintenance();
                break;
                
            default:
                throw new Exception(__('Invalid cron event.', 'wc-rbm'));
        }
    }
}