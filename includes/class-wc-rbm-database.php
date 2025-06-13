<?php
/**
 * Database Handler Class
 */
class WC_RBM_Database {
    
    /**
     * Database version
     */
    const DB_VERSION = '2.0';
    
    /**
     * Table names cache
     */
    private $table_names = null;
    
    /**
     * Get table names
     */
    public function get_table_names() {
        if ($this->table_names === null) {
            global $wpdb;
            
            $this->table_names = array(
                'subscriptions' => $wpdb->prefix . 'recurring_subscriptions',
                'invoices' => $wpdb->prefix . 'recurring_invoices',
                'user_urls' => $wpdb->prefix . 'user_whitelist_urls'
            );
        }
        
        return $this->table_names;
    }
    
    /**
     * Create database tables
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $tables = $this->get_table_names();
        
        // Subscriptions table
        $sql_subscriptions = "CREATE TABLE {$tables['subscriptions']} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            subscription_type varchar(20) NOT NULL,
            amount decimal(10,2) NOT NULL,
            status varchar(20) DEFAULT 'active',
            start_date datetime DEFAULT CURRENT_TIMESTAMP,
            next_billing_date datetime NOT NULL,
            last_billing_date datetime NULL,
            expiry_date datetime NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY next_billing_date (next_billing_date),
            KEY expiry_date (expiry_date)
        ) $charset_collate;";
        
        // Invoices table
        $sql_invoices = "CREATE TABLE {$tables['invoices']} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            subscription_id mediumint(9) NOT NULL,
            user_id bigint(20) NOT NULL,
            invoice_number varchar(50) NOT NULL,
            amount decimal(10,2) NOT NULL,
            status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            paid_at datetime NULL,
            payment_method varchar(50) NULL,
            transaction_id varchar(100) NULL,
            PRIMARY KEY (id),
            KEY subscription_id (subscription_id),
            KEY user_id (user_id),
            KEY status (status),
            UNIQUE KEY invoice_number (invoice_number)
        ) $charset_collate;";
        
        // User URLs table
        $sql_urls = "CREATE TABLE {$tables['user_urls']} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            subscription_id mediumint(9) NOT NULL,
            url varchar(255) NOT NULL,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY subscription_id (subscription_id),
            KEY status (status),
            UNIQUE KEY user_subscription (user_id, subscription_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($sql_subscriptions);
        dbDelta($sql_invoices);
        dbDelta($sql_urls);
        
        // Update database version
        update_option('wc_rbm_db_version', self::DB_VERSION);
    }
    
    /**
     * Check if tables exist
     */
    public function tables_exist() {
        global $wpdb;
        $tables = $this->get_table_names();
        
        foreach ($tables as $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Check if database needs update
     */
    public function needs_update() {
        $current_version = get_option('wc_rbm_db_version', '0');
        return version_compare($current_version, self::DB_VERSION, '<');
    }
    
    /**
     * Update database
     */
    public function update() {
        if ($this->needs_update()) {
            $this->create_tables();
        }
    }
}