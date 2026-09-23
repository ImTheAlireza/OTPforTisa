<?php
/**
 * The self-tests behind the "test this section" buttons.
 *
 * Every settings tab can prove itself: the general tab checks the ground it
 * stands on (versions, tables, cron), the code tab stores a code for a
 * fictitious number and reads it back, the gateway tab reports what each
 * provider is missing, the security tab says what the captcha would receive in
 * this browser, the registration tab walks the enabled fields, the design tab
 * measures the colours the visitor will actually get, the store tab asks
 * WooCommerce what it has, and the data tab writes an event and finds it again.
 *
 * Two rules hold everywhere in here: nothing is reported as "ok" without having
 * been done, and nothing is reported as "ok" on a guess. A check that cannot run
 * says so and says why — that is more useful than a green tick.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Diagnostics;

use TisaOtp\Captcha\Manager as CaptchaManager;
use TisaOtp\Config\Settings;
use TisaOtp\Cron\Maintenance;
use TisaOtp\Gateway\AccountProbe;
use TisaOtp\Gateway\Registry;
use TisaOtp\Install\Guard;
use TisaOtp\Install\Package;
use TisaOtp\Install\Schema;
use TisaOtp\Log\LogStore;
use TisaOtp\Otp\CodeStore;
use TisaOtp\Otp\OtpService;
use TisaOtp\Registration\FieldCatalog;
use TisaOtp\Registration\FieldSchema;
use TisaOtp\Support\Colour;
use TisaOtp\Support\Transport;
use TisaOtp\Support\Rejection;

defined( 'ABSPATH' ) || exit;

final class SelfTest {

	/**
	 * The number the code test stores against. It is not a real subscriber, and
	 * the code is revoked before this method returns.
	 */
	const SAMPLE_PHONE = '09000000000';

	/** @var Settings */
	private $settings;

	/** @var Schema */
	private $schema;

	/** @var OtpService */
	private $otp;

	/** @var CodeStore */
	private $codes;

	/** @var Registry */
	private $gateways;

	/** @var LogStore */
	private $logs;

	/** @var CaptchaManager */
	private $captcha;

	/** @var FieldSchema */
	private $fields;

	public function __construct(
		Settings $settings,
		Schema $schema,
		OtpService $otp,
		CodeStore $codes,
		Registry $gateways,
		LogStore $logs,
		CaptchaManager $captcha,
		FieldSchema $fields
	) {
		$this->settings = $settings;
		$this->schema   = $schema;
		$this->otp      = $otp;
		$this->codes    = $codes;
		$this->gateways = $gateways;
		$this->logs     = $logs;
		$this->captcha  = $captcha;
		$this->fields   = $fields;
	}

	/**
	 * Which tabs can be tested, in menu order.
	 *
	 * @return string[]
	 */
	public static function kinds(): array {
		return array( 'general', 'code', 'gateways', 'security', 'registration', 'design', 'store', 'data' );
	}

	/**
	 * Run one test and hand back everything the modal needs to draw it.
	 *
	 * @return array<string,mixed>
	 */
	public function run( string $kind ): array {
		$kind = sanitize_key( $kind );

		if ( ! in_array( $kind, self::kinds(), true ) ) {
			throw Rejection::make( 'unknown_check', __( 'این آزمایش شناخته نشد.', 'tisa-otp' ) );
		}

		$result = $this->{$kind}();

		$result['kind'] = $kind;
		$result['ok']   = $this->verdict( $result['rows'] );

		return $result;
	}

	/**
	 * A test failed if any row failed. Warnings do not fail a test: they are
	 * things that deserve a look, not things that are broken.
	 *
	 * @param array<int,array<string,string>> $rows
	 */
	private function verdict( array $rows ): bool {
		foreach ( $rows as $row ) {
			if ( 'fail' === $row['status'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array<string,string>
	 */
	private function row( string $label, string $value, string $status, string $note = '' ): array {
		return array(
			'label'  => $label,
			'value'  => $value,
			'status' => in_array( $status, array( 'ok', 'warn', 'fail', 'info' ), true ) ? $status : 'info',
			'note'   => $note,
		);
	}

	/**
	 * @param array<int,array<string,string>> $rows
	 * @return array<string,mixed>
	 */
	private function result( string $title, string $summary, array $rows ): array {
		return array(
			'title'   => $title,
			'summary' => $summary,
			'rows'    => $rows,
		);
	}

	/* ---------------------------------------------------------------------
	 * عمومی
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,mixed>
	 */
	private function general(): array {
		$rows = array();

		// The bootstrap defines this; the fallback keeps the test readable on its own.
		$min_php = defined( 'TISA_OTP_MIN_PHP' ) ? TISA_OTP_MIN_PHP : '7.4';
		$php_ok  = version_compare( PHP_VERSION, $min_php, '>=' );

		$rows[] = $this->row(
			__( 'نسخه PHP', 'tisa-otp' ),
			PHP_VERSION,
			$php_ok ? 'ok' : 'fail',
			$php_ok ? '' : sprintf( /* translators: %s: minimum PHP version */ __( 'افزونه به PHP %s یا بالاتر نیاز دارد.', 'tisa-otp' ), $min_php )
		);

		$wp_version = get_bloginfo( 'version' );
		$wp_ok      = version_compare( $wp_version, '6.1', '>=' );

		$rows[] = $this->row(
			__( 'نسخه وردپرس', 'tisa-otp' ),
			$wp_version,
			$wp_ok ? 'ok' : 'warn',
			$wp_ok ? '' : __( 'روی ۶.۱ یا بالاتر آزمایش شده است.', 'tisa-otp' )
		);

		/*
		 * Whether the files on disk are one package. This is the row that would
		 * have answered "why has nothing changed?" in one click, and the one
		 * that tells a half-replaced install apart from a working one.
		 */
		$package = Package::verify();
		$offence = Package::offenders( $package );
		$broke   = Guard::failures();

		$rows[] = $this->row(
			__( 'یکپارچگی بستهٔ نصب‌شده', 'tisa-otp' ),
			$package['ok']
				? sprintf( /* translators: %d: number of files checked */ __( 'درست — %s فایل بررسی شد', 'tisa-otp' ), number_format_i18n( $package['checked'] ) )
				: __( 'ناقص', 'tisa-otp' ),
			$package['ok'] ? 'ok' : 'fail',
			! $package['ok']
				? implode( '، ', array_slice( $offence, 0, 5 ) ) . ' — ' . __( 'بستهٔ کامل همین نسخه را از نو نصب کنید (جایگزینی، نه حذف).', 'tisa-otp' )
				: ''
		);

		if ( ! empty( $broke ) ) {
			$ids = array();

			foreach ( $broke as $failure ) {
				$ids[] = $failure['id'];
			}

			$rows[] = $this->row(
				__( 'سرویس‌هایی که بالا نیامدند', 'tisa-otp' ),
				number_format_i18n( count( $ids ) ),
				'fail',
				implode( '، ', $ids ) . ' — ' . __( 'تا وقتی این پیام هست، بخشی از افزونه کار نمی‌کند. بستهٔ کامل را از نو نصب کنید.', 'tisa-otp' )
			);
		}

		$missing = $this->schema->missingTables();

		$rows[] = $this->row(
			__( 'جدول‌های افزونه', 'tisa-otp' ),
			array() === $missing
				? __( 'سالم', 'tisa-otp' )
				: number_format_i18n( count( $missing ) ) . ' ' . __( 'جدول نیست', 'tisa-otp' ),
			array() === $missing ? 'ok' : 'fail',
			array() === $missing ? '' : implode( ', ', $missing ) . ' — ' . __( 'افزونه را یک‌بار غیرفعال و فعال کنید تا ساخته شوند.', 'tisa-otp' )
		);

		$cron = wp_next_scheduled( Maintenance::HOOK );

		$rows[] = $this->row(
			__( 'زمان‌بند پاک‌سازی', 'tisa-otp' ),
			$cron ? wp_date( 'Y-m-d H:i', (int) $cron ) : __( 'ثبت نشده', 'tisa-otp' ),
			$cron ? 'ok' : 'warn',
			$cron ? '' : __( 'پاک‌سازی کدهای منقضی و رویدادهای قدیمی هنوز اجرا نشده است.', 'tisa-otp' )
		);

		$enabled = $this->settings->bool( 'enabled', true );

		$rows[] = $this->row(
			__( 'وضعیت افزونه', 'tisa-otp' ),
			$enabled ? __( 'فعال', 'tisa-otp' ) : __( 'غیرفعال', 'tisa-otp' ),
			$enabled ? 'ok' : 'warn',
			$enabled ? '' : __( 'با افزونهٔ غیرفعال هیچ فرمی روی سایت کار نمی‌کند و API پاسخ نمی‌دهد.', 'tisa-otp' )
		);

		$rows[] = $this->row(
			__( 'حالت احراز', 'tisa-otp' ),
			$this->settings->str( 'auth_mode', 'smart' ),
			'info',
			sprintf( /* translators: 1: flow, 2: channel */ __( 'جریان: %1$s · کانال: %2$s', 'tisa-otp' ), $this->settings->str( 'registration_flow', 'fields_then_code' ), $this->settings->str( 'channel', 'sms' ) )
		);

		$rows[] = $this->row(
			__( 'کش شیء', 'tisa-otp' ),
			wp_using_ext_object_cache() ? __( 'فعال', 'tisa-otp' ) : __( 'غیرفعال', 'tisa-otp' ),
			'info',
			wp_using_ext_object_cache() ? __( 'انبار کدهای موقت روی همین کش نوشته می‌شود، نه در دیتابیس.', 'tisa-otp' ) : ''
		);

		$rows[] = $this->row(
			__( 'نشانی REST', 'tisa-otp' ),
			esc_url_raw( rest_url( 'tisa-otp/v1/' ) ),
			'info',
			__( 'همین نشانی روی سایت باید از مرورگر کاربر قابل دسترس باشد.', 'tisa-otp' )
		);

		return $this->result(
			__( 'آزمایش عمومی', 'tisa-otp' ),
			__( 'نسخه‌ها، جدول‌ها و زمان‌بند.', 'tisa-otp' ),
			$rows
		);
	}

	/* ---------------------------------------------------------------------
	 * کد و کانال‌ها
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,mixed>
	 */
	private function code(): array {
		$rows = array();

		$length = $this->otp->length();
		$ttl    = $this->otp->ttl();

		$rows[] = $this->row( __( 'طول کد', 'tisa-otp' ), number_format_i18n( $length ) . ' ' . __( 'رقم', 'tisa-otp' ), $length >= 4 ? 'ok' : 'warn', $length < 4 ? __( 'کد کمتر از چهار رقم قابل حدس است.', 'tisa-otp' ) : '' );
		$rows[] = $this->row( __( 'اعتبار کد', 'tisa-otp' ), number_format_i18n( $ttl ) . ' ' . __( 'ثانیه', 'tisa-otp' ), 'ok' );
		$rows[] = $this->row( __( 'انبار کد', 'tisa-otp' ), $this->storeLabel(), 'info', $this->settings->str( 'code_store', 'database' ) );

		// The real test: generate, store, find, revoke. No SMS leaves this.
		$code   = $this->otp->generate();
		$record = $this->otp->store( self::SAMPLE_PHONE, $code, 'sms', '127.0.0.1' );

		$pending = $this->otp->isPending( self::SAMPLE_PHONE );
		$left    = $this->otp->secondsLeft( self::SAMPLE_PHONE );

		$this->otp->revoke( self::SAMPLE_PHONE );

		$rows[] = $this->row(
			__( 'ساخت کد', 'tisa-otp' ),
			$code,
			strlen( $code ) === $length ? 'ok' : 'fail',
			sprintf( /* translators: %d: number of digits */ __( '%d رقم، ساخته‌شده با همان تنظیمات همین صفحه.', 'tisa-otp' ), $length )
		);

		$stored_ok = $pending && $left > 0;

		$rows[] = $this->row(
			__( 'ذخیره و بازخوانی', 'tisa-otp' ),
			$stored_ok ? __( 'درست', 'tisa-otp' ) : __( 'ناموفق', 'tisa-otp' ),
			$stored_ok ? 'ok' : 'fail',
			$stored_ok
				? sprintf( /* translators: 1: sample phone, 2: seconds */ __( 'برای شمارهٔ آزمایشی %1$s ذخیره و بی‌درنگ باطل شد؛ %2$s ثانیه اعتبار داشت.', 'tisa-otp' ), self::SAMPLE_PHONE, number_format_i18n( $left ) )
				: __( 'کد ذخیره شد ولی بلافاصله پیدا نشد؛ انبار کد کار نمی‌کند. ورود کاربران در این وضعیت ممکن نیست.', 'tisa-otp' )
		);

		$rows[] = $this->row(
			__( 'شناسهٔ رکورد', 'tisa-otp' ),
			'#' . number_format_i18n( $record->id() ),
			'info',
			sprintf( /* translators: 1: fingerprint, 2: expiry */ __( 'اثر انگشت %1$s · انقضا %2$s', 'tisa-otp' ), substr( $record->fingerprint(), 0, 12 ) . '…', wp_date( 'H:i:s', $record->expiresAt() ) )
		);

		$rows[] = $this->row( __( 'کانال ارسال', 'tisa-otp' ), $this->settings->str( 'channel', 'sms' ), 'info', __( 'کانال آزمایشی همین حالا برای همین مقدار تنظیم شده است.', 'tisa-otp' ) );
		$rows[] = $this->row( __( 'پس از باطل کردن', 'tisa-otp' ), $this->otp->isPending( self::SAMPLE_PHONE ) ? __( 'باز هم فعال', 'tisa-otp' ) : __( 'پاک شد', 'tisa-otp' ), $this->otp->isPending( self::SAMPLE_PHONE ) ? 'warn' : 'ok', __( 'کد آزمایشی نباید در انبار بماند.', 'tisa-otp' ) );

		return $this->result(
			__( 'آزمایش کد یکبارمصرف', 'tisa-otp' ),
			__( 'هیچ پیامکی ارسال نمی‌شود.', 'tisa-otp' ),
			$rows
		);
	}

	private function storeLabel(): string {
		$class = get_class( $this->codes );

		if ( false !== strpos( $class, 'CacheCodeStore' ) ) {
			return __( 'کش شیء', 'tisa-otp' );
		}

		if ( false !== strpos( $class, 'TableCodeStore' ) ) {
			return __( 'جدول اختصاصی', 'tisa-otp' );
		}

		return $class;
	}

	/* ---------------------------------------------------------------------
	 * سامانه‌های پیامکی
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,mixed>
	 */
	private function gateways(): array {
		$rows  = array();
		$chain = $this->gateways->deliveryOrder();

		foreach ( $this->gateways->report() as $id => $gateway ) {
			$role = array();

			if ( $gateway['active'] ) {
				$role[] = __( 'اصلی', 'tisa-otp' );
			}

			if ( $gateway['backup'] ) {
				$role[] = __( 'پشتیبان', 'tisa-otp' );
			}

			$note = array();

			if ( ! $gateway['ready'] ) {
				$note[] = __( 'ناقص:', 'tisa-otp' ) . ' ' . ( $gateway['missing'] ? implode( ', ', $gateway['missing'] ) : __( 'پیکربندی کامل نیست', 'tisa-otp' ) );
			}

			if ( $gateway['resting'] ) {
				$note[] = __( 'در استراحت قطع‌کن مدار', 'tisa-otp' );
			}

			if ( '' !== $gateway['health_text'] ) {
				$note[] = $gateway['health_text'];
			}

			$status = $gateway['ready'] ? ( $gateway['active'] || $gateway['backup'] ? 'ok' : 'info' ) : 'warn';
			$value  = $gateway['ready'] ? ( $gateway['sender'] ? $gateway['sender'] : __( 'آماده', 'tisa-otp' ) ) : __( 'آماده نیست', 'tisa-otp' );

			/*
			 * A gateway whose last send failed is not "ready". Saying "آماده"
			 * next to a failure the owner reported is how this test loses their
			 * trust; the row has to carry the failure and its reason.
			 */
			$health = isset( $gateway['health'] ) ? (array) $gateway['health'] : array();

			if ( $health && empty( $health['ok'] ) ) {
				$status = 'fail';
				$value  = isset( $health['error'] ) && '' !== (string) $health['error'] ? (string) $health['error'] : __( 'ناموفق', 'tisa-otp' );

				if ( ! empty( $health['detail'] ) ) {
					$note[] = (string) $health['detail'];
				}

				if ( ! empty( $health['reason'] ) ) {
					$note[] = (string) $health['reason'];
				}
			}

			$rows[] = $this->row(
				$gateway['label'] . ( $role ? ' — ' . implode( ' / ', $role ) : '' ),
				$value,
				$status,
				implode( ' · ', array_filter( $note ) )
			);
		}

		if ( array() === $rows ) {
			$rows[] = $this->row( __( 'سامانه‌ها', 'tisa-otp' ), __( 'هیچ سامانه‌ای ثبت نشده', 'tisa-otp' ), 'fail', __( 'بدون سامانهٔ پیامکی هیچ کدی ارسال نمی‌شود.', 'tisa-otp' ) );
		}

		$order = array();

		foreach ( $chain as $id ) {
			$report = $this->gateways->report();
			$order[] = isset( $report[ $id ] ) ? $report[ $id ]['label'] : $id;
		}

		$rows[] = $this->row(
			__( 'ترتیب تلاش', 'tisa-otp' ),
			$order ? implode( ' → ', $order ) : __( 'خالی', 'tisa-otp' ),
			$order ? 'ok' : 'fail',
			__( 'اگر سامانهٔ اول خطا بدهد، بعدی امتحان می‌شود.', 'tisa-otp' )
		);

		$channel = $this->settings->str( 'channel', 'sms' );

		$rows[] = $this->row(
			__( 'کانال ارسال کد', 'tisa-otp' ),
			'sms' === $channel ? __( 'پیامک', 'tisa-otp' ) : __( 'ایمیل', 'tisa-otp' ),
			'sms' === $channel ? 'ok' : 'warn',
			'sms' === $channel
				? ''
				: __( 'کدها با ایمیل فرستاده می‌شوند؛ در بخش «کد و کانال‌ها» عوض می‌شود.', 'tisa-otp' )
		);

		$rows[] = $this->reachability( $chain );

		foreach ( $this->account( $chain ) as $account_row ) {
			$rows[] = $account_row;
		}

		$rows[] = $this->row(
			__( 'ارسال واقعی', 'tisa-otp' ),
			__( 'آزمایش جدا', 'tisa-otp' ),
			'info',
			__( 'برای ارسال واقعی، دکمهٔ «ارسال پیامک آزمایشی».', 'tisa-otp' )
		);

		return $this->result(
			__( 'آزمایش سامانه‌های پیامکی', 'tisa-otp' ),
			__( 'آماده / ناقص / خطای واقعی.', 'tisa-otp' ),
			$rows
		);
	}

	/**
	 * Can this server open a connection to the gateway it is configured to use?
	 *
	 * This is the row that answers "the SMS does not arrive" without asking
	 * anybody: it asks the host to resolve the panel's domain and open a socket
	 * to it, and reports what came back. It never sends a message and never
	 * carries credentials, so it is safe to press as often as you like.
	 *
	 * @param string[] $chain Delivery order.
	 * @return array<string,mixed>
	 */
	private function reachability( array $chain ): array {
		$target = '';

		foreach ( $chain as $id ) {
			$plan = $this->gateways->planFor( $id );

			if ( ! empty( $plan['endpoint'] ) && false === strpos( (string) $plan['endpoint'], '…' ) ) {
				$target = (string) $plan['endpoint'];
				break;
			}
		}

		if ( '' === $target ) {
			return $this->row(
				__( 'دسترسی این سرور به سامانه', 'tisa-otp' ),
				__( 'بررسی نشد', 'tisa-otp' ),
				'info',
				__( 'برای این سامانه نشانی قابل بررسی ثبت نشده است.', 'tisa-otp' )
			);
		}

		$host  = (string) wp_parse_url( $target, PHP_URL_HOST );
		$block = $this->settings->bool( 'direct_send', false ) ? null : Transport::blockFailure( $host );

		if ( null !== $block ) {
			return $this->row(
				__( 'دسترسی این سرور به سامانه', 'tisa-otp' ),
				__( 'بسته است', 'tisa-otp' ),
				'fail',
				sprintf(
					/* translators: 1: gateway host, 2: what WordPress answered */
					__( '%1$s — %2$s', 'tisa-otp' ),
					$host,
					$block['message']
				) . ' ' . __( 'یا در تنظیمات › سامانه‌های پیامکی «ارسال مستقیم» را روشن کنید.', 'tisa-otp' )
			);
		}

		$started  = microtime( true );
		$response = wp_remote_get(
			$target,
			array(
				'timeout'             => 8,
				'redirection'         => 0,
				'limit_response_size' => 1024,
				'headers'             => array( 'Accept' => '*/*' ),
				'user-agent'          => 'TisaOTP/' . TISA_OTP_VERSION . '; ' . home_url( '/' ),
			)
		);

		$ms   = (int) round( ( microtime( true ) - $started ) * 1000 );
		$host = (string) wp_parse_url( $target, PHP_URL_HOST );

		$this->logs->write(
			'diagnostic',
			'admin.reachability',
			sprintf( /* translators: 1: host, 2: outcome */ __( 'بررسی دسترسی به %1$s: %2$s', 'tisa-otp' ), $host, is_wp_error( $response ) ? $response->get_error_code() : (string) wp_remote_retrieve_response_code( $response ) ),
			array(
				'service' => $host,
				'ok'      => ! is_wp_error( $response ),
				'ms'      => $ms,
			)
		);

		if ( is_wp_error( $response ) ) {
			$transport = Transport::fromError( $response );

			return $this->row(
				__( 'دسترسی این سرور به سامانه', 'tisa-otp' ),
				$host,
				'fail',
				$ms . ' ' . __( 'میلی‌ثانیه', 'tisa-otp' ) . ' · ' . $transport['reason'] . ' — ' . $transport['message']
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		return $this->row(
			__( 'دسترسی این سرور به سامانه', 'tisa-otp' ),
			$host,
			$status > 0 ? 'ok' : 'warn',
			sprintf(
				/* translators: 1: milliseconds, 2: HTTP status */
				__( '%1$d میلی‌ثانیه · پاسخ HTTP %2$d.', 'tisa-otp' ),
				$ms,
				$status
			)
		);
	}

	/**
	 * Ask the panel about the account rather than about a message.
	 *
	 * The reachability row above proves the network; this one proves the
	 * credentials, the credit and the line — read-only, no message, no charge.
	 * A driver answers it only if it implements AccountProbe, so a third-party
	 * driver written before this existed keeps working and simply reports that
	 * it cannot be asked.
	 *
	 * @param string[] $chain Delivery order.
	 * @return array<int,array<string,string>>
	 */
	private function account( array $chain ): array {
		$driver = null;

		foreach ( $chain as $id ) {
			$candidate = $this->gateways->find( $id );

			if ( $candidate instanceof AccountProbe ) {
				$driver = $candidate;
				break;
			}
		}

		if ( null === $driver ) {
			return array(
				$this->row(
					__( 'کلید API و اعتبار', 'tisa-otp' ),
					__( 'بررسی نشد', 'tisa-otp' ),
					'info',
					__( 'این سامانه امکان بررسی حساب را ندارد.', 'tisa-otp' )
				),
			);
		}

		$probe = $driver->probe();

		if ( ! empty( $probe['error_code'] ) && 'rejected' !== $probe['error_code'] ) {
			$labels = array(
				'not_configured' => __( 'تنظیم نشده', 'tisa-otp' ),
				'unauthorized'   => __( 'رد شد', 'tisa-otp' ),
				'no_credit'      => __( 'اعتبار تمام', 'tisa-otp' ),
				'rate_limited'   => __( 'محدود شده', 'tisa-otp' ),
				'transport'      => __( 'وصل نشد', 'tisa-otp' ),
			);

			return array(
				$this->row(
					__( 'کلید API و اعتبار', 'tisa-otp' ),
					isset( $labels[ $probe['error_code'] ] ) ? $labels[ $probe['error_code'] ] : __( 'بررسی نشد', 'tisa-otp' ),
					'not_configured' === $probe['error_code'] ? 'warn' : 'fail',
					trim( (string) $probe['reason'] . ' — ' . (string) $probe['message'], ' —' )
				),
			);
		}

		$rows = array();
		$rows[] = $this->row(
			__( 'کلید API و اعتبار', 'tisa-otp' ),
			null === $probe['credit']
				? __( 'پذیرفته شد', 'tisa-otp' )
				: sprintf( /* translators: %s: account credit */ __( 'پذیرفته شد · اعتبار %s', 'tisa-otp' ), self::amount( (float) $probe['credit'] ) ),
			'ok',
			(string) $probe['reason']
		);

		$sender = (string) $probe['sender'];

		if ( '' === $sender ) {
			$rows[] = $this->row(
				__( 'شماره خط', 'tisa-otp' ),
				__( 'تنظیم نشده', 'tisa-otp' ),
				'info',
				__( 'ارسال با الگو به شماره خط نیاز ندارد.', 'tisa-otp' )
			);

			return $rows;
		}

		$known = $probe['sender_ok'];
		$lines = is_array( $probe['lines'] ) ? implode( ' · ', array_map( 'strval', $probe['lines'] ) ) : '';
		$note  = '' !== $lines
			? sprintf( /* translators: %s: line numbers of the account */ __( 'خط‌های این حساب: %s', 'tisa-otp' ), $lines )
			: (string) $probe['message'];

		$rows[] = $this->row(
			__( 'شماره خط', 'tisa-otp' ),
			$sender,
			true === $known ? 'ok' : ( false === $known ? 'warn' : 'info' ),
			trim( ( false === $known ? (string) $probe['message'] . ' ' : '' ) . $note )
		);

		return $rows;
	}

	/**
	 * `165.3` rather than `165.30`, with a thousands separator an Iranian owner
	 * reads without stopping.
	 */
	private static function amount( float $value ): string {
		$text = number_format( $value, 2, '.', '٬' );

		return rtrim( rtrim( $text, '0' ), '.' );
	}

	/* ---------------------------------------------------------------------
	 * امنیت و محدودیت
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,mixed>
	 */
	private function security(): array {
		$rows     = array();
		$captcha  = $this->captcha->diagnostics();
		$key      = $this->settings->str( 'captcha_site_key' );
		$secret   = $this->settings->str( 'captcha_secret_key' );

		$rows[] = $this->row(
			__( 'سرویس کپچا', 'tisa-otp' ),
			$captcha['label'],
			$captcha['enabled'] ? 'ok' : 'info',
			$captcha['enabled'] ? '' : __( 'کپچا خاموش است؛ هانی‌پات و سقف ارسال همچنان کار می‌کنند.', 'tisa-otp' )
		);

		if ( $captcha['enabled'] ) {
			$rows[] = $this->row(
				__( 'کلید سایت', 'tisa-otp' ),
				'' === trim( $key ) ? __( 'خالی', 'tisa-otp' ) : $this->mask( $key ),
				'' === trim( $key ) ? 'fail' : 'ok',
				'' === trim( $key ) ? __( 'بدون کلید سایت، ویجت کپچا روی فرم ظاهر نمی‌شود.', 'tisa-otp' ) : ''
			);

			$rows[] = $this->row(
				__( 'کلید مخفی', 'tisa-otp' ),
				'' === trim( $secret ) ? __( 'خالی', 'tisa-otp' ) : __( 'ثبت شده', 'tisa-otp' ),
				'' === trim( $secret ) ? 'fail' : 'ok',
				'' === trim( $secret ) ? __( 'بدون کلید مخفی، پاسخ کاربر در سرور تأیید نمی‌شود و همه رد می‌شوند.', 'tisa-otp' ) : ''
			);
		}

		if ( $captcha['halfConfigured'] ) {
			$rows[] = $this->row( __( 'پیکربندی نیمه‌کاره', 'tisa-otp' ), __( 'بله', 'tisa-otp' ), 'fail', __( 'تنها یکی از دو کلید پر شده است؛ ویجت و تأیید سروری هر دو می‌شکنند.', 'tisa-otp' ) );
		}

		$rows[] = $this->row(
			__( 'زمان نمایش', 'tisa-otp' ),
			$this->triggerLabel( $captcha['trigger'] ),
			'info',
			__( 'کپچا می‌تواند از همان اول دیده شود یا بعد از چند تلاش مشکوک.', 'tisa-otp' )
		);

		$override = trim( $this->settings->str( 'captcha_script_override' ) );

		$rows[] = $this->row(
			__( 'نشانی جایگزین اسکریپت', 'tisa-otp' ),
			'' === $override ? __( 'خالی', 'tisa-otp' ) : $override,
			'info',
			'' === $override ? __( 'اگر اسکریپت رسمی روی سایت باز نشود، می‌توانید آینهٔ خودتان را اینجا بگذارید.', 'tisa-otp' ) : __( 'اول این نشانی امتحان می‌شود، بعد نشانی رسمی.', 'tisa-otp' )
		);

		$rows[] = $this->row(
			__( 'فهرست اسکریپت‌ها', 'tisa-otp' ),
			number_format_i18n( count( $captcha['scripts'] ) ) . ' ' . __( 'نشانی', 'tisa-otp' ),
			count( $captcha['scripts'] ) > 0 ? 'ok' : 'fail',
			$captcha['scripts'] ? implode( ' · ', $captcha['scripts'] ) : __( 'هیچ نشانی‌ای برای بارگذاری نیست؛ یعنی ویجت هیچ‌وقت نمی‌آید.', 'tisa-otp' )
		);

		$rows[] = $this->row(
			__( 'اگر سرویس قطع باشد', 'tisa-otp' ),
			$captcha['failOpen'] ? __( 'ورود بسته نمی‌شود', 'tisa-otp' ) : __( 'ورود بسته می‌شود', 'tisa-otp' ),
			$captcha['failOpen'] ? 'ok' : 'warn',
			$captcha['failOpen']
				? __( 'هانی‌پات و سقف ارسال فعال می‌مانند و رویداد captcha.fail_open ثبت می‌شود.', 'tisa-otp' )
				: __( 'با قطعی سرویس کپچا، هیچ ورودی‌ای پذیرفته نمی‌شود.', 'tisa-otp' )
		);

		/*
		 * What the guard has actually been doing. A captcha that never renders
		 * is not a theory: it shows up as `guard.rejected / captcha_missing` in
		 * the events, one row per visitor who tried to log in. Counting those
		 * rows here turns "the captcha does not load" into a number and a fix.
		 */
		$rejected = $this->captchaRejects( 7 );

		if ( $rejected['total'] > 0 ) {
			$rows[] = $this->row(
				__( 'ردشدن به‌خاطر کپچا (۷ روز)', 'tisa-otp' ),
				number_format_i18n( $rejected['total'] ) . ' ' . __( 'درخواست', 'tisa-otp' ),
				$rejected['captcha'] > 0 ? 'fail' : 'warn',
				$rejected['captcha'] > 0
					? sprintf(
						/* translators: %d: number of requests */
						__( '%d درخواست بدون توکن کپچا رسیده است؛ ویجت در مرورگر کاربران بارگذاری نشده. جایگزین: نشانی اسکریپت یا خاموش کردن کپچا.', 'tisa-otp' ),
						$rejected['captcha']
					)
					: __( 'هیچ‌کدام به‌خاطر نبود توکن کپچا نبوده؛ محدودیت‌های دیگر کاربران را رد کرده‌اند.', 'tisa-otp' )
			);
		}

		$rows[] = $this->row(
			__( 'نمایش در این مرورگر', 'tisa-otp' ),
			__( 'در همین پنجره ادامه دارد…', 'tisa-otp' ),
			'info',
			__( 'ادامه در همین پنجره و در مرورگر شما.', 'tisa-otp' )
		);

		return $this->result(
			__( 'آزمایش امنیت و کپچا', 'tisa-otp' ),
			__( 'از سمت سرور و در همین مرورگر.', 'tisa-otp' ),
			$rows
		);
	}

	/**
	 * How many requests the guard turned away, and how many of them because the
	 * captcha token was never there.
	 *
	 * @param int $days Window.
	 * @return array{total:int,captcha:int}
	 */
	private function captchaRejects( int $days ): array {
		$tally = $this->logs->tally( 'guard.rejected', $days );
		$total = 0;

		foreach ( (array) $tally as $count ) {
			$total += (int) $count;
		}

		$captcha = 0;

		foreach ( $this->logs->query(
			array(
				'event' => 'guard.rejected',
				'hours' => $days * 24,
				'limit' => 500,
			)
		) as $row ) {
			if ( isset( $row->error_code ) && 'captcha_missing' === $row->error_code ) {
				$captcha++;
			}
		}

		return array(
			'total'   => $total,
			'captcha' => $captcha,
		);
	}

	private function triggerLabel( string $trigger ): string {
		$labels = array(
			'always'  => __( 'همیشه', 'tisa-otp' ),
			'suspect' => __( 'بعد از رفتار مشکوک', 'tisa-otp' ),
			'auto'    => __( 'خودکار', 'tisa-otp' ),
			'none'    => __( 'هیچ‌وقت', 'tisa-otp' ),
		);

		return isset( $labels[ $trigger ] ) ? $labels[ $trigger ] : $trigger;
	}

	/**
	 * Show the shape of a key without handing it back.
	 */
	private function mask( string $key ): string {
		$key = trim( $key );

		if ( strlen( $key ) <= 8 ) {
			return str_repeat( '•', strlen( $key ) );
		}

		return substr( $key, 0, 4 ) . str_repeat( '•', 6 ) . substr( $key, -4 );
	}

	/* ---------------------------------------------------------------------
	 * فرم عضویت
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,mixed>
	 */
	private function registration(): array {
		$rows  = array();
		$flow  = $this->settings->str( 'registration_flow', 'fields_then_code' );
		$shown = $this->settings->bool( 'registration_enabled', true );

		$rows[] = $this->row(
			__( 'فرم عضویت', 'tisa-otp' ),
			$shown ? __( 'روشن', 'tisa-otp' ) : __( 'خاموش', 'tisa-otp' ),
			'info',
			$shown ? '' : __( 'شمارهٔ تازه، حساب جدید نمی‌سازد.', 'tisa-otp' )
		);

		$rows[] = $this->row(
			__( 'ترتیب گام‌ها', 'tisa-otp' ),
			$this->fields->isCodeFirst() ? __( 'اول کد، بعد مشخصات', 'tisa-otp' ) : __( 'اول مشخصات، بعد کد', 'tisa-otp' ),
			'ok',
			$flow
		);

		$active = $this->fields->active();
		$labels = FieldCatalog::labels();
		$known  = array_keys( FieldCatalog::presets() );

		$names     = array();
		$required  = array();
		$unknown   = array();

		foreach ( $active as $field ) {
			$id    = (string) $field['id'];
			$names[] = isset( $labels[ $id ] ) ? $labels[ $id ] : $id;

			if ( ! empty( $field['required'] ) ) {
				$required[] = isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
			}

			if ( ! in_array( $id, $known, true ) ) {
				$unknown[] = $id;
			}
		}

		$rows[] = $this->row(
			__( 'فیلدهای فعال', 'tisa-otp' ),
			$names ? implode( ' · ', $names ) : __( 'هیچ فیلدی', 'tisa-otp' ),
			$names ? 'ok' : 'warn',
			$names ? '' : __( 'فرم عضویت بدون هیچ فیلد اضافه‌ای ثبت می‌شود.', 'tisa-otp' )
		);

		$rows[] = $this->row( __( 'فیلدهای اجباری', 'tisa-otp' ), $required ? implode( ' · ', $required ) : __( 'هیچ‌کدام', 'tisa-otp' ), 'info' );

		$rows[] = $this->row(
			__( 'کلید متای شماره', 'tisa-otp' ),
			$this->settings->str( 'phone_meta_key', 'tisa_phone' ),
			'' === trim( $this->settings->str( 'phone_meta_key', 'tisa_phone' ) ) ? 'fail' : 'ok',
			__( 'شمارهٔ تأییدشده در همین کلید پروفایل ذخیره می‌شود.', 'tisa-otp' )
		);

		$lookup = $this->settings->items( 'lookup_meta_keys' );

		$rows[] = $this->row(
			__( 'کلیدهای جست‌وجو', 'tisa-otp' ),
			$lookup ? implode( ', ', $lookup ) : __( 'خالی', 'tisa-otp' ),
			$lookup ? 'ok' : 'warn',
			$lookup ? __( 'برای پیدا کردن حساب‌های قدیمیِ همین سایت.', 'tisa-otp' ) : __( 'کاربر قدیمی با کلید دیگری پیدا نمی‌شود.', 'tisa-otp' )
		);

		if ( $unknown ) {
			$rows[] = $this->row( __( 'فیلد ناشناس', 'tisa-otp' ), implode( ' · ', $unknown ), 'fail', __( 'این شناسه‌ها در فهرست فیلدهای شناخته‌شده نیستند و ممکن است ذخیره نشوند.', 'tisa-otp' ) );
		}

		$rows[] = $this->row(
			__( 'نام کاربری', 'tisa-otp' ),
			$this->settings->str( 'username_from', 'phone' ),
			'info',
			__( 'ایمیل به‌عنوان نام کاربری یا برای بازیابی استفاده می‌شود.', 'tisa-otp' )
		);

		return $this->result(
			__( 'آزمایش فرم عضویت', 'tisa-otp' ),
			__( 'گام‌ها، فیلدها و مقصد ذخیره.', 'tisa-otp' ),
			$rows
		);
	}

	/* ---------------------------------------------------------------------
	 * ظاهر فرم
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,mixed>
	 */
	private function design(): array {
		$rows   = array();
		$accent = $this->settings->str( 'accent', '#0f766e' );
		$surface = $this->settings->str( 'surface', '#ffffff' );

		$pairs = array(
			array(
				__( 'رنگ تأکید روی کارت سفید', 'tisa-otp' ),
				$accent,
				'#ffffff',
				__( 'بلندی، پیوندها و دکمه‌های متن‌دار.', 'tisa-otp' ),
			),
			array(
				__( 'متن سفید روی رنگ تأکید', 'tisa-otp' ),
				'#ffffff',
				$accent,
				__( 'دکمهٔ اصلی «دریافت کد» با همین رنگ ساخته می‌شود.', 'tisa-otp' ),
			),
			array(
				__( 'متن روی پس‌زمینهٔ فرم', 'tisa-otp' ),
				'#101828',
				$surface,
				__( 'پس‌زمینه‌ای که برای کارت فرم انتخاب کرده‌اید.', 'tisa-otp' ),
			),
		);

		foreach ( $pairs as $pair ) {
			$ratio = Colour::ratio( $pair[1], $pair[2] );

			if ( null === $ratio ) {
				$rows[] = $this->row( $pair[0], __( 'قابل‌خواندن نبود', 'tisa-otp' ), 'warn', sprintf( /* translators: 1: colour, 2: colour */ __( 'رنگ‌های %1$s و %2$s شناسایی نشدند.', 'tisa-otp' ), $pair[1], $pair[2] ) );
				continue;
			}

			$pass = $ratio >= 4.5;

			$rows[] = $this->row(
				$pair[0],
				number_format_i18n( $ratio, 2 ) . ':1',
				$pass ? 'ok' : 'fail',
				$pass
					? ( '' !== $pair[3] ? $pair[3] : '' )
					: sprintf( /* translators: %s: minimum ratio */ __( 'کمتر از %s:1 — متن سخت خوانده می‌شود. یک رنگ تأکید تیره‌تر انتخاب کنید.', 'tisa-otp' ), '4.5' )
			);
		}

		$rows[] = $this->row( __( 'رنگ تأکید', 'tisa-otp' ), $accent, 'info', __( 'هم دکمه‌ها، هم گرادیان‌ها و هم حالت هاور از همین رنگ ساخته می‌شوند.', 'tisa-otp' ) );
		$rows[] = $this->row( __( 'پس‌زمینهٔ فرم', 'tisa-otp' ), $surface, 'info' );
		$rows[] = $this->row( __( 'گردی گوشه‌ها', 'tisa-otp' ), number_format_i18n( $this->settings->int( 'radius', 14 ) ) . ' px', 'info' );
		$rows[] = $this->row( __( 'عرض فرم', 'tisa-otp' ), number_format_i18n( $this->settings->int( 'width', 420 ) ) . ' px', 'info' );

		return $this->result(
			__( 'آزمایش ظاهر فرم', 'tisa-otp' ),
			__( 'رنگ‌های واقعی فرم و نسبت کنتراست.', 'tisa-otp' ),
			$rows
		);
	}

	/* ---------------------------------------------------------------------
	 * فروشگاه
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,mixed>
	 */
	private function store(): array {
		$rows = array();
		$woo  = class_exists( 'WooCommerce' );

		$rows[] = $this->row(
			__( 'ووکامرس', 'tisa-otp' ),
			$woo ? ( defined( 'WC_VERSION' ) ? WC_VERSION : __( 'فعال', 'tisa-otp' ) ) : __( 'فعال نیست', 'tisa-otp' ),
			$woo ? 'ok' : 'warn',
			$woo ? '' : __( 'تنظیمات این بخش تا نصب ووکامرس اثری ندارند.', 'tisa-otp' )
		);

		if ( $woo ) {
			$rows[] = $this->row(
				__( 'فرم حساب کاربری', 'tisa-otp' ),
				$this->settings->bool( 'woo_account_form', true ) ? __( 'جایگزین می‌شود', 'tisa-otp' ) : __( 'دست‌نخورده', 'tisa-otp' ),
				'info',
				__( 'فرم ورود ووکامرس در صفحهٔ «حساب کاربری» با فرم OTP عوض می‌شود.', 'tisa-otp' )
			);

			$rows[] = $this->row(
				__( 'ورود پیش از تسویه', 'tisa-otp' ),
				$this->settings->bool( 'woo_checkout_gate', true ) ? __( 'اجباری', 'tisa-otp' ) : __( 'اختیاری', 'tisa-otp' ),
				'info'
			);

			$rows[] = $this->row(
				__( 'همگام‌سازی شماره صورتحساب', 'tisa-otp' ),
				$this->settings->bool( 'sync_billing_phone', true ) ? __( 'روشن', 'tisa-otp' ) : __( 'خاموش', 'tisa-otp' ),
				$this->settings->bool( 'sync_billing_phone', true ) ? 'ok' : 'info',
				$this->settings->bool( 'sync_billing_phone', true ) ? __( 'شمارهٔ تأییدشده در billing_phone هم نوشته می‌شود.', 'tisa-otp' ) : ''
			);

			$rows[] = $this->row(
				__( 'اتصال سفارش‌های مهمان', 'tisa-otp' ),
				$this->settings->bool( 'link_guest_orders', true ) ? __( 'روشن', 'tisa-otp' ) : __( 'خاموش', 'tisa-otp' ),
				'info',
				$this->settings->bool( 'link_guest_orders', true ) ? __( 'سفارش‌های قدیمی با همان شماره به حساب تازه وصل می‌شوند.', 'tisa-otp' ) : ''
			);

			$rows[] = $this->row(
				__( 'شمارهٔ صورتحساب در سایت', 'tisa-otp' ),
				$this->hasBillingPhone() ? __( 'پیدا شد', 'tisa-otp' ) : __( 'پیدا نشد', 'tisa-otp' ),
				$this->hasBillingPhone() ? 'ok' : 'warn',
				$this->hasBillingPhone() ? '' : __( 'هیچ کاربری کلید billing_phone ندارد؛ اگر ووکامرس تازه نصب شده این طبیعی است.', 'tisa-otp' )
			);
		}

		return $this->result(
			__( 'آزمایش فروشگاه', 'tisa-otp' ),
			__( 'وضعیت ووکامرس و اثر تنظیمات.', 'tisa-otp' ),
			$rows
		);
	}

	private function hasBillingPhone(): bool {
		global $wpdb;

		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT user_id FROM ' . $wpdb->usermeta . ' WHERE meta_key = %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'billing_phone'
			)
		);

		return null !== $found;
	}

	/* ---------------------------------------------------------------------
	 * داده و رویدادها
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,mixed>
	 */
	private function data(): array {
		$rows    = array();
		$enabled = $this->settings->bool( 'logs_enabled', true );

		$rows[] = $this->row(
			__( 'ثبت رویدادها', 'tisa-otp' ),
			$enabled ? __( 'روشن', 'tisa-otp' ) : __( 'خاموش', 'tisa-otp' ),
			$enabled ? 'ok' : 'warn',
			$enabled ? '' : __( 'با ثبت خاموش، هیچ رویدادی نوشته نمی‌شود و گزارش‌ها خالی می‌مانند.', 'tisa-otp' )
		);

		$missing = $this->schema->missingTables();

		$rows[] = $this->row(
			__( 'جدول رویدادها', 'tisa-otp' ),
			in_array( $this->logs->table(), $missing, true ) ? __( 'ساخته نشده', 'tisa-otp' ) : __( 'موجود', 'tisa-otp' ),
			in_array( $this->logs->table(), $missing, true ) ? 'fail' : 'ok',
			$this->logs->table()
		);

		$before = $this->logs->totals();

		$rows[] = $this->row(
			__( 'رویدادهای ثبت‌شده', 'tisa-otp' ),
			number_format_i18n( (int) $before['total'] ),
			'info',
			sprintf( /* translators: %s: number of errors */ __( '%s موردش خطا بوده است.', 'tisa-otp' ), number_format_i18n( (int) $before['errors'] ) )
		);

		$rows[] = $this->row(
			__( 'نگهداری', 'tisa-otp' ),
			number_format_i18n( $this->settings->int( 'logs_keep_days', 7 ) ) . ' ' . __( 'روز', 'tisa-otp' ),
			'info',
			__( 'رویدادهای قدیمی‌تر در پاک‌سازی روزانه حذف می‌شوند.', 'tisa-otp' )
		);

		$rows[] = $this->row( __( 'حالت اشکال‌زدایی', 'tisa-otp' ), $this->settings->bool( 'debug', false ) ? __( 'روشن', 'tisa-otp' ) : __( 'خاموش', 'tisa-otp' ), 'info', __( 'در حالت روشن، هر رویداد در error_log هم نوشته می‌شود.', 'tisa-otp' ) );

		// The real test: write one event and find it again.
		$event  = 'admin.self_test';
		$wrote  = $this->logs->write( 'debug', $event, __( 'آزمایش خودکار پیشخوان: اگر این خط را می‌بینید، ثبت رویداد کار می‌کند.', 'tisa-otp' ), array( 'channel' => 'admin' ) );
		$found  = $wrote ? $this->logs->count( array( 'event' => $event, 'hours' => 1 ) ) : 0;

		$round_trip = $wrote && $found > 0;

		$rows[] = $this->row(
			__( 'نوشتن و خواندن', 'tisa-otp' ),
			$round_trip ? __( 'درست', 'tisa-otp' ) : ( $enabled ? __( 'ناموفق', 'tisa-otp' ) : __( 'اجرا نشد', 'tisa-otp' ) ),
			$round_trip ? 'ok' : ( $enabled ? 'fail' : 'warn' ),
			$round_trip
				? sprintf( /* translators: %s: event name */ __( 'یک رویداد %s نوشته شد و بلافاصله پیدا شد؛ در فهرست رویدادها هم دیده می‌شود.', 'tisa-otp' ), $event )
				: ( $enabled ? __( 'نوشتن رویداد در دیتابیس ناموفق بود؛ گزارش‌ها نمی‌توانند چیزی نشان دهند.', 'tisa-otp' ) : __( 'برای اجرای این آزمایش اول «ثبت رویدادها» را روشن کنید.', 'tisa-otp' ) )
		);

		return $this->result(
			__( 'آزمایش داده و رویدادها', 'tisa-otp' ),
			__( 'یک رویداد نوشته و بلافاصله خوانده می‌شود.', 'tisa-otp' ),
			$rows
		);
	}
}
