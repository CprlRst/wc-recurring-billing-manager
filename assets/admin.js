jQuery(document).ready(function($) {
    'use strict';

    const WC_RBM_Admin = {
        
        // Configuration
        config: {
            ajaxUrl: wcRecurringBillingAdmin.ajax_url || ajaxurl,
            nonce: wcRecurringBillingAdmin.nonce,
            ajaxInProgress: false
        },

        // Initialize
        init: function() {
            console.log('WC RBM Admin: Initializing...');
            
            this.bindEvents();
            this.initializeUI();
            this.updateStats();
            
            console.log('WC RBM Admin: Initialization complete');
        },

        // Bind all events
        bindEvents: function() {
            // Subscription form
            $('#create-subscription-form').on('submit', this.handleSubscriptionCreate.bind(this));
            
            // Subscription management (using event delegation for dynamic elements)
            $(document).on('click', '.manage-subscription', this.handleSubscriptionToggle.bind(this));
            $(document).on('click', '.create-invoice', this.handleInvoiceCreate.bind(this));
            $(document).on('click', '.delete-subscription', this.handleSubscriptionDelete.bind(this));
            
            // Export functionality
            $(document).on('click', '.export-data', this.handleDataExport.bind(this));
            
            // Refresh whitelist
            $(document).on('click', '#refresh-whitelist', this.handleWhitelistRefresh.bind(this));
            
            // URL removal
            $(document).on('click', '.remove-url', this.handleUrlRemoval.bind(this));
            
            // Bulk actions
            $('#bulk-action-submit').on('click', this.handleBulkAction.bind(this));
            
            // Select all checkbox
            $('#select-all').on('change', this.toggleSelectAll.bind(this));
            
            // Subscription type change
            $('#subscription_type').on('change', this.updatePricePreview.bind(this));
            $('#amount').on('input', this.updatePricePreview.bind(this));
        },

        // Handle subscription creation
        handleSubscriptionCreate: function(e) {
            e.preventDefault();
            
            if (this.config.ajaxInProgress) {
                return;
            }
            
            const $form = $(e.target);
            const $submitBtn = $form.find('input[type="submit"]');
            
            // Validate form
            const formData = this.validateSubscriptionForm($form);
            if (!formData.valid) {
                this.showNotification(formData.message, 'error');
                return;
            }
            
            // Confirm creation
            const confirmMsg = this.buildConfirmationMessage(formData);
            if (!confirm(confirmMsg)) {
                return;
            }
            
            // Submit form
            this.setButtonLoading($submitBtn, 'Creating...');
            this.config.ajaxInProgress = true;
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'wc_rbm_manage_subscription',
                    operation: 'create',
                    user_id: formData.userId,
                    subscription_type: formData.subscriptionType,
                    amount: formData.amount,
                    duration: formData.duration,
                    nonce: this.config.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotification(response.data.message || 'Subscription created successfully!', 'success');
                        $form[0].reset();
                        
                        // Reload page after delay
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        this.showNotification(response.data || 'Error creating subscription', 'error');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('AJAX Error:', status, error);
                    this.showNotification('Network error. Please try again.', 'error');
                },
                complete: () => {
                    this.resetButtonLoading($submitBtn, 'Create Subscription');
                    this.config.ajaxInProgress = false;
                }
            });
        },

        // Handle subscription toggle (pause/activate)
        handleSubscriptionToggle: function(e) {
            e.preventDefault();
            
            if (this.config.ajaxInProgress) {
                return;
            }
            
            const $btn = $(e.currentTarget);
            const id = $btn.data('id');
            const action = $btn.data('action');
            const actionText = action === 'pause' ? 'pause' : 'activate';
            
            const confirmMsg = `Are you sure you want to ${actionText} this subscription?`;
            if (!confirm(confirmMsg)) {
                return;
            }
            
            this.setButtonLoading($btn, 'Processing...');
            this.config.ajaxInProgress = true;
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'wc_rbm_manage_subscription',
                    operation: action,
                    subscription_id: id,
                    nonce: this.config.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotification(response.data.message || `Subscription ${actionText}d successfully!`, 'success');
                        
                        // Update button and status
                        const newAction = action === 'pause' ? 'activate' : 'pause';
                        const newText = newAction === 'pause' ? 'Pause' : 'Activate';
                        $btn.data('action', newAction).text(newText);
                        
                        // Update status cell
                        const $row = $btn.closest('tr');
                        const $statusCell = $row.find('[class*="status-"]');
                        const newStatus = action === 'pause' ? 'paused' : 'active';
                        $statusCell.removeClass('status-active status-paused status-cancelled')
                                  .addClass('status-' + newStatus)
                                  .text(newStatus.charAt(0).toUpperCase() + newStatus.slice(1));
                        
                        this.updateStats();
                    } else {
                        this.showNotification(response.data || 'Error updating subscription', 'error');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('AJAX Error:', status, error);
                    this.showNotification('Network error. Please try again.', 'error');
                },
                complete: () => {
                    this.resetButtonLoading($btn, $btn.text());
                    this.config.ajaxInProgress = false;
                }
            });
        },

        // Handle subscription deletion (IMPROVED)
        handleSubscriptionDelete: function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            console.log('Delete button clicked');
            
            if (this.config.ajaxInProgress) {
                console.log('AJAX already in progress');
                return;
            }
            
            const $btn = $(e.currentTarget);
            const subscriptionId = $btn.data('id');
            const userName = $btn.data('user') || 'Unknown User';
            
            console.log('Deleting subscription:', subscriptionId, 'for user:', userName);
            
            if (!subscriptionId) {
                this.showNotification('Error: No subscription ID found', 'error');
                return;
            }
            
            // Multi-step confirmation
            const confirmMsg = `⚠️ DELETE SUBSCRIPTION WARNING ⚠️\n\n` +
                             `This will permanently delete:\n` +
                             `• Subscription #${subscriptionId} for ${userName}\n` +
                             `• All associated URLs\n` +
                             `• All related invoices\n\n` +
                             `This action CANNOT be undone!\n\n` +
                             `Are you absolutely sure?`;
            
            if (!confirm(confirmMsg)) {
                return;
            }
            
            // Second confirmation
            const deleteConfirm = prompt('Type "DELETE" to confirm permanent deletion:');
            if (deleteConfirm !== 'DELETE') {
                this.showNotification('Deletion cancelled. You must type "DELETE" to confirm.', 'warning');
                return;
            }
            
            // Proceed with deletion
            this.setButtonLoading($btn, 'Deleting...');
            this.config.ajaxInProgress = true;
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'wc_rbm_delete_subscription',
                    subscription_id: subscriptionId,
                    nonce: this.config.nonce
                },
                timeout: 30000,
                success: (response) => {
                    console.log('Delete response:', response);
                    
                    if (response.success) {
                        this.showNotification(response.data.message || 'Subscription deleted successfully!', 'success');
                        
                        // Log deletion details
                        if (response.data.details) {
                            console.log('Deletion details:', response.data.details);
                        }
                        
                        // Remove row with animation
                        const $row = $btn.closest('tr');
                        $row.fadeOut(500, () => {
                            $row.remove();
                            this.updateStats();
                            
                            // Check if table is empty
                            if ($('.wp-list-table tbody tr').length === 0) {
                                $('.wp-list-table tbody').html(
                                    '<tr><td colspan="9" style="text-align: center;">No subscriptions found.</td></tr>'
                                );
                            }
                        });
                    } else {
                        this.showNotification(response.data || 'Error deleting subscription', 'error');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Delete AJAX Error:', status, error, xhr.responseText);
                    let errorMsg = 'Network error occurred';
                    
                    if (xhr.responseJSON && xhr.responseJSON.data) {
                        errorMsg = xhr.responseJSON.data;
                    } else if (xhr.status === 0) {
                        errorMsg = 'Connection failed. Please check your network.';
                    } else {
                        errorMsg = `Server error: ${xhr.status} ${error}`;
                    }
                    
                    this.showNotification(errorMsg, 'error');
                },
                complete: () => {
                    this.resetButtonLoading($btn, 'Delete');
                    this.config.ajaxInProgress = false;
                }
            });
        },

        // Handle invoice creation
        handleInvoiceCreate: function(e) {
            e.preventDefault();
            
            if (this.config.ajaxInProgress) {
                return;
            }
            
            const $btn = $(e.currentTarget);
            const id = $btn.data('id');
            
            if (!confirm('Create a new invoice for this subscription?')) {
                return;
            }
            
            this.setButtonLoading($btn, 'Creating...');
            this.config.ajaxInProgress = true;
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'wc_rbm_create_invoice',
                    subscription_id: id,
                    nonce: this.config.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotification(response.data.message || 'Invoice created successfully!', 'success');
                    } else {
                        this.showNotification(response.data || 'Error creating invoice', 'error');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('AJAX Error:', status, error);
                    this.showNotification('Network error. Please try again.', 'error');
                },
                complete: () => {
                    this.resetButtonLoading($btn, 'Invoice');
                    this.config.ajaxInProgress = false;
                }
            });
        },

        // Handle data export (new feature)
        handleDataExport: function(e) {
            e.preventDefault();
            
            const $btn = $(e.currentTarget);
            const exportType = $btn.data('export-type') || 'subscriptions';
            
            this.setButtonLoading($btn, 'Exporting...');
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'wc_rbm_export_data',
                    export_type: exportType,
                    nonce: this.config.nonce
                },
                success: (response) => {
                    if (response.success) {
                        // Convert data to CSV
                        const csv = this.convertToCSV(response.data.data);
                        
                        // Download file
                        this.downloadCSV(csv, response.data.filename);
                        
                        this.showNotification('Export completed successfully!', 'success');
                    } else {
                        this.showNotification(response.data || 'Export failed', 'error');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Export Error:', status, error);
                    this.showNotification('Export failed. Please try again.', 'error');
                },
                complete: () => {
                    this.resetButtonLoading($btn, $btn.text());
                }
            });
        },

        // Validate subscription form
        validateSubscriptionForm: function($form) {
            const userId = $('#user_id').val();
            const subscriptionType = $('#subscription_type').val();
            const amount = parseFloat($('#amount').val());
            const duration = $('#duration').val();

            if (!userId) {
                return { valid: false, message: 'Please select a user.' };
            }
            
            if (!subscriptionType) {
                return { valid: false, message: 'Please select a billing interval.' };
            }
            
            if (!amount || amount <= 0) {
                return { valid: false, message: 'Please enter a valid amount greater than $0.' };
            }

            return {
                valid: true,
                userId: userId,
                subscriptionType: subscriptionType,
                amount: amount,
                duration: duration
            };
        },

        // Build confirmation message
        buildConfirmationMessage: function(formData) {
            const userName = $('#user_id option:selected').text();
            const durationType = formData.subscriptionType === 'monthly' ? 'month' : 'year';
            const durationText = formData.duration ? ` for ${formData.duration} months` : ' (lifetime)';
            
            return `Create ${formData.subscriptionType} subscription for ${userName}?\n\n` +
                   `Amount: $${formData.amount.toFixed(2)} per ${durationType}${durationText}`;
        },

        // Update statistics
        updateStats: function() {
            const totalActive = $('.status-active').length;
            const totalPaused = $('.status-paused').length;
            const totalCancelled = $('.status-cancelled').length;
            const totalSubscriptions = totalActive + totalPaused + totalCancelled;
            
            if (totalSubscriptions > 0) {
                const statsHtml = `
                    <div class="subscription-stats">
                        <h4>📊 Subscription Overview</h4>
                        <div class="stats-grid">
                            <div class="stat-box">
                                <div class="stat-number">${totalSubscriptions}</div>
                                <div class="stat-label">Total</div>
                            </div>
                            <div class="stat-box stat-active">
                                <div class="stat-number">${totalActive}</div>
                                <div class="stat-label">Active</div>
                            </div>
                            <div class="stat-box stat-paused">
                                <div class="stat-number">${totalPaused}</div>
                                <div class="stat-label">Paused</div>
                            </div>
                            <div class="stat-box stat-cancelled">
                                <div class="stat-number">${totalCancelled}</div>
                                <div class="stat-label">Cancelled</div>
                            </div>
                        </div>
                    </div>
                `;
                
                $('.subscription-stats').remove();
                $('.wp-list-table').before(statsHtml);
            }
        },

        // Show notification
        showNotification: function(message, type = 'info') {
            const notificationClass = `notice notice-${type} is-dismissible`;
            const $notification = $(`<div class="${notificationClass}"><p>${message}</p></div>`);
            
            // Remove existing notifications
            $('.wrap .notice').remove();
            
            // Add new notification
            $('.wrap h1').after($notification);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                $notification.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
            
            // Scroll to notification
            $('html, body').animate({
                scrollTop: $notification.offset().top - 50
            }, 300);
        },

        // Set button loading state
        setButtonLoading: function($button, text) {
            $button.data('original-text', $button.text() || $button.val());
            
            if ($button.is('input')) {
                $button.val(text).prop('disabled', true);
            } else {
                $button.html(`<span class="spinner is-active"></span> ${text}`).prop('disabled', true);
            }
        },

        // Reset button loading state
        resetButtonLoading: function($button, text) {
            if ($button.is('input')) {
                $button.val(text || $button.data('original-text')).prop('disabled', false);
            } else {
                $button.html(text || $button.data('original-text')).prop('disabled', false);
            }
        },

        // Initialize UI enhancements
        initializeUI: function() {
            // Add export buttons
            if ($('.wrap h1').length && !$('.export-buttons').length) {
                const exportButtons = `
                    <div class="export-buttons" style="display: inline-block; margin-left: 20px;">
                        <button class="button export-data" data-export-type="subscriptions">
                            📥 Export Subscriptions
                        </button>
                        <button class="button export-data" data-export-type="invoices">
                            📥 Export Invoices
                        </button>
                        <button class="button export-data" data-export-type="urls">
                            📥 Export URLs
                        </button>
                    </div>
                `;
                $('.wrap h1').append(exportButtons);
            }
            
            // Initialize tooltips
            this.initTooltips();
            
            // Add keyboard shortcuts
            this.initKeyboardShortcuts();
        },

        // Initialize tooltips
        initTooltips: function() {
            $('[title]').each(function() {
                const $this = $(this);
                const title = $this.attr('title');
                
                $this.on('mouseenter', function() {
                    $('<div class="tooltip">' + title + '</div>').appendTo('body').fadeIn(200);
                }).on('mouseleave', function() {
                    $('.tooltip').remove();
                }).on('mousemove', function(e) {
                    $('.tooltip').css({
                        top: e.pageY + 10,
                        left: e.pageX + 10
                    });
                });
            });
        },

        // Initialize keyboard shortcuts
        initKeyboardShortcuts: function() {
            $(document).on('keydown', (e) => {
                // Ctrl/Cmd + N = New subscription
                if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                    e.preventDefault();
                    $('#user_id').focus();
                }
                
                // Ctrl/Cmd + E = Export
                if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
                    e.preventDefault();
                    $('.export-data:first').click();
                }
            });
        },

        // Convert data to CSV
        convertToCSV: function(data) {
            if (!data || data.length === 0) {
                return '';
            }
            
            const headers = Object.keys(data[0]);
            const csvHeaders = headers.join(',');
            
            const csvRows = data.map(row => {
                return headers.map(header => {
                    const value = row[header] || '';
                    // Escape quotes and wrap in quotes if contains comma
                    const escaped = String(value).replace(/"/g, '""');
                    return escaped.includes(',') ? `"${escaped}"` : escaped;
                }).join(',');
            });
            
            return csvHeaders + '\n' + csvRows.join('\n');
        },

        // Download CSV file
        downloadCSV: function(csv, filename) {
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            
            if (navigator.msSaveBlob) {
                // IE 10+
                navigator.msSaveBlob(blob, filename);
            } else {
                link.href = URL.createObjectURL(blob);
                link.download = filename;
                link.style.display = 'none';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        },

        // Handle whitelist refresh
        handleWhitelistRefresh: function(e) {
            e.preventDefault();
            
            const $btn = $(e.currentTarget);
            this.setButtonLoading($btn, 'Refreshing...');
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'wc_rbm_refresh_whitelist',
                    nonce: this.config.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotification('Whitelist refreshed successfully!', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        this.showNotification(response.data || 'Error refreshing whitelist', 'error');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('AJAX Error:', status, error);
                    this.showNotification('Network error. Please try again.', 'error');
                },
                complete: () => {
                    this.resetButtonLoading($btn, 'Refresh Whitelist');
                }
            });
        },

        // Handle URL removal
        handleUrlRemoval: function(e) {
            e.preventDefault();
            
            const $btn = $(e.currentTarget);
            const urlId = $btn.data('id');
            
            if (!confirm('Remove this URL from the whitelist?')) {
                return;
            }
            
            this.setButtonLoading($btn, 'Removing...');
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'wc_rbm_remove_user_url',
                    url_id: urlId,
                    nonce: this.config.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotification('URL removed successfully!', 'success');
                        $btn.closest('tr').fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        this.showNotification(response.data || 'Error removing URL', 'error');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('AJAX Error:', status, error);
                    this.showNotification('Network error. Please try again.', 'error');
                },
                complete: () => {
                    this.resetButtonLoading($btn, 'Remove');
                }
            });
        },

        // Handle bulk actions
        handleBulkAction: function(e) {
            e.preventDefault();
            
            const action = $('#bulk-action-selector').val();
            const selected = $('.subscription-checkbox:checked').map(function() {
                return $(this).val();
            }).get();
            
            if (!action) {
                this.showNotification('Please select a bulk action.', 'warning');
                return;
            }
            
            if (selected.length === 0) {
                this.showNotification('Please select at least one subscription.', 'warning');
                return;
            }
            
            const confirmMsg = `Apply "${action}" to ${selected.length} subscription(s)?`;
            if (!confirm(confirmMsg)) {
                return;
            }
            
            // Process bulk action
            this.processBulkAction(action, selected);
        },

        // Process bulk action
        processBulkAction: function(action, ids) {
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'wc_rbm_bulk_action',
                    bulk_action: action,
                    subscription_ids: ids,
                    nonce: this.config.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotification(response.data.message || 'Bulk action completed!', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        this.showNotification(response.data || 'Bulk action failed', 'error');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Bulk Action Error:', status, error);
                    this.showNotification('Network error. Please try again.', 'error');
                }
            });
        },

        // Toggle select all
        toggleSelectAll: function(e) {
            const isChecked = $(e.target).is(':checked');
            $('.subscription-checkbox').prop('checked', isChecked);
        },

        // Update price preview
        updatePricePreview: function() {
            const amount = parseFloat($('#amount').val()) || 0;
            const type = $('#subscription_type').val();
            const duration = $('#duration').val();
            
            let preview = `$${amount.toFixed(2)} per ${type === 'monthly' ? 'month' : 'year'}`;
            if (duration) {
                preview += ` for ${duration} months`;
            } else {
                preview += ' (lifetime)';
            }
            
            $('.price-preview').text(preview);
        }
    };

    // Initialize the admin handler
    WC_RBM_Admin.init();
});