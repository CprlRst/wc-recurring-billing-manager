# WooCommerce Recurring Billing Manager - Installation Guide

## 📁 File Structure
Create this folder structure in your WordPress installation:

```
/wp-content/plugins/wc-recurring-billing-manager/
├── wc-recurring-billing-manager.php
├── assets/
│   ├── frontend.css
│   ├── frontend.js
│   └── admin.js
└── README.md
```

## 🚀 Installation Steps

### 1. Create Plugin Directory
```bash
mkdir /wp-content/plugins/wc-recurring-billing-manager
mkdir /wp-content/plugins/wc-recurring-billing-manager/assets
```

### 2. Add Files
- Place the main PHP file as `wc-recurring-billing-manager.php`
- Place CSS file as `assets/frontend.css`
- Place JavaScript files as `assets/frontend.js` and `assets/admin.js`

### 3. Activate Plugin
1. Go to WordPress Admin → Plugins
2. Find "WooCommerce Recurring Billing Manager"
3. Click "Activate"

### 4. Verify Installation
- Check that "Recurring Billing" appears in admin menu
- Verify database tables were created:
  - `wp_recurring_subscriptions`
  - `wp_recurring_invoices`

## ⚙️ Configuration

### WooCommerce Requirements
- WooCommerce must be installed and activated
- User accounts must be enabled
- Email functionality should be working

### Bricks Theme Integration
The plugin automatically integrates with Bricks theme's global settings:
- Database: `wp_options`
- Option name: `bricks_global_settings`
- Target field: `myTemplatesWhitelist`

## 📋 Usage Instructions

### For Administrators

#### Creating Subscriptions
1. Go to **Recurring Billing** in admin menu
2. Fill out the subscription form:
   - Select user
   - Choose billing interval (Monthly/Yearly)
   - Set amount
3. Click "Create Subscription"

#### Managing Subscriptions
- **Pause/Activate**: Click respective buttons in subscription list
- **Create Invoice**: Generate manual invoices
- **View Stats**: See active/paused subscription counts

#### Invoice Management
1. Go to **Recurring Billing → Invoices**
2. View all generated invoices
3. Track payment status

### For Customers

#### URL Management
1. Log into WooCommerce account
2. Go to **URL Manager** section
3. View current whitelisted URLs
4. Submit new URLs for approval

#### Features Available
- Real-time URL validation
- Duplicate URL prevention
- Automatic HTTPS formatting
- Copy URLs functionality

## 🔧 Technical Details

### Database Schema

#### Subscriptions Table (`wp_recurring_subscriptions`)
```sql
- id (mediumint, primary key)
- user_id (bigint, foreign key to users)
- subscription_type (varchar: 'monthly'/'yearly')
- amount (decimal)
- status (varchar: 'active'/'paused'/'cancelled')
- start_date (datetime)
- next_billing_date (datetime)
- last_billing_date (datetime)
- created_at (datetime)
```

#### Invoices Table (`wp_recurring_invoices`)
```sql
- id (mediumint, primary key)
- subscription_id (mediumint, foreign key)
- user_id (bigint, foreign key to users)
- invoice_number (varchar, unique)
- amount (decimal)
- status (varchar: 'pending'/'paid'/'failed')
- created_at (datetime)
- paid_at (datetime)
```

### Cron Jobs
- **Frequency**: Daily
- **Hook**: `process_recurring_billing`
- **Function**: Automatically creates invoices for due subscriptions

### AJAX Endpoints
- `submit_url` - Customer URL submission
- `manage_subscription` - Admin subscription management
- `create_invoice` - Manual invoice creation

## 🛡️ Security Features

### Nonce Verification
- All AJAX requests use WordPress nonces
- Prevents CSRF attacks

### Permission Checks
- Admin functions require `manage_options` capability
- Customer functions require user login

### Input Validation
- URL validation and sanitization
- SQL injection prevention
- XSS protection

## 🎨 Customization

### Styling
Modify `assets/frontend.css` to customize:
- URL manager appearance
- Form styling
- Message display
- Responsive design

### Functionality
The main PHP file contains hooks for:
- Custom email templates
- Payment gateway integration
- Additional subscription types
- Custom billing cycles

## 🔍 Troubleshooting

### Common Issues

#### Plugin Not Appearing
- Verify WooCommerce is active
- Check file permissions
- Review error logs

#### URL Submission Failing
- Verify AJAX URL is correct
- Check nonce generation
- Confirm user permissions

#### Cron Jobs Not Running
- Test WordPress cron functionality
- Check server cron configuration
- Verify scheduled events

### Debug Mode
Add this to wp-config.php for debugging:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## 📧 Support & Development

### Hooks Available
```php
// Before URL is added to whitelist
do_action('wc_recurring_billing_before_url_add', $url, $user_id);

// After subscription is created
do_action('wc_recurring_billing_subscription_created', $subscription_id);

// Before invoice is generated
do_action('wc_recurring_billing_before_invoice', $subscription);
```

### Filters Available
```php
// Modify email content
apply_filters('wc_recurring_billing_email_content', $message, $invoice);

// Customize invoice number format
apply_filters('wc_recurring_billing_invoice_number', $number, $subscription);
```

## 🔄 Updates & Maintenance

### Regular Tasks
1. Monitor subscription processing
2. Check invoice generation
3. Verify email delivery
4. Review error logs

### Backup Recommendations
- Database tables (subscriptions & invoices)
- Bricks global settings
- Plugin configuration

This plugin provides a complete recurring billing solution integrated with your existing Bricks theme setup. The URL whitelist management directly updates your `bricks_global_settings` as requested.
