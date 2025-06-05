jQuery(document).ready(function($) {
    'use strict';

    // Global variables for admin functionality
    var ajaxInProgress = false;
    var bulkActionsEnabled = false;

    // Initialize admin features
    initializeAdminFeatures();

    function initializeAdminFeatures() {
        setupSubscriptionForm();
        setupSubscriptionManagement();
        setupInvoiceManagement();
        setupBulkActions();
        setupRealTimeStats();
        setupUserSearch();
        setupKeyboardShortcuts();
        setupDataExport();
    }

    // Enhanced Create Subscription Form Handler
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

    // Enhanced form validation
    function validateSubscriptionForm($form) {
        var userId = $('#user_id').val();
        var subscriptionType = $('#subscription_type').val();
        var amount = parseFloat($('#amount').val());
        var duration = $('#duration').val();

        if (!userId) {
            return { valid: false, message: 'Please select a user.' };
        }

        if (!subscriptionType) {
            return { valid: false, message: 'Please select a billing interval.' };
        }

        if (!amount || amount <= 0) {
            return { valid: false, message: 'Please enter a valid amount greater than $0.' };
        }

        if (amount > 10000) {
            return { valid: false, message: 'Amount cannot exceed $10,000.' };
        }

        if (duration && (duration < 1 || duration > 120)) {
            return { valid: false, message: 'Duration must be between 1 and 120 months.' };
        }

        return {
            valid: true,
            userId: userId,
            subscriptionType: subscriptionType,
            amount: amount,
            duration: duration
        };
    }

    // Build confirmation message
    function buildConfirmationMessage(formData) {
        var userName = $('#user_id option:selected').text();
        var durationType = formData.subscriptionType === 'monthly' ? 'month' : 'year';
        var durationText = formData.duration ? ` for ${formData.duration} months` : ' (lifetime)';
        
        return `Create ${formData.subscriptionType} subscription for ${userName}?\n\n` +
               `Amount: $${formData.amount.toFixed(2)} per ${durationType}\n` +
               `Duration: ${durationText}\n` +
               `Next billing: ${getNextBillingDate(formData.subscriptionType)}`;
    }

    // Get next billing date
    function getNextBillingDate(type) {
        var date = new Date();
        if (type === 'monthly') {
            date.setMonth(date.getMonth() + 1);
        } else {
            date.setFullYear(date.getFullYear() + 1);
        }
        return date.toLocaleDateString();
    }

    // Enhanced Subscription Management
    function setupSubscriptionManagement() {
        $('.manage-subscription').on('click', function() {
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

    // Enhanced Invoice Management
    function setupInvoiceManagement() {
        $('.create-invoice').on('click', function() {
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

    // Auto-calculate next billing date preview
    $('#subscription_type, #amount, #duration').on('change input', function() {
        updateBillingPreview();
    });

    function updateBillingPreview() {
        var type = $('#subscription_type').val();
        var amount = parseFloat($('#amount').val()) || 0;
        var duration = parseInt($('#duration').val()) || 0;
        
        $('.billing-preview').remove();
        
        if (type && amount > 0) {
            var nextDate = new Date();
            var intervalText = '';
            
            if (type === 'monthly') {
                nextDate.setMonth(nextDate.getMonth() + 1);
                intervalText = 'Monthly';
            } else if (type === 'yearly') {
                nextDate.setFullYear(nextDate.getFullYear() + 1);
                intervalText = 'Yearly';
            }
            
            var durationText = duration > 0 ? ` for ${duration} months` : ' (lifetime)';
            var totalText = duration > 0 ? ` | Total: $${(amount * (type === 'monthly' ? duration : Math.ceil(duration / 12))).toFixed(2)}` : '';
            
            var preview = `<div class="billing-preview" style="background: #e7f3ff; padding: 10px; margin: 10px 0; border-left: 4px solid #0073aa; border-radius: 4px;">
                <strong>Preview:</strong> ${intervalText} billing of $${amount.toFixed(2)}${durationText}<br>
                <small>Next billing: ${nextDate.toLocaleDateString()}${totalText}</small>
            </div>`;
            
            $('#amount').after(preview);
        }
    }

    // Enhanced user selection with search
    function setupUserSearch() {
        if ($('#user_id').length) {
            var $userSelect = $('#user_id');
            var $searchContainer = $('<div class="user-search-container" style="position: relative; margin-bottom: 10px;"></div>');
            var $searchBox = $('<input type="text" placeholder="Search users by name or email..." style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">');
            
            $userSelect.before($searchContainer);
            $searchContainer.append($searchBox);
            
            // Store original options
            var originalOptions = $userSelect.html();
            var allOptions = [];
            
            $userSelect.find('option').each(function() {
                if ($(this).val()) {
                    allOptions.push({
                        value: $(this).val(),
                        text: $(this).text().toLowerCase(),
                        html: $(this)[0].outerHTML
                    });
                }
            });
            
            $searchBox.on('input', function() {
                var searchTerm = $(this).val().toLowerCase();
                
                if (searchTerm === '') {
                    $userSelect.html(originalOptions);
                } else {
                    var filteredOptions = '<option value="">Select User</option>';
                    
                    allOptions.forEach(function(option) {
                        if (option.text.includes(searchTerm)) {
                            filteredOptions += option.html;
                        }
                    });
                    
                    $userSelect.html(filteredOptions);
                }
                
                // Update preview if user changes
                updateBillingPreview();
            });

            // Add keyboard navigation
            $searchBox.on('keydown', function(e) {
                if (e.keyCode === 40) { // Down arrow
                    e.preventDefault();
                    $userSelect.focus();
                }
            });
        }
    }

    // Enhanced bulk actions for subscriptions
    function setupBulkActions() {
        if ($('.wp-list-table tbody tr').length > 1) {
            addBulkActionControls();
            enableBulkActionsFeatures();
        }
    }

    function addBulkActionControls() {
        var bulkControls = `
            <div class="bulk-actions-container" style="background: #f9f9f9; padding: 15px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h4 style="margin: 0 0 15px 0;">Bulk Actions</h4>
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <label style="display: flex; align-items: center; gap: 5px;">
                        <input type="checkbox" id="select-all-subscriptions"> 
                        <span>Select All</span>
                    </label>
                    <select id="bulk-action-select" style="padding: 5px;">
                        <option value="">Choose Action</option>
                        <option value="pause">Pause Selected</option>
                        <option value="activate">Activate Selected</option>
                        <option value="create-invoices">Create Invoices</option>
                        <option value="export">Export Data</option>
                    </select>
                    <button type="button" id="apply-bulk-action" class="button">Apply</button>
                    <span id="bulk-selection-count" style="color: #666; font-size: 12px;"></span>
                </div>
            </div>
        `;
        
        $('.wp-list-table').before(bulkControls);
        
        // Add checkboxes to each row
        $('.wp-list-table tbody tr').each(function() {
            var subscriptionId = $(this).find('.manage-subscription').data('id');
            if (subscriptionId) {
                var $checkbox = $('<input type="checkbox" class="subscription-checkbox" value="' + subscriptionId + '" style="margin-right: 8px;">');
                $(this).find('td:first').prepend($checkbox);
            }
        });
        
        bulkActionsEnabled = true;
    }

    function enableBulkActionsFeatures() {
        // Select all functionality
        $('#select-all-subscriptions').on('change', function() {
            $('.subscription-checkbox').prop('checked', $(this).is(':checked'));
            updateBulkSelectionCount();
        });
        
        // Individual checkbox changes
        $(document).on('change', '.subscription-checkbox', function() {
            updateBulkSelectionCount();
            
            // Update select all checkbox
            var totalCheckboxes = $('.subscription-checkbox').length;
            var checkedCheckboxes = $('.subscription-checkbox:checked').length;
            
            if (checkedCheckboxes === 0) {
                $('#select-all-subscriptions').prop('indeterminate', false).prop('checked', false);
            } else if (checkedCheckboxes === totalCheckboxes) {
                $('#select-all-subscriptions').prop('indeterminate', false).prop('checked', true);
            } else {
                $('#select-all-subscriptions').prop('indeterminate', true);
            }
        });
        
        // Bulk action handler
        $('#apply-bulk-action').on('click', function() {
            if (ajaxInProgress) return false;

            var action = $('#bulk-action-select').val();
            var selectedIds = getSelectedSubscriptionIds();
            
            if (!action || selectedIds.length === 0) {
                showNotification('Please select an action and at least one subscription.', 'warning');
                return;
            }
            
            var confirmMsg = `Apply "${action}" to ${selectedIds.length} subscription(s)?\n\n`;
            
            switch (action) {
                case 'pause':
                    confirmMsg += 'This will pause billing and disable URL access for selected subscriptions.';
                    break;
                case 'activate':
                    confirmMsg += 'This will resume billing and restore URL access for selected subscriptions.';
                    break;
                case 'create-invoices':
                    confirmMsg += 'This will create new invoices and send emails to customers.';
                    break;
                case 'export':
                    confirmMsg += 'This will export subscription data to a CSV file.';
                    break;
            }
            
            if (!confirm(confirmMsg)) {
                return;
            }
            
            if (action === 'export') {
                exportSubscriptionData(selectedIds);
                return;
            }
            
            processBulkAction(action, selectedIds);
        });
    }

    function updateBulkSelectionCount() {
        var count = $('.subscription-checkbox:checked').length;
        var total = $('.subscription-checkbox').length;
        $('#bulk-selection-count').text(count > 0 ? `${count} of ${total} selected` : '');
    }

    function getSelectedSubscriptionIds() {
        var selectedIds = [];
        $('.subscription-checkbox:checked').each(function() {
            selectedIds.push($(this).val());
        });
        return selectedIds;
    }

    function processBulkAction(action, selectedIds) {
        ajaxInProgress = true;
        var $applyBtn = $('#apply-bulk-action');
        var originalText = $applyBtn.text();
        
        setButtonLoading($applyBtn, 'Processing...');
        
        var completedRequests = 0;
        var totalRequests = selectedIds.length;
        var errors = [];
        
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
            
            $.post(ajaxurl, ajaxData)
            .always(function(response) {
                completedRequests++;
                
                if (!response.success) {
                    errors.push(`ID ${id}: ${response.data || 'Unknown error'}`);
                }
                
                // Update progress
                var progress = Math.round((completedRequests / totalRequests) * 100);
                $applyBtn.text(`Processing... ${progress}%`);
                
                if (completedRequests === totalRequests) {
                    // All requests completed
                    if (errors.length === 0) {
                        showNotification(`Bulk action "${action}" completed successfully for ${totalRequests} subscription(s)!`, 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showNotification(`Bulk action completed with ${errors.length} error(s). Check console for details.`, 'warning');
                        console.error('Bulk action errors:', errors);
                        setTimeout(() => location.reload(), 3000);
                    }
                    
                    resetButtonLoading($applyBtn, originalText);
                    ajaxInProgress = false;
                }
            });
        });
    }

    // Real-time subscription statistics
    function setupRealTimeStats() {
        updateSubscriptionStats();
        
        // Update stats every 30 seconds
        setInterval(updateSubscriptionStats, 30000);
    }

    function updateSubscriptionStats() {
        var totalActive = $('.status-active').length;
        var totalPaused = $('.status-paused').length;
        var totalCancelled = $('.status-cancelled').length;
        var totalSubscriptions = totalActive + totalPaused + totalCancelled;
        
        if (totalSubscriptions > 0) {
            var statsHtml = `
                <div class="subscription-stats" style="background: #fff; padding: 15px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <h4 style="margin: 0 0 15px 0; color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px;">📊 Subscription Overview</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                        <div class="stat-item" style="text-align: center; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                            <div style="font-size: 24px; font-weight: bold; color: #333;">${totalSubscriptions}</div>
                            <div style="color: #666; font-size: 12px;">Total</div>
                        </div>
                        <div class="stat-item" style="text-align: center; padding: 10px; background: #d4edda; border-radius: 4px;">
                            <div style="font-size: 24px; font-weight: bold; color: #155724;">${totalActive}</div>
                            <div style="color: #155724; font-size: 12px;">Active</div>
                        </div>
                        <div class="stat-item" style="text-align: center; padding: 10px; background: #fff3cd; border-radius: 4px;">
                            <div style="font-size: 24px; font-weight: bold; color: #856404;">${totalPaused}</div>
                            <div style="color: #856404; font-size: 12px;">Paused</div>
                        </div>
                        ${totalCancelled > 0 ? `
                        <div class="stat-item" style="text-align: center; padding: 10px; background: #f8d7da; border-radius: 4px;">
                            <div style="font-size: 24px; font-weight: bold; color: #721c24;">${totalCancelled}</div>
                            <div style="color: #721c24; font-size: 12px;">Cancelled</div>
                        </div>
                        ` : ''}
                    </div>
                </div>
            `;
            
            $('.subscription-stats').remove();
            $('.wp-list-table').before(statsHtml);
        }
    }

    // Keyboard shortcuts
    function setupKeyboardShortcuts() {
        $(document).on('keydown', function(e) {
            // Ctrl/Cmd + S to save form
            if ((e.ctrlKey || e.metaKey) && e.keyCode === 83) {
                e.preventDefault();
                if ($('#create-subscription-form').is(':visible')) {
                    $('#create-subscription-form').submit();
                }
            }
            
            // Ctrl/Cmd + A to select all checkboxes
            if ((e.ctrlKey || e.metaKey) && e.keyCode === 65 && bulkActionsEnabled) {
                if (!$(e.target).is('input[type="text"], textarea')) {
                    e.preventDefault();
                    $('#select-all-subscriptions').prop('checked', true).trigger('change');
                }
            }
            
            // Escape to clear selections
            if (e.keyCode === 27) {
                if (bulkActionsEnabled) {
                    $('.subscription-checkbox').prop('checked', false);
                    $('#select-all-subscriptions').prop('checked', false);
                    updateBulkSelectionCount();
                }
            }
        });
    }

    // Data export functionality
    function setupDataExport() {
        // Add export button to admin
        if ($('.wrap h1').length) {
            var $exportBtn = $('<a href="#" class="page-title-action" id="export-all-data">Export All Data</a>');
            $('.wrap h1').after($exportBtn);
            
            $exportBtn.on('click', function(e) {
                e.preventDefault();
                exportAllSubscriptionData();
            });
        }
    }

    function exportSubscriptionData(selectedIds) {
        var csvContent = "data:text/csv;charset=utf-8,";
        csvContent += "ID,User Name,Email,Subscription Type,Amount,Status,Start Date,Next Billing,Expiry Date\n";
        
        selectedIds.forEach(function(id) {
            var $row = $('.subscription-checkbox[value="' + id + '"]').closest('tr');
            var rowData = [
                id,
                $row.find('td:nth-child(2)').text().trim().split('\n')[0],
                $row.find('td:nth-child(2) small').text().trim(),
                $row.find('td:nth-child(3)').text().trim(),
                $row.find('td:nth-child(4)').text().trim(),
                $row.find('td:nth-child(5)').text().trim(),
                new Date().toLocaleDateString(),
                $row.find('td:nth-child(6)').text().trim(),
                $row.find('td:nth-child(7)').text().trim()
            ];
            
            csvContent += rowData.map(field => `"${field}"`).join(',') + '\n';
        });
        
        downloadCSV(csvContent, 'selected-subscriptions-' + new Date().toISOString().split('T')[0] + '.csv');
    }

    function exportAllSubscriptionData() {
        if (!confirm('Export all subscription data to CSV file?')) {
            return;
        }
        
        var csvContent = "data:text/csv;charset=utf-8,";
        csvContent += "ID,User Name,Email,Subscription Type,Amount,Status,Start Date,Next Billing,Expiry Date\n";
        
        $('.wp-list-table tbody tr').each(function() {
            var $row = $(this);
            var id = $row.find('.subscription-checkbox').val();
            
            if (id) {
                var rowData = [
                    id,
                    $row.find('td:nth-child(2)').text().trim().split('\n')[0],
                    $row.find('td:nth-child(2) small').text().trim(),
                    $row.find('td:nth-child(3)').text().trim(),
                    $row.find('td:nth-child(4)').text().trim(),
                    $row.find('td:nth-child(5)').text().trim(),
                    new Date().toLocaleDateString(),
                    $row.find('td:nth-child(6)').text().trim(),
                    $row.find('td:nth-child(7)').text().trim()
                ];
                
                csvContent += rowData.map(field => `"${field}"`).join(',') + '\n';
            }
        });
        
        downloadCSV(csvContent, 'all-subscriptions-' + new Date().toISOString().split('T')[0] + '.csv');
    }

    function downloadCSV(csvContent, filename) {
        var encodedUri = encodeURI(csvContent);
        var link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", filename);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        showNotification('Data exported successfully!', 'success');
    }

    // Utility functions
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
        // This function would load invoice data via AJAX
        // Implementation depends on your specific needs
        console.log('Loading invoices data...');
    }

    // Initialize tooltips for better UX
    function initTooltips() {
        $('[title]').each(function() {
            var $element = $(this);
            var title = $element.attr('title');
            
            $element.removeAttr('title').on('mouseenter', function() {
                var $tooltip = $('<div class="admin-tooltip" style="position: absolute; background: #333; color: white; padding: 8px 12px; border-radius: 4px; font-size: 12px; z-index: 9999; max-width: 200px; word-wrap: break-word;">' + title + '</div>');
                $('body').append($tooltip);
                
                var offset = $element.offset();
                $tooltip.css({
                    top: offset.top - $tooltip.outerHeight() - 8,
                    left: offset.left + ($element.outerWidth() / 2) - ($tooltip.outerWidth() / 2)
                });
            }).on('mouseleave', function() {
                $('.admin-tooltip').remove();
            });
        });
    }

    // Initialize tooltips
    initTooltips();

    // Auto-save form data to prevent data loss
    var formDataBackup = {};
    
    $('#create-subscription-form input, #create-subscription-form select').on('change input', function() {
        var $field = $(this);
        formDataBackup[$field.attr('name') || $field.attr('id')] = $field.val();
        localStorage.setItem('wc_recurring_billing_form_backup', JSON.stringify(formDataBackup));
    });

    // Restore form data on page load
    try {
        var savedData = JSON.parse(localStorage.getItem('wc_recurring_billing_form_backup') || '{}');
        Object.keys(savedData).forEach(function(key) {
            var $field = $('[name="' + key + '"], #' + key);
            if ($field.length && savedData[key]) {
                $field.val(savedData[key]);
            }
        });
    } catch (e) {
        console.log('Could not restore form data:', e);
    }

    // Clear backup on successful form submission
    $('#create-subscription-form').on('submit', function() {
        localStorage.removeItem('wc_recurring_billing_form_backup');
    });

    console.log('WC Recurring Billing Admin: All features initialized successfully');
});
