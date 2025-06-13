<?php
/**
 * Admin Invoices Page Template
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap wc-rbm-admin-page">
    <h1><?php _e('Invoices', 'wc-rbm'); ?></h1>
    
    <?php if (!empty($stats)): ?>
    <!-- Invoice Statistics -->
    <div class="subscription-stats">
        <h4><?php _e('📊 Invoice Overview', 'wc-rbm'); ?></h4>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
                <div class="stat-label"><?php _e('Total Invoices', 'wc-rbm'); ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo number_format($stats['pending']); ?></div>
                <div class="stat-label"><?php _e('Pending', 'wc-rbm'); ?></div>
            </div>
            <div class="stat-box stat-active">
                <div class="stat-number"><?php echo number_format($stats['paid']); ?></div>
                <div class="stat-label"><?php _e('Paid', 'wc-rbm'); ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo wc_price($stats['monthly_revenue']); ?></div>
                <div class="stat-label"><?php _e('This Month', 'wc-rbm'); ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Filter Options -->
    <div class="tablenav top">
        <div class="alignleft actions">
            <select name="filter_status" id="filter_status">
                <option value=""><?php _e('All Statuses', 'wc-rbm'); ?></option>
                <option value="pending"><?php _e('Pending', 'wc-rbm'); ?></option>
                <option value="paid"><?php _e('Paid', 'wc-rbm'); ?></option>
                <option value="failed"><?php _e('Failed', 'wc-rbm'); ?></option>
            </select>
            
            <select name="filter_month" id="filter_month">
                <option value=""><?php _e('All Months', 'wc-rbm'); ?></option>
                <?php
                for ($i = 0; $i < 12; $i++) {
                    $month = date('Y-m', strtotime("-$i months"));
                    $label = date('F Y', strtotime("-$i months"));
                    echo '<option value="' . $month . '">' . $label . '</option>';
                }
                ?>
            </select>
            
            <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'wc-rbm'); ?>">
        </div>
    </div>
    
    <!-- Invoices Table -->
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 150px;"><?php _e('Invoice #', 'wc-rbm'); ?></th>
                <th><?php _e('User', 'wc-rbm'); ?></th>
                <th style="width: 100px;"><?php _e('Type', 'wc-rbm'); ?></th>
                <th style="width: 100px;"><?php _e('Amount', 'wc-rbm'); ?></th>
                <th style="width: 100px;"><?php _e('Status', 'wc-rbm'); ?></th>
                <th style="width: 120px;"><?php _e('Created', 'wc-rbm'); ?></th>
                <th style="width: 120px;"><?php _e('Paid', 'wc-rbm'); ?></th>
                <th style="width: 150px;"><?php _e('Payment Method', 'wc-rbm'); ?></th>
                <th style="width: 150px;"><?php _e('Actions', 'wc-rbm'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($invoices)): ?>
            <tr>
                <td colspan="9" style="text-align: center;">
                    <?php _e('No invoices found.', 'wc-rbm'); ?>
                </td>
            </tr>
            <?php else: ?>
                <?php foreach ($invoices as $invoice): ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($invoice->invoice_number); ?></strong>
                    </td>
                    <td>
                        <strong><?php echo esc_html($invoice->display_name); ?></strong><br>
                        <small><?php echo esc_html($invoice->user_email); ?></small>
                    </td>
                    <td><?php echo ucfirst($invoice->subscription_type); ?></td>
                    <td><?php echo wc_price($invoice->amount); ?></td>
                    <td>
                        <span class="status-<?php echo $invoice->status; ?>">
                            <?php echo ucfirst($invoice->status); ?>
                        </span>
                    </td>
                    <td>
                        <?php echo date_i18n(
                            get_option('date_format') . ' ' . get_option('time_format'), 
                            strtotime($invoice->created_at)
                        ); ?>
                    </td>
                    <td>
                        <?php 
                        if ($invoice->paid_at) {
                            echo date_i18n(
                                get_option('date_format') . ' ' . get_option('time_format'), 
                                strtotime($invoice->paid_at)
                            );
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                    <td>
                        <?php 
                        if ($invoice->payment_method) {
                            echo esc_html($invoice->payment_method);
                            if ($invoice->transaction_id) {
                                echo '<br><small>' . esc_html($invoice->transaction_id) . '</small>';
                            }
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                    <td>
                        <?php if ($invoice->status === 'pending'): ?>
                            <button class="button send-invoice-email" 
                                    data-id="<?php echo $invoice->id; ?>">
                                <?php _e('Resend Email', 'wc-rbm'); ?>
                            </button>
                            <button class="button mark-paid" 
                                    data-id="<?php echo $invoice->id; ?>">
                                <?php _e('Mark Paid', 'wc-rbm'); ?>
                            </button>
                        <?php else: ?>
                            <button class="button view-invoice" 
                                    data-id="<?php echo $invoice->id; ?>">
                                <?php _e('View', 'wc-rbm'); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>