<?php

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
		return 'https://ippanelcom.github.io/Edge-Document/docs/send/pattern/';
	}

	public function fields(): array {
		return array(
			'ippanel_api_key' => array(
				'label' => __( 'کلید API', 'signa' ),
				'type'  => 'password',
				'hint'  => __( 'از پنل: توسعه‌دهندگان › کلیدهای دسترسی.', 'signa' ),
			),
			'ippanel_pattern' => array(
				'label' => __( 'کد پترن', 'signa' ),
				'type'  => 'text',
				'hint'  => __( 'اگر خالی بماند، پیام متنی معمولی ارسال می‌شود.', 'signa' ),
			),
			'ippanel_param'   => array(
				'label' => __( 'نام متغیر پترن', 'signa' ),
				'type'  => 'text',
				'hint'  => __( 'همان نامی که در متن پترن آمده؛ پیش‌فرض: code', 'signa' ),
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

	public function plan(): array {
		$pattern = trim( $this->option( 'ippanel_pattern' ) );
		$sender  = self::e164Line( $this->option( 'ippanel_sender' ) );
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
				? array(
					sprintf(
						__( 'متغیر پترن «%s» فرستاده می‌شود؛ باید با متغیر داخل متن پترن یکی باشد.', 'signa' ),
						$this->param()
					),
				)
				: array( __( 'بدون پترن، پیامک متنی به شماره‌های «لیست سیاه» نمی‌رسد؛ برای کد ورود، پترن توصیه می‌شود.', 'signa' ) ),
		);
	}

	private function param(): string {
		return (string) apply_filters( 'signa_ippanel_param', $this->paramName( 'ippanel_param', 'code' ) );
	}

	public function deliver( DeliveryRequest $request ): GatewayResult {
		$apiKey = trim( $this->option( 'ippanel_api_key' ) );

		if ( '' === $apiKey ) {
			return $this->notConfigured( __( 'برای آی‌پی‌پنل کلید API را در تنظیمات کامل کنید.', 'signa' ) );
		}

		$pattern = trim( $this->option( 'ippanel_pattern' ) );
		$sender  = self::e164Line( $this->option( 'ippanel_sender' ) );

		if ( '' === $sender ) {
			return $this->notConfigured( __( 'برای آی‌پی‌پنل شماره فرستنده را در تنظیمات وارد کنید.', 'signa' ) );
		}

		$to = self::e164( $request->phone() );

		if ( '' !== $pattern ) {
			$payload = array(
				'sending_type' => 'pattern',
				'from_number'  => $sender,
				'code'         => $pattern,
				'recipients'   => array( $to ),
				'params'       => array( $this->param() => $request->code() ),
			);
		} else {
			$payload = array(
				'sending_type' => 'webservice',
				'from_number'  => $sender,
				'message'      => $request->render( $this->settings->str( 'sms_template' ), $this->settings->int( 'code_ttl', 120 ) ),
				'params'       => array( 'recipients' => array( $to ) ),
			);
		}

		$mode     = '' !== $pattern ? 'pattern' : 'text';
		$response = $this->post(
			self::ENDPOINT,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
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

		if ( $status >= 200 && $status < 300 && ! $this->hasError( $body ) && array() !== $body ) {
			return GatewayResult::sent(
				$this->id(),
				$this->referenceFrom( $body, array( 'data.message_outbox_ids.0', 'data.bulk_id', 'data.id', 'data.message_id' ) ),
				$status,
				array( 'mode' => $mode )
			);
		}

		$text  = $this->errorText( $body );
		$mcode = isset( $body['meta']['message_code'] ) && is_scalar( $body['meta']['message_code'] ) ? (string) $body['meta']['message_code'] : '';
		$code  = $this->codeForStatus( $status );

		if ( '400-1' === $mcode ) {
			$code = 'unauthorized';
		} elseif ( $status >= 200 && $status < 300 ) {
			$code = 'rejected';
		}

		return GatewayResult::failed(
			$this->id(),
			$code,
			'' !== $text ? $text : __( 'آی‌پی‌پنل درخواست را نپذیرفت.', 'signa' ),
			$status,
			array(
				'mode'   => $mode,
				'reason' => 'IPPanel HTTP ' . $status . ( '' !== $mcode ? ' ' . $mcode : '' ) . ( '' !== $text ? ': ' . $text : '' ),
			)
		);
	}

	private function hasError( array $body ): bool {
		if ( isset( $body['meta'] ) && is_array( $body['meta'] ) && array_key_exists( 'status', $body['meta'] ) && false === (bool) $body['meta']['status'] ) {
			return true;
		}

		if ( ! empty( $body['error'] ) ) {
			return true;
		}

		if ( isset( $body['code'] ) && is_numeric( $body['code'] ) && (int) $body['code'] >= 400 ) {
			return true;
		}

		return false;
	}

	private function errorText( array $body ): string {
		foreach ( array( 'meta.message', 'error.message', 'error', 'message', 'data.message' ) as $path ) {
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
