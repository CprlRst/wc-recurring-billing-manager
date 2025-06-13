<?php
/**
 * Admin Subscriptions Page Template
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap wc-rbm-admin-page">
    <h1>
        <?php _e('Recurring Billing Subscriptions', 'wc-rbm'); ?>
        <a href="#" class="page-title-action" id="add-new-subscription">
            <?php _e('Add New', 'wc-rbm'); ?>
        </a>
    </h1>
    
    <?php if (!empty($stats)): ?>
    <!-- Statistics Overview -->
    <div class="subscription-stats">
        <h4><?php _e('📊 Subscription Overview', 'wc-rbm'); ?></h4>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
                <div class="stat-label"><?php _e('Total', 'wc-rbm'); ?></div>
            </div>
            <div class="stat-box stat-active">
                <div class="stat-number"><?php echo number_format($stats['active']); ?></div>
                <div class="stat-label"><?php _e('Active', 'wc-rbm'); ?></div>
            </div>
            <div class="stat-box stat-paused">
                <div class="stat-number"><?php echo number_format($stats['paused']); ?></div>
                <div class="stat-label"><?php _e('Paused', 'wc-rbm'); ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo wc_price($stats['monthly_revenue'] * 12 + $stats['yearly_revenue']); ?></div>
                <div class="stat-label"><?php _e('Annual Revenue', 'wc-rbm'); ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Subscription Creation Form (Initially Hidden) -->
    <div class="subscription-form" id="subscription-form" style="display: none;">
        <h2><?php _e('Create New Subscription', 'wc-rbm'); ?></h2>
        <form id="create-subscription-form">
            <table class="form-table">
                <tr>
                    <th><label for="user_id"><?php _e('User', 'wc-rbm'); ?></label></th>
                    <td>
                        <select name="user_id" id="user_id" class="regular-text" required>
                            <option value=""><?php _e('Select User', 'wc-rbm'); ?></option>
                            <?php
                            $users = get_users(array('orderby' => 'display_name'));
                            foreach ($users as $user) {
                                printf(
                                    '<option value="%d">%s (%s)</option>',
                                    $user->ID,
                                    esc_html($user->display_name),
                                    esc_html($user->user_email)
                                );
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="subscription_type"><?php _e('Billing Interval', 'wc-rbm'); ?></label></th>
                    <td>
                        <select name="subscription_type" id="subscription_type" required>
                            <option value="monthly"><?php _e('Monthly', 'wc-rbm'); ?></option>
                            <option value="yearly"><?php _e('Yearly', 'wc-rbm'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="amount"><?php _e('Amount', 'wc-rbm'); ?></label></th>
                    <td>
                        <input type="number" name="amount" id="amount" class="regular-text" 
                               step="0.01" min="0" required 
                               placeholder="0.00" />
                        <span class="description"><?php echo get_woocommerce_currency_symbol(); ?></span>
                    </td>
                </tr>
                <tr>
                    <th><label for="duration"><?php _e('Duration (months)', 'wc-rbm'); ?></label></th>
                    <td>
                        <input type="number" name="duration" id="duration" class="regular-text" 
                               step="1" min="0" 
                               placeholder="<?php esc_attr_e('Leave empty for lifetime', 'wc-rbm'); ?>" />
                        <p class="description"><?php _e('Leave empty for lifetime subscription', 'wc-rbm'); ?></p>
                    </td>
                </tr>
            </table>
            
            <div class="price-preview" style="padding: 10px; background: #f0f0f0; margin: 10px 0;"></div>
            
            <p class="submit">
                <input type="submit" class="button-primary" value="<?php esc_attr_e('Create Subscription', 'wc-rbm'); ?>" />
                <button type="button" class="button" id="cancel-subscription">
                    <?php _e('Cancel', 'wc-rbm'); ?>
                </button>
            </p>
        </form>
    </div>
    
    <!-- Bulk Actions -->
    <div class="tablenav top">
        <div class="alignleft actions bulkactions">
            <label for="bulk-action-selector-top" class="screen-reader-text">
                <?php _e('Select bulk action', 'wc-rbm'); ?>
            </label>
            <select name="action" id="bulk-action-selector">
                <option value="-1"><?php _e('Bulk Actions', 'wc-rbm'); ?></option>
                <option value="activate"><?php _e('Activate', 'wc-rbm'); ?></option>
                <option value="pause"><?php _e('Pause', 'wc-rbm'); ?></option>
                <option value="delete"><?php _e('Delete', 'wc-rbm'); ?></option>
            </select>
            <input type="submit" id="bulk-action-submit" class="button action" 
                   value="<?php esc_attr_e('Apply', 'wc-rbm'); ?>">
        </div>
    </div>
    
    <!-- Subscriptions Table -->
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <td class="manage-column column-cb check-column">
                    <input id="select-all" type="checkbox" />
                </td>
                <th style="width: 50px;"><?php _e('ID', 'wc-rbm'); ?></th>
                <th><?php _e('User', 'wc-rbm'); ?></th>
                <th><?php _e('Current URL', 'wc-rbm'); ?></th>
                <th style="width: 80px;"><?php _e('Type', 'wc-rbm'); ?></th>
                <th style="width: 80px;"><?php _e('Amount', 'wc-rbm'); ?></th>
                <th style="width: 80px;"><?php _e('Status', 'wc-rbm'); ?></th>
                <th style="width: 120px;"><?php _e('Next Billing', 'wc-rbm'); ?></th>
                <th style="width: 90px;"><?php _e('Expires', 'wc-rbm'); ?></th>
                <th class="column-actions"><?php _e('Actions', 'wc-rbm'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($subscriptions)): ?>
            <tr>
                <td colspan="10" style="text-align: center;">
                    <?php _e('No subscriptions found.', 'wc-rbm'); ?>
                </td>
            </tr>
            <?php else: ?>
                <?php foreach ($subscriptions as $subscription): ?>
                <tr id="subscription-row-<?php echo $subscription->id; ?>">
                    <th scope="row" class="check-column">
                        <input type="checkbox" class="subscription-checkbox" 
                               value="<?php echo $subscription->id; ?>" />
                    </th>
                    <td><?php echo $subscription->id; ?></td>
                    <td>
                        <strong><?php echo esc_html($subscription->display_name); ?></strong><br>
                        <small><?php echo esc_html($subscription->user_email); ?></small>
                    </td>
                    <td class="url-cell">
                        <?php if ($subscription->current_url): ?>
                            <a href="<?php echo esc_url($subscription->current_url); ?>" 
                               target="_blank" title="<?php esc_attr_e('Visit URL', 'wc-rbm'); ?>">
                                <?php echo esc_html($subscription->current_url); ?>
                            </a>
                        <?php else: ?>
                            <span style="color: #666; font-style: italic;">
                                <?php _e('No URL submitted', 'wc-rbm'); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo ucfirst($subscription->subscription_type); ?></td>
                    <td><?php echo wc_price($subscription->amount); ?></td>
                    <td>
                        <span class="status-<?php echo $subscription->status; ?>">
                            <?php echo ucfirst($subscription->status); ?>
                        </span>
                    </td>
                    <td>
                        <?php echo date_i18n(
                            get_option('date_format'), 
                            strtotime($subscription->next_billing_date)
                        ); ?>
                    </td>
                    <td>
                        <?php 
                        if ($subscription->expiry_date) {
                            echo date_i18n(
                                get_option('date_format'), 
                                strtotime($subscription->expiry_date)
                            );
                        } else {
                            _e('Lifetime', 'wc-rbm');
                        }
                        ?>
                    </td>
                    <td>
                        <button class="button manage-subscription" 
                                data-id="<?php echo $subscription->id; ?>" 
                                data-action="<?php echo $subscription->status === 'active' ? 'pause' : 'activate'; ?>"
                                title="<?php echo $subscription->status === 'active' ? 
                                        esc_attr__('Pause subscription', 'wc-rbm') : 
                                        esc_attr__('Activate subscription', 'wc-rbm'); ?>">
                            <?php echo $subscription->status === 'active' ? 
                                    __('Pause', 'wc-rbm') : __('Activate', 'wc-rbm'); ?>
                        </button>
                        <button class="button create-invoice" 
                                data-id="<?php echo $subscription->id; ?>"
                                title="<?php esc_attr_e('Create new invoice', 'wc-rbm'); ?>">
                            <?php _e('Invoice', 'wc-rbm'); ?>
                        </button>
                        <button class="button button-link-delete delete-subscription" 
                                data-id="<?php echo $subscription->id; ?>" 
                                data-user="<?php echo esc_attr($subscription->display_name); ?>"
                                title="<?php esc_attr_e('Permanently delete subscription', 'wc-rbm'); ?>">
                            <?php _e('Delete', 'wc-rbm'); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
jQuery(document).ready(function($) {
    // Show/hide subscription form
    $('#add-new-subscription').on('click', function(e) {
        e.preventDefault();
        $('#subscription-form').slideToggle();
        $('#user_id').focus();
    });
    
    $('#cancel-subscription').on('click', function() {
        $('#subscription-form').slideUp();
        $('#create-subscription-form')[0].reset();
    });
});
</script>