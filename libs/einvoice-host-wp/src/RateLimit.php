<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Shared daily upload and action rate limiter for the eInvoice plugin family.
 *
 * One class instead of four identical copies ( SPEV_Ratelimit, SPX_Ratelimit, SPVX_Ratelimit,
 * SPKV_Ratelimit ): logged-in visitors are tracked by user id, anonymous ones by a hashed IP address,
 * and the counters are transients that reset every calendar day ( site timezone ).
 *
 * ⚠️ THIS CLASS IS INSTANTIATED, NOT STATIC. A static class cannot carry a per-plugin "domain"
 * ( option-name prefix, transient prefix, filter name ) without either near-identical classes per
 * plugin or a parameter threaded through every single call. One small object per plugin, held once in
 * that plugin's own singleton, is the plain solution.
 *
 * @package SpecialPress\EInvoice\Host
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Host;

if ( ! defined( 'ABSPATH' ) )
	exit;


if ( ! class_exists( __NAMESPACE__ . '\\RateLimit' ) )
{

class RateLimit
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

		$limit = $this->limit();
		$used  = (int) get_transient( $this->key( $this->identifier() ) );

		// ⚠️ ADMINISTRATORS ARE EXEMPT. The limit protects against a public shortcode being hammered
		// by anonymous visitors - a logged-in administrator poking at their own installation ( or
		// running an internal test batch ) is not that threat model and should never see "try again
		// tomorrow". Capability check, not a global toggle - regular visitors are unaffected.
		if( current_user_can( 'manage_options' ) )
			return( array( 'allowed' => true, 'limit' => $limit, 'used' => $used, 'remaining' => -1 ) );

		return( array(
			'allowed'   => ( $limit === 0 ) || ( $used < $limit ),
			'limit'     => $limit,
			'used'      => $used,
			'remaining' => ( $limit === 0 ) ? -1 : max( 0, $limit - $used ),
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

		if( $this->limit() === 0 )
			return;

		$key  = $this->key( $this->identifier() );
		$used = (int) get_transient( $key );

		set_transient( $key, $used + 1, DAY_IN_SECONDS );

	}


}

}
