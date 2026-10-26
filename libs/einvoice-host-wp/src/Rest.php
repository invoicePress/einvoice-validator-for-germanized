<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Shared REST plumbing for the eInvoice plugin family: one namespace, one live nonce endpoint, one
 * nonce-verification helper every plugin's own routes call from their `permission_callback`.
 *
 * ⚠️ THE NONCE ACTION IS THE CORE `'wp_rest'`, NOT A CUSTOM ONE - MEASURED, NOT ASSUMED. A first
 * version used a custom `'einvoice_rest'` action, reasoning ( wrongly ) that WordPress core's own
 * `X-WP-Nonce` handling ( `rest_cookie_check_errors()`, hooked to `rest_authentication_errors` ) was a
 * no-op unless a route's own code checked it. In fact core runs that check on EVERY REST request when
 * a logged-in auth cookie is present, REGARDLESS of the route or its `permission_callback` - it
 * verifies `X-WP-Nonce` against action `'wp_rest'` specifically and rejects with 403 "Cookie check
 * failed" before this plugin's own `permission_callback` is ever reached if the action doesn't match.
 * A live browser test as a logged-in user confirmed exactly this 403 with the custom action.
 *
 * ⚠️ THE NONCE ITSELF IS FETCHED VIA admin-ajax.php, NOT A REST ROUTE - THIS IS NOT OPTIONAL, MEASURED
 * LIVE. `wp-includes/rest-api.php::rest_cookie_check_errors()` explicitly runs
 * `wp_set_current_user( 0 )` and returns early whenever a REST request carries NO `X-WP-Nonce` header
 * at all ( see its own comment: "No nonce at all, so act as if it's an unauthenticated request." ) -
 * which is exactly the situation for the very first request asking for a nonce. A REST endpoint can
 * therefore never hand a logged-in visitor their own first `wp_rest` nonce: by the time its callback
 * runs, `get_current_user_id()` has already been forced to 0, so `wp_create_nonce('wp_rest')` bakes in
 * the WRONG (anonymous) user and fails validation on every later, properly-authenticated request.
 * Confirmed live: a REST `/nonce` route returned a nonce that failed against both this namespace's own
 * routes AND plain core routes ( `wp/v2/pages` ) with "Cookie check failed", while the identical
 * `wp_create_nonce('wp_rest')` call made from `admin-ajax.php` ( which has no such gate ) or embedded
 * server-side at page-render time ( core's own `wpApiSettings.nonce` ) both validate correctly.
 * `ajaxNonce()` below is therefore the only entry point that works for a live-fetched nonce; anonymous
 * visitors are unaffected either way ( `is_user_logged_in()` is false for them, so the REST gate above
 * never fires ), but a logged-in visitor is the majority of manual testing and a real fraction of real
 * traffic, so this could not be left as a documented limitation.
 *
 * @package SpecialPress\EInvoice\Host
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Host;

if ( ! defined( 'ABSPATH' ) )
	exit;


if ( ! class_exists( __NAMESPACE__ . '\\Rest' ) )
{

class Rest
{


	const NAMESPACE_    = 'einvoice/v1';
	const NONCE_ACTION  = 'wp_rest';
	const NONCE_AJAX_ACTION = 'einvoice_nonce';


	/** @var bool guards against registering the AJAX nonce action more than once when several plugins call init() */
	private static $ajaxNonceRegistered = false;


	/**
	 * Call once per plugin, on `plugins_loaded` or later ( never in a constructor - see the
	 * load-order ⚠️ this codebase already documents elsewhere for cross-plugin `class_exists()`
	 * checks; `rest_api_init` itself only fires once every active plugin's main file has run, so any
	 * hook timing at or after that point is safe ).
	 *
	 * @return	void
	 * @since	1.0.0
	 */

	public static function init()
	{

		// Several plugins each call init() -> these two add_action() calls would each fire once per
		// plugin; add_action() itself is idempotent for the identical callback + priority, but the
		// guard makes the "registered exactly once" invariant explicit rather than accidental.

		if( self::$ajaxNonceRegistered )
			return;

		self::$ajaxNonceRegistered = true;

		add_action( 'wp_ajax_' . self::NONCE_AJAX_ACTION, array( __CLASS__, 'ajaxNonce' ) );
		add_action( 'wp_ajax_nopriv_' . self::NONCE_AJAX_ACTION, array( __CLASS__, 'ajaxNonce' ) );

	}


	/**
	 * AJAX: a fresh `wp_rest` nonce, fetched live via admin-ajax.php - see the class doc-block ⚠️ for
	 * why this cannot be a REST route. Every consuming plugin's JS should call this ( `action:
	 * 'einvoice_nonce'` against `admin-ajax.php` ) before its first `einvoice/v1/*` REST call, exactly
	 * like it already fetches its own plugin-specific nonce today.
	 *
	 * @return	void
	 * @since	1.1.0
	 */

	public static function ajaxNonce()
	{
		wp_send_json_success( array( 'nonce' => wp_create_nonce( self::NONCE_ACTION ) ) );
	}


	/**
	 * Verify the `X-WP-Nonce` header a route's own `permission_callback` receives its request with.
	 *
	 * @param	\WP_REST_Request	$request
	 * @return	bool
	 * @since	1.0.0
	 */

	public static function verifyNonce( \WP_REST_Request $request )
	{
		$nonce = $request->get_header( 'X-WP-Nonce' );

		return( $nonce !== null && wp_verify_nonce( (string) $nonce, self::NONCE_ACTION ) !== false );
	}


	/**
	 * Register one route under the shared `einvoice/v1` namespace. A thin wrapper only so every
	 * plugin's own registration call reads the same way; `rest_api_init` firing more than once per
	 * request never happens in WordPress, so no additional guard is needed here.
	 *
	 * @param	string	$route	e.g. "/validate"
	 * @param	array	$args	register_rest_route() args
	 * @return	void
	 * @since	1.0.0
	 */

	public static function registerRoute( $route, array $args )
	{
		add_action( 'rest_api_init', function() use ( $route, $args )
		{
			register_rest_route( Rest::NAMESPACE_, $route, $args );
		} );
	}


}

}
