<?php
/**
 * My Account > Addresses — RouteMile override of WC core's
 * templates/myaccount/my-address.php (v9.3.0).
 *
 * Same data flow as core — the `woocommerce_my_account_get_addresses`
 * filter (name → title map), `wc_get_account_formatted_address()`,
 * the Edit/Add label states, the empty-address state, and the
 * `woocommerce_my_account_after_my_address` action are all preserved
 * exactly. Only the MARKUP changes: the cards live under our own
 * `.routew-address-grid > .routew-address-col` structure instead of
 * core's `.u-columns > .col2-set > .u-column1/.u-column2` float layout,
 * so the plugin CSS owns the layout and never fights theme `.col2-set`
 * rules.
 *
 * WC's own strings keep the 'woocommerce' text domain so existing
 * translations apply (standard practice for template overrides) — the
 * inline `phpcs:ignore TextDomainMismatch` annotations document this
 * for the WordPress Plugin Check / PHPCS runs. Local template variables
 * carry the `routew_` prefix per plugin naming conventions.
 *
 * Loaded via ROUTEW_Shortcodes::override_wc_templates() on the
 * `woocommerce_locate_template` filter. A theme override at
 * yourtheme/woocommerce/myaccount/my-address.php still wins.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package RouteMile
 * @version 1.6.0
 */

defined( 'ABSPATH' ) || exit;

$routew_customer_id = get_current_user_id();

if ( ! wc_ship_to_billing_address_only() && wc_shipping_enabled() ) {
	$routew_get_addresses = apply_filters(
		'woocommerce_my_account_get_addresses',
		array(
			// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WC core string, uses WC language packs.
			'billing'  => __( 'Billing address', 'woocommerce' ),
			// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WC core string, uses WC language packs.
			'shipping' => __( 'Shipping address', 'woocommerce' ),
		),
		$routew_customer_id
	);
} else {
	$routew_get_addresses = apply_filters(
		'woocommerce_my_account_get_addresses',
		array(
			// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WC core string, uses WC language packs.
			'billing' => __( 'Billing address', 'woocommerce' ),
		),
		$routew_customer_id
	);
}
?>

<p class="routew-lead">
	<?php echo apply_filters( 'woocommerce_my_account_my_address_description', esc_html__( 'The following addresses will be used on the checkout page by default.', 'woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.I18n.TextDomainMismatch -- filtered value may contain HTML; WC core string keeps the WC domain for its language packs. ?>
</p>

<div class="routew-address-grid">

	<?php foreach ( $routew_get_addresses as $routew_address_name => $routew_address_title ) : ?>
		<?php $routew_address = wc_get_account_formatted_address( $routew_address_name ); ?>

		<div class="routew-address-col routew-address-col--<?php echo esc_attr( $routew_address_name ); ?><?php echo $routew_address ? '' : ' routew-address-col--empty'; ?>">
			<header class="routew-address-card-header">
				<h2><?php echo esc_html( $routew_address_title ); ?></h2>
				<a href="<?php echo esc_url( wc_get_endpoint_url( 'edit-address', $routew_address_name ) ); ?>" class="routew-address-edit">
					<?php
					/* translators: %s: Address title */
					printf( $routew_address ? esc_html__( 'Edit %s', 'woocommerce' ) : esc_html__( 'Add %s', 'woocommerce' ), esc_html( $routew_address_title ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WC core strings, use WC language packs.
					?>
				</a>
			</header>
			<address>
				<?php
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WC core string, uses WC language packs.
				echo $routew_address ? wp_kses_post( $routew_address ) : esc_html__( 'You have not set up this type of address yet.', 'woocommerce' );

				/**
				 * Used to output content after core address fields.
				 *
				 * @param string $routew_address_name Address type.
				 * @since 8.7.0
				 */
				do_action( 'woocommerce_my_account_after_my_address', $routew_address_name );
				?>
			</address>
		</div>

	<?php endforeach; ?>

</div>
