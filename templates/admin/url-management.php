<?php
/**
 * Admin URL Management Page Template
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap wc-rbm-admin-page">
    <h1><?php _e('URL Management', 'wc-rbm'); ?></h1>
    
    <?php if (!empty($stats)): ?>
    <!-- URL Statistics -->
    <div class="subscription-stats">
        <h4><?php _e('📊 URL Overview', 'wc-rbm'); ?></h4>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
                <div class="stat-label"><?php _e('Total URLs', 'wc-rbm'); ?></div>
            </div>
            <div class="stat-box stat-active">
                <div class="stat-number"><?php echo number_format($stats['active']); ?></div>
                <div class="stat-label"><?php _e('Active', 'wc-rbm'); ?></div>
            </div>
            <div class="stat-box stat-paused">
                <div class="stat-number"><?php echo number_format($stats['expired']); ?></div>
                <div class="stat-label"><?php _e('Expired', 'wc-rbm'); ?></div>
            </div>
            <div class="stat-box stat-cancelled">
                <div class="stat-number"><?php echo number_format($stats['removed']); ?></div>
                <div class="stat-label"><?php _e('Removed', 'wc-rbm'); ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Current Bricks Whitelist Display -->
    <div class="current-whitelist">
        <h2>
            <?php _e('Current Bricks Whitelist', 'wc-rbm'); ?>
            <button type="button" id="refresh-whitelist" class="button" style="float: right;">
                <?php _e('Refresh Whitelist', 'wc-rbm'); ?>
            </button>
        </h2>
        <div>
            <?php echo $current_whitelist ? esc_html($current_whitelist) : __('No URLs in whitelist', 'wc-rbm'); ?>
        </div>
        
        <div style="margin-top: 15px;">
            <strong><?php _e('Total URLs in whitelist:', 'wc-rbm'); ?></strong> 
            <?php 
            $whitelist_count = $current_whitelist ? count(array_filter(explode("\n", $current_whitelist))) : 0;
            echo $whitelist_count;
            ?>
        </div>
    </div>
    
    <!-- User Submitted URLs Table -->
    <h2><?php _e('User Submitted URLs', 'wc-rbm'); ?></h2>
    
    <!-- Filter Options -->
    <div class="tablenav top">
        <div class="alignleft actions">
            <select name="filter_status" id="filter_url_status">
                <option value=""><?php _e('All Statuses', 'wc-rbm'); ?></option>
                <option value="active"><?php _e('Active', 'wc-rbm'); ?></option>
                <option value="expired"><?php _e('Expired', 'wc-rbm'); ?></option>
                <option value="removed"><?php _e('Removed', 'wc-rbm'); ?></option>
            </select>
            
            <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'wc-rbm'); ?>">
        </div>
        
        <div class="alignright">
            <button type="button" class="button" id="cleanup-expired-urls">
                <?php _e('Cleanup Expired URLs', 'wc-rbm'); ?>
            </button>
        </div>
    </div>
    
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php _e('User', 'wc-rbm'); ?></th>
                <th><?php _e('URL', 'wc-rbm'); ?></th>
                <th style="width: 100px;"><?php _e('Subscription', 'wc-rbm'); ?></th>
                <th style="width: 100px;"><?php _e('URL Status', 'wc-rbm'); ?></th>
                <th style="width: 120px;"><?php _e('Sub Status', 'wc-rbm'); ?></th>
                <th style="width: 100px;"><?php _e('Expires', 'wc-rbm'); ?></th>
                <th style="width: 150px;"><?php _e('Submitted', 'wc-rbm'); ?></th>
                <th style="width: 100px;"><?php _e('Actions', 'wc-rbm'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($user_urls)): ?>
            <tr>
                <td colspan="8" style="text-align: center;">
                    <?php _e('No URLs found.', 'wc-rbm'); ?>
                </td>
            </tr>
            <?php else: ?>
                <?php foreach ($user_urls as $url_record): ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($url_record->display_name); ?></strong><br>
                        <small><?php echo esc_html($url_record->user_email); ?></small>
                    </td>
                    <td class="url-cell">
                        <a href="<?php echo esc_url($url_record->url); ?>" 
                           target="_blank" 
                           title="<?php esc_attr_e('Visit URL', 'wc-rbm'); ?>">
                            <?php echo esc_html($url_record->url); ?>
                        </a>
                    </td>
                    <td><?php echo ucfirst($url_record->subscription_type); ?></td>
                    <td>
                        <span class="status-<?php echo $url_record->status; ?>">
                            <?php echo ucfirst($url_record->status); ?>
                        </span>
                    </td>
                    <td>
                        <span class="status-<?php echo $url_record->sub_status; ?>">
                            <?php echo ucfirst($url_record->sub_status); ?>
                        </span>
                    </td>
                    <td>
                        <?php 
                        if ($url_record->expiry_date) {
                            $expiry_time = strtotime($url_record->expiry_date);
                            echo date_i18n(get_option('date_format'), $expiry_time);
                            
                            if ($expiry_time < time()) {
                                echo '<br><small style="color: #dc3232;">' . __('Expired', 'wc-rbm') . '</small>';
                            }
                        } else {
                            _e('Lifetime', 'wc-rbm');
                        }
                        ?>
                    </td>
                    <td>
                        <?php echo date_i18n(
                            get_option('date_format') . ' ' . get_option('time_format'), 
                            strtotime($url_record->created_at)
                        ); ?>
                    </td>
                    <td>
                        <?php if ($url_record->status === 'active'): ?>
                            <button class="button remove-url" 
                                    data-id="<?php echo $url_record->id; ?>">
                                <?php _e('Remove', 'wc-rbm'); ?>
                            </button>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <!-- Whitelist Management Tools -->
    <div class="whitelist-tools" style="margin-top: 40px;">
        <h2><?php _e('Whitelist Management Tools', 'wc-rbm'); ?></h2>
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4;">
            <p><?php _e('Use these tools to manage your Bricks whitelist:', 'wc-rbm'); ?></p>
            
            <p>
                <button type="button" class="button button-primary" id="sync-whitelist">
                    <?php _e('Sync All Active URLs', 'wc-rbm'); ?>
                </button>
                <span class="description">
                    <?php _e('Rebuilds the whitelist with all active URLs from the database.', 'wc-rbm'); ?>
                </span>
            </p>
            
            <p>
                <button type="button" class="button" id="export-whitelist">
                    <?php _e('Export Whitelist', 'wc-rbm'); ?>
                </button>
                <span class="description">
                    <?php _e('Download the current whitelist as a text file.', 'wc-rbm'); ?>
                </span>
            </p>
            
            <p>
                <button type="button" class="button button-link-delete" id="clear-whitelist">
                    <?php _e('Clear Plugin URLs', 'wc-rbm'); ?>
                </button>
                <span class="description">
                    <?php _e('Remove all plugin-managed URLs from the whitelist (keeps manually added URLs).', 'wc-rbm'); ?>
                </span>
            </p>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Cleanup expired URLs
    $('#cleanup-expired-urls').on('click', function() {
        if (confirm('<?php _e('This will mark all URLs from expired/inactive subscriptions as expired. Continue?', 'wc-rbm'); ?>')) {
            // Trigger cleanup
            $.post(ajaxurl, {
                action: 'wc_rbm_cleanup_urls',
                nonce: wcRecurringBillingAdmin.nonce
            }, function(response) {
                if (response.success) {
                    location.reload();
                }
            });
        }
    });
    
    // Sync whitelist
    $('#sync-whitelist').on('click', function() {
        if (confirm('<?php _e('This will rebuild the whitelist with all active URLs. Continue?', 'wc-rbm'); ?>')) {
            $(this).prop('disabled', true).text('<?php _e('Syncing...', 'wc-rbm'); ?>');
            
            $.post(ajaxurl, {
                action: 'wc_rbm_sync_whitelist',
                nonce: wcRecurringBillingAdmin.nonce
            }, function(response) {
                if (response.success) {
                    alert('<?php _e('Whitelist synced successfully!', 'wc-rbm'); ?>');
                    location.reload();
                }
            });
        }
    });
    
    // Export whitelist
    $('#export-whitelist').on('click', function() {
        var whitelist = $('.current-whitelist > div').text();
        var blob = new Blob([whitelist], {type: 'text/plain'});
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'whitelist-' + new Date().toISOString().split('T')[0] + '.txt';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });
});
</script>