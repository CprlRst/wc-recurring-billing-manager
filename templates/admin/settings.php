<?php
/**
 * Admin Settings Page Template
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap wc-rbm-admin-page">
    <h1><?php _e('Recurring Billing Settings', 'wc-rbm'); ?></h1>
    
    <form method="post" action="">
        <?php wp_nonce_field('wc_rbm_save_settings', 'wc_rbm_settings_nonce'); ?>
        
        <!-- General Settings -->
        <div class="wc-rbm-settings-section">
            <h3><?php _e('General Settings', 'wc-rbm'); ?></h3>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="email_notifications">
                            <?php _e('Email Notifications', 'wc-rbm'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="checkbox" 
                               id="email_notifications" 
                               name="email_notifications" 
                               value="yes" 
                               <?php checked($settings['email_notifications'], 'yes'); ?> />
                        <label for="email_notifications">
                            <?php _e('Enable email notifications for subscriptions and invoices', 'wc-rbm'); ?>
                        </label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="enable_auto_billing">
                            <?php _e('Automatic Billing', 'wc-rbm'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="checkbox" 
                               id="enable_auto_billing" 
                               name="enable_auto_billing" 
                               value="yes" 
                               <?php checked($settings['enable_auto_billing'], 'yes'); ?> />
                        <label for="enable_auto_billing">
                            <?php _e('Automatically create invoices for recurring subscriptions', 'wc-rbm'); ?>
                        </label>
                        <p class="description">
                            <?php _e('When enabled, invoices will be automatically generated on billing dates.', 'wc-rbm'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="debug_mode">
                            <?php _e('Debug Mode', 'wc-rbm'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="checkbox" 
                               id="debug_mode" 
                               name="debug_mode" 
                               value="yes" 
                               <?php checked($settings['debug_mode'], 'yes'); ?> />
                        <label for="debug_mode">
                            <?php _e('Enable debug logging', 'wc-rbm'); ?>
                        </label>
                        <p class="description">
                            <?php _e('Logs will be saved to:', 'wc-rbm'); ?>
                            <code><?php echo wp_upload_dir()['basedir'] . '/wc-rbm-logs/'; ?></code>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Invoice Settings -->
        <div class="wc-rbm-settings-section">
            <h3><?php _e('Invoice Settings', 'wc-rbm'); ?></h3>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="invoice_prefix">
                            <?php _e('Invoice Prefix', 'wc-rbm'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="text" 
                               id="invoice_prefix" 
                               name="invoice_prefix" 
                               value="<?php echo esc_attr($settings['invoice_prefix']); ?>" 
                               class="regular-text" />
                        <p class="description">
                            <?php _e('Prefix for invoice numbers (e.g., INV-2024-000001)', 'wc-rbm'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="invoice_due_days">
                            <?php _e('Invoice Due Days', 'wc-rbm'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="number" 
                               id="invoice_due_days" 
                               name="invoice_due_days" 
                               value="<?php echo esc_attr($settings['invoice_due_days']); ?>" 
                               min="1" 
                               max="90" 
                               class="small-text" />
                        <span class="description">
                            <?php _e('days after invoice creation', 'wc-rbm'); ?>
                        </span>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- URL Management Settings -->
        <div class="wc-rbm-settings-section">
            <h3><?php _e('URL Management Settings', 'wc-rbm'); ?></h3>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="auto_approve_urls">
                            <?php _e('Auto-Approve URLs', 'wc-rbm'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="checkbox" 
                               id="auto_approve_urls" 
                               name="auto_approve_urls" 
                               value="yes" 
                               <?php checked(isset($settings['auto_approve_urls']) ? $settings['auto_approve_urls'] : 'yes', 'yes'); ?> />
                        <label for="auto_approve_urls">
                            <?php _e('Automatically approve submitted URLs', 'wc-rbm'); ?>
                        </label>
                        <p class="description">
                            <?php _e('URLs are automatically added to the whitelist without manual approval.', 'wc-rbm'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="url_validation_strict">
                            <?php _e('Strict URL Validation', 'wc-rbm'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="checkbox" 
                               id="url_validation_strict" 
                               name="url_validation_strict" 
                               value="yes" 
                               <?php checked(isset($settings['url_validation_strict']) ? $settings['url_validation_strict'] : 'no', 'yes'); ?> />
                        <label for="url_validation_strict">
                            <?php _e('Enable strict URL validation', 'wc-rbm'); ?>
                        </label>
                        <p class="description">
                            <?php _e('Requires URLs to have valid SSL certificates and be publicly accessible.', 'wc-rbm'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Cron Job Settings -->
        <div class="wc-rbm-settings-section">
            <h3><?php _e('Scheduled Tasks', 'wc-rbm'); ?></h3>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Next Billing Process', 'wc-rbm'); ?></th>
                    <td>
                        <?php echo WC_RBM()->cron->get_next_scheduled('wc_rbm_process_recurring_billing'); ?>
                        <button type="button" class="button" id="run-billing-now">
                            <?php _e('Run Now', 'wc-rbm'); ?>
                        </button>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Next URL Cleanup', 'wc-rbm'); ?></th>
                    <td>
                        <?php echo WC_RBM()->cron->get_next_scheduled('wc_rbm_cleanup_expired_urls'); ?>
                        <button type="button" class="button" id="run-cleanup-now">
                            <?php _e('Run Now', 'wc-rbm'); ?>
                        </button>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Next Maintenance', 'wc-rbm'); ?></th>
                    <td>
                        <?php echo WC_RBM()->cron->get_next_scheduled('wc_rbm_daily_maintenance'); ?>
                        <button type="button" class="button" id="run-maintenance-now">
                            <?php _e('Run Now', 'wc-rbm'); ?>
                        </button>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="send_daily_reports">
                            <?php _e('Daily Reports', 'wc-rbm'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="checkbox" 
                               id="send_daily_reports" 
                               name="send_daily_reports" 
                               value="yes" 
                               <?php checked(isset($settings['send_daily_reports']) ? $settings['send_daily_reports'] : 'no', 'yes'); ?> />
                        <label for="send_daily_reports">
                            <?php _e('Send daily summary reports to admin email', 'wc-rbm'); ?>
                        </label>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Advanced Settings -->
        <div class="wc-rbm-settings-section">
            <h3><?php _e('Advanced Settings', 'wc-rbm'); ?></h3>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="delete_data_on_uninstall">
                            <?php _e('Delete Data on Uninstall', 'wc-rbm'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="checkbox" 
                               id="delete_data_on_uninstall" 
                               name="delete_data_on_uninstall" 
                               value="yes" 
                               <?php checked(isset($settings['delete_data_on_uninstall']) ? $settings['delete_data_on_uninstall'] : 'no', 'yes'); ?> />
                        <label for="delete_data_on_uninstall">
                            <?php _e('Remove all plugin data when uninstalling', 'wc-rbm'); ?>
                        </label>
                        <p class="description" style="color: #d63638;">
                            <?php _e('Warning: This will permanently delete all subscriptions, invoices, and URLs when the plugin is uninstalled.', 'wc-rbm'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        
        <p class="submit">
            <input type="submit" 
                   class="button-primary" 
                   value="<?php esc_attr_e('Save Settings', 'wc-rbm'); ?>" />
        </p>
    </form>
    
    <!-- System Info -->
    <div class="wc-rbm-settings-section">
        <h3><?php _e('System Information', 'wc-rbm'); ?></h3>
        
        <table class="form-table">
            <tr>
                <th><?php _e('Plugin Version', 'wc-rbm'); ?></th>
                <td><?php echo WC_RBM_VERSION; ?></td>
            </tr>
            <tr>
                <th><?php _e('Database Version', 'wc-rbm'); ?></th>
                <td><?php echo get_option('wc_rbm_db_version', 'Not set'); ?></td>
            </tr>
            <tr>
                <th><?php _e('WordPress Version', 'wc-rbm'); ?></th>
                <td><?php echo get_bloginfo('version'); ?></td>
            </tr>
            <tr>
                <th><?php _e('WooCommerce Version', 'wc-rbm'); ?></th>
                <td><?php echo defined('WC_VERSION') ? WC_VERSION : 'Not installed'; ?></td>
            </tr>
            <tr>
                <th><?php _e('PHP Version', 'wc-rbm'); ?></th>
                <td><?php echo PHP_VERSION; ?></td>
            </tr>
            <tr>
                <th><?php _e('Database Tables', 'wc-rbm'); ?></th>
                <td>
                    <?php 
                    $tables = WC_RBM()->database->get_table_names();
                    foreach ($tables as $key => $table) {
                        $exists = WC_RBM()->database->tables_exist();
                        echo ucfirst($key) . ': ' . ($exists ? '✅' : '❌') . '<br>';
                    }
                    ?>
                </td>
            </tr>
        </table>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Run cron jobs manually
    $('#run-billing-now, #run-cleanup-now, #run-maintenance-now').on('click', function() {
        var $btn = $(this);
        var action = '';
        
        if ($btn.attr('id') === 'run-billing-now') {
            action = 'wc_rbm_run_billing';
        } else if ($btn.attr('id') === 'run-cleanup-now') {
            action = 'wc_rbm_run_cleanup';
        } else {
            action = 'wc_rbm_run_maintenance';
        }
        
        $btn.prop('disabled', true).text('<?php _e('Running...', 'wc-rbm'); ?>');
        
        $.post(ajaxurl, {
            action: action,
            nonce: wcRecurringBillingAdmin.nonce
        }, function(response) {
            if (response.success) {
                alert('<?php _e('Task completed successfully!', 'wc-rbm'); ?>');
                location.reload();
            } else {
                alert('Error: ' + response.data);
                $btn.prop('disabled', false).text('<?php _e('Run Now', 'wc-rbm'); ?>');
            }
        });
    });
});
</script>