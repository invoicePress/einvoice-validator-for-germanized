<?php
/**
 * Plugin Name:       eInvoice Validator for Germanized
 * Plugin URI:        https://invoicepress.de/
 * Description:       Checks every e-invoice that Germanized Pro creates against the official rules with VAMPIRE, directly in the shop, and holds back a faulty invoice before it is finalized and sent.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            invoicePress
 * Author URI:        https://invoicepress.de/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       einvoice-validator-for-germanized
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * WC tested up to:   11.2
 */


if ( ! defined( 'ABSPATH' ) ) exit;


/**
 * About this plugin
 * -----------------
 *
 * Germanized Pro writes the e-invoice of an order ( ZUGFeRD, XRechnung, Peppol ) but does not check it against the
 * official rules. A faulty invoice is noticed only when the customer rejects it. This plugin checks it with VAMPIRE PHP
 * on the shop's own server, before the invoice is finalized:
 *
 *   1. Germanized Pro creates the invoice of an order as a draft. The plugin takes over the automatic finalization
 *      ( EIVG_Adapter_Germanized::hold_auto_finalize() ).
 *   2. It lets Germanized Pro write the e-invoices of the draft in memory and checks them ( EIVG_Flow::check_order() ).
 *   3. Valid: the plugin finalizes the invoice, and Germanized Pro numbers and sends it as usual. Invalid: the invoice
 *      stays a draft, and the shop owner gets a note at the order and a mail with the findings in plain words.
 *
 * ⚠️ THE ORDER ITSELF IS NEVER TOUCHED. Checkout, payment, order status and downloads run as always; only the invoice
 * waits.
 *
 * ⚠️ NOTHING LEAVES THE SERVER. The check runs here; the plugin calls no service and sends nothing home.
 *
 * The code has a core ( everything with EIVG_ that does not name Germanized ) and one adapter. A later plugin for
 * another invoice plugin takes the core unchanged and brings its own adapter ( EIVG_Adapter ).
 */


define( 'EIVG_VERSION', '1.0.0' );
define( 'EIVG_FILE', __FILE__ );
define( 'EIVG_DIR', plugin_dir_path( __FILE__ ) );
define( 'EIVG_URL', plugin_dir_url( __FILE__ ) );


/**
 * ⚠️ THE BUNDLED TRANSLATIONS ARE REGISTERED HERE, ON `init` WITH PRIORITY 0. WordPress 6.8 registers the `Domain
 * Path` only for a site-activated plugin; a network-activated copy would stay English without this call.
 */
add_action( 'init', static function ()
{
	load_plugin_textdomain( 'einvoice-validator-for-germanized', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}, 0 );


/**
 * ⚠️ VAMPIRE PHP IS AUTOLOADED BY HAND, FOR EXACTLY ONE NAMESPACE PREFIX, as in the eInvoice Validator for VAMPIRE.
 * Several plugins of the suite carry a copy; whichever loads first serves the request. A second Composer autoloader
 * for the same classes would fight the first one.
 *
 * ⚠️ The realpath() guard keeps a crafted class name with ".." inside vendor/invoicepress/vampire-php/src/.
 */
spl_autoload_register( static function ( $class )
{
	$prefix = 'EinvoiceValidator\\';

	if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 )
		return;

	$root = EIVG_DIR . 'vendor/invoicepress/vampire-php/src/';
	$real = realpath( $root . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php' );
	$base = (string) realpath( $root );

	if ( $real === false || $base === '' || strncmp( $real, $base, strlen( $base ) ) !== 0 )
		return;

	require_once $real;
} );


require_once EIVG_DIR . 'includes/class-eivg-settings.php';
require_once EIVG_DIR . 'includes/class-eivg-engine.php';
require_once EIVG_DIR . 'includes/class-eivg-texts.php';
require_once EIVG_DIR . 'includes/class-eivg-customer.php';
require_once EIVG_DIR . 'includes/class-eivg-reports.php';
require_once EIVG_DIR . 'includes/interface-eivg-adapter.php';
require_once EIVG_DIR . 'includes/class-eivg-adapter-germanized.php';
require_once EIVG_DIR . 'includes/class-eivg-flow.php';
require_once EIVG_DIR . 'includes/class-eivg-checkout.php';
require_once EIVG_DIR . 'includes/class-eivg-master-data.php';

if ( is_admin() )
{
	require_once EIVG_DIR . 'includes/class-eivg-selftest.php';
	require_once EIVG_DIR . 'includes/class-eivg-admin.php';
	require_once EIVG_DIR . 'includes/class-eivg-order-box.php';
}


/**
 * Declare compatibility with the WooCommerce order tables ( HPOS ). Orders are only read and written through the
 * CRUD functions of WooCommerce.
 */
add_action( 'before_woocommerce_init', static function ()
{
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) )
	{
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );


/**
 * Start once all plugins are loaded: the adapter needs to know whether Germanized Pro is there.
 */
add_action( 'plugins_loaded', static function ()
{
	EIVG_Flow::boot( new EIVG_Adapter_Germanized() );
	EIVG_Checkout::boot();
	EIVG_Master_Data::boot();

	if ( is_admin() )
	{
		EIVG_Admin::boot();
		EIVG_Order_Box::boot();
	}
}, 20 );


/**
 * The link to the manual in the plugin list ( class ManualLink in this plugin's copy of einvoice-host-wp ).
 */
( static function ()
{
	if ( ! class_exists( '\SpecialPress\EInvoice\Host\ManualLink' ) && is_readable( __DIR__ . '/libs/einvoice-host-wp/src/ManualLink.php' ) )
		require_once __DIR__ . '/libs/einvoice-host-wp/src/ManualLink.php';

	if ( ! class_exists( '\SpecialPress\EInvoice\Host\ManualLink' ) )
		return;

	\SpecialPress\EInvoice\Host\ManualLink::register( __FILE__, static function () {
		return( array(
			'manual'  => __( 'Manual', 'einvoice-validator-for-germanized' ),
			'support' => __( 'Support', 'einvoice-validator-for-germanized' ),
		) );
	}, 'https://wordpress.org/support/plugin/einvoice-validator-for-germanized/' );
} )();
