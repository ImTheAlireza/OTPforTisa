<?php
/**
 * What a channel asks a gateway to deliver.
 *
 * @package Signa
 */

namespace Signa\Gateway;

defined( 'ABSPATH' ) || exit;

final class DeliveryRequest {

	/** @var string */
	private $phone;

	/** @var string */
	private $code;

	/** @var string */
	private $channel;

	/** @var array<string,mixed> */
	private $context;

	private function __construct( string $phone, string $code, string $channel, array $context ) {
		$this->phone   = $phone;
		$this->code    = $code;
		$this->channel = $channel;
		$this->context = $context;
	}

	public static function make( string $phone, string $code, string $channel = 'sms', array $context = array() ): self {
		return new self( $phone, $code, $channel, $context );
	}

	public function phone(): string {
		return $this->phone;
	}

	public function code(): string {
		return $this->code;
	}

	public function channel(): string {
		return $this->channel;
	}

	/**
	 * @return mixed
	 */
	public function context( string $key, $default = null ) {
		return array_key_exists( $key, $this->context ) ? $this->context[ $key ] : $default;
	}

	public function all(): array {
		return $this->context;
	}

	public function isTest(): bool {
		return ! empty( $this->context['test'] );
	}

	public function userId(): int {
		return isset( $this->context['user_id'] ) ? (int) $this->context['user_id'] : 0;
	}

	public function with( array $context ): self {
		return new self( $this->phone, $this->code, $this->channel, array_merge( $this->context, $context ) );
	}

	/**
	 * Render a message template with the usual placeholders.
	 */
	public function render( string $template, int $ttlSeconds = 120 ): string {
		$domain = $this->domain();

		$replacements = array(
			'{code}'     => $this->code,
			'{phone}'    => $this->phone,
			'{minutes}'  => (string) max( 1, (int) round( $ttlSeconds / 60 ) ),
			'{site}'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{domain}'   => $domain,
			'{webotp}'   => '' !== $domain ? '@' . $domain . ' #' . $this->code : '',
		);

		/**
		 * Filter placeholder replacements used when rendering a message.
		 *
		 * @param array           $replacements Placeholder map.
		 * @param DeliveryRequest $request      Delivery request.
		 */
		$replacements = (array) apply_filters( 'signa_message_tokens', $replacements, $this );

		return $this->appendWebOtp( strtr( $template, $replacements ), $domain );
	}

	/**
	 * Host name used by the WebOTP binding line, without scheme, port or slashes.
	 */
	private function domain(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Append the `@example.com #12345` line browsers need to autofill the code.
	 *
	 * Only free-text bodies are touched. Pattern/lookup gateways build their own
	 * message server-side, so a line appended here would never reach the handset;
	 * those need the binding added inside the provider's pattern instead.
	 */
	private function appendWebOtp( string $message, string $domain ): string {
		if ( '' === $domain || ! $this->context( 'webotp' ) ) {
			return $message;
		}

		$binding = '@' . $domain;

		if ( false !== strpos( $message, $binding ) ) {
			return $message;
		}

		return trim( $message ) . "\n" . $binding . ' #' . $this->code;
	}
}
