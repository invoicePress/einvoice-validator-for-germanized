<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * The menu entries of the suite in wp-admin: "eInvoice Viewer" for the viewer and its add-ons with a page, "eInvoice
 * Validator" for the validators and the comparison, and "eInvoice Validation Explorer" for the explorers, each with an
 * overview and one page per active plugin below it.
 *
 * Ralf on 22.10.2026: "einen menüpunkt für die validatoren, einen für die explorer". Until then each of the six plugins
 * put a page of its own under *Settings*, and with all of them installed that menu grew long. A plugin replaces its
 * `add_options_page()` with one call:
 *
 *     \SpecialPress\EInvoice\Host\AdminMenu::add( 'validator', array(
 *         'slug'       => 'spko-settings',
 *         'title'      => __( 'E-Invoice Validator KoSIT', 'my-domain' ),
 *         'menu'       => 'KoSIT',
 *         'callback'   => array( __CLASS__, 'page' ),
 *         'version'    => SPKO_VERSION,
 *         'shortcode'  => '[spko_validator]',
 *         'labels'     => array( 'overview' => __( 'Overview', 'my-domain' ), … ),
 *     ) );
 *
 * ⚠️ **EVERY PLUGIN MUST WORK ALONE, SO THE FIRST ONE ADDS THE ENTRY.** Whichever plugin of a group calls first on
 * `admin_menu` creates the top-level entry and its overview; the others only add their page below it. With one plugin
 * of the group active, the entry carries that one page and the overview.
 *
 * ⚠️ **THE SLUG OF A PAGE STAYS WHAT IT WAS.** Forms post to `options.php` and come back to their referer, and a link of a
 * plugin to its own page keeps working through `admin.php?page=<slug>`. An old address `options-general.php?page=<slug>`
 * from a bookmark or a manual is sent on by `redirect_old()`; WordPress would otherwise answer it with "Sorry, you are
 * not allowed to access this page."
 *
 * ⚠️ **THE TEXTS COME FROM THE CALLING PLUGIN.** This library has no text domain. The labels of the overview are taken from
 * the first plugin that registered; a plugin that comes later and brings labels in another language cannot happen, because
 * all of them translate in the language of the user.
 *
 * ⚠️ **A NEW CLASS, NOT A NEW METHOD ON AN OLD ONE.** Every plugin carries its own copy of this library, and the copy that
 * loads first serves all of them ( see ManualLink ).
 *
 * @package SpecialPress\EInvoice\Host
 * @since 1.8.0
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Host;

if ( ! defined( 'ABSPATH' ) )
	exit;

if ( ! class_exists( __NAMESPACE__ . '\\AdminMenu' ) )
{

class AdminMenu
{

	/**
	 * The groups. The names are product names and stay the same in every language.
	 *
	 * ⚠️ THE POSITIONS SIT BELOW "SETTINGS" ( 80 ) AND APART FROM EACH OTHER, so that the entries stand together and
	 * a third plugin that takes 81 does not push one of them away.
	 *
	 * ⚠️ "viewer" CAME LATER ( Ralf, 22.10.2026: the viewer gets a menu like the validators, with the Inbound Check below
	 * it ). A copy of this class from before that does not know the group, and when that copy loads first, `add()` returns
	 * ''. A plugin of the viewer group must therefore fall back to its old page under Settings on '' instead of trusting
	 * the call.
	 */
	const GROUPS = array(
		'viewer'    => array( 'slug' => 'einvoice-viewer', 'name' => 'eInvoice Viewer', 'icon' => 'dashicons-media-document', 'position' => 81.30 ),
		'validator' => array( 'slug' => 'einvoice-validator', 'name' => 'eInvoice Validator', 'icon' => 'dashicons-yes-alt', 'position' => 81.31 ),
		'explorer'  => array( 'slug' => 'einvoice-validation-explorer', 'name' => 'eInvoice Validation Explorer', 'icon' => 'dashicons-search', 'position' => 81.32 ),
	);

	/** @var array<string,array<string,array>> group => page slug => page */
	private static $pages = array();

	/** @var array<string,array> group => labels of the overview */
	private static $labels = array();

	/** @var bool */
	private static $hooked = false;


	/**
	 * Adds the page of one plugin below its group. Call it on `admin_menu`.
	 *
	 * @param	string	$group	'viewer' | 'validator' | 'explorer'
	 * @param	array	$page	slug, title, menu, callback, capability ( default manage_options ), version, shortcode,
	 *                          labels ( overview, intro, column_name, column_version, column_shortcode, open )
	 * @return	string	the hook suffix of the page, '' when nothing was added
	 */

	public static function add( $group, array $page )
	{
		if ( ! isset( self::GROUPS[ $group ] ) || ! function_exists( 'add_menu_page' ) )
			return( '' );

		$slug       = (string) ( $page[ 'slug' ] ?? '' );
		$capability = (string) ( $page[ 'capability' ] ?? 'manage_options' );

		if ( $slug === '' || ! is_callable( $page[ 'callback' ] ?? null ) )
			return( '' );

		$g = self::GROUPS[ $group ];

		self::hook();

		if ( ! isset( self::$pages[ $group ] ) )
		{
			self::$pages[ $group ]  = array();
			self::$labels[ $group ] = is_array( $page[ 'labels' ] ?? null ) ? $page[ 'labels' ] : array();

			add_menu_page( $g[ 'name' ], $g[ 'name' ], $capability, $g[ 'slug' ], static function () use ( $group ) {
				self::overview( $group );
			}, $g[ 'icon' ], $g[ 'position' ] );

			// ⚠️ The first item below the entry would otherwise repeat the name of the entry instead of saying "Overview".
			$overview = (string) ( self::$labels[ $group ][ 'overview' ] ?? 'Overview' );
			add_submenu_page( $g[ 'slug' ], $g[ 'name' ], $overview, $capability, $g[ 'slug' ] );
		}

		self::$pages[ $group ][ $slug ] = $page;

		return( (string) add_submenu_page(
			$g[ 'slug' ],
			(string) ( $page[ 'title' ] ?? $slug ),
			(string) ( $page[ 'menu' ] ?? $page[ 'title' ] ?? $slug ),
			$capability,
			$slug,
			$page[ 'callback' ]
		) );
	}


	/**
	 * The address of a page of the suite, for links and for the redirect after saving.
	 *
	 * @param	string	$slug
	 * @param	array	$args	further query arguments
	 * @return	string
	 */

	public static function url( $slug, array $args = array() )
	{
		return( add_query_arg( array( 'page' => (string) $slug ) + $args, admin_url( 'admin.php' ) ) );
	}


	/** @return void */

	private static function hook()
	{
		if ( self::$hooked )
			return;

		self::$hooked = true;

		add_action( 'admin_page_access_denied', array( __CLASS__, 'redirect_old' ) );
	}


	/**
	 * Sends an old address under *Settings* to the page's place in the new menu.
	 *
	 * ⚠️ ON `admin_page_access_denied`, NOT ON `admin_init`. The pages are known only after `admin_menu`, which runs after
	 * `admin_init`; WordPress reports an unknown page under *Settings* through this hook, after the menu is built.
	 *
	 * @return	void
	 */

	public static function redirect_old()
	{
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only read to choose the target of a redirect, nothing is changed.
		$page = isset( $_GET[ 'page' ] ) ? sanitize_key( wp_unslash( (string) $_GET[ 'page' ] ) ) : '';

		if ( $pagenow !== 'options-general.php' || $page === '' )
			return;

		foreach ( self::$pages as $pages )
		{
			if ( ! isset( $pages[ $page ] ) )
				continue;

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the query arguments are passed on unchanged.
			$args = array_map( 'sanitize_text_field', wp_unslash( (array) $_GET ) );
			unset( $args[ 'page' ] );

			wp_safe_redirect( self::url( $page, $args ) );
			exit;
		}
	}


	/**
	 * The overview: the active plugins of the group, with their version, their shortcode and a link to their page.
	 *
	 * @param	string	$group
	 * @return	void
	 */

	private static function overview( $group )
	{
		$l = self::$labels[ $group ] ?? array();
		$s = static function ( $key, $default ) use ( $l ) {
			return( (string) ( $l[ $key ] ?? $default ) );
		};

		echo '<div class="wrap"><h1>' . esc_html( self::GROUPS[ $group ][ 'name' ] ) . '</h1>';
		echo '<p>' . esc_html( $s( 'intro', 'The active plugins of this group. Each one has its own page with its settings.' ) ) . '</p>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>'
			. '<th>' . esc_html( $s( 'column_name', 'Plugin' ) ) . '</th>'
			. '<th>' . esc_html( $s( 'column_version', 'Version' ) ) . '</th>'
			. '<th>' . esc_html( $s( 'column_shortcode', 'Shortcode' ) ) . '</th>'
			. '<th></th></tr></thead><tbody>';

		foreach ( self::$pages[ $group ] ?? array() as $slug => $page )
		{
			echo '<tr><td>' . esc_html( (string) ( $page[ 'title' ] ?? $slug ) ) . '</td>'
				. '<td>' . esc_html( (string) ( $page[ 'version' ] ?? '' ) ) . '</td>'
				// An add-on of the viewer has no shortcode of its own; an empty <code> would draw a grey box.
				. '<td>' . ( (string) ( $page[ 'shortcode' ] ?? '' ) !== '' ? '<code>' . esc_html( (string) $page[ 'shortcode' ] ) . '</code>' : '–' ) . '</td>'
				. '<td><a class="button" href="' . esc_url( self::url( $slug ) ) . '">' . esc_html( $s( 'open', 'Open' ) ) . '</a></td></tr>';
		}

		echo '</tbody></table></div>';
	}

}

}
