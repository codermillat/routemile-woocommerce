# RouteMile for WooCommerce - Quick Reference Guide

## Plugin Entry Point
- **Main File:** `routemile-woocommerce.php`
- **Core Class:** `ROUTEW_Core` in `includes/class-routew-core.php`
- **Initialization:** Hooks into `plugins_loaded` action

## Key Classes and Their Responsibilities

### Core Classes
- **ROUTEW_Core**: Main orchestrator, loads dependencies, manages hooks
- **ROUTEW_Config**: Configuration constants (prep time, radius, retries)
- **ROUTEW_Roles**: User role management (delivery_boy role)
- **ROUTEW_Order_Statuses**: Custom order statuses registration

### Checkout & Shipping
- **ROUTEW_Checkout**: Checkout enhancements, location handling, zone validation
- **ROUTEW_Shipping_Method**: Distance-based shipping calculation
- **ROUTEW_Mapping_Service**: Google Maps API integration (geocoding, distance)

### Admin & Dashboard
- **ROUTEW_Dashboard**: Admin delivery dashboard, order assignment
- **ROUTEW_Order_Admin**: Order admin functionality, receipt printing
- **ROUTEW_Settings**: Settings page management
- **ROUTEW_Admin_Bar**: Admin bar integration (delivery status toggle)
- **ROUTEW_Reporting**: Daily reports on dashboard

### Frontend
- **ROUTEW_Shortcodes**: Frontend shortcodes (track order, re-order)
- **ROUTEW_Delivery_Boy_View**: Delivery personnel view

### Services
- **ROUTEW_Mapping_Service**: Google Maps API wrapper
- **ROUTEW_Rate_Limiter**: Rate limiting for API requests
- **ROUTEW_Notifications**: Email notifications on status changes

## Key Hooks

### Actions
- `plugins_loaded`: Plugin initialization
- `admin_menu`: Admin menu registration
- `wp_enqueue_scripts`: Script/style enqueuing
- `woocommerce_checkout_fields`: Checkout field customization
- `woocommerce_after_checkout_validation`: Delivery zone validation
- `woocommerce_cart_calculate_fees`: Delivery fee calculation
- `woocommerce_checkout_create_order`: Save delivery details
- `woocommerce_order_status_changed`: Status change notifications

### Filters
- `woocommerce_shipping_methods`: Add custom shipping method
- `woocommerce_shipping_chosen_method`: Prefer RouteMile shipping
- `wc_order_statuses`: Add custom order statuses
- `template_include`: Custom delivery dashboard template
- `query_vars`: Add custom query variables

## AJAX Endpoints

### Public Endpoints (with nonce)
- `routew_get_restaurant_location`: Get restaurant coordinates
- `routew_update_customer_location`: Update customer location in session
- `routew_print_receipt`: Print order receipt (with auth check)
- `routew_debug_status`: Debug endpoint

### Admin Endpoints (with nonce + capability check)
- `routew_toggle_delivery_status`: Toggle delivery open/closed

## Order Meta Fields

- `_routew_delivery_boy_id`: Assigned delivery personnel ID
- `_routew_delivery_address`: Full delivery address
- `_routew_delivery_lat`: Delivery latitude
- `_routew_delivery_lng`: Delivery longitude
- `_routew_delivery_distance`: Distance in kilometers
- `_routew_delivery_duration`: Estimated duration in minutes
- `_routew_address_unit`: Unit/apartment number

## Session Data

- `customer_lat`: Customer latitude
- `customer_lng`: Customer longitude
- `customer_address`: Customer address

## Settings Options

Stored in `routew_settings` option:
- `routew_google_maps_api_key`: Google Maps API key
- `routew_restaurant_address`: Restaurant address
- `routew_preparation_time`: Default preparation time (minutes)
- `routew_delivery_fee_base`: Base delivery fee
- `routew_delivery_fee_per_km`: Fee per kilometer
- `routew_delivery_zone_radius`: Delivery zone radius (km)
- `routew_is_open`: Delivery status (open/closed)

## Custom Order Statuses

- `routew-in-kitchen`: Order is being prepared
- `routew-assigned`: Delivery personnel assigned
- `routew-picked-up`: Order picked up, out for delivery
- `completed`: Standard WooCommerce status (delivered)

## User Roles & Capabilities

### Delivery Boy Role
- Capabilities: `read`, `edit_posts`, `routew_delivery_access`
- Access: Delivery dashboard only
- Restrictions: No admin bar, limited order access

### Custom Capability
- `routew_delivery_access`: Required for delivery dashboard access
- Granted to: `delivery_boy` role, `administrator` role

## Templates

### Frontend Templates
- `templates/delivery-dashboard-template.php`: Delivery personnel dashboard
- `templates/delivery-boy-view.php`: Alternative delivery view
- `templates/receipt-template.php`: Order receipt template

## Shortcodes

- `[routew_track_order]`: Order tracking form and status display

## Routes

- `/delivery-dashboard`: Delivery personnel dashboard (rewrite rule)

## Security Measures

1. **Nonce Verification**: All AJAX endpoints and forms
2. **Capability Checks**: Admin functions require proper capabilities
3. **Input Sanitization**: All user inputs sanitized
4. **Output Escaping**: All outputs escaped
5. **Rate Limiting**: IP-based rate limiting for API requests
6. **Authorization**: Order ownership checks for receipts

## Common Functions

### Get Restaurant Location
```php
$options = get_option( 'routew_settings' );
$restaurant_address = $options['routew_restaurant_address'];
$mapping = new ROUTEW_Mapping_Service();
$coords = $mapping->get_coords( $restaurant_address );
```

### Get Order Delivery Boy
```php
$order = wc_get_order( $order_id );
$delivery_boy_id = $order->get_meta( '_routew_delivery_boy_id' );
```

### Get Delivery Details
```php
$order = wc_get_order( $order_id );
$address = $order->get_meta( '_routew_delivery_address' );
$lat = $order->get_meta( '_routew_delivery_lat' );
$lng = $order->get_meta( '_routew_delivery_lng' );
$distance = $order->get_meta( '_routew_delivery_distance' );
$duration = $order->get_meta( '_routew_delivery_duration' );
```

### Calculate Shipping
```php
$mapping = new ROUTEW_Mapping_Service();
$distance_data = $mapping->get_distance( $origin, $destination );
// Returns: array( 'distance' => float, 'duration' => int )
```

### Rate Limiting
```php
$rate_limit_check = ROUTEW_Rate_Limiter::check_rate_limit( 'action_name', 10 );
if ( is_wp_error( $rate_limit_check ) ) {
    // Handle rate limit error
}
```

## Testing

### Run PHPUnit Tests
```bash
vendor/bin/phpunit
```

### Run E2E Tests (Playwright)
```bash
cd tests/e2e
npm install
npm test
```

### Test Coverage
- Unit tests: `tests/unit/`
- Integration tests: `tests/integration/`
- E2E tests: `tests/e2e/`
- Security tests: `tests/security/`

## Debugging

### Enable Debug Logging
```php
// Already enabled if WooCommerce logger is available
wc_get_logger()->debug( 'Message', array( 'source' => 'routemile-woocommerce' ) );
```

### Check Rate Limiting
```php
$remaining = ROUTEW_Rate_Limiter::get_remaining_quota( 'action_name', 10 );
```

### Debug Status Endpoint
- AJAX endpoint: `routew_debug_status`
- Returns current session and configuration status

## Common Issues & Solutions

### Issue: Google Maps not loading
- **Solution**: Check API key in settings, verify API key has correct permissions

### Issue: Shipping not calculating
- **Solution**: Check session data, verify restaurant address is set, check API key

### Issue: Delivery dashboard not accessible
- **Solution**: Verify user has `routew_delivery_access` capability, check rewrite rules

### Issue: Orders not showing in dashboard
- **Solution**: Check order status, verify delivery boy assignment, check meta queries

### Issue: Rate limiting errors
- **Solution**: Check rate limit settings, verify IP detection, check transient storage

## File Locations

### Core Files
- Main plugin: `routemile-woocommerce.php`
- Core class: `includes/class-routew-core.php`
- Config: `includes/class-routew-config.php`

### Checkout Files
- Checkout: `includes/class-routew-checkout.php`
- Shipping: `includes/class-routew-shipping-method.php`
- Mapping: `includes/services/class-routew-mapping-service.php`

### Admin Files
- Dashboard: `includes/class-routew-dashboard.php`
- Order Admin: `includes/class-routew-order-admin.php`
- Settings: `includes/class-routew-settings.php`

### Frontend Files
- Shortcodes: `includes/class-routew-shortcodes.php`
- Delivery View: `includes/class-routew-delivery-boy-view.php`

### Assets
- Admin JS: `assets/js/admin.js`
- Checkout JS: `assets/js/checkout.js`
- Dashboard JS: `assets/js/delivery-dashboard.js`
- Frontend CSS: `assets/css/frontend.css`

## Constants

- `ROUTEW_VERSION`: Plugin version (1.0.1)
- `ROUTEW_PLUGIN_DIR`: Plugin directory path
- `ROUTEW_PLUGIN_URL`: Plugin URL

## Dependencies

- **WordPress**: 5.0+
- **WooCommerce**: Required
- **Google Maps API**: Required for maps and distance calculation
- **PHP**: 7.4+ (recommended 8.0+)

## Version History

- **1.0.1**: Current version
- **1.0.0**: Initial release

---

**Last Updated:** January 2025

