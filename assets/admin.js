jQuery(document).ready(function($) {
    'use strict';

    console.log('WC Recurring Billing Admin: Script loaded successfully');
    
    // Global variables for admin functionality
    var ajaxInProgress = false;
    var bulkActionsEnabled = false;

    // Check if required dependencies are available
    if (typeof wcRecurringBillingAdmin === 'undefined') {
        console.error('WC Recurring Billing Admin: wcRecurringBillingAdmin not localized');
        return;
    }

    console.log('WC Recurring Billing Admin: Localized data available', wcRecurringBillingAdmin);

    // Initialize admin features with a slight delay to ensure DOM is ready
    setTimeout(function() {
        initializeAdminFeatures();
    }, 100);

    /**
     * Initialize all admin functionality
     */
    function initializeAdminFeatures() {
        console.log('WC Recurring Billing Admin: Initializing features');
        
        setupSubscriptionForm();
        setupSubscriptionManagement();
        setupInvoiceManagement();
        setupSubscriptionDeletion(); // Key function for delete
        setupBulkActions();
        setupRealTimeStats();
        setupUserSearch();
        setupKeyboardShortcuts();
        setupDataExport();
        initTooltips();
        initAutoSaveForm();
        
        console.log('WC Recurring Billing Admin: All features initialized');
        
        // Verify delete buttons are properly handled
        verifyDeleteButtonSetup();
    }

    /**
     * Verify delete button setup with debugging
     */
    function verifyDeleteButtonSetup() {
        var deleteButtons = $('.delete-subscription');
        console.log('WC Recurring Billing Admin: Found', deleteButtons.length, 'delete buttons');
        
        deleteButtons.each(function(index) {
            var $btn = $(this);
            var events = $._data(this, 'events');
            console.log('Button', index, 'ID:', $btn.data('id'), 'Events:', events);
        });
    }

    /**
     * FIXED: Setup subscription deletion functionality
     * Using multiple event binding approaches to ensure it works
     */
    function setupSubscriptionDeletion() {
        console.log('WC Recurring Billing Admin: Setting up delete functionality');
        
        // Method 1: Direct event binding
        $('.delete-subscription').off('click.deleteSubscription').on('click.deleteSubscription', handleDeleteClick);
        
        // Method 2: Event delegation (backup)
        $(document).off('click.deleteSubscription', '.delete-subscription').on('click.deleteSubscription', '.delete-subscription', handleDeleteClick);
        
        // Method 3: Body delegation (fallback)
        $('body').off('click.deleteSubscription', '.delete-subscription').on('click.deleteSubscription', '.delete-subscription', handleDeleteClick);
        
        console.log('WC Recurring Billing Admin: Delete event handlers attached');
        
        // Verify attachment worked
        setTimeout(function() {
            var deleteButtons = $('.delete-subscription');
            deleteButtons.each(function(index) {
                var events = $._data(this, 'events');
                if (events && events.click) {
                    console.log('✅ Delete button', index, 'has click handler');
                } else {
                    console.warn('❌ Delete button', index, 'missing click handler');
                }
            });
        }, 500);
    }

    /**
     * Handle delete button click
     */
    function handleDeleteClick(e) {
        e.preventDefault();
        e.stopPropagation();
        
        console.log('🗑️ Delete button clicked!');
        
        if (ajaxInProgress) {
            console.log('AJAX in progress, ignoring click');
            return false;
        }

        var $btn = $(this);
        var subscriptionId = $btn.data('id');
        var userName = $btn.data('user') || 'Unknown User';
        var originalText = $btn.text();
        
        console.log('Subscription ID:', subscriptionId, 'User:', userName);
        
        if (!subscriptionId) {
            console.error('No subscription ID found');
            showNotification('Error: No subscription ID found', 'error');
            return false;
        }
        
        // Enhanced confirmation dialog
        var confirmMsg = `⚠️ PERMANENT DELETION WARNING ⚠️\n\n` +
                       `Delete subscription for: ${userName}\n` +
                       `Subscription ID: ${subscriptionId}\n\n` +
                       `This will permanently:\n` +
                       `• Delete the subscription\n` +
                       `• Remove all associated URLs\n` +
                       `• Delete all invoices\n` +
                       `• Update the whitelist\n\n` +
                       `This action CANNOT be undone!\n\n` +
                       `Click OK to continue, Cancel to abort.`;
        
        if (!confirm(confirmMsg)) {
            console.log('User cancelled deletion');
            return false;
        }

        // Secondary confirmation
        var deleteConfirm = prompt('Type "DELETE" (in capital letters) to confirm permanent deletion:');
        if (deleteConfirm !== 'DELETE') {
            console.log('User did not type DELETE correctly:', deleteConfirm);
            showNotification('Deletion cancelled. You must type "DELETE" exactly to confirm.', 'warning');
            return false;
        }
        
        console.log('User confirmed deletion, proceeding...');
        
        // Set loading state
        setButtonLoading($btn, 'Deleting...');
        ajaxInProgress = true;
        
        // Make AJAX request with detailed error handling
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'delete_subscription',
                subscription_id: subscriptionId,
                nonce: wcRecurringBillingAdmin.nonce
            },
            timeout: 30000,
            beforeSend: function() {
                console.log('Sending delete request for subscription:', subscriptionId);
            }
        })
        .done(function(response) {
            console.log('Delete response received:', response);
            
            if (response && response.success) {
                showNotification('Subscription deleted successfully!', 'success');
                
                // Log deletion details
                if (response.data && response.data.details) {
                    console.log('Deletion details:', response.data.details);
                }
                
                // Remove the row with animation
                var $row = $btn.closest('tr');
                $row.fadeOut(500, function() {
                    $(this).remove();
                    updateSubscriptionStats();
                    console.log('Row removed from table');
                });
                
            } else {
                var errorMsg = 'Unknown error occurred';
                if (response && response.data) {
                    errorMsg = response.data;
                }
                console.error('Delete failed:', errorMsg);
                showNotification('Error deleting subscription: ' + errorMsg, 'error');
                resetButtonLoading($btn, originalText);
            }
        })
        .fail(function(xhr, status, error) {
            console.error('AJAX Delete Error:');
            console.error('Status:', status);
            console.error('Error:', error);
            console.error('Response Text:', xhr.responseText);
            console.error('Status Code:', xhr.status);
            
            var errorMsg = 'Network error occurred';
            if (xhr.responseText) {
                try {
                    var errorResponse = JSON.parse(xhr.responseText);
                    if (errorResponse.data) {
                        errorMsg = errorResponse.data;
                    }
                } catch (e) {
                    errorMsg = 'Server error: ' + xhr.status;
                }
            }
            
            showNotification(errorMsg, 'error');
            resetButtonLoading($btn, originalText);
        })
        .always(function() {
            ajaxInProgress = false;
            console.log('Delete request completed');
        });
        
        return false;
    }

    /**
     * Enhanced Create Subscription Form Handler
     */
    function setupSubscriptionForm() {
        $('#create-subscription-form').on('submit', function(e) {
            e.preventDefault();
            
            if (ajaxInProgress) {
                return false;
            }
            
            var $form = $(this);
            var $submitBtn = $form.find('input[type="submit"]');
            var originalBtnText = $submitBtn.val();
            
            // Enhanced validation
            var formData = validateSubscriptionForm($form);
            if (!formData.valid) {
                showNotification(formData.message, 'error');
                return;
            }
            
            // Confirm subscription creation with details
            var confirmMsg = buildConfirmationMessage(formData);
            if (!confirm(confirmMsg)) {
                return;
            }
            
            // Set loading state
            setButtonLoading($submitBtn, 'Creating...');
            ajaxInProgress = true;
            
            $.post(ajaxurl, {
                action: 'manage_subscription',
                operation: 'create',
                user_id: formData.userId,
                subscription_type: formData.subscriptionType,
                amount: formData.amount,
                duration: formData.duration,
                nonce: wcRecurringBillingAdmin.nonce
            })
            .done(function(response) {
                if (response.success) {
                    showNotification('Subscription created successfully!', 'success');
                    
                    // Reset form
                    $form[0].reset();
                    $('.billing-preview').remove();
                    
                    // Reload page after short delay
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification('Error: ' + (response.data || 'Unknown error occurred'), 'error');
                }
            })
            .fail(function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                showNotification('Network error. Please check your connection and try again.', 'error');
            })
            .always(function() {
                resetButtonLoading($submitBtn, originalBtnText);
                ajaxInProgress = false;
            });
        });
    }

    /**
     * Enhanced Subscription Management (Pause/Activate)
     */
    function setupSubscriptionManagement() {
        $(document).on('click', '.manage-subscription', function() {
            if (ajaxInProgress) return false;

            var $btn = $(this);
            var id = $btn.data('id');
            var action = $btn.data('action');
            var originalText = $btn.text();
            
            var actionText = action === 'pause' ? 'pause' : 'activate';
            var confirmMsg = `Are you sure you want to ${actionText} this subscription?\n\n` +
                           `This will ${action === 'pause' ? 'stop future billing and disable URL access' : 'resume billing and restore URL access'}.`;
            
            if (!confirm(confirmMsg)) {
                return;
            }
            
            setButtonLoading($btn, 'Processing...');
            ajaxInProgress = true;
            
            $.post(ajaxurl, {
                action: 'manage_subscription',
                operation: action,
                subscription_id: id,
                nonce: wcRecurringBillingAdmin.nonce
            })
            .done(function(response) {
                if (response.success) {
                    showNotification(`Subscription ${actionText}d successfully!`, 'success');
                    
                    // Update button state immediately
                    var newAction = action === 'pause' ? 'activate' : 'pause';
                    var newText = newAction === 'pause' ? 'Pause' : 'Activate';
                    $btn.data('action', newAction).text(newText);
                    
                    // Update status in table
                    var $statusCell = $btn.closest('tr').find('.status-' + (action === 'pause' ? 'active' : 'paused'));
                    $statusCell.removeClass('status-active status-paused')
                             .addClass('status-' + (action === 'pause' ? 'paused' : 'active'))
                             .text(action === 'pause' ? 'Paused' : 'Active');
                    
                    updateSubscriptionStats();
                } else {
                    showNotification('Error: ' + (response.data || 'Unknown error occurred'), 'error');
                }
            })
            .fail(function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                showNotification('Network error. Please try again.', 'error');
            })
            .always(function() {
                resetButtonLoading($btn, originalText);
                ajaxInProgress = false;
            });
        });
    }

    /**
     * Enhanced Invoice Management
     */
    function setupInvoiceManagement() {
        $(document).on('click', '.create-invoice', function() {
            if (ajaxInProgress) return false;

            var $btn = $(this);
            var id = $btn.data('id');
            var originalText = $btn.text();
            
            var confirmMsg = 'Create a new invoice for this subscription?\n\n' +
                           'This will generate an invoice and send an email to the customer with payment instructions.';
            
            if (!confirm(confirmMsg)) {
                return;
            }
            
            setButtonLoading($btn, 'Creating...');
            ajaxInProgress = true;
            
            $.post(ajaxurl, {
                action: 'create_invoice',
                subscription_id: id,
                nonce: wcRecurringBillingAdmin.nonce
            })
            .done(function(response) {
                if (response.success) {
                    showNotification('Invoice created and email sent successfully!', 'success');
                    
                    // Optionally refresh the invoices section
                    if ($('.invoices-table').length) {
                        loadInvoicesData();
                    }
                } else {
                    showNotification('Error: ' + (response.data || 'Unknown error occurred'), 'error');
                }
            })
            .fail(function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                showNotification('Network error. Please try again.', 'error');
            })
            .always(function() {
                resetButtonLoading($btn, originalText);
                ajaxInProgress = false;
            });
        });
    }

    // Simplified helper functions for this focused fix
    function validateSubscriptionForm($form) {
        var userId = $('#user_id').val();
        var subscriptionType = $('#subscription_type').val();
        var amount = parseFloat($('#amount').val());

        if (!userId) return { valid: false, message: 'Please select a user.' };
        if (!subscriptionType) return { valid: false, message: 'Please select a billing interval.' };
        if (!amount || amount <= 0) return { valid: false, message: 'Please enter a valid amount greater than $0.' };

        return {
            valid: true,
            userId: userId,
            subscriptionType: subscriptionType,
            amount: amount,
            duration: $('#duration').val()
        };
    }

    function buildConfirmationMessage(formData) {
        var userName = $('#user_id option:selected').text();
        var durationType = formData.subscriptionType === 'monthly' ? 'month' : 'year';
        
        return `Create ${formData.subscriptionType} subscription for ${userName}?\n\n` +
               `Amount: $${formData.amount.toFixed(2)} per ${durationType}`;
    }

    function setupBulkActions() { /* Simplified for focus */ }
    function setupRealTimeStats() { updateSubscriptionStats(); }
    function setupUserSearch() { /* Simplified for focus */ }
    function setupKeyboardShortcuts() { /* Simplified for focus */ }
    function setupDataExport() { /* Simplified for focus */ }
    function initTooltips() { /* Simplified for focus */ }
    function initAutoSaveForm() { /* Simplified for focus */ }

    function updateSubscriptionStats() {
        var totalActive = $('.status-active').length;
        var totalPaused = $('.status-paused').length;
        var totalCancelled = $('.status-cancelled').length;
        var totalSubscriptions = totalActive + totalPaused + totalCancelled;
        
        if (totalSubscriptions > 0) {
            var statsHtml = `
                <div class="subscription-stats" style="background: #fff; padding: 15px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                    <h4 style="margin: 0 0 15px 0; color: #333;">📊 Subscription Overview</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                        <div style="text-align: center; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                            <div style="font-size: 24px; font-weight: bold; color: #333;">${totalSubscriptions}</div>
                            <div style="color: #666; font-size: 12px;">Total</div>
                        </div>
                        <div style="text-align: center; padding: 10px; background: #d4edda; border-radius: 4px;">
                            <div style="font-size: 24px; font-weight: bold; color: #155724;">${totalActive}</div>
                            <div style="color: #155724; font-size: 12px;">Active</div>
                        </div>
                        <div style="text-align: center; padding: 10px; background: #fff3cd; border-radius: 4px;">
                            <div style="font-size: 24px; font-weight: bold; color: #856404;">${totalPaused}</div>
                            <div style="color: #856404; font-size: 12px;">Paused</div>
                        </div>
                    </div>
                </div>
            `;
            
            $('.subscription-stats').remove();
            $('.wp-list-table').before(statsHtml);
        }
    }

    /**
     * Utility functions
     */
    function setButtonLoading($button, text) {
        $button.data('original-text', $button.text() || $button.val());
        if ($button.is('input')) {
            $button.val(text).prop('disabled', true);
        } else {
            $button.html('<span class="loading-spinner"></span>' + text).prop('disabled', true);
        }
    }

    function resetButtonLoading($button, originalText) {
        if ($button.is('input')) {
            $button.val(originalText || $button.data('original-text')).prop('disabled', false);
        } else {
            $button.html(originalText || $button.data('original-text')).prop('disabled', false);
        }
    }

    function showNotification(message, type) {
        type = type || 'info';
        
        var notificationClass = 'notice notice-' + type;
        if (type === 'success') notificationClass += ' is-dismissible';
        
        var $notification = $('<div class="' + notificationClass + '" style="margin: 15px 0; padding: 12px; border-left-width: 4px; border-left-style: solid;"><p>' + message + '</p></div>');
        
        $('.wrap h1').after($notification);
        
        // Auto-remove after 5 seconds
        setTimeout(function() {
            $notification.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
        
        // Scroll to notification
        $('html, body').animate({
            scrollTop: $notification.offset().top - 50
        }, 300);
    }

    function loadInvoicesData() {
        console.log('Loading invoices data...');
    }

    console.log('WC Recurring Billing Admin: Script initialization complete');
    
    // Final verification that delete handlers are working
    setTimeout(function() {
        console.log('=== FINAL VERIFICATION ===');
        $('.delete-subscription').each(function(index) {
            var events = $._data(this, 'events');
            if (events && events.click) {
                console.log('✅ Delete button', index, 'ready');
            } else {
                console.error('❌ Delete button', index, 'not ready');
            }
        });
    }, 1000);
});