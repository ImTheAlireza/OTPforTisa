<?php
/**
 * FarazSMS driver.
 *
 * The panel is built on IPPanel's infrastructure, and its own documentation
 * ships exactly two working transports:
 *
 *   pattern   POST https://ippanel.com/patterns/pattern
 *             query/body: username, password, from, to=<json array>,
 *                         input_data=<json map>, pattern_code
 *   free text GET  http://sms.farazsms.com/class/sms/webservice/send_url.php
 *             ?from=&to=&msg=&uname=&pass=
 *
 * The previous code posted a bespoke JSON body (`op: pattern`, `inputData` as a
 * map) to a form endpoint, which the panel rejects — "the code was not sent"
 * with no explanation anywhere. The transports below follow the vendor samples
 * and the free-text path keeps a second attempt for accounts whose panel still
 * exposes the IPPanel `api/select` bridge.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Gateway\Drivers;

use TisaOtp\Gateway\DeliveryRequest;
use TisaOtp\Gateway\GatewayResult;
use TisaOtp\Gateway\HttpGateway;

defined( 'ABSPATH' ) || exit;

final class FarazSms extends HttpGateway {

	const PATTERN_ENDPOINT = 'https://ippanel.com/patterns/pattern';
	const SEND_URL_ENDPOINT = 'http://sms.farazsms.com/class/sms/webservice/send_url.php';
	const SELECT_ENDPOINT   = 'http://ippanel.com/api/select';

	public function id(): string {
		return 'faraz';
	}

	public function label(): string {
		return __( 'فراز اس‌ام‌اس', 'tisa-otp' );
	}

	public function docsUrl(): string {
		return 'https://farazsms.com/';
	}

	public function fields(): array {
		return array(
			'faraz_username' => array(
				'label' => __( 'نام کاربری', 'tisa-otp' ),
				'type'  => 'text',
			),
			'faraz_password' => array(
				'label' => __( 'رمز عبور', 'tisa-otp' ),
				'type'  => 'password',
			),
			'faraz_from'     => array(
				'label' => __( 'شماره فرستنده', 'tisa-otp' ),
				'type'  => 'text',
			),
			'faraz_pattern'  => array(
				'label' => __( 'کد پترن', 'tisa-otp' ),
				'type'  => 'text',
				'hint'  => __( 'در صورت تنظیم، ارسال از مسیر پترن انجام می‌شود.', 'tisa-otp' ),
			),
		);
	}

	public function missing(): array {
		$missing = array();

		if ( '' === trim( $this->option( 'faraz_username' ) ) ) {
			$missing[] = 'faraz_username';
		}
		if ( '' === trim( $this->option( 'faraz_password' ) ) ) {
			$missing[] = 'faraz_password';
		}

		return $missing;
	}

	/**
	 * @return array{mode:string,sender:string,template:string,endpoint:string,issues:string[],notes:string[]}
	 */
	public function plan(): array {
		$pattern = trim( $this->option( 'faraz_pattern' ) );
		$sender  = trim( $this->option( 'faraz_from' ) );
		$issues  = array();

		foreach ( $this->missing() as $key ) {
			$label    = isset( $this->fields()[ $key ]['label'] ) ? (string) $this->fields()[ $key ]['label'] : $key;
			$issues[] = sprintf( /* translators: %s: settings field label */ __( 'مقدار «%s» تنظیم نشده است.', 'tisa-otp' ), $label );
		}

		if ( '' === $pattern && '' === $sender ) {
			$issues[] = __( 'برای ارسال متنی، شماره فرستنده لازم است؛ یا کد پترن را وارد کنید.', 'tisa-otp' );
		}

		return array(
			'mode'     => '' !== $pattern ? 'pattern' : 'text',
			'sender'   => '' !== $sender ? $sender : '+983000505',
			'template' => $pattern,
			'endpoint' => '' !== $pattern ? self::PATTERN_ENDPOINT : self::SEND_URL_ENDPOINT,
			'issues'   => $issues,
			'notes'    => array( __( 'نام متغیر داخل پترن باید با کلید ارسالی یکی باشد (پیش‌فرض: verification-code).', 'tisa-otp' ) ),
		);
	}

	public function deliver( DeliveryRequest $request ): GatewayResult {
		if ( array() !== $this->missing() ) {
			return $this->notConfigured( __( 'برای فراز اس‌ام‌اس نام کاربری و رمز را در تنظیمات کامل کنید.', 'tisa-otp' ) );
		}

		$username = $this->option( 'faraz_username' );
		$password = $this->option( 'faraz_password' );
		$pattern  = trim( $this->option( 'faraz_pattern' ) );
		$sender   = trim( $this->option( 'faraz_from' ) );

		if ( '' !== $pattern ) {
			return $this->sendPattern( $request, $username, $password, $pattern, $sender );
		}

		if ( '' === $sender ) {
			return $this->notConfigured( __( 'برای ارسال متنی، شماره فرستنده را وارد کنید یا کد پترن بگذارید.', 'tisa-otp' ) );
		}

		return $this->sendText( $request, $username, $password, $sender );
	}

	/**
	 * Pattern delivery, exactly as the vendor's own PHP sample does it.
	 */
	private function sendPattern( DeliveryRequest $request, string $username, string $password, string $pattern, string $sender ): GatewayResult {
		/**
		 * Filter the pattern variable name used by FarazSMS.
		 *
		 * @param string $key Input key.
		 */
		$inputKey = (string) apply_filters( 'tisa_otp_faraz_pattern_key', 'verification-code' );
		$payload  = array( $inputKey => $request->code() );

		$url = add_query_arg(
			array(
				'username'     => rawurlencode( $username ),
				'password'     => rawurlencode( $password ),
				'from'         => rawurlencode( '' !== $sender ? $sender : '+983000505' ),
				'to'           => rawurlencode( (string) wp_json_encode( array( $request->phone() ) ) ),
				'input_data'   => rawurlencode( (string) wp_json_encode( $payload ) ),
				'pattern_code' => rawurlencode( $pattern ),
			),
			self::PATTERN_ENDPOINT
		);

		$response = $this->post(
			$url,
			array(
				'timeout'     => 12,
				'redirection' => 0,
				'body'        => $payload,
				// The panel wants the payload as it is, with no JSON header.
				'headers'     => array(),
			)
		);

		return $this->evaluateText( $response, 'pattern' );
	}

	/**
	 * Free text over the documented webservice URL, with one fallback bridge.
	 */
	private function sendText( DeliveryRequest $request, string $username, string $password, string $sender ): GatewayResult {
		$message = $request->render( $this->settings->str( 'sms_template' ), $this->settings->int( 'code_ttl', 120 ) );

		$response = $this->get(
			add_query_arg(
				array(
					'from'  => rawurlencode( $sender ),
					'to'    => rawurlencode( $request->phone() ),
					'msg'   => rawurlencode( $message ),
					'uname' => rawurlencode( $username ),
					'pass'  => rawurlencode( $password ),
				),
				self::SEND_URL_ENDPOINT
			),
			array(
				'timeout'     => 12,
				'redirection' => 0,
			)
		);

		$result = $this->evaluateText( $response, 'text' );

		if ( $result->isSent() || $result->isConfigurationProblem() ) {
			return $result;
		}

		// Some panels only expose the IPPanel bridge.
		$bridge = $this->post(
			self::SELECT_ENDPOINT,
			array(
				'timeout' => 12,
				'body'    => wp_json_encode(
					array(
						'op'      => 'send',
						'user'    => $username,
						'pass'    => $password,
						'fromNum' => $sender,
						'toNum'   => $request->phone(),
						'message' => $message,
					)
				),
			)
		);

		return $this->evaluateText( $bridge, 'text' );
	}

	/**
	 * These endpoints answer with a bare tracking id on success and a sentence
	 * (Persian) or a JSON error object on failure — never with a documented
	 * `status` field.
	 *
	 * @param array|\WP_Error $response
	 */
	private function evaluateText( $response, string $mode ): GatewayResult {
		if ( is_wp_error( $response ) ) {
			return $this->transportFailure( $response );
		}

		$status = $this->status( $response );
		$raw    = trim( (string) wp_remote_retrieve_body( $response ) );
		$body   = $this->decode( $response );

		if ( isset( $body['status'] ) && 'OK' === strtoupper( (string) $body['status'] ) ) {
			return GatewayResult::sent( $this->id(), $this->referenceFrom( $body, array( 'data.refId', 'data.id', 'data' ) ), $status, array( 'mode' => $mode ) );
		}

		if ( 200 === $status && '' !== $raw && ! $this->looksLikeFailure( $raw, $body ) ) {
			// A tracking code is short and alphanumeric; a failure sentence is not.
			return GatewayResult::sent( $this->id(), substr( preg_replace( '/[^0-9a-zA-Z\-]/', '', $raw ), 0, 64 ), $status, array( 'mode' => $mode ) );
		}

		$detail = '';

		foreach ( array( 'message', 'error', 'error.message', 'data.message' ) as $path ) {
			$value = $body;
			foreach ( explode( '.', $path ) as $segment ) {
				if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
					$value = null;
					break;
				}
				$value = $value[ $segment ];
			}
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$detail = $value;
				break;
			}
		}

		if ( '' === $detail && '' !== $raw ) {
			$detail = substr( sanitize_text_field( wp_strip_all_tags( $raw ) ), 0, 160 );
		}

		return GatewayResult::failed( $this->id(), $this->codeForStatus( $status ), $detail, $status, array( 'mode' => $mode ) );
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function looksLikeFailure( string $raw, array $body ): bool {
		if ( ! empty( $body['error'] ) ) {
			return true;
		}

		if ( isset( $body['code'] ) && is_numeric( $body['code'] ) && (int) $body['code'] >= 400 ) {
			return true;
		}

		if ( preg_match( '/[^0-9a-zA-Z\-\s]/u', $raw ) ) {
			return true;
		}

		return strlen( $raw ) > 64 && false !== strpos( $raw, ' ' );
	}
}
