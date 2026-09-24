<?php
/**
 * Picks the configured captcha provider and exposes its client bundle.
 *
 * @package Signa
 */

namespace Signa\Captcha;

use Signa\Config\Settings;
use Signa\Log\Logger;

defined( 'ABSPATH' ) || exit;

final class Manager {

	/** @var Settings */
	private $settings;

	/** @var Logger */
	private $logger;

	/** @var array<string,CaptchaProvider>|null */
	private $providers;

	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * @return array<string,CaptchaProvider>
	 */
	public function providers(): array {
		if ( null !== $this->providers ) {
			return $this->providers;
		}

		$this->providers = array(
			'recaptcha_v3' => new RecaptchaV3( $this->settings, $this->logger ),
			'hcaptcha'     => new Hcaptcha( $this->settings, $this->logger ),
			'arcaptcha'    => new Arcaptcha( $this->settings, $this->logger ),
		);

		/**
		 * Register additional captcha providers.
		 *
		 * @param array<string,CaptchaProvider> $providers id => provider.
		 */
		foreach ( (array) apply_filters( 'signa_captcha_providers', array() ) as $id => $provider ) {
			if ( $provider instanceof CaptchaProvider ) {
				$this->providers[ (string) $id ] = $provider;
			}
		}

		return $this->providers;
	}

	/**
	 * @return array<string,string>
	 */
	public function labels(): array {
		$labels = array( 'none' => __( 'بدون کپچا', 'signa' ) );

		foreach ( $this->providers() as $id => $provider ) {
			$labels[ $id ] = $provider->label();
		}

		return $labels;
	}

	public function active(): ?CaptchaProvider {
		$id        = $this->settings->str( 'captcha_provider', 'none' );
		$providers = $this->providers();

		if ( 'none' === $id || ! isset( $providers[ $id ] ) ) {
			return null;
		}

		if ( ! $this->configured() ) {
			return null;
		}

		return $providers[ $id ];
	}

	public function isOn(): bool {
		return null !== $this->active();
	}

	public function configured(): bool {
		return '' !== trim( $this->settings->str( 'captcha_site_key' ) )
			&& '' !== trim( $this->settings->str( 'captcha_secret_key' ) );
	}

	/**
	 * A provider picked but half-configured is the worst state: nothing renders
	 * and nobody knows why. The admin screen asks this to warn early.
	 */
	public function halfConfigured(): bool {
		$id = $this->settings->str( 'captcha_provider', 'none' );

		if ( 'none' === $id || ! isset( $this->providers()[ $id ] ) ) {
			return false;
		}

		return ! $this->configured();
	}

	/**
	 * `always` challenges every send, `after_limit` only once quotas tighten.
	 */
	public function trigger(): string {
		$trigger = $this->settings->str( 'captcha_trigger', 'always' );

		return in_array( $trigger, array( 'always', 'after_limit' ), true ) ? $trigger : 'always';
	}

	/**
	 * When the captcha service itself cannot be reached, should a visitor be let
	 * through (keeping the honeypot and the quota), or locked out?
	 *
	 * Locking out is the wrong trade in Iran, where the largest provider is
	 * routinely unreachable: it turns a spam filter into a total outage of
	 * sign-in. Default is therefore "let them through, and shout in the log".
	 */
	public function failOpen(): bool {
		return $this->settings->bool( 'captcha_fail_open', true );
	}

	/**
	 * Everything the browser needs to render and solve the challenge.
	 *
	 * `scripts` is a list, not a URL: the client walks it until a bundle loads,
	 * which is what makes a blocked or filtered host survivable.
	 */
	public function clientBundle(): array {
		$provider = $this->active();

		if ( null === $provider ) {
			return array(
				'enabled'  => false,
				'provider' => 'none',
				'kind'     => 'none',
				'config'   => array(),
				'scripts'  => array(),
			);
		}

		$config   = $provider->clientConfig();
		$primary  = $provider->scriptUrl();
		$override = trim( $this->settings->str( 'captcha_script_override' ) );
		$scripts  = array();

		// A self-hosted mirror wins when the admin typed one in.
		if ( '' !== $override && ( 0 === strpos( $override, 'https://' ) || 0 === strpos( $override, 'http://' ) ) ) {
			$scripts[] = $override;
		}

		if ( '' !== $primary ) {
			$scripts[] = $primary;
		}

		if ( $provider instanceof ScriptFallbacks ) {
			$scripts = array_merge( $scripts, $provider->fallbackScriptUrls() );
		}

		/**
		 * Filter every script URL offered to the browser, in order.
		 *
		 * @param string[]        $scripts  Script URLs.
		 * @param CaptchaProvider $provider Active provider.
		 */
		$scripts = (array) apply_filters( 'signa_captcha_script_urls', $scripts, $provider );

		return array(
			'enabled'     => true,
			'provider'    => $provider->id(),
			'kind'        => isset( $config['kind'] ) ? (string) $config['kind'] : 'widget',
			'siteKey'     => isset( $config['siteKey'] ) ? (string) $config['siteKey'] : '',
			'script'      => $primary,
			'scripts'     => array_values( array_unique( array_filter( array_map( 'strval', $scripts ) ) ) ),
			'config'      => $config,
			'trigger'     => $this->trigger(),
			'failOpen'    => $this->failOpen(),
			'loadTimeout' => max( 3000, min( 20000, $this->settings->int( 'captcha_timeout', 8000 ) ) ),
		);
	}

	public function verify( string $token, string $ip ): CaptchaResult {
		$provider = $this->active();

		if ( null === $provider ) {
			return CaptchaResult::passed();
		}

		if ( '' === trim( $token ) || strlen( $token ) > 4096 ) {
			return CaptchaResult::failed( 'captcha_missing', __( 'لطفاً ابتدا تأیید کنید که ربات نیستید.', 'signa' ) );
		}

		return $provider->verify( $token, $ip );
	}

	/**
	 * Plain data for the tools screen: what is configured, and what is not.
	 *
	 * @return array<string,mixed>
	 */
	public function diagnostics(): array {
		$id       = $this->settings->str( 'captcha_provider', 'none' );
		$provider = isset( $this->providers()[ $id ] ) ? $this->providers()[ $id ] : null;
		$bundle   = $this->clientBundle();

		return array(
			'provider'       => $id,
			'label'          => null === $provider ? __( 'بدون کپچا', 'signa' ) : $provider->label(),
			'enabled'        => $this->isOn(),
			'halfConfigured' => $this->halfConfigured(),
			'trigger'        => $this->trigger(),
			'failOpen'       => $this->failOpen(),
			'scripts'        => isset( $bundle['scripts'] ) ? $bundle['scripts'] : array(),
			'kind'           => isset( $bundle['kind'] ) ? $bundle['kind'] : 'none',
		);
	}
}
