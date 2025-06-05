jQuery(document).ready(function($) {
    'use strict';

    // URL Submission Form Handler
    $('#url-submission-form').on('submit', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $submitBtn = $form.find('button[type="submit"]');
        var $resultDiv = $('#url-submission-result');
        var $urlInput = $('#new_url');
        var newUrl = $urlInput.val().trim();
        
        // Validate URL
        if (!newUrl || !isValidUrl(newUrl)) {
            showMessage('Please enter a valid URL starting with http:// or https://', 'error');
            return;
        }
        
        // Disable submit button and show loading
        $submitBtn.prop('disabled', true);
        $submitBtn.html('<span class="loading-spinner"></span>Submitting...');
        
        // Clear previous messages
        $resultDiv.empty();
        
        // Submit via AJAX
        $.post(wcRecurringBilling.ajax_url, {
            action: 'submit_url',
            new_url: newUrl,
            nonce: wcRecurringBilling.nonce
        })
        .done(function(response) {
            if (response.success) {
                showMessage(response.data, 'success');
                $urlInput.val(''); // Clear the input
                
                // Reload the page after 2 seconds to show updated URLs
                setTimeout(function() {
                    location.reload();
                }, 2000);
            } else {
                showMessage(response.data || 'An error occurred. Please try again.', 'error');
            }
        })
        .fail(function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            showMessage('Network error. Please check your connection and try again.', 'error');
        })
        .always(function() {
            // Re-enable submit button
            $submitBtn.prop('disabled', false);
            $submitBtn.html('Submit URL');
        });
    });
    
    // Enhanced URL input validation on typing
    $('#new_url').on('input keyup paste blur', function() {
        var $input = $(this);
        var url = $input.val().trim();
        var $submitBtn = $('#url-submission-form button[type="submit"]');
        var $validationMsg = $('#url-validation-message');
        
        if (!url) {
            $input.css('border-color', '');
            $submitBtn.prop('disabled', true);
            $validationMsg.html('');
            return;
        }
        
        var validation = validateURL(url);
        
        if (validation.valid) {
            $input.css('border-color', '#28a745');
            $submitBtn.prop('disabled', false);
            $validationMsg.html('<span style="color: #28a745;">' + validation.message + '</span>');
        } else {
            $input.css('border-color', '#dc3545');
            $submitBtn.prop('disabled', true);
            $validationMsg.html('<span style="color: #dc3545;">' + validation.message + '</span>');
        }
    });
    
    // Enhanced URL validation function
    function validateURL(url) {
        if (!url) {
            return { valid: false, message: 'Please enter a URL' };
        }
        
        // Check for protocol
        var httpPattern = /^https?:\/\//i;
        if (!httpPattern.test(url)) {
            return { valid: false, message: 'URL must start with http:// or https://' };
        }
        
        // Basic URL pattern validation
        var urlPattern = /^https?:\/\/([\w\-]+(\.[\w\-]+)*)(:[0-9]+)?(\/[^\s]*)?$/i;
        if (!urlPattern.test(url)) {
            return { valid: false, message: 'Please enter a valid URL format' };
        }
        
        // Additional validation using URL constructor
        try {
            var urlObj = new URL(url);
            
            // Check for valid hostname
            if (!urlObj.hostname || urlObj.hostname.length < 3) {
                return { valid: false, message: 'Invalid domain name' };
            }
            
            // Check for at least one dot in hostname (except localhost)
            if (urlObj.hostname !== 'localhost' && !urlObj.hostname.includes('.')) {
                return { valid: false, message: 'Domain must include a valid extension' };
            }
            
            return { valid: true, message: 'Valid URL ✓' };
        } catch (e) {
            return { valid: false, message: 'Invalid URL format' };
        }
    }
    
    // Legacy function for backward compatibility
    function isValidUrl(string) {
        return validateURL(string).valid;
    }
    
    // URL input focus/blur effects
    $('#new_url').on('focus', function() {
        $(this).parent().addClass('focused');
    }).on('blur', function() {
        $(this).parent().removeClass('focused');
    });
    
    // Helper function to show messages with improved styling
    function showMessage(message, type) {
        var $resultDiv = $('#url-submission-result');
        var cssClass = type === 'success' ? 'woocommerce-message' : 'woocommerce-error';
        var icon = type === 'success' ? '✅ ' : '❌ ';
        
        var $messageDiv = $('<div class="' + cssClass + '">' + icon + message + '</div>');
        $resultDiv.html($messageDiv);
        
        // Add animation
        $messageDiv.hide().fadeIn(300);
        
        // Scroll to message
        $('html, body').animate({
            scrollTop: $resultDiv.offset().top - 50
        }, 500);
        
        // Auto-hide success messages after 5 seconds
        if (type === 'success') {
            setTimeout(function() {
                $messageDiv.fadeOut(500);
            }, 5000);
        }
    }
    
    // Add confirmation for URL submission
    $('#url-submission-form').on('submit', function(e) {
        var url = $('#new_url').val().trim();
        var isUpdate = $('#new_url').val() !== '' && $('.current-url').length > 0;
        
        var confirmMessage = isUpdate 
            ? 'Are you sure you want to update your URL to "' + url + '"? This will replace your current whitelisted URL.'
            : 'Are you sure you want to add "' + url + '" to your whitelist?';
        
        if (!confirm(confirmMessage)) {
            e.preventDefault();
            return false;
        }
    });
    
    // Auto-format URL input (add https:// if missing protocol)
    $('#new_url').on('blur', function() {
        var $input = $(this);
        var url = $input.val().trim();
        
        if (url && !url.match(/^https?:\/\//i)) {
            // Only add https:// if it looks like a valid domain
            if (url.includes('.') && !url.includes(' ')) {
                $input.val('https://' + url);
                $input.trigger('input'); // Trigger validation
            }
        }
    });
    
    // Character counter for URL input
    if ($('#new_url').length && !$('.url-char-counter').length) {
        $('#new_url').after('<small class="url-char-counter" style="color: #666; font-size: 12px; display: block; margin-top: 5px;"></small>');
    }
    
    $('#new_url').on('input', function() {
        var length = $(this).val().length;
        var maxLength = 255; // Typical URL length limit
        var $counter = $('.url-char-counter');
        
        if ($counter.length) {
            $counter.text(length + '/' + maxLength + ' characters');
            
            if (length > maxLength * 0.9) {
                $counter.css('color', '#dc3232');
            } else if (length > maxLength * 0.7) {
                $counter.css('color', '#ffb900');
            } else {
                $counter.css('color', '#666');
            }
        }
    });
    
    // Copy current URLs functionality with improved UX
    if ($('.current-urls .urls-display').length && !$('.copy-urls-btn').length) {
        var $copyBtn = $('<button type="button" class="copy-urls-btn" style="margin-top: 10px; padding: 5px 10px; background: #0073aa; color: white; border: none; border-radius: 3px; font-size: 12px; cursor: pointer; transition: all 0.3s ease;">📋 Copy URLs</button>');
        $('.current-urls').append($copyBtn);
        
        $copyBtn.on('click', function() {
            var urlText = $('.urls-display').text();
            var $btn = $(this);
            
            // Modern clipboard API with fallback
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(urlText).then(function() {
                    showCopySuccess($btn);
                }).catch(function() {
                    fallbackCopyToClipboard(urlText, $btn);
                });
            } else {
                fallbackCopyToClipboard(urlText, $btn);
            }
        });
        
        function showCopySuccess($btn) {
            $btn.html('✅ Copied!').css('background', '#46b450');
            setTimeout(function() {
                $btn.html('📋 Copy URLs').css('background', '#0073aa');
            }, 2000);
        }
        
        function fallbackCopyToClipboard(text, $btn) {
            var textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            textArea.style.top = '-999999px';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            
            try {
                document.execCommand('copy');
                showCopySuccess($btn);
            } catch (err) {
                console.error('Fallback copy failed:', err);
                $btn.html('❌ Copy Failed').css('background', '#dc3232');
                setTimeout(function() {
                    $btn.html('📋 Copy URLs').css('background', '#0073aa');
                }, 2000);
            }
            
            document.body.removeChild(textArea);
        }
    }
    
    // Enhanced form validation with real-time feedback
    $('#new_url').on('keydown', function(e) {
        // Allow common navigation keys
        var allowedKeys = [8, 9, 27, 46, 110, 190]; // backspace, tab, escape, delete, decimal point
        
        if (allowedKeys.indexOf(e.keyCode) !== -1 ||
            // Allow Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
            (e.keyCode === 65 && e.ctrlKey === true) ||
            (e.keyCode === 67 && e.ctrlKey === true) ||
            (e.keyCode === 86 && e.ctrlKey === true) ||
            (e.keyCode === 88 && e.ctrlKey === true) ||
            // Allow home, end, left, right
            (e.keyCode >= 35 && e.keyCode <= 39)) {
            return;
        }
        
        // Prevent spaces in URL
        if (e.keyCode === 32) {
            e.preventDefault();
            return false;
        }
    });
    
    // URL input paste handler to clean pasted URLs
    $('#new_url').on('paste', function(e) {
        var $input = $(this);
        
        setTimeout(function() {
            var pastedText = $input.val();
            
            // Clean up common issues with pasted URLs
            pastedText = pastedText.trim()
                .replace(/\s+/g, '') // Remove all spaces
                .replace(/^.*?(https?:\/\/)/, '$1') // Remove text before http/https
                .split(/[\s\n\r]/)[0]; // Take only the first part if multiple URLs
            
            $input.val(pastedText);
            $input.trigger('input');
        }, 10);
    });
    
    // Add loading states and better error handling
    function addLoadingState($element, text) {
        $element.data('original-text', $element.html());
        $element.html('<span class="loading-spinner"></span>' + text).prop('disabled', true);
    }
    
    function removeLoadingState($element) {
        $element.html($element.data('original-text') || 'Submit').prop('disabled', false);
    }
    
    // Keyboard shortcuts
    $(document).on('keydown', function(e) {
        // Ctrl/Cmd + Enter to submit form
        if ((e.ctrlKey || e.metaKey) && e.keyCode === 13) {
            if ($('#url-submission-form').is(':visible')) {
                $('#url-submission-form').submit();
            }
        }
    });
    
    // Add tooltips for better UX
    if ($('#new_url').length) {
        $('#new_url').attr('title', 'Enter a valid URL starting with http:// or https://\nExample: https://yourdomain.com');
    }
    
    // Monitor form changes and warn before leaving page
    var formChanged = false;
    $('#url-submission-form input, #url-submission-form select, #url-submission-form textarea').on('change input', function() {
        formChanged = true;
    });
    
    $('#url-submission-form').on('submit', function() {
        formChanged = false; // Reset flag on submit
    });
    
    $(window).on('beforeunload', function() {
        if (formChanged) {
            return 'You have unsaved changes. Are you sure you want to leave?';
        }
    });
    
    // Initialize any existing URL for validation
    if ($('#new_url').val()) {
        $('#new_url').trigger('input');
    }
    
    // Add focus to URL input when page loads
    setTimeout(function() {
        if ($('#new_url').is(':visible') && !$('#new_url').is(':focus')) {
            $('#new_url').focus();
        }
    }, 500);
    
    // Progress indicator for form submission
    function updateProgress(step, total) {
        var percent = Math.round((step / total) * 100);
        var $progressBar = $('.submission-progress');
        
        if ($progressBar.length === 0) {
            $progressBar = $('<div class="submission-progress" style="width: 100%; height: 4px; background: #f0f0f0; border-radius: 2px; margin: 10px 0; overflow: hidden;"><div class="progress-fill" style="height: 100%; background: #0073aa; width: 0%; transition: width 0.3s ease;"></div></div>');
            $('#url-submission-form').prepend($progressBar);
        }
        
        $progressBar.find('.progress-fill').css('width', percent + '%');
        
        if (percent >= 100) {
            setTimeout(function() {
                $progressBar.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 500);
        }
    }
    
    // URL history tracking (for users who want to see previous URLs)
    function addToUrlHistory(url) {
        var history = JSON.parse(localStorage.getItem('url_submission_history') || '[]');
        
        // Remove if already exists
        history = history.filter(function(item) {
            return item.url !== url;
        });
        
        // Add to beginning
        history.unshift({
            url: url,
            timestamp: new Date().toISOString()
        });
        
        // Keep only last 5
        history = history.slice(0, 5);
        
        localStorage.setItem('url_submission_history', JSON.stringify(history));
    }
    
    // Add URL suggestions based on history
    function showUrlSuggestions() {
        var history = JSON.parse(localStorage.getItem('url_submission_history') || '[]');
        
        if (history.length > 0 && !$('.url-suggestions').length) {
            var $suggestions = $('<div class="url-suggestions" style="margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 4px; border: 1px solid #dee2e6;"><strong>Previous URLs:</strong></div>');
            
            history.forEach(function(item) {
                var $suggestion = $('<button type="button" class="url-suggestion" style="display: block; width: 100%; text-align: left; background: none; border: none; padding: 5px; margin: 2px 0; cursor: pointer; border-radius: 3px; font-family: monospace; font-size: 12px;">' + item.url + '</button>');
                
                $suggestion.on('click', function() {
                    $('#new_url').val(item.url).trigger('input');
                    $('.url-suggestions').hide();
                });
                
                $suggestion.on('mouseenter', function() {
                    $(this).css('background', '#e9ecef');
                }).on('mouseleave', function() {
                    $(this).css('background', 'none');
                });
                
                $suggestions.append($suggestion);
            });
            
            $('#new_url').after($suggestions);
            
            // Hide suggestions when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('#new_url, .url-suggestions').length) {
                    $('.url-suggestions').hide();
                }
            });
            
            $('#new_url').on('focus', function() {
                $('.url-suggestions').show();
            });
        }
    }
    
    // Initialize URL suggestions if we're on the URL management page
    if ($('#new_url').length) {
        showUrlSuggestions();
    }
});
