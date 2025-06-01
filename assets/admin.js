jQuery(document).ready(function($) {
    'use strict';

    // Create Subscription Form Handler
    $('#create-subscription-form').on('submit', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $submitBtn = $form.find('input[type="submit"]');
        var originalBtnText = $submitBtn.val();
        
        // Validate form
        var userId = $('#user_id').val();
        var subscriptionType = $('#subscription_type').val();
        var amount = parseFloat($('#amount').val());
        
        if (!userId || !subscriptionType || !amount || amount <= 0) {
            alert('Please fill in all fields with valid values.');
            return;
        }
        
        // Confirm subscription creation
        var userName = $('#user_id option:selected').text();
        var confirmMsg = 'Create ' + subscriptionType + ' subscription for ' + userName + ' at $' + amount.toFixed(2) + '?';
        
        if (!confirm(confirmMsg)) {
            return;
        }
        
        // Disable submit button
        $submitBtn.val('Creating...').prop('disabled', true);
        
        $.post(ajaxurl, {
            action: 'manage_subscription',
            operation: 'create',
            user_id: userId,
            subscription_type: subscriptionType,
            amount: amount,
            nonce: wcRecurringBillingAdmin.nonce
        })
        .done(function(response) {
            if (response.success) {
                alert('Subscription created successfully!');
                location.reload();
            } else {
                alert('Error: ' + (response.data || 'Unknown error occurred'));
            }
        })
        .fail(function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            alert('Network error. Please try again.');
        })
        .always(function() {
            $submitBtn.val(originalBtnText).prop('disabled', false);
        });
    });
    
    // Manage Subscription Buttons
    $('.manage-subscription').on('click', function() {
        var $btn = $(this);
        var id = $btn.data('id');
        var action = $btn.data('action');
        var originalText = $btn.text();
        
        var actionText = action === 'pause' ? 'pause' : 'activate';
        var confirmMsg = 'Are you sure you want to ' + actionText + ' this subscription?';
        
        if (!confirm(confirmMsg)) {
            return;
        }
        
        $btn.text('Processing...').prop('disabled', true);
        
        $.post(ajaxurl, {
            action: 'manage_subscription',
            operation: action,
            subscription_id: id,
            nonce: wcRecurringBillingAdmin.nonce
        })
        .done(function(response) {
            if (response.success) {
                alert('Subscription updated successfully!');
                location.reload();
            } else {
                alert('Error: ' + (response.data || 'Unknown error occurred'));
            }
        })
        .fail(function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            alert('Network error. Please try again.');
        })
        .always(function() {
            $btn.text(originalText).prop('disabled', false);
        });
    });
    
    // Create Invoice Buttons
    $('.create-invoice').on('click', function() {
        var $btn = $(this);
        var id = $btn.data('id');
        var originalText = $btn.text();
        
        if (!confirm('Create a new invoice for this subscription?')) {
            return;
        }
        
        $btn.text('Creating...').prop('disabled', true);
        
        $.post(ajaxurl, {
            action: 'create_invoice',
            subscription_id: id,
            nonce: wcRecurringBillingAdmin.nonce
        })
        .done(function(response) {
            if (response.success) {
                alert('Invoice created successfully!');
                location.reload();
            } else {
                alert('Error: ' + (response.data || 'Unknown error occurred'));
            }
        })
        .fail(function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            alert('Network error. Please try again.');
        })
        .always(function() {
            $btn.text(originalText).prop('disabled', false);
        });
    });
    
    // Auto-calculate next billing date preview
    $('#subscription_type, #amount').on('change input', function() {
        var type = $('#subscription_type').val();
        var amount = parseFloat($('#amount').val()) || 0;
        
        if (type && amount > 0) {
            var nextDate = new Date();
            if (type === 'monthly') {
                nextDate.setMonth(nextDate.getMonth() + 1);
            } else if (type === 'yearly') {
                nextDate.setFullYear(nextDate.getFullYear() + 1);
            }
            
            var preview = '<small style="color: #666;">Next billing: ' + 
                         nextDate.toLocaleDateString() + ' ($' + amount.toFixed(2) + ')</small>';
            
            $('.billing-preview').remove();
            $('#amount').after('<div class="billing-preview">' + preview + '</div>');
        } else {
            $('.billing-preview').remove();
        }
    });
    
    // Enhanced user selection with search
    if ($('#user_id').length) {
        // Add search functionality to user dropdown
        var $userSelect = $('#user_id');
        var $searchBox = $('<input type="text" placeholder="Search users..." style="width: 100%; margin-bottom: 10px; padding: 8px;">');
        $userSelect.before($searchBox);
        
        // Store original options
        var originalOptions = $userSelect.html();
        
        $searchBox.on('input', function() {
            var searchTerm = $(this).val().toLowerCase();
            
            if (searchTerm === '') {
                $userSelect.html(originalOptions);
            } else {
                var filteredOptions = '<option value="">Select User</option>';
                $userSelect.find('option').each(function() {
                    var optionText = $(this).text().toLowerCase();
                    if (optionText.includes(searchTerm) && $(this).val() !== '') {
                        filteredOptions += $(this)[0].outerHTML;
                    }
                });
                $userSelect.html(filteredOptions);
            }
        });
    }
    
    // Bulk actions for subscriptions
    if ($('.wp-list-table tbody tr').length > 1) {
        // Add bulk action controls
        var bulkControls = `
            <div class="bulk-actions" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">
                <h4>Bulk Actions</h4>
                <label><input type="checkbox" id="select-all-subscriptions"> Select All</label>
                <select id="bulk-action-select" style="margin: 0 10px;">
                    <option value="">Choose Action</option>
                    <option value="pause">Pause Selected</option>
                    <option value="activate">Activate Selected</option>
                    <option value="create-invoices">Create Invoices for Selected</option>
                </select>
                <button type="button" id="apply-bulk-action" class="button">Apply</button>
            </div>
        `;
        
        $('.wp-list-table').before(bulkControls);
        
        // Add checkboxes to each row
        $('.wp-list-table tbody tr').each(function() {
            var subscriptionId = $(this).find('.manage-subscription').data('id');
            if (subscriptionId) {
                $(this).find('td:first').prepend('<input type="checkbox" class="subscription-checkbox" value="' + subscriptionId + '"> ');
            }
        });
        
        // Select all functionality
        $('#select-all-subscriptions').on('change', function() {
            $('.subscription-checkbox').prop('checked', $(this).is(':checked'));
        });
        
        // Bulk action handler
        $('#apply-bulk-action').on('click', function() {
            var action = $('#bulk-action-select').val();
            var selectedIds = [];
            
            $('.subscription-checkbox:checked').each(function() {
                selectedIds.push($(this).val());
            });
            
            if (!action || selectedIds.length === 0) {
                alert('Please select an action and at least one subscription.');
                return;
            }
            
            if (!confirm('Apply ' + action + ' to ' + selectedIds.length + ' subscription(s)?')) {
                return;
            }
            
            // Process bulk action
            var promises = [];
            selectedIds.forEach(function(id) {
                var ajaxAction = action === 'create-invoices' ? 'create_invoice' : 'manage_subscription';
                var ajaxData = {
                    action: ajaxAction,
                    nonce: wcRecurringBillingAdmin.nonce
                };
                
                if (action === 'create-invoices') {
                    ajaxData.subscription_id = id;
                } else {
                    ajaxData.operation = action;
                    ajaxData.subscription_id = id;
                }
                
                promises.push($.post(ajaxurl, ajaxData));
            });
            
            Promise.all(promises).then(function() {
                alert('Bulk action completed successfully!');
                location.reload();
            }).catch(function() {
                alert('Some actions failed. Please check and try again.');
                location.reload();
            });
        });
    }
    
    // Real-time subscription statistics
    function updateSubscriptionStats() {
        var totalActive = $('.status-active').length;
        var totalPaused = $('.status-paused').length;
        var totalSubscriptions = totalActive + totalPaused;
        
        if (totalSubscriptions > 0) {
            var statsHtml = `
                <div class="subscription-stats" style="background: #fff; padding: 15px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                    <h4>Quick Stats</h4>
                    <div style="display: flex; gap: 20px;">
                        <div><strong>Total:</strong> ${totalSubscriptions}</div>
                        <div><strong>Active:</strong> <span style="color: #46b450;">${totalActive}</span></div>
                        <div><strong>Paused:</strong> <span style="color: #ffb900;">${totalPaused}</span></div>
                    </div>
                </div>
            `;
            
            $('.subscription-stats').remove();
            $('.wp-list-table').before(statsHtml);
        }
    }
    
    updateSubscriptionStats();
});