<?php
/**
 * IPPanel driver — pattern delivery when a pattern code exists, otherwise a
 * plain webservice message.
 *
 * Two payload bugs made this driver fail on every request:
 *
 *   1. `sending_type` was sent as `bulk`; the v1 API only knows
 *      `webservice`, `pattern`, `peer_to_peer` and `url`, so the panel answered
 *      an error and the visitor saw "the code was not sent".
 *   2. the sender number was never required, so an account without a configured
 *      line sent `from_number: ""`.
 *
 * The endpoint and the `Authorization` header were already right.
 *
 * @package Signa
 */

namespace Signa\Gateway\Drivers;

use Signa\Gateway\DeliveryRequest;
use Signa\Gateway\GatewayResult;
use Signa\Gateway\HttpGateway;

defined( 'ABSPATH' ) || exit;

final class Ippanel extends HttpGateway {

	const ENDPOINT = 'https://edge.ippanel.com/v1/api/send';

	public function id(): string {
		return 'ippanel';
	}

	public function label(): string {
		return __( 'IPPanel', 'signa' );
	}

	public function docsUrl(): string {
		return 'https://ippanel.com/';
	}

	public function fields(): array {
		return array(
			'ippanel_api_key' => array(
				'label' => __( 'کلید API', 'signa' ),
				'type'  => 'password',
			),
			'ippanel_pattern' => array(
				'label' => __( 'کد پترن', 'signa' ),
				'type'  => 'text',
				'hint'  => __( 'اگر خالی بماند، پیام متنی معمولی ارسال می‌شود.', 'signa' ),
			),
			'ippanel_sender'  => array(
				'label' => __( 'شماره فرستنده', 'signa' ),
				'type'  => 'text',
				'hint'  => __( 'برای هر دو حالت لازم است؛ نمونه: +983000505', 'signa' ),
			),
		);
	}

	public function missing(): array {
		return '' === trim( $this->option( 'ippanel_api_key' ) ) ? array( 'ippanel_api_key' ) : array();
	}

	/**
	 * @return array{mode:string,sender:string,template:string,endpoint:string,issues:string[],notes:string[]}
	 */
	public function plan(): array {
		$pattern = trim( $this->option( 'ippanel_pattern' ) );
		$sender  = trim( $this->option( 'ippanel_sender' ) );
		$issues  = array();

		if ( '' === trim( $this->option( 'ippanel_api_key' ) ) ) {
			$issues[] = __( 'کلید API آی‌پی‌پنل تنظیم نشده است.', 'signa' );
		}

		if ( '' === $sender ) {
			$issues[] = __( 'شماره فرستنده خالی است؛ API آی‌پی‌پنل بدون شماره خط پیام را نمی‌پذیرد.', 'signa' );
		}

		return array(
			'mode'     => '' !== $pattern ? 'pattern' : 'text',
			'sender'   => $sender,
			'template' => $pattern,
			'endpoint' => self::ENDPOINT,
			'issues'   => $issues,
			'notes'    => '' !== $pattern
				? array( __( 'متغیر پترن باید «code» باشد؛ در غیر این صورت نام متغیر را با فیلتر signa_ippanel_param عوض کنید.', 'signa' ) )
				: array(),
		);
	}

	public function deliver( DeliveryRequest $request ): GatewayResult {
		$apiKey = $this->option( 'ippanel_api_key' );

		if ( '' === $apiKey ) {
			return $this->notConfigured( __( 'برای آی‌پی‌پنل کلید API را در تنظیمات کامل کنید.', 'signa' ) );
		}

		$pattern = trim( $this->option( 'ippanel_pattern' ) );
		$sender  = trim( $this->option( 'ippanel_sender' ) );

		if ( '' === $sender ) {
			return $this->notConfigured( __( 'برای آی‌پی‌پنل شماره فرستنده را در تنظیمات وارد کنید.', 'signa' ) );
		}

		$payload = array(
			'from_number' => $sender,
			'recipients'  => array( $request->phone() ),
		);

		if ( '' !== $pattern ) {
			/**
			 * Filter the pattern variable name expected by the IPPanel pattern.
			 *
			 * @param string $name Variable name inside the pattern.
			 */
			$param = (string) apply_filters( 'signa_ippanel_param', 'code' );

			$payload['sending_type'] = 'pattern';
			$payload['code']         = $pattern;
			$payload['params']       = array( $param => $request->code() );
		} else {
			$payload['sending_type'] = 'webservice';
			$payload['message']      = $request->render( $this->settings->str( 'sms_template' ), $this->settings->int( 'code_ttl', 120 ) );
		}

		$response = $this->post(
			self::ENDPOINT,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'Authorization' => $apiKey,
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->transportFailure( $response );
		}

		$status = $this->status( $response );
		$body   = $this->decode( $response );

		if ( in_array( $status, array( 200, 201, 202 ), true ) && ! $this->hasError( $body ) ) {
			return GatewayResult::sent(
				$this->id(),
				$this->referenceFrom( $body, array( 'data.id', 'data.message_id', 'data.ids.0', 'data.message_ids.0', 'data.0' ) ),
				$status,
				array( 'mode' => '' !== $pattern ? 'pattern' : 'text' )
			);
		}

		return GatewayResult::failed(
			$this->id(),
			'' !== $this->errorText( $body ) ? 'rejected' : $this->codeForStatus( $status ),
			$this->errorText( $body ),
			$status,
			array( 'mode' => '' !== $pattern ? 'pattern' : 'text' )
		);
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function hasError( array $body ): bool {
		if ( ! empty( $body['error'] ) ) {
			return true;
		}

		// The pattern endpoint answers `{"code": 422, "message": "…"}` on failure.
		if ( isset( $body['code'] ) && is_numeric( $body['code'] ) && (int) $body['code'] >= 400 ) {
			return true;
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function errorText( array $body ): string {
		foreach ( array( 'error.message', 'error', 'message', 'data.message' ) as $path ) {
			$value = $body;

			foreach ( explode( '.', $path ) as $segment ) {
				if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
					$value = null;
					break;
				}

				$value = $value[ $segment ];
			}

			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return sanitize_text_field( $value );
			}
		}

		return '';
	}
}
