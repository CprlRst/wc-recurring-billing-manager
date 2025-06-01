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
    
    // URL input validation on typing
    $('#new_url').on('input', function() {
        var $input = $(this);
        var url = $input.val().trim();
        var $submitBtn = $('#url-submission-form button[type="submit"]');
        
        if (url && !isValidUrl(url)) {
            $input.css('border-color', '#dc3232');
            $submitBtn.prop('disabled', true);
        } else {
            $input.css('border-color', '#ddd');
            $submitBtn.prop('disabled', false);
        }
    });
    
    // URL input focus/blur effects
    $('#new_url').on('focus', function() {
        $(this).parent().addClass('focused');
    }).on('blur', function() {
        $(this).parent().removeClass('focused');
    });
    
    // Helper function to validate URL
    function isValidUrl(string) {
        try {
            var url = new URL(string);
            return url.protocol === 'http:' || url.protocol === 'https:';
        } catch (_) {
            return false;
        }
    }
    
    // Helper function to show messages
    function showMessage(message, type) {
        var $resultDiv = $('#url-submission-result');
        var cssClass = type === 'success' ? 'woocommerce-message' : 'woocommerce-error';
        
        var $messageDiv = $('<div class="' + cssClass + '">' + message + '</div>');
        $resultDiv.html($messageDiv);
        
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
        
        if (!confirm('Are you sure you want to add "' + url + '" to your whitelist?')) {
            e.preventDefault();
            return false;
        }
    });
    
    // Auto-format URL input (add https:// if missing protocol)
    $('#new_url').on('blur', function() {
        var $input = $(this);
        var url = $input.val().trim();
        
        if (url && !url.match(/^https?:\/\//)) {
            $input.val('https://' + url);
        }
    });
    
    // Character counter for URL input
    $('#new_url').after('<small class="url-char-counter" style="color: #666; font-size: 12px;"></small>');
    
    $('#new_url').on('input', function() {
        var length = $(this).val().length;
        var maxLength = 255; // Typical URL length limit
        var $counter = $('.url-char-counter');
        
        $counter.text(length + '/' + maxLength + ' characters');
        
        if (length > maxLength * 0.9) {
            $counter.css('color', '#dc3232');
        } else {
            $counter.css('color', '#666');
        }
    });
    
    // Copy current URLs functionality
    if ($('.current-urls .urls-display').length) {
        var $copyBtn = $('<button type="button" class="copy-urls-btn" style="margin-top: 10px; padding: 5px 10px; background: #0073aa; color: white; border: none; border-radius: 3px; font-size: 12px; cursor: pointer;">Copy URLs</button>');
        $('.current-urls').append($copyBtn);
        
        $copyBtn.on('click', function() {
            var urlText = $('.urls-display').text();
            
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(urlText).then(function() {
                    $copyBtn.text('Copied!').css('background', '#46b450');
                    setTimeout(function() {
                        $copyBtn.text('Copy URLs').css('background', '#0073aa');
                    }, 2000);
                });
            } else {
                // Fallback for older browsers
                var textArea = document.createElement('textarea');
                textArea.value = urlText;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                
                $copyBtn.text('Copied!').css('background', '#46b450');
                setTimeout(function() {
                    $copyBtn.text('Copy URLs').css('background', '#0073aa');
                }, 2000);
            }
        });
    }
});