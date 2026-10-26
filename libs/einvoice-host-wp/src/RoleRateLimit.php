<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Daily rate limiter with one limit per WordPress role, for the eInvoice plugin family ( 1.4.0 ).
 *
 * Same counting as RateLimit: logged-in visitors by user id, anonymous ones by a hashed IP address, counters are
 * transients that reset every calendar day ( site timezone ). New is the table per role ( roleLimits() ) and the
 * reason of a refusal ( `reason` in status() ).
 *
 * ⚠️ A CLASS OF ITS OWN NAME, NOT A NEW VERSION OF RateLimit. Every plugin of the family ships its own copy of this
 * library, and PHP keeps the first class of a name that is loaded; plugins load in alphabetical order. A site with an
 * older einvoice-comparison or einvoice-inbound ( both load before the validators and the viewer ) would hand every
 * later plugin the old RateLimit without the role methods, and the settings page would end in a fatal error. Under
 * a new name no old copy can take its place. RateLimit stays as it was for the plugins that still use it.
 *
 * ⚠️ AND THEREFORE EVERY LATER CHANGE OF THIS CLASS MUST STAY COMPATIBLE: two plugins with different copies of
 * RoleRateLimit meet the same rule. Add methods, never change the meaning of an existing one.
 *
 * @package SpecialPress\EInvoice\Host
 * @since 1.4.0
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Host;

if ( ! defined( 'ABSPATH' ) )
	exit;


if ( ! class_exists( __NAMESPACE__ . '\\RoleRateLimit' ) )
{

class RoleRateLimit
{


	/** @var string option-name / transient-key / filter-name prefix, e.g. "spev" */
	private $domain;

	/** @var int the daily limit used when the option itself has never been saved */
	private $defaultLimit;


	/**
	 * @param	string	$domain			option-name / transient-key / filter-name prefix ( e.g. "spev" ) -
	 *									MUST match the prefix the plugin's own settings screen already
	 *									uses for `<domain>_daily_limit` / `<domain>_trust_proxy`, so an
	 *									existing installation's saved settings keep applying unchanged.
	 * @param	int		$defaultLimit	the daily limit assumed before the option is ever saved
	 * @since	1.0.0
	 */

	public function __construct( $domain, $defaultLimit = 10 )
	{
		$this->domain       = (string) $domain;
		$this->defaultLimit = (int) $defaultLimit;
	}


	/**
	 * @return	string
	 * @since	1.0.0
	 */

	public function optionLimit()
	{
		return( $this->domain . '_daily_limit' );
	}


	/**
	 * @return	string
	 * @since	1.0.0
	 */

	public function optionTrustProxy()
	{
		return( $this->domain . '_trust_proxy' );
	}


	/**
	 * The configured daily limit ( 0 = unlimited ).
	 *
	 * @return	int
	 * @since	1.0.0
	 */

	public function limit()
	{
		$limit = (int) get_option( $this->optionLimit(), $this->defaultLimit );

		return( $limit < 0 ? 0 : $limit );
	}


	/* ============================================================== limits per role ( 1.4.0 ) */


	/** The key of the visitor who is not logged in, in the per-role table. A WordPress role never starts with "_". */
	const GUEST = '_guest';


	/**
	 * @return	string	the option that holds the per-role table, e.g. "spko_role_limits"
	 * @since	1.4.0
	 */

	public function optionRoleLimits()
	{
		return( $this->domain . '_role_limits' );
	}


	/**
	 * Every role a limit can be set for: the guest first, then the roles of this site in their WordPress order.
	 *
	 * ⚠️ THE ROLES OF THE SITE, NOT A FIXED LIST. WooCommerce adds "Customer" and "Shop manager", a membership
	 * plugin adds its own; a fixed list of the five default roles would leave those users with no row and no
	 * way to set their limit.
	 *
	 * @param	string	$guestLabel	the translated name of the guest row, supplied by the plugin
	 * @return	array<string,string>	role => display name
	 * @since	1.4.0
	 */

	public static function roles( $guestLabel )
	{
		$out = array( self::GUEST => (string) $guestLabel );

		if( function_exists( 'wp_roles' ) )
			foreach( wp_roles()->get_names() as $role => $name )
				$out[ (string) $role ] = translate_user_role( (string) $name );

		return( $out );
	}


	/**
	 * The per-role table: role => '' ( unlimited ) or a number ( 0 = no access, n = n per day ).
	 *
	 * ⚠️ BEFORE THE TABLE IS FIRST SAVED, EVERY ROLE GETS THE OLD SINGLE LIMIT. That limit counted every visitor
	 * alike, and an update must not change what a site allows without the operator doing anything. The old meaning
	 * of 0 ( "unlimited" ) becomes '' here, because in the table 0 means "no access".
	 *
	 * @return	array<string,string|int>
	 * @since	1.4.0
	 */

	public function roleLimits()
	{
		$saved = get_option( $this->optionRoleLimits(), null );

		if( is_array( $saved ) )
			return( $saved );

		$old = $this->limit();
		$out = array();

		foreach( array_keys( self::roles( '' ) ) as $role )
			$out[ $role ] = $old === 0 ? '' : $old;

		return( $out );
	}


	/**
	 * The limit of the current visitor: null for unlimited, 0 for no access, n per day.
	 *
	 * ⚠️ A USER WITH SEVERAL ROLES GETS THE MOST GENEROUS ONE. Roles add rights in WordPress; a shop manager who is
	 * also a subscriber must not lose what the shop manager row allows. A role without a row ( added after the table
	 * was saved ) counts like the guest, the most careful choice the operator has already made.
	 *
	 * @return	int|null
	 * @since	1.4.0
	 */

	public function limitForCurrentUser()
	{
		$table = $this->roleLimits();
		$user  = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$roles = ( $user && $user->exists() ) ? array_values( (array) $user->roles ) : array();

		if( $roles === array() )
			$roles = array( self::GUEST );

		$best = 0;

		foreach( $roles as $role )
		{
			$value = array_key_exists( $role, $table ) ? $table[ $role ] : ( $table[ self::GUEST ] ?? '' );

			if( $value === '' || $value === null )
				return( null );

			$best = max( $best, (int) $value );
		}

		return( $best );
	}


	/**
	 * Turns the posted table into what is stored: only known roles, each '' or a whole number from 0 up.
	 *
	 * @param	mixed	$input	the posted array
	 * @return	array<string,string|int>
	 * @since	1.4.0
	 */

	public static function sanitizeRoleLimits( $input )
	{
		$out = array();

		foreach( array_keys( self::roles( '' ) ) as $role )
		{
			$raw = is_array( $input ) && isset( $input[ $role ] ) ? trim( (string) $input[ $role ] ) : '';

			$out[ $role ] = ( $raw === '' || ! is_numeric( $raw ) ) ? '' : max( 0, (int) $raw );
		}

		return( $out );
	}


	/**
	 * The settings field: one row per role with a number field.
	 *
	 * ⚠️ THE TEXTS COME FROM THE PLUGIN. This library has no text domain of its own; a translatable string here would
	 * be found by no plugin's `.pot`. Every plugin passes its own translated strings, written as literal __() calls in
	 * its settings class.
	 *
	 * @param	array	$texts	guest, role, limit, help ( already translated )
	 * @return	void
	 * @since	1.4.0
	 */

	public function renderRoleLimitsField( array $texts )
	{
		$table = $this->roleLimits();
		$name  = $this->optionRoleLimits();

		echo '<table class="widefat striped" style="max-width:32em"><thead><tr><th>' . esc_html( $texts['role'] ?? '' ) . '</th><th>' . esc_html( $texts['limit'] ?? '' ) . '</th></tr></thead><tbody>';

		foreach( self::roles( $texts['guest'] ?? '' ) as $role => $label )
		{
			$value = array_key_exists( $role, $table ) ? (string) $table[ $role ] : (string) ( $table[ self::GUEST ] ?? '' );

			printf(
				'<tr><td>%1$s</td><td><input type="number" min="0" class="small-text" name="%2$s[%3$s]" value="%4$s" /></td></tr>',
				esc_html( $label ),
				esc_attr( $name ),
				esc_attr( $role ),
				esc_attr( $value )
			);
		}

		echo '</tbody></table>';

		if( ! empty( $texts['help'] ) )
			echo '<p class="description">' . esc_html( $texts['help'] ) . '</p>';
	}


	/**
	 * The identifier of the current visitor ( user id or IP based ).
	 *
	 * @return	string
	 * @since	1.0.0
	 */

	public function identifier()
	{
		$user_id = get_current_user_id();

		if( $user_id )
			return( 'user:' . $user_id );

		return( 'ip:' . $this->clientIp() );
	}


	/**
	 * The client IP address ( honours a proxy header only when explicitly enabled ).
	 *
	 * ⚠️ THE PROXY HEADER IS ONLY HONOURED WHEN EXPLICITLY ENABLED. X-Forwarded-For is trivially
	 * forged, so trusting it by default would turn the rate limit into a suggestion. Behind a real
	 * reverse proxy it is the only way to see the visitor at all - hence the setting.
	 *
	 * @return	string
	 * @since	1.0.0
	 */

	public function clientIp()
	{

		// $_SERVER values are unslashed and sanitized before use; the preg_replace() below then keeps
		// only the characters that are valid inside an IPv4 / IPv6 address, so the result is always a
		// safe string.

		$ip = isset( $_SERVER[ 'REMOTE_ADDR' ] )
			? sanitize_text_field( wp_unslash( $_SERVER[ 'REMOTE_ADDR' ] ) )
			: '';

		if( get_option( $this->optionTrustProxy() ) && ! empty( $_SERVER[ 'HTTP_X_FORWARDED_FOR' ] ) )
		{
			/*
			 * ⚠️ THE LAST ENTRY, NOT THE FIRST. A proxy APPENDS the address it saw to whatever header the
			 * client sent, so the first entry is written by the visitor himself and can be anything; with
			 * the first entry a visitor would choose his own counter and never reach the limit. The last
			 * entry is the one the trusted proxy added. EAPI_Free_Quota in einvoice-api follows the same
			 * rule. Behind a chain of several trusted proxies the operator uses the client-IP filter of the
			 * plugin.
			 */
			$forwarded = sanitize_text_field( wp_unslash( $_SERVER[ 'HTTP_X_FORWARDED_FOR' ] ) );
			$parts     = array_values( array_filter( array_map( 'trim', explode( ',', $forwarded ) ), 'strlen' ) );

			if( $parts !== array() )
				$ip = $parts[ count( $parts ) - 1 ];
		}

		// keep only characters valid in IPv4 / IPv6

		$ip = preg_replace( '/[^0-9A-Fa-f:\.]/', '', $ip );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- $this->domain is the prefix of the plugin that uses this shared class, e.g. 'sppv'.
		return( (string) apply_filters( $this->domain . '_client_ip', $ip ) );

	}


	/**
	 * The transient key for the current visitor and day.
	 *
	 * @param	string	$identifier	The visitor identifier.
	 * @return	string
	 * @since	1.0.0
	 */

	private function key( $identifier )
	{
		// the day part ( site timezone ) makes the counter reset every calendar day

		$day = gmdate( 'Ymd', (int) current_time( 'timestamp' ) );

		return( $this->domain . '_rl_' . $day . '_' . substr( wp_hash( $identifier ), 0, 20 ) );
	}


	/**
	 * The current rate-limit status of the visitor.
	 *
	 * @return	array	array{ allowed: bool, limit: int, used: int, remaining: int }
	 * @since	1.0.0
	 */

	public function status()
	{

		$own   = $this->limitForCurrentUser();
		$used  = (int) get_transient( $this->key( $this->identifier() ) );

		// ⚠️ `limit` KEEPS ITS OLD MEANING FOR THE CALLERS: 0 = unlimited. "No access" is told by `reason`, not by
		// the number, so a plugin that shows "x of y left" never shows "x of 0".
		$limit = $own === null ? 0 : $own;

		// ⚠️ ADMINISTRATORS ARE EXEMPT. The limit protects against a public shortcode being hammered
		// by anonymous visitors - a logged-in administrator poking at their own installation ( or
		// running an internal test batch ) is not that threat model and should never see "try again
		// tomorrow". Capability check, not a global toggle - regular visitors are unaffected.
		if( current_user_can( 'manage_options' ) )
			return( array( 'allowed' => true, 'limit' => 0, 'used' => $used, 'remaining' => -1, 'reason' => '' ) );

		if( $own === 0 )
			return( array( 'allowed' => false, 'limit' => 0, 'used' => $used, 'remaining' => 0, 'reason' => 'role' ) );

		$allowed = ( $own === null ) || ( $used < $own );

		return( array(
			'allowed'   => $allowed,
			'limit'     => $limit,
			'used'      => $used,
			'remaining' => ( $own === null ) ? -1 : max( 0, $own - $used ),
			'reason'    => $allowed ? '' : 'limit',
		) );

	}


	/**
	 * Count one successful upload / action for the current visitor.
	 *
	 * @return	void
	 * @since	1.0.0
	 */

	public function increment()
	{

		// ⚠️ Counted only where a number applies: unlimited visitors and administrators leave no transient behind.

		if( $this->limitForCurrentUser() === null || current_user_can( 'manage_options' ) )
			return;

		$key  = $this->key( $this->identifier() );
		$used = (int) get_transient( $key );

		set_transient( $key, $used + 1, DAY_IN_SECONDS );

	}


}

}
