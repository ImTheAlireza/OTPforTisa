<?php
/**
 * Picks the configured captcha provider and exposes its client bundle.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Captcha;

use TisaOtp\Config\Settings;
use TisaOtp\Log\Logger;

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
		foreach ( (array) apply_filters( 'tisa_otp_captcha_providers', array() ) as $id => $provider ) {
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
		$labels = array( 'none' => __( 'بدون کپچا', 'tisa-otp' ) );

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
	 * `always` challenges every send, `after_limit` only once quotas tighten.
	 */
	public function trigger(): string {
		$trigger = $this->settings->str( 'captcha_trigger', 'always' );

		return in_array( $trigger, array( 'always', 'after_limit' ), true ) ? $trigger : 'always';
	}

	/**
	 * Everything the browser needs to render and solve the challenge.
	 */
	public function clientBundle(): array {
		$provider = $this->active();

		if ( null === $provider ) {
			return array( 'enabled' => false, 'provider' => 'none' );
		}

		return array(
			'enabled'  => true,
			'provider' => $provider->id(),
			'script'   => $provider->scriptUrl(),
			'config'   => $provider->clientConfig(),
			'trigger'  => $this->trigger(),
		);
	}

	public function verify( string $token, string $ip ): CaptchaResult {
		$provider = $this->active();

		if ( null === $provider ) {
			return CaptchaResult::passed();
		}

		if ( '' === trim( $token ) || strlen( $token ) > 4096 ) {
			return CaptchaResult::failed( 'captcha_missing', __( 'لطفاً ابتدا تأیید کنید که ربات نیستید.', 'tisa-otp' ) );
		}

		return $provider->verify( $token, $ip );
	}
}
