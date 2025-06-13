<?php
/**
 * URL Manager Handler Class
 */
class WC_RBM_URL_Manager {
    
    /**
     * Database tables
     */
    private $tables;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->tables = WC_RBM()->database->get_table_names();
    }
    
    /**
     * Submit URL for user
     */
    public function submit_url($user_id, $subscription_id, $new_url) {
        global $wpdb;
        
        try {
            // Validate URL format
            if (!filter_var($new_url, FILTER_VALIDATE_URL)) {
                throw new Exception(__('Invalid URL format.', 'wc-rbm'));
            }
            
            // Check if URL already exists for another user
            $existing_other = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->tables['user_urls']} 
                 WHERE url = %s AND user_id != %d AND status = 'active'",
                $new_url, $user_id
            ));
            
            if ($existing_other) {
                throw new Exception(__('This URL is already registered to another user.', 'wc-rbm'));
            }
            
            // Check if user already has a URL for this subscription
            $existing_url = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->tables['user_urls']} 
                 WHERE user_id = %d AND subscription_id = %d AND status = 'active'",
                $user_id, $subscription_id
            ));
            
            $old_url = $existing_url ? $existing_url->url : null;
            
            if ($existing_url) {
                // Update existing URL
                $result = $wpdb->update(
                    $this->tables['user_urls'],
                    array(
                        'url' => $new_url,
                        'updated_at' => current_time('mysql')
                    ),
                    array('id' => $existing_url->id),
                    array('%s', '%s'),
                    array('%d')
                );
                
                if ($result === false) {
                    throw new Exception(__('Failed to update URL.', 'wc-rbm'));
                }
                
                // Update Bricks whitelist
                $this->update_bricks_whitelist_with_change($old_url, $new_url);
                
                return array(
                    'success' => true,
                    'message' => __('URL has been successfully updated in the whitelist.', 'wc-rbm')
                );
                
            } else {
                // Insert new URL
                $result = $wpdb->insert(
                    $this->tables['user_urls'],
                    array(
                        'user_id' => $user_id,
                        'subscription_id' => $subscription_id,
                        'url' => $new_url,
                        'status' => 'active',
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ),
                    array('%d', '%d', '%s', '%s', '%s', '%s')
                );
                
                if (!$result) {
                    throw new Exception(__('Failed to add URL.', 'wc-rbm'));
                }
                
                // Update Bricks whitelist
                $this->update_bricks_whitelist();
                
                return array(
                    'success' => true,
                    'message' => __('URL has been successfully added to the whitelist.', 'wc-rbm')
                );
            }
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    /**
     * Get user's URL for a subscription
     */
    public function get_user_url($user_id, $subscription_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->tables['user_urls']} 
             WHERE user_id = %d AND subscription_id = %d AND status = 'active'",
            $user_id, $subscription_id
        ));
    }
    
    /**
     * Get all active URLs
     */
    public function get_active_urls() {
        global $wpdb;
        
        return $wpdb->get_results(
            "SELECT u.* FROM {$this->tables['user_urls']} u
             INNER JOIN {$this->tables['subscriptions']} s ON u.subscription_id = s.id
             WHERE u.status = 'active' AND s.status = 'active'
             AND (s.expiry_date IS NULL OR s.expiry_date > NOW())
             ORDER BY u.created_at ASC"
        );
    }
    
    /**
     * Update Bricks whitelist with all active URLs
     */
    public function update_bricks_whitelist() {
        // Get all active URLs
        $active_urls = $this->get_active_urls();
        $plugin_managed_urls = array();
        
        foreach ($active_urls as $url_obj) {
            $plugin_managed_urls[] = trim($url_obj->url);
        }
        
        // Get current Bricks settings
        $bricks_settings = get_option('bricks_global_settings', '');
        $settings = maybe_unserialize($bricks_settings);
        
        if (!is_array($settings)) {
            $settings = array();
        }
        
        // Get existing whitelist
        $existing_whitelist = isset($settings['myTemplatesWhitelist']) ? $settings['myTemplatesWhitelist'] : '';
        $existing_urls = array_filter(array_map('trim', explode("\n", $existing_whitelist)));
        
        // Get all URLs ever managed by our plugin for cleanup
        $all_plugin_urls = $this->get_all_plugin_managed_urls();
        
        // Preserve non-plugin URLs
        $preserved_urls = array();
        foreach ($existing_urls as $url) {
            if (!in_array($url, $all_plugin_urls) || in_array($url, $plugin_managed_urls)) {
                $preserved_urls[] = $url;
            }
        }
        
        // Add new plugin URLs
        foreach ($plugin_managed_urls as $url) {
            if (!in_array($url, $preserved_urls)) {
                $preserved_urls[] = $url;
            }
        }
        
        // Update settings
        $settings['myTemplatesWhitelist'] = implode("\n", array_filter($preserved_urls));
        
        // Save to database
        $this->save_bricks_settings($settings);
        
        // Log the update
        error_log('WC RBM: Updated Bricks whitelist with ' . count($plugin_managed_urls) . ' URLs');
        
        return true;
    }
    
    /**
     * Update Bricks whitelist when URL changes
     */
    public function update_bricks_whitelist_with_change($old_url, $new_url) {
        // Get current Bricks settings
        $bricks_settings = get_option('bricks_global_settings', '');
        $settings = maybe_unserialize($bricks_settings);
        
        if (!is_array($settings)) {
            $settings = array();
        }
        
        // Get existing whitelist
        $existing_whitelist = isset($settings['myTemplatesWhitelist']) ? $settings['myTemplatesWhitelist'] : '';
        $existing_urls = array_filter(array_map('trim', explode("\n", $existing_whitelist)));
        
        // Remove old URL if exists
        if ($old_url) {
            $existing_urls = array_filter($existing_urls, function($url) use ($old_url) {
                return trim($url) !== trim($old_url);
            });
        }
        
        // Add new URL if not already there
        if ($new_url && !in_array(trim($new_url), $existing_urls)) {
            $existing_urls[] = trim($new_url);
        }
        
        // Update settings
        $settings['myTemplatesWhitelist'] = implode("\n", array_filter($existing_urls));
        
        // Save to database
        $this->save_bricks_settings($settings);
        
        return true;
    }
    
    /**
     * Remove URLs from Bricks whitelist
     */
    public function remove_urls_from_whitelist($urls_to_remove) {
        // Get current Bricks settings
        $bricks_settings = get_option('bricks_global_settings', '');
        $settings = maybe_unserialize($bricks_settings);
        
        if (!is_array($settings)) {
            return;
        }
        
        // Get existing whitelist
        $existing_whitelist = isset($settings['myTemplatesWhitelist']) ? $settings['myTemplatesWhitelist'] : '';
        $existing_urls = array_filter(array_map('trim', explode("\n", $existing_whitelist)));
        
        // Convert URLs to remove to array
        $remove_array = array();
        foreach ($urls_to_remove as $url_obj) {
            if (is_object($url_obj)) {
                $remove_array[] = trim($url_obj->url);
            } else {
                $remove_array[] = trim($url_obj);
            }
        }
        
        // Filter out URLs to remove
        $filtered_urls = array_filter($existing_urls, function($url) use ($remove_array) {
            return !in_array(trim($url), $remove_array);
        });
        
        // Update settings
        $settings['myTemplatesWhitelist'] = implode("\n", $filtered_urls);
        
        // Save to database
        $this->save_bricks_settings($settings);
        
        return true;
    }
    
    /**
     * Get all URLs ever managed by the plugin
     */
    private function get_all_plugin_managed_urls() {
        global $wpdb;
        
        $urls = $wpdb->get_results(
            "SELECT DISTINCT url FROM {$this->tables['user_urls']}"
        );
        
        $url_array = array();
        foreach ($urls as $url_obj) {
            $url_array[] = trim($url_obj->url);
        }
        
        return $url_array;
    }
    
    /**
     * Save Bricks settings
     */
    private function save_bricks_settings($settings) {
        global $wpdb;
        
        $serialized_settings = serialize($settings);
        
        // Use direct database update for reliability
        $result = $wpdb->update(
            $wpdb->options,
            array('option_value' => $serialized_settings),
            array('option_name' => 'bricks_global_settings'),
            array('%s'),
            array('%s')
        );
        
        // Clear WordPress option cache
        wp_cache_delete('bricks_global_settings', 'options');
        
        return $result !== false;
    }
    
    /**
     * Remove URL by ID
     */
    public function remove_url($url_id) {
        global $wpdb;
        
        // Get URL details first
        $url_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->tables['user_urls']} WHERE id = %d",
            $url_id
        ));
        
        if (!$url_record) {
            throw new Exception(__('URL not found.', 'wc-rbm'));
        }
        
        // Update status to removed
        $result = $wpdb->update(
            $this->tables['user_urls'],
            array(
                'status' => 'removed',
                'updated_at' => current_time('mysql')
            ),
            array('id' => $url_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result === false) {
            throw new Exception(__('Failed to remove URL.', 'wc-rbm'));
        }
        
        // Update Bricks whitelist
        $this->update_bricks_whitelist();
        
        return true;
    }
    
    /**
     * Clean up expired URLs
     */
    public function cleanup_expired_urls() {
        global $wpdb;
        
        // Mark URLs as expired for inactive subscriptions
        $updated = $wpdb->query(
            "UPDATE {$this->tables['user_urls']} u
             INNER JOIN {$this->tables['subscriptions']} s ON u.subscription_id = s.id
             SET u.status = 'expired', u.updated_at = NOW()
             WHERE u.status = 'active' 
             AND (s.status != 'active' OR (s.expiry_date IS NOT NULL AND s.expiry_date <= NOW()))"
        );
        
        if ($updated > 0) {
            // Update Bricks whitelist
            $this->update_bricks_whitelist();
            
            error_log('WC RBM: Cleaned up ' . $updated . ' expired URLs');
        }
        
        return $updated;
    }
    
    /**
     * Get all URLs for export
     */
    public function get_all_for_export() {
        global $wpdb;
        
        return $wpdb->get_results(
            "SELECT 
                u.id,
                usr.user_login,
                usr.user_email,
                u.url,
                u.status,
                s.subscription_type,
                s.amount,
                s.status as subscription_status,
                u.created_at,
                u.updated_at
             FROM {$this->tables['user_urls']} u
             LEFT JOIN {$this->tables['subscriptions']} s ON u.subscription_id = s.id
             LEFT JOIN {$wpdb->users} usr ON u.user_id = usr.ID
             ORDER BY u.created_at DESC",
            ARRAY_A
        );
    }
    
    /**
     * Get URL statistics
     */
    public function get_statistics() {
        global $wpdb;
        
        $stats = array();
        
        // Total URLs
        $stats['total'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tables['user_urls']}"
        );
        
        // Active URLs
        $stats['active'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tables['user_urls']} WHERE status = 'active'"
        );
        
        // Expired URLs
        $stats['expired'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tables['user_urls']} WHERE status = 'expired'"
        );
        
        // Removed URLs
        $stats['removed'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tables['user_urls']} WHERE status = 'removed'"
        );
        
        return $stats;
    }
}