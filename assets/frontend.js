jQuery(document).ready(function($) {
    'use strict';

    const WC_RBM_Frontend = {
        
        // Configuration
        config: {
            ajaxUrl: wcRecurringBilling.ajax_url,
            nonce: wcRecurringBilling.nonce,
            subscriptionId: wcRecurringBilling.subscription_id || null,
            userId: wcRecurringBilling.user_id || null
        },

        // Initialize
        init: function() {
            this.bindEvents();
            this.initializeValidation();
            this.setupUrlHistory();
            this.initializeUI();
        },

        // Bind all events
        bindEvents: function() {
            // URL submission form
            $('#url-submission-form').on('submit', this.handleUrlSubmission.bind(this));
            
            // URL input validation
            $('#new_url').on('input keyup paste blur', this.validateUrlInput.bind(this));
            
            // URL input focus effects
            $('#new_url').on('focus', function() {
                $(this).parent().addClass('focused');
            }).on('blur', function() {
                $(this).parent().removeClass('focused');
            });
            
            // Auto-format URL on blur
            $('#new_url').on('blur', this.autoFormatUrl.bind(this));
            
            // Copy URL functionality
            $(document).on('click', '.copy-urls-btn', this.copyUrls.bind(this));
            
            // Keyboard shortcuts
            $(document).on('keydown', this.handleKeyboardShortcuts.bind(this));
        },

        // Handle URL submission
        handleUrlSubmission: function(e) {
            e.preventDefault();
            
            const $form = $(e.target);
            const $submitBtn = $form.find('button[type="submit"]');
            const $resultDiv = $('#url-submission-result');
            const $urlInput = $('#new_url');
            const newUrl = $urlInput.val().trim();
            
            // Validate URL
            if (!newUrl || !this.isValidUrl(newUrl)) {
                this.showMessage('Please enter a valid URL starting with http:// or https://', 'error');
                return;
            }
            
            // Check if we have a subscription ID
            if (!this.config.subscriptionId) {
                this.showMessage('No active subscription found. Please refresh the page.', 'error');
                return;
            }
            
            // Confirm submission
            const isUpdate = $('.current-url').length > 0;
            const confirmMsg = isUpdate 
                ? `Update your URL to "${newUrl}"? This will replace your current whitelisted URL.`
                : `Add "${newUrl}" to your whitelist?`;
            
            if (!confirm(confirmMsg)) {
                return;
            }
            
            // Disable submit button and show loading
            this.setButtonLoading($submitBtn, 'Submitting...');
            
            // Clear previous messages
            $resultDiv.empty();
            
            // Submit via AJAX
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'wc_rbm_submit_url',
                    new_url: newUrl,
                    subscription_id: this.config.subscriptionId,
                    nonce: this.config.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showMessage(response.data, 'success');
                        $urlInput.val('');
                        
                        // Add to history
                        this.addToUrlHistory(newUrl);
                        
                        // Reload after 2 seconds
                        setTimeout(() => {
                            location.reload();
                        }, 2000);
                    } else {
                        this.showMessage(response.data || 'An error occurred. Please try again.', 'error');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('AJAX Error:', status, error);
                    this.showMessage('Network error. Please check your connection and try again.', 'error');
                },
                complete: () => {
                    this.resetButtonLoading($submitBtn, isUpdate ? 'Update URL' : 'Submit URL');
                }
            });
        },

        // Enhanced URL validation
        validateUrlInput: function(e) {
            const $input = $(e.target);
            const url = $input.val().trim();
            const $submitBtn = $('#url-submission-form button[type="submit"]');
            const $validationMsg = $('#url-validation-message');
            
            if (!url) {
                $input.removeClass('valid invalid');
                $submitBtn.prop('disabled', true);
                $validationMsg.html('');
                return;
            }
            
            const validation = this.validateURL(url);
            
            if (validation.valid) {
                $input.removeClass('invalid').addClass('valid');
                $submitBtn.prop('disabled', false);
                $validationMsg.html(`<span class="valid-message">${validation.message}</span>`);
            } else {
                $input.removeClass('valid').addClass('invalid');
                $submitBtn.prop('disabled', true);
                $validationMsg.html(`<span class="invalid-message">${validation.message}</span>`);
            }
            
            // Update character counter
            this.updateCharCounter($input);
        },

        // Comprehensive URL validation
        validateURL: function(url) {
            if (!url) {
                return { valid: false, message: 'Please enter a URL' };
            }
            
            // Check for protocol
            if (!/^https?:\/\//i.test(url)) {
                return { valid: false, message: 'URL must start with http:// or https://' };
            }
            
            // Validate URL format
            try {
                const urlObj = new URL(url);
                
                // Check hostname
                if (!urlObj.hostname || urlObj.hostname.length < 3) {
                    return { valid: false, message: 'Invalid domain name' };
                }
                
                // Check for valid TLD (except localhost)
                if (urlObj.hostname !== 'localhost' && !urlObj.hostname.includes('.')) {
                    return { valid: false, message: 'Domain must include a valid extension' };
                }
                
                // Check for common typos
                if (urlObj.hostname.includes('..') || urlObj.hostname.endsWith('.')) {
                    return { valid: false, message: 'Invalid domain format' };
                }
                
                return { valid: true, message: '✓ Valid URL' };
            } catch (e) {
                return { valid: false, message: 'Invalid URL format' };
            }
        },

        // Check if URL is valid (simplified)
        isValidUrl: function(string) {
            return this.validateURL(string).valid;
        },

        // Auto-format URL
        autoFormatUrl: function(e) {
            const $input = $(e.target);
            let url = $input.val().trim();
            
            if (url && !url.match(/^https?:\/\//i)) {
                // Only add https:// if it looks like a domain
                if (url.includes('.') && !url.includes(' ') && url.length > 3) {
                    $input.val('https://' + url);
                    $input.trigger('input');
                }
            }
        },

        // Update character counter
        updateCharCounter: function($input) {
            const length = $input.val().length;
            const maxLength = 255;
            let $counter = $('.url-char-counter');
            
            if (!$counter.length) {
                $counter = $('<small class="url-char-counter"></small>');
                $input.after($counter);
            }
            
            $counter.text(`${length}/${maxLength} characters`);
            
            if (length > maxLength * 0.9) {
                $counter.removeClass('warning').addClass('danger');
            } else if (length > maxLength * 0.7) {
                $counter.removeClass('danger').addClass('warning');
            } else {
                $counter.removeClass('danger warning');
            }
        },

        // Copy URLs functionality
        copyUrls: function(e) {
            e.preventDefault();
            const $btn = $(e.target);
            const urlText = $('.urls-display').text();
            
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(urlText)
                    .then(() => this.showCopySuccess($btn))
                    .catch(() => this.fallbackCopyToClipboard(urlText, $btn));
            } else {
                this.fallbackCopyToClipboard(urlText, $btn);
            }
        },

        // Show copy success
        showCopySuccess: function($btn) {
            const originalText = $btn.html();
            $btn.html('✅ Copied!').addClass('success');
            setTimeout(() => {
                $btn.html(originalText).removeClass('success');
            }, 2000);
        },

        // Fallback copy method
        fallbackCopyToClipboard: function(text, $btn) {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            document.body.appendChild(textArea);
            textArea.select();
            
            try {
                document.execCommand('copy');
                this.showCopySuccess($btn);
            } catch (err) {
                console.error('Copy failed:', err);
                $btn.html('❌ Copy Failed').addClass('error');
                setTimeout(() => {
                    $btn.html('📋 Copy URLs').removeClass('error');
                }, 2000);
            }
            
            document.body.removeChild(textArea);
        },

        // Handle keyboard shortcuts
        handleKeyboardShortcuts: function(e) {
            // Ctrl/Cmd + Enter to submit
            if ((e.ctrlKey || e.metaKey) && e.keyCode === 13) {
                if ($('#url-submission-form').is(':visible')) {
                    $('#url-submission-form').submit();
                }
            }
        },

        // Show message
        showMessage: function(message, type) {
            const $resultDiv = $('#url-submission-result');
            const cssClass = type === 'success' ? 'woocommerce-message' : 'woocommerce-error';
            const icon = type === 'success' ? '✅' : '❌';
            
            const $messageDiv = $(`<div class="${cssClass} ${type}-message">${icon} ${message}</div>`);
            $resultDiv.html($messageDiv);
            
            // Animation
            $messageDiv.hide().fadeIn(300);
            
            // Scroll to message
            $('html, body').animate({
                scrollTop: $resultDiv.offset().top - 100
            }, 500);
            
            // Auto-hide success messages
            if (type === 'success') {
                setTimeout(() => {
                    $messageDiv.fadeOut(500);
                }, 5000);
            }
        },

        // Set button loading state
        setButtonLoading: function($button, text) {
            $button.data('original-text', $button.html());
            $button.html(`<span class="loading-spinner"></span>${text}`).prop('disabled', true);
        },

        // Reset button loading state
        resetButtonLoading: function($button, text) {
            $button.html(text || $button.data('original-text')).prop('disabled', false);
        },

        // URL history management
        setupUrlHistory: function() {
            this.showUrlSuggestions();
            
            // Monitor form changes
            let formChanged = false;
            $('#url-submission-form input').on('change input', function() {
                formChanged = true;
            });
            
            $('#url-submission-form').on('submit', function() {
                formChanged = false;
            });
            
            $(window).on('beforeunload', function() {
                if (formChanged) {
                    return 'You have unsaved changes. Are you sure you want to leave?';
                }
            });
        },

        // Add to URL history
        addToUrlHistory: function(url) {
            let history = JSON.parse(localStorage.getItem('wc_rbm_url_history') || '[]');
            
            // Remove duplicates
            history = history.filter(item => item.url !== url);
            
            // Add new entry
            history.unshift({
                url: url,
                timestamp: new Date().toISOString()
            });
            
            // Keep only last 5
            history = history.slice(0, 5);
            
            localStorage.setItem('wc_rbm_url_history', JSON.stringify(history));
        },

        // Show URL suggestions
        showUrlSuggestions: function() {
            const history = JSON.parse(localStorage.getItem('wc_rbm_url_history') || '[]');
            
            if (history.length > 0 && !$('.url-suggestions').length) {
                const $suggestions = $('<div class="url-suggestions"><strong>Recent URLs:</strong></div>');
                
                history.forEach(item => {
                    const $suggestion = $(`<button type="button" class="url-suggestion">${item.url}</button>`);
                    
                    $suggestion.on('click', () => {
                        $('#new_url').val(item.url).trigger('input');
                        $('.url-suggestions').hide();
                    });
                    
                    $suggestions.append($suggestion);
                });
                
                $('#new_url').after($suggestions);
                
                // Show/hide suggestions
                $('#new_url').on('focus', () => $('.url-suggestions').show());
                $(document).on('click', e => {
                    if (!$(e.target).closest('#new_url, .url-suggestions').length) {
                        $('.url-suggestions').hide();
                    }
                });
            }
        },

        // Initialize UI enhancements
        initializeUI: function() {
            // Add tooltips
            if ($('#new_url').length) {
                $('#new_url').attr('title', 'Enter a valid URL starting with http:// or https://');
            }
            
            // Initialize validation on existing value
            if ($('#new_url').val()) {
                $('#new_url').trigger('input');
            }
            
            // Auto-focus URL input
            setTimeout(() => {
                if ($('#new_url').is(':visible') && !$('#new_url').is(':focus')) {
                    $('#new_url').focus();
                }
            }, 500);
            
            // Add copy button if needed
            if ($('.current-urls .urls-display').length && !$('.copy-urls-btn').length) {
                const $copyBtn = $('<button type="button" class="copy-urls-btn">📋 Copy URLs</button>');
                $('.current-urls').append($copyBtn);
            }
        },

        // Initialize validation messages
        initializeValidation: function() {
            if (!$('#url-validation-message').length && $('#new_url').length) {
                $('#new_url').after('<div id="url-validation-message"></div>');
            }
        }
    };

    // Initialize the frontend handler
    WC_RBM_Frontend.init();
});