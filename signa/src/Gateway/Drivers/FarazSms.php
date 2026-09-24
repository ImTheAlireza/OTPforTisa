<?php
/**
 * FarazSMS driver.
 *
 * FarazSMS moved its web service to a new platform. The current official
 * documentation (https://farazsms.com/webservice/ → docs.iranpayamak.com)
 * describes a JSON API with an `Api-Key` header:
 *
 *   pattern  POST https://api.iranpayamak.com/ws/v1/sms/pattern
 *            {code, attributes:{name:value}, recipient:"09…",
 *             line_number, number_format:"english"}
 *   text     POST https://api.iranpayamak.com/ws/v1/sms/simple
 *            {text, line_number, recipients:["09…"], number_format, schedule}
 *   answer   {"status":"success"|"error", "data":<id>, "messages":…}
 *
 * That is the route used whenever an API key is set. Accounts still on the
 * old IPPanel-based panel (username + password, no key) keep the legacy
 * transports below, which follow the vendor's old PHP samples:
 *
 *   pattern   POST https://ippanel.com/patterns/pattern
 *   free text GET  http://sms.farazsms.com/class/sms/webservice/send_url.php
 *
 * The legacy routes answer with a bare number; a short one is an error code,
 * not a tracking id (2.0.0 counted both as "sent").
 *
 * @package Signa
 */

namespace Signa\Gateway\Drivers;

use Signa\Gateway\DeliveryRequest;
use Signa\Gateway\GatewayResult;
use Signa\Gateway\HttpGateway;

defined( 'ABSPATH' ) || exit;

final class FarazSms extends HttpGateway {

	const API_PATTERN_ENDPOINT = 'https://api.iranpayamak.com/ws/v1/sms/pattern';
	const API_SIMPLE_ENDPOINT  = 'https://api.iranpayamak.com/ws/v1/sms/simple';

	const PATTERN_ENDPOINT = 'https://ippanel.com/patterns/pattern';
	const SEND_URL_ENDPOINT = 'http://sms.farazsms.com/class/sms/webservice/send_url.php';
	const SELECT_ENDPOINT   = 'http://ippanel.com/api/select';

	public function id(): string {
		return 'faraz';
	}

	public function label(): string {
		return __( 'فراز اس‌ام‌اس', 'signa' );
	}

	public function docsUrl(): string {
		return 'https://docs.iranpayamak.com/send-pattern-based-sms-13925177e0';
	}

	public function fields(): array {
		return array(
			'faraz_api_key'  => array(
				'label' => __( 'کلید API', 'signa' ),
				'type'  => 'password',
				'hint'  => __( 'وب‌سرویس جدید فراز؛ اگر پنل شما کلید API دارد فقط همین کافی است.', 'signa' ),
			),
			'faraz_pattern'  => array(
				'label' => __( 'کد پترن', 'signa' ),
				'type'  => 'text',
				'hint'  => __( 'در صورت تنظیم، ارسال از مسیر پترن انجام می‌شود.', 'signa' ),
			),
			'faraz_param'    => array(
				'label' => __( 'نام متغیر پترن', 'signa' ),
				'type'  => 'text',
				'hint'  => __( 'همان نامی که در متن پترن آمده؛ پیش‌فرض: code (پنل قدیمی: verification-code)', 'signa' ),
			),
			'faraz_from'     => array(
				'label' => __( 'شماره خط فرستنده', 'signa' ),
				'type'  => 'text',
			),
			'faraz_username' => array(
				'label' => __( 'نام کاربری (پنل قدیمی)', 'signa' ),
				'type'  => 'text',
				'hint'  => __( 'فقط برای پنل‌های قدیمی بدون کلید API.', 'signa' ),
			),
			'faraz_password' => array(
				'label' => __( 'رمز عبور (پنل قدیمی)', 'signa' ),
				'type'  => 'password',
			),
		);
	}

	/**
	 * Either an API key (new platform) or username + password (legacy panel).
	 */
	public function missing(): array {
		if ( $this->usesApi() ) {
			return array();
		}

		$missing = array();

		if ( '' === trim( $this->option( 'faraz_username' ) ) && '' === trim( $this->option( 'faraz_password' ) ) ) {
			return array( 'faraz_api_key' );
		}

		if ( '' === trim( $this->option( 'faraz_username' ) ) ) {
			$missing[] = 'faraz_username';
		}
		if ( '' === trim( $this->option( 'faraz_password' ) ) ) {
			$missing[] = 'faraz_password';
		}

		return $missing;
	}

	private function usesApi(): bool {
		return '' !== trim( $this->option( 'faraz_api_key' ) );
	}

	private function param(): string {
		/**
		 * Filter the pattern variable name used by FarazSMS.
		 *
		 * @param string $key Input key.
		 */
		return (string) apply_filters( 'signa_faraz_pattern_key', $this->paramName( 'faraz_param', $this->usesApi() ? 'code' : 'verification-code' ) );
	}

	private function line(): string {
		return trim( \Signa\Support\Phone::latinDigits( $this->option( 'faraz_from' ) ) );
	}

	/**
	 * @return array{mode:string,sender:string,template:string,endpoint:string,issues:string[],notes:string[]}
	 */
	public function plan(): array {
		$pattern = trim( $this->option( 'faraz_pattern' ) );
		$sender  = $this->line();
		$api     = $this->usesApi();
		$issues  = array();
		$notes   = array();

		foreach ( $this->missing() as $key ) {
			$label    = isset( $this->fields()[ $key ]['label'] ) ? (string) $this->fields()[ $key ]['label'] : $key;
			$issues[] = sprintf( /* translators: %s: settings field label */ __( 'مقدار «%s» تنظیم نشده است.', 'signa' ), $label );
		}

		if ( '' === $pattern && '' === $sender ) {
			$issues[] = __( 'برای ارسال متنی، شماره خط لازم است؛ یا کد پترن را وارد کنید.', 'signa' );
		}

		if ( '' !== $pattern ) {
			$notes[] = sprintf(
				/* translators: %s: variable name */
				__( 'متغیر پترن «%s» فرستاده می‌شود؛ باید با متغیر داخل متن پترن یکی باشد.', 'signa' ),
				$this->param()
			);
		}

		if ( ! $api && array() === $this->missing() ) {
			$notes[] = __( 'این حساب با نام کاربری و رمز (پنل قدیمی) وصل است. اگر پنل فراز شما کلید API دارد، آن را وارد کنید تا از وب‌سرویس جدید استفاده شود.', 'signa' );
		}

		if ( $api ) {
			$endpoint = '' !== $pattern ? self::API_PATTERN_ENDPOINT : self::API_SIMPLE_ENDPOINT;
		} else {
			$endpoint = '' !== $pattern ? self::PATTERN_ENDPOINT : self::SEND_URL_ENDPOINT;
		}

		return array(
			'mode'     => '' !== $pattern ? 'pattern' : 'text',
			'sender'   => '' !== $sender ? $sender : ( $api ? '' : '+983000505' ),
			'template' => $pattern,
			'endpoint' => $endpoint,
			'issues'   => $issues,
			'notes'    => $notes,
		);
	}

	public function deliver( DeliveryRequest $request ): GatewayResult {
		if ( array() !== $this->missing() ) {
			return $this->notConfigured( __( 'برای فراز اس‌ام‌اس کلید API (یا در پنل قدیمی، نام کاربری و رمز) را در تنظیمات کامل کنید.', 'signa' ) );
		}

		$pattern = trim( $this->option( 'faraz_pattern' ) );
		$sender  = $this->line();

		if ( $this->usesApi() ) {
			if ( '' === $pattern && '' === $sender ) {
				return $this->notConfigured( __( 'برای ارسال متنی فراز، شماره خط را وارد کنید یا کد پترن بگذارید.', 'signa' ) );
			}

			return $this->sendApi( $request, trim( $this->option( 'faraz_api_key' ) ), $pattern, $sender );
		}

		$username = $this->option( 'faraz_username' );
		$password = $this->option( 'faraz_password' );

		if ( '' !== $pattern ) {
			return $this->sendPattern( $request, $username, $password, $pattern, $sender );
		}

		if ( '' === $sender ) {
			return $this->notConfigured( __( 'برای ارسال متنی، شماره فرستنده را وارد کنید یا کد پترن بگذارید.', 'signa' ) );
		}

		return $this->sendText( $request, $username, $password, $sender );
	}

	/**
	 * The current FarazSMS web service (docs.iranpayamak.com).
	 */
	private function sendApi( DeliveryRequest $request, string $key, string $pattern, string $sender ): GatewayResult {
		if ( '' !== $pattern ) {
			$url     = self::API_PATTERN_ENDPOINT;
			$payload = array(
				'code'          => $pattern,
				'attributes'    => array( $this->param() => $request->code() ),
				'recipient'     => $request->phone(),
				'number_format' => 'english',
			);

			if ( '' !== $sender ) {
				$payload['line_number'] = $sender;
			}
		} else {
			$url     = self::API_SIMPLE_ENDPOINT;
			$payload = array(
				'text'          => $request->render( $this->settings->str( 'sms_template' ), $this->settings->int( 'code_ttl', 120 ) ),
				'line_number'   => $sender,
				'recipients'    => array( $request->phone() ),
				'number_format' => 'english',
				'schedule'      => null,
			);
		}

		$mode     = '' !== $pattern ? 'pattern' : 'text';
		$response = $this->post(
			$url,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'Api-Key'      => $key,
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->transportFailure( $response );
		}

		$status = $this->status( $response );
		$body   = $this->decode( $response );
		$state  = isset( $body['status'] ) && is_scalar( $body['status'] ) ? strtolower( (string) $body['status'] ) : '';

		if ( $status >= 200 && $status < 300 && 'success' === $state ) {
			return GatewayResult::sent( $this->id(), $this->referenceFrom( $body, array( 'data' ) ), $status, array( 'mode' => $mode ) );
		}

		$text = $this->apiMessage( $body );
		$code = $this->codeForStatus( $status );

		if ( $status >= 200 && $status < 300 ) {
			$code = 'rejected';
		} elseif ( 422 === $status && preg_match( '/اعتبار|موجودی|credit|balance/iu', $text ) ) {
			$code = 'no_credit';
		}

		return GatewayResult::failed(
			$this->id(),
			$code,
			'' !== $text ? $text : __( 'فراز اس‌ام‌اس درخواست را نپذیرفت.', 'signa' ),
			$status,
			array(
				'mode'   => $mode,
				'reason' => 'FarazSMS HTTP ' . $status . ( '' !== $text ? ': ' . $text : '' ),
			)
		);
	}

	/**
	 * `messages` is a string, a list, or a field => list map.
	 *
	 * @param array<string,mixed> $body
	 */
	private function apiMessage( array $body ): string {
		foreach ( array( 'messages', 'message', 'errors' ) as $key ) {
			if ( ! isset( $body[ $key ] ) ) {
				continue;
			}

			$value = $body[ $key ];
			$flat  = array();

			array_walk_recursive(
				$value,
				static function ( $item ) use ( &$flat ) {
					if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
						$flat[] = trim( (string) $item );
					}
				}
			);

			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$flat = array( trim( (string) $value ) );
			}

			if ( array() !== $flat ) {
				return substr( sanitize_text_field( implode( ' — ', array_slice( $flat, 0, 3 ) ) ), 0, 240 );
			}
		}

		return '';
	}

	/**
	 * Pattern delivery, exactly as the vendor's own PHP sample does it.
	 */
	private function sendPattern( DeliveryRequest $request, string $username, string $password, string $pattern, string $sender ): GatewayResult {
		$payload = array( $this->param() => $request->code() );

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
			// A tracking id is a long number; a failure sentence is not.
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

		/*
		 * The legacy panels answer errors with a bare, short number (0, 2, -1,
		 * 11…) and success with a long tracking id. 2.0.0 read both as sent,
		 * so an empty account looked healthy while no code arrived.
		 */
		$bare = trim( $raw, " \t\n\r\0\x0B\"[]" );

		if ( preg_match( '/^-?\d+$/', $bare ) && strlen( ltrim( $bare, '-' ) ) < 5 ) {
			return true;
		}

		return strlen( $raw ) > 64 && false !== strpos( $raw, ' ' );
	}
}
