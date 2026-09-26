<?php

namespace Signa\Gateway\Drivers;

use Signa\Gateway\DeliveryRequest;
use Signa\Gateway\GatewayResult;
use Signa\Gateway\HttpGateway;
use Signa\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class MeliPayamak extends HttpGateway {
	const ENDPOINT         = 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';
	const PATTERN_ENDPOINT = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';
	private static $codes = array(
		-111 => array( 'unauthorized', 'IP این سرور برای وب‌سرویس ملی پیامک مجاز نیست.' ),
		-110 => array( 'unauthorized', 'ملی پیامک به‌جای رمز عبور، کلید API (APIKey) می‌خواهد؛ آن را از پنل بسازید و در فیلد رمز بگذارید.' ),
		-109 => array( 'unauthorized', 'برای استفاده از API باید در پنل ملی پیامک IP مجاز تعریف کنید.' ),
		-108 => array( 'unauthorized', 'IP این سرور به‌دلیل تلاش‌های ناموفق در ملی پیامک مسدود شده است؛ با پشتیبانی تماس بگیرید.' ),
		-10  => array( 'rejected', 'در متغیرهای ارسالی لینک وجود دارد.' ),
		-7   => array( 'upstream', 'خطا در شمارهٔ فرستندهٔ ملی پیامک؛ با پشتیبانی تماس بگیرید.' ),
		-6   => array( 'upstream', 'خطای داخلی ملی پیامک؛ کمی بعد دوباره تلاش کنید.' ),
		-5   => array( 'rejected', 'تعداد متغیرها با متن الگو (bodyId) نمی‌خواند؛ الگو باید فقط یک متغیر برای کد داشته باشد.' ),
		-4   => array( 'rejected', 'کد الگو (bodyId) درست نیست یا هنوز در پنل ملی پیامک تأیید نشده است.' ),
		-3   => array( 'rejected', 'خط ارسالی در سیستم ملی پیامک تعریف نشده است؛ با پشتیبانی تماس بگیرید.' ),
		-2   => array( 'rejected', 'محدودیت تعداد شماره؛ هر بار فقط یک گیرنده مجاز است.' ),
		-1   => array( 'unauthorized', 'دسترسی به این وب‌سرویس در حساب ملی پیامک فعال نیست؛ با پشتیبانی تماس بگیرید.' ),
		0    => array( 'unauthorized', 'نام کاربری یا رمز/کلید API ملی پیامک درست نیست.' ),
		2    => array( 'no_credit', 'اعتبار حساب ملی پیامک کافی نیست؛ حساب را شارژ کنید.' ),
		3    => array( 'rate_limited', 'به سقف ارسال روزانهٔ ملی پیامک رسیده‌اید.' ),
		4    => array( 'rate_limited', 'به سقف حجم ارسال ملی پیامک رسیده‌اید.' ),
		5    => array( 'rejected', 'شمارهٔ فرستنده معتبر نیست.' ),
		6    => array( 'upstream', 'سامانهٔ ملی پیامک در حال به‌روزرسانی است؛ کمی بعد دوباره تلاش کنید.' ),
		7    => array( 'rejected', 'متن پیامک کلمهٔ فیلترشده دارد.' ),
		9    => array( 'rejected', 'ارسال از خطوط عمومی با وب‌سرویس ممکن نیست؛ کد الگو (bodyId) را تنظیم کنید تا از خط خدماتی ارسال شود.' ),
		10   => array( 'unauthorized', 'حساب ملی پیامک فعال نیست.' ),
		11   => array( 'upstream', 'پیامک ارسال نشد.' ),
		12   => array( 'unauthorized', 'مدارک حساب ملی پیامک کامل نیست؛ احراز هویت پنل را تکمیل کنید.' ),
		14   => array( 'rejected', 'متن پیامک لینک دارد و ارسال لینک برای حساب شما آزاد نیست.' ),
		15   => array( 'rejected', 'پیامک متنی باید با «لغو11» تمام شود؛ برای کد ورود، کد الگو (bodyId) را تنظیم کنید.' ),
		16   => array( 'invalid_recipient', 'شمارهٔ گیرنده پیدا نشد.' ),
		17   => array( 'rejected', 'متن پیامک خالی است.' ),
		18   => array( 'invalid_recipient', 'شمارهٔ گیرنده نامعتبر است.' ),
		19   => array( 'rate_limited', 'از محدودیت ساعتی ملی پیامک فراتر رفته‌اید.' ),
		35   => array( 'rejected', 'شمارهٔ گیرنده در لیست سیاه مخابرات است؛ فقط از مسیر الگو (bodyId) می‌رسد.' ),
	);

	public function id(): string {
		return 'meli';
	}

	public function label(): string {
		return __( 'ملی پیامک', 'signa' );
	}

	public function docsUrl(): string {
		return 'https://www.melipayamak.com/api/sendbybasenumber2/';
	}

	public function fields(): array {
		return array(
			'meli_username' => array(
				'label' => __( 'نام کاربری', 'signa' ),
				'type'  => 'text',
			),
			'meli_password' => array(
				'label' => __( 'رمز عبور یا کلید API', 'signa' ),
				'type'  => 'password',
				'hint'  => __( 'اگر پنل «الزام استفاده از ApiKey» را فعال کرده، کلید API را بگذارید.', 'signa' ),
			),
			'meli_body_id'  => array(
				'label' => __( 'کد الگو (bodyId)', 'signa' ),
				'type'  => 'text',
				'hint'  => __( 'توصیه‌شده: ارسال از خط خدماتی اشتراکی؛ الگو فقط یک متغیر برای کد داشته باشد.', 'signa' ),
			),
			'meli_from'     => array(
				'label' => __( 'شماره فرستنده', 'signa' ),
				'type'  => 'text',
				'hint'  => __( 'فقط برای ارسال متنی بدون الگو لازم است.', 'signa' ),
			),
		);
	}

	public function missing(): array {
		$missing = array();

		if ( '' === trim( $this->option( 'meli_username' ) ) ) {
			$missing[] = 'meli_username';
		}
		if ( '' === trim( $this->option( 'meli_password' ) ) ) {
			$missing[] = 'meli_password';
		}

		return $missing;
	}

	private function bodyId(): string {
		return (string) preg_replace( '/\D/', '', Phone::latinDigits( trim( $this->option( 'meli_body_id' ) ) ) );
	}

	public function plan(): array {
		$sender = trim( $this->option( 'meli_from' ) );
		$bodyId = $this->bodyId();
		$issues = array();
		$notes  = array();

		foreach ( $this->missing() as $key ) {
			$label    = isset( $this->fields()[ $key ]['label'] ) ? (string) $this->fields()[ $key ]['label'] : $key;
			$issues[] = sprintf(  __( 'مقدار «%s» تنظیم نشده است.', 'signa' ), $label );
		}

		if ( '' !== trim( $this->option( 'meli_body_id' ) ) && '' === $bodyId ) {
			$issues[] = __( 'کد الگو (bodyId) باید عدد باشد؛ همان عددی که پنل ملی پیامک کنار الگو نشان می‌دهد.', 'signa' );
		}

		if ( '' === $bodyId && '' === $sender ) {
			$issues[] = __( 'نه کد الگو تنظیم شده و نه شماره فرستنده؛ ملی پیامک بدون یکی از این دو پیام را رد می‌کند.', 'signa' );
		}

		if ( '' === $bodyId ) {
			$notes[] = __( 'ارسال متنی از خط اختصاصی به «لیست سیاه» نمی‌رسد و ممکن است «لغو11» بخواهد؛ برای کد ورود، کد الگو توصیه می‌شود.', 'signa' );
		}

		return array(
			'mode'     => '' !== $bodyId ? 'pattern' : 'text',
			'sender'   => '' !== $bodyId ? '' : $sender,
			'template' => $bodyId,
			'endpoint' => '' !== $bodyId ? self::PATTERN_ENDPOINT : self::ENDPOINT,
			'issues'   => $issues,
			'notes'    => $notes,
		);
	}

	public function deliver( DeliveryRequest $request ): GatewayResult {
		if ( array() !== $this->missing() ) {
			return $this->notConfigured( __( 'برای ملی پیامک نام کاربری و رمز را در تنظیمات کامل کنید.', 'signa' ) );
		}

		$bodyId = $this->bodyId();

		if ( '' !== $bodyId ) {
			return $this->evaluate(
				$this->post(
					self::PATTERN_ENDPOINT,
					array(
						'headers' => $this->formHeaders(),
						'body'    => array(
							'username' => trim( $this->option( 'meli_username' ) ),
							'password' => trim( $this->option( 'meli_password' ) ),
							'text'     => $request->code(),
							'to'       => $request->phone(),
							'bodyId'   => $bodyId,
						),
					)
				),
				'pattern'
			);
		}

		$sender = trim( $this->option( 'meli_from' ) );

		if ( '' === $sender ) {
			return $this->notConfigured( __( 'برای ملی پیامک کد الگو (bodyId) یا شماره فرستنده را وارد کنید.', 'signa' ) );
		}

		return $this->evaluate(
			$this->post(
				self::ENDPOINT,
				array(
					'headers' => $this->formHeaders(),
					'body'    => array(
						'username' => trim( $this->option( 'meli_username' ) ),
						'password' => trim( $this->option( 'meli_password' ) ),
						'to'       => $request->phone(),
						'from'     => $sender,
						'text'     => $request->render( $this->settings->str( 'sms_template' ), $this->settings->int( 'code_ttl', 120 ) ),
						'isFlash'  => 'false',
					),
				)
			),
			'text'
		);
	}

	private function evaluate( $response, string $mode ): GatewayResult {
		if ( is_wp_error( $response ) ) {
			return $this->transportFailure( $response );
		}

		$status = $this->status( $response );
		$body   = $this->decode( $response );
		$ret    = isset( $body['RetStatus'] ) && is_numeric( $body['RetStatus'] ) ? (int) $body['RetStatus'] : null;
		$value  = isset( $body['Value'] ) && is_scalar( $body['Value'] ) ? trim( (string) $body['Value'] ) : '';
		$words  = isset( $body['StrRetStatus'] ) && is_scalar( $body['StrRetStatus'] ) ? sanitize_text_field( (string) $body['StrRetStatus'] ) : '';

		if ( 200 === $status && 1 === $ret && preg_match( '/^\d{10,}$/', $value ) ) {
			return GatewayResult::sent( $this->id(), $value, $status, array( 'mode' => $mode ) );
		}

		$reason = null;

		if ( preg_match( '/^-?\d{1,4}$/', $value ) && isset( self::$codes[ (int) $value ] ) && ( 1 !== (int) $value ) ) {
			$reason = (int) $value;
		} elseif ( null !== $ret && 1 !== $ret && isset( self::$codes[ $ret ] ) ) {
			$reason = $ret;
		}

		if ( null !== $reason ) {
			return GatewayResult::failed(
				$this->id(),
				self::$codes[ $reason ][0],
				self::$codes[ $reason ][1],
				$status,
				array(
					'mode'       => $mode,
					'api_status' => $reason,
					'reason'     => 'MeliPayamak ' . $reason . ( '' !== $words ? ': ' . $words : '' ),
				)
			);
		}

		if ( 200 === $status && 1 === $ret ) {
			return GatewayResult::sent( $this->id(), substr( (string) preg_replace( '/[^0-9A-Za-z\-]/', '', $value ), 0, 64 ), $status, array( 'mode' => $mode ) );
		}

		return GatewayResult::failed(
			$this->id(),
			200 === $status ? 'rejected' : $this->codeForStatus( $status ),
			'' !== $words ? $words : __( 'ملی پیامک درخواست را نپذیرفت.', 'signa' ),
			$status,
			array(
				'mode'   => $mode,
				'reason' => 'MeliPayamak HTTP ' . $status . ( null !== $ret ? ' RetStatus ' . $ret : '' ) . ( '' !== $value ? ' Value ' . substr( $value, 0, 32 ) : '' ),
			)
		);
	}
}
