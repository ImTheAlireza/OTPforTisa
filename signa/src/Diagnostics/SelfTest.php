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
 * @package Signa
 */

namespace Signa\Diagnostics;

use Signa\Blocklist\Trusted;
use Signa\Captcha\Manager as CaptchaManager;
use Signa\Config\Sanitizer;
use Signa\Config\Settings;
use Signa\Cron\Maintenance;
use Signa\Gateway\AccountProbe;
use Signa\Gateway\Registry;
use Signa\Install\Guard;
use Signa\Install\Package;
use Signa\Install\Schema;
use Signa\Log\LogStore;
use Signa\Otp\CodeStore;
use Signa\Otp\OtpService;
use Signa\Registration\FieldCatalog;
use Signa\Registration\FieldSchema;
use Signa\Support\Colour;
use Signa\Support\Transport;
use Signa\Support\Rejection;

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
			throw Rejection::make( 'unknown_check', __( 'این آزمایش شناخته نشد.', 'signa' ) );
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
		$min_php = defined( 'SIGNA_MIN_PHP' ) ? SIGNA_MIN_PHP : '7.4';
		$php_ok  = version_compare( PHP_VERSION, $min_php, '>=' );

		$rows[] = $this->row(
			__( 'نسخه PHP', 'signa' ),
			PHP_VERSION,
			$php_ok ? 'ok' : 'fail',
			$php_ok ? '' : sprintf( /* translators: %s: minimum PHP version */ __( 'افزونه به PHP %s یا بالاتر نیاز دارد.', 'signa' ), $min_php )
		);

		$wp_version = get_bloginfo( 'version' );
		$wp_ok      = version_compare( $wp_version, '6.1', '>=' );

		$rows[] = $this->row(
			__( 'نسخه وردپرس', 'signa' ),
			$wp_version,
			$wp_ok ? 'ok' : 'warn',
			$wp_ok ? '' : __( 'روی ۶.۱ یا بالاتر آزمایش شده است.', 'signa' )
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
			__( 'یکپارچگی بستهٔ نصب‌شده', 'signa' ),
			$package['ok']
				? sprintf( /* translators: %d: number of files checked */ __( 'درست — %s فایل بررسی شد', 'signa' ), number_format_i18n( $package['checked'] ) )
				: __( 'ناقص', 'signa' ),
			$package['ok'] ? 'ok' : 'fail',
			! $package['ok']
				? implode( '، ', array_slice( $offence, 0, 5 ) ) . ' — ' . __( 'بستهٔ کامل همین نسخه را از نو نصب کنید (جایگزینی، نه حذف).', 'signa' )
				: ''
		);

		if ( ! empty( $broke ) ) {
			$ids = array();

			foreach ( $broke as $failure ) {
				$ids[] = $failure['id'];
			}

			$rows[] = $this->row(
				__( 'سرویس‌هایی که بالا نیامدند', 'signa' ),
				number_format_i18n( count( $ids ) ),
				'fail',
				implode( '، ', $ids ) . ' — ' . __( 'تا وقتی این پیام هست، بخشی از افزونه کار نمی‌کند. بستهٔ کامل را از نو نصب کنید.', 'signa' )
			);
		}

		$missing = $this->schema->missingTables();

		$rows[] = $this->row(
			__( 'جدول‌های افزونه', 'signa' ),
			array() === $missing
				? __( 'سالم', 'signa' )
				: number_format_i18n( count( $missing ) ) . ' ' . __( 'جدول نیست', 'signa' ),
			array() === $missing ? 'ok' : 'fail',
			array() === $missing ? '' : implode( ', ', $missing ) . ' — ' . __( 'افزونه را یک‌بار غیرفعال و فعال کنید تا ساخته شوند.', 'signa' )
		);

		$cron = wp_next_scheduled( Maintenance::HOOK );

		$rows[] = $this->row(
			__( 'زمان‌بند پاک‌سازی', 'signa' ),
			$cron ? wp_date( 'Y-m-d H:i', (int) $cron ) : __( 'ثبت نشده', 'signa' ),
			$cron ? 'ok' : 'warn',
			$cron ? '' : __( 'پاک‌سازی کدهای منقضی و رویدادهای قدیمی هنوز اجرا نشده است.', 'signa' )
		);

		$enabled = $this->settings->bool( 'enabled', true );

		$rows[] = $this->row(
			__( 'وضعیت افزونه', 'signa' ),
			$enabled ? __( 'فعال', 'signa' ) : __( 'غیرفعال', 'signa' ),
			$enabled ? 'ok' : 'warn',
			$enabled ? '' : __( 'با افزونهٔ غیرفعال هیچ فرمی روی سایت کار نمی‌کند و API پاسخ نمی‌دهد.', 'signa' )
		);

		$rows[] = $this->row(
			__( 'حالت احراز', 'signa' ),
			$this->settings->str( 'auth_mode', 'smart' ),
			'info',
			sprintf( /* translators: 1: flow, 2: channel */ __( 'جریان: %1$s · کانال: %2$s', 'signa' ), $this->settings->str( 'registration_flow', 'fields_then_code' ), $this->settings->str( 'channel', 'sms' ) )
		);

		$rows[] = $this->row(
			__( 'کش شیء', 'signa' ),
			wp_using_ext_object_cache() ? __( 'فعال', 'signa' ) : __( 'غیرفعال', 'signa' ),
			'info',
			wp_using_ext_object_cache() ? __( 'انبار کدهای موقت روی همین کش نوشته می‌شود، نه در دیتابیس.', 'signa' ) : ''
		);

		$rows[] = $this->row(
			__( 'نشانی REST', 'signa' ),
			esc_url_raw( rest_url( 'signa/v1/' ) ),
			'info',
			__( 'همین نشانی روی سایت باید از مرورگر کاربر قابل دسترس باشد.', 'signa' )
		);

		return $this->result(
			__( 'آزمایش عمومی', 'signa' ),
			__( 'نسخه‌ها، جدول‌ها و زمان‌بند.', 'signa' ),
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

		$rows[] = $this->row( __( 'طول کد', 'signa' ), number_format_i18n( $length ) . ' ' . __( 'رقم', 'signa' ), $length >= 4 ? 'ok' : 'warn', $length < 4 ? __( 'کد کمتر از چهار رقم قابل حدس است.', 'signa' ) : '' );
		$rows[] = $this->row( __( 'اعتبار کد', 'signa' ), number_format_i18n( $ttl ) . ' ' . __( 'ثانیه', 'signa' ), 'ok' );
		$rows[] = $this->row( __( 'انبار کد', 'signa' ), $this->storeLabel(), 'info', $this->settings->str( 'code_store', 'database' ) );

		// The real test: generate, store, find, revoke. No SMS leaves this.
		$code   = $this->otp->generate();
		$record = $this->otp->store( self::SAMPLE_PHONE, $code, 'sms', '127.0.0.1' );

		$pending = $this->otp->isPending( self::SAMPLE_PHONE );
		$left    = $this->otp->secondsLeft( self::SAMPLE_PHONE );

		$this->otp->revoke( self::SAMPLE_PHONE );

		$rows[] = $this->row(
			__( 'ساخت کد', 'signa' ),
			$code,
			strlen( $code ) === $length ? 'ok' : 'fail',
			sprintf( /* translators: %d: number of digits */ __( '%d رقم، ساخته‌شده با همان تنظیمات همین صفحه.', 'signa' ), $length )
		);

		$stored_ok = $pending && $left > 0;

		$rows[] = $this->row(
			__( 'ذخیره و بازخوانی', 'signa' ),
			$stored_ok ? __( 'درست', 'signa' ) : __( 'ناموفق', 'signa' ),
			$stored_ok ? 'ok' : 'fail',
			$stored_ok
				? sprintf( /* translators: 1: sample phone, 2: seconds */ __( 'برای شمارهٔ آزمایشی %1$s ذخیره و بی‌درنگ باطل شد؛ %2$s ثانیه اعتبار داشت.', 'signa' ), self::SAMPLE_PHONE, number_format_i18n( $left ) )
				: __( 'کد ذخیره شد ولی بلافاصله پیدا نشد؛ انبار کد کار نمی‌کند. ورود کاربران در این وضعیت ممکن نیست.', 'signa' )
		);

		$rows[] = $this->row(
			__( 'شناسهٔ رکورد', 'signa' ),
			'#' . number_format_i18n( $record->id() ),
			'info',
			sprintf( /* translators: 1: fingerprint, 2: expiry */ __( 'اثر انگشت %1$s · انقضا %2$s', 'signa' ), substr( $record->fingerprint(), 0, 12 ) . '…', wp_date( 'H:i:s', $record->expiresAt() ) )
		);

		$rows[] = $this->row( __( 'کانال ارسال', 'signa' ), $this->settings->str( 'channel', 'sms' ), 'info', __( 'کانال آزمایشی همین حالا برای همین مقدار تنظیم شده است.', 'signa' ) );
		$rows[] = $this->row( __( 'پس از باطل کردن', 'signa' ), $this->otp->isPending( self::SAMPLE_PHONE ) ? __( 'باز هم فعال', 'signa' ) : __( 'پاک شد', 'signa' ), $this->otp->isPending( self::SAMPLE_PHONE ) ? 'warn' : 'ok', __( 'کد آزمایشی نباید در انبار بماند.', 'signa' ) );

		return $this->result(
			__( 'آزمایش کد یکبارمصرف', 'signa' ),
			__( 'هیچ پیامکی ارسال نمی‌شود.', 'signa' ),
			$rows
		);
	}

	private function storeLabel(): string {
		$class = get_class( $this->codes );

		if ( false !== strpos( $class, 'CacheCodeStore' ) ) {
			return __( 'کش شیء', 'signa' );
		}

		if ( false !== strpos( $class, 'TableCodeStore' ) ) {
			return __( 'جدول اختصاصی', 'signa' );
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
				$role[] = __( 'اصلی', 'signa' );
			}

			if ( $gateway['backup'] ) {
				$role[] = __( 'پشتیبان', 'signa' );
			}

			$note = array();

			if ( ! $gateway['ready'] ) {
				$note[] = __( 'ناقص:', 'signa' ) . ' ' . ( $gateway['missing'] ? implode( ', ', $gateway['missing'] ) : __( 'پیکربندی کامل نیست', 'signa' ) );
			}

			if ( $gateway['resting'] ) {
				$note[] = __( 'در استراحت قطع‌کن مدار', 'signa' );
			}

			if ( '' !== $gateway['health_text'] ) {
				$note[] = $gateway['health_text'];
			}

			$status = $gateway['ready'] ? ( $gateway['active'] || $gateway['backup'] ? 'ok' : 'info' ) : 'warn';
			$value  = $gateway['ready'] ? ( $gateway['sender'] ? $gateway['sender'] : __( 'آماده', 'signa' ) ) : __( 'آماده نیست', 'signa' );

			/*
			 * A gateway whose last send failed is not "ready". Saying "آماده"
			 * next to a failure the owner reported is how this test loses their
			 * trust; the row has to carry the failure and its reason.
			 */
			$health = isset( $gateway['health'] ) ? (array) $gateway['health'] : array();

			if ( $health && empty( $health['ok'] ) ) {
				$status = 'fail';
				$value  = isset( $health['error'] ) && '' !== (string) $health['error'] ? (string) $health['error'] : __( 'ناموفق', 'signa' );

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
			$rows[] = $this->row( __( 'سامانه‌ها', 'signa' ), __( 'هیچ سامانه‌ای ثبت نشده', 'signa' ), 'fail', __( 'بدون سامانهٔ پیامکی هیچ کدی ارسال نمی‌شود.', 'signa' ) );
		}

		$order = array();

		foreach ( $chain as $id ) {
			$report = $this->gateways->report();
			$order[] = isset( $report[ $id ] ) ? $report[ $id ]['label'] : $id;
		}

		$rows[] = $this->row(
			__( 'ترتیب تلاش', 'signa' ),
			$order ? implode( ' → ', $order ) : __( 'خالی', 'signa' ),
			$order ? 'ok' : 'fail',
			__( 'اگر سامانهٔ اول خطا بدهد، بعدی امتحان می‌شود.', 'signa' )
		);

		$channel = $this->settings->str( 'channel', 'sms' );

		$rows[] = $this->row(
			__( 'کانال ارسال کد', 'signa' ),
			'sms' === $channel ? __( 'پیامک', 'signa' ) : __( 'ایمیل', 'signa' ),
			'sms' === $channel ? 'ok' : 'warn',
			'sms' === $channel
				? ''
				: __( 'کدها با ایمیل فرستاده می‌شوند؛ در بخش «کد و کانال‌ها» عوض می‌شود.', 'signa' )
		);

		$rows[] = $this->reachability( $chain );

		foreach ( $this->account( $chain ) as $account_row ) {
			$rows[] = $account_row;
		}

		$rows[] = $this->row(
			__( 'ارسال واقعی', 'signa' ),
			__( 'آزمایش جدا', 'signa' ),
			'info',
			__( 'برای ارسال واقعی، دکمهٔ «ارسال پیامک آزمایشی».', 'signa' )
		);

		return $this->result(
			__( 'آزمایش سامانه‌های پیامکی', 'signa' ),
			__( 'آماده / ناقص / خطای واقعی.', 'signa' ),
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
				__( 'دسترسی این سرور به سامانه', 'signa' ),
				__( 'بررسی نشد', 'signa' ),
				'info',
				__( 'برای این سامانه نشانی قابل بررسی ثبت نشده است.', 'signa' )
			);
		}

		$host  = (string) wp_parse_url( $target, PHP_URL_HOST );
		$block = $this->settings->bool( 'direct_send', false ) ? null : Transport::blockFailure( $host );

		if ( null !== $block ) {
			return $this->row(
				__( 'دسترسی این سرور به سامانه', 'signa' ),
				__( 'بسته است', 'signa' ),
				'fail',
				sprintf(
					/* translators: 1: gateway host, 2: what WordPress answered */
					__( '%1$s — %2$s', 'signa' ),
					$host,
					$block['message']
				) . ' ' . __( 'یا در تنظیمات › سامانه‌های پیامکی «ارسال مستقیم» را روشن کنید.', 'signa' )
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
				'user-agent'          => 'SignaOTP/' . SIGNA_VERSION . '; ' . home_url( '/' ),
			)
		);

		$ms   = (int) round( ( microtime( true ) - $started ) * 1000 );
		$host = (string) wp_parse_url( $target, PHP_URL_HOST );

		$this->logs->write(
			'diagnostic',
			'admin.reachability',
			sprintf( /* translators: 1: host, 2: outcome */ __( 'بررسی دسترسی به %1$s: %2$s', 'signa' ), $host, is_wp_error( $response ) ? $response->get_error_code() : (string) wp_remote_retrieve_response_code( $response ) ),
			array(
				'service' => $host,
				'ok'      => ! is_wp_error( $response ),
				'ms'      => $ms,
			)
		);

		if ( is_wp_error( $response ) ) {
			$transport = Transport::fromError( $response );

			return $this->row(
				__( 'دسترسی این سرور به سامانه', 'signa' ),
				$host,
				'fail',
				$ms . ' ' . __( 'میلی‌ثانیه', 'signa' ) . ' · ' . $transport['reason'] . ' — ' . $transport['message']
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		return $this->row(
			__( 'دسترسی این سرور به سامانه', 'signa' ),
			$host,
			$status > 0 ? 'ok' : 'warn',
			sprintf(
				/* translators: 1: milliseconds, 2: HTTP status */
				__( '%1$d میلی‌ثانیه · پاسخ HTTP %2$d.', 'signa' ),
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
					__( 'کلید API و اعتبار', 'signa' ),
					__( 'بررسی نشد', 'signa' ),
					'info',
					__( 'این سامانه امکان بررسی حساب را ندارد.', 'signa' )
				),
			);
		}

		$probe = $driver->probe();

		if ( ! empty( $probe['error_code'] ) && 'rejected' !== $probe['error_code'] ) {
			$labels = array(
				'not_configured' => __( 'تنظیم نشده', 'signa' ),
				'unauthorized'   => __( 'رد شد', 'signa' ),
				'no_credit'      => __( 'اعتبار تمام', 'signa' ),
				'rate_limited'   => __( 'محدود شده', 'signa' ),
				'transport'      => __( 'وصل نشد', 'signa' ),
			);

			return array(
				$this->row(
					__( 'کلید API و اعتبار', 'signa' ),
					isset( $labels[ $probe['error_code'] ] ) ? $labels[ $probe['error_code'] ] : __( 'بررسی نشد', 'signa' ),
					'not_configured' === $probe['error_code'] ? 'warn' : 'fail',
					trim( (string) $probe['reason'] . ' — ' . (string) $probe['message'], ' —' )
				),
			);
		}

		$rows = array();
		$rows[] = $this->row(
			__( 'کلید API و اعتبار', 'signa' ),
			null === $probe['credit']
				? __( 'پذیرفته شد', 'signa' )
				: sprintf( /* translators: %s: account credit */ __( 'پذیرفته شد · اعتبار %s', 'signa' ), self::amount( (float) $probe['credit'] ) ),
			'ok',
			(string) $probe['reason']
		);

		$sender = (string) $probe['sender'];

		if ( '' === $sender ) {
			$rows[] = $this->row(
				__( 'شماره خط', 'signa' ),
				__( 'تنظیم نشده', 'signa' ),
				'info',
				__( 'ارسال با الگو به شماره خط نیاز ندارد.', 'signa' )
			);

			return $rows;
		}

		$known = $probe['sender_ok'];
		$lines = is_array( $probe['lines'] ) ? implode( ' · ', array_map( 'strval', $probe['lines'] ) ) : '';
		$note  = '' !== $lines
			? sprintf( /* translators: %s: line numbers of the account */ __( 'خط‌های این حساب: %s', 'signa' ), $lines )
			: (string) $probe['message'];

		$rows[] = $this->row(
			__( 'شماره خط', 'signa' ),
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
		$maxRows  = $this->settings->int( 'logs_max_rows', 200000 );
		$key      = $this->settings->str( 'captcha_site_key' );
		$secret   = $this->settings->str( 'captcha_secret_key' );

		$rows[] = $this->row(
			__( 'سرویس کپچا', 'signa' ),
			$captcha['label'],
			$captcha['enabled'] ? 'ok' : 'info',
			$captcha['enabled'] ? '' : __( 'کپچا خاموش است؛ هانی‌پات و سقف ارسال همچنان کار می‌کنند.', 'signa' )
		);

		if ( $captcha['enabled'] ) {
			$rows[] = $this->row(
				__( 'کلید سایت', 'signa' ),
				'' === trim( $key ) ? __( 'خالی', 'signa' ) : $this->mask( $key ),
				'' === trim( $key ) ? 'fail' : 'ok',
				'' === trim( $key ) ? __( 'بدون کلید سایت، ویجت کپچا روی فرم ظاهر نمی‌شود.', 'signa' ) : ''
			);

			$rows[] = $this->row(
				__( 'کلید مخفی', 'signa' ),
				'' === trim( $secret ) ? __( 'خالی', 'signa' ) : __( 'ثبت شده', 'signa' ),
				'' === trim( $secret ) ? 'fail' : 'ok',
				'' === trim( $secret ) ? __( 'بدون کلید مخفی، پاسخ کاربر در سرور تأیید نمی‌شود و همه رد می‌شوند.', 'signa' ) : ''
			);
		}

		if ( $captcha['halfConfigured'] ) {
			$rows[] = $this->row( __( 'پیکربندی نیمه‌کاره', 'signa' ), __( 'بله', 'signa' ), 'fail', __( 'تنها یکی از دو کلید پر شده است؛ ویجت و تأیید سروری هر دو می‌شکنند.', 'signa' ) );
		}

		/*
		 * The two doors into an account, and how the site decided each one
		 * opens. Both are settings a security review asks about first, and both
		 * were previously invisible from inside the panel.
		 */
		$passwordOff = $this->settings->bool( 'password_login_off', false );
		$remember    = $this->settings->bool( 'remember_login', true );

		$rows[] = $this->row(
			__( 'ورود با گذرواژه', 'signa' ),
			$passwordOff ? __( 'بسته', 'signa' ) : __( 'باز', 'signa' ),
			$passwordOff ? 'ok' : 'info',
			$passwordOff
				? __( 'گذرواژه از wp-login.php پذیرفته نمی‌شود؛ گذرواژهٔ برنامه و REST کار می‌کنند. راه بازگشت: کد اضطراری.', 'signa' )
				: __( 'ورود با گذرواژه باز است. اگر می‌خواهید فقط با کد وارد شوند، کلید «ورود فقط با کد» را روشن کنید.', 'signa' )
		);

		$rows[] = $this->row(
			__( 'مدت نشست', 'signa' ),
			$remember ? __( '۱۴ روز', 'signa' ) : __( 'تا بسته شدن مرورگر', 'signa' ),
			'info'
		);

		$siteLimit = $this->settings->int( 'limit_per_site_daily', 300 );

		$rows[] = $this->row(
			__( 'سقف روزانهٔ کل سایت', 'signa' ),
			$siteLimit > 0 ? number_format_i18n( $siteLimit ) . ' ' . __( 'ارسال', 'signa' ) : __( 'بی‌سقف', 'signa' ),
			$siteLimit > 0 ? 'ok' : 'warn',
			$siteLimit > 0 ? '' : __( 'بدون این سقف، حمله از هزاران آدرس می‌تواند اعتبار پیامک را در یک روز مصرف کند.', 'signa' )
		);

		$rows[] = $this->row(
			__( 'سقف ردیف‌های رویدادها', 'signa' ),
			$maxRows > 0 ? number_format_i18n( $maxRows ) . ' ' . __( 'ردیف', 'signa' ) : __( 'بی‌سقف', 'signa' ),
			$maxRows > 0 ? 'ok' : 'warn',
			$maxRows > 0 ? __( 'قدیمی‌ترین ردیف‌ها حذف می‌شوند تا جدول دیسک را پر نکند.', 'signa' ) : __( 'بدون سقف، جدول رویدادها می‌تواند میلیون‌ها ردیف شود.', 'signa' )
		);

		$rows[] = $this->row(
			__( 'زمان نمایش', 'signa' ),
			$this->triggerLabel( $captcha['trigger'] ),
			'after_limit' === $captcha['trigger'] ? 'warn' : 'info',
			'after_limit' === $captcha['trigger']
				? __( 'در این حالت دو تلاش اول هر شماره و سه تلاش اول هر IP بدون چالش رد می‌شوند؛ اگر همان لحظه چیزی ندیدید، علتش همین است.', 'signa' )
				: __( 'چالش از همان اولین درخواست خواسته می‌شود.', 'signa' )
		);

		/*
		 * "Why did it not ask me for a captcha?" has three answers that look
		 * identical from the outside: the challenge is invisible by design, the
		 * number is on the exempt list, or there is no captcha at all. The rows
		 * below name which one is true instead of leaving it to be guessed at.
		 */
		$rows[] = $this->row(
			__( 'چالش دیدنی است؟', 'signa' ),
			'score' === $captcha['kind'] ? __( 'نه — بی‌صدا', 'signa' ) : __( 'بله', 'signa' ),
			'score' === $captcha['kind'] ? 'info' : 'ok',
			'score' === $captcha['kind']
				? __( 'نسخهٔ ۳ بی‌صدا است و هیچ ویجتی نشان نمی‌دهد. برای چالشی که دیده شود، ARCaptcha یا hCaptcha را انتخاب کنید.', 'signa' )
				: __( 'کاربر چالش را می‌بیند و باید کاملش کند.', 'signa' )
		);

		$rows[] = $this->trustedRow();

		$override = trim( $this->settings->str( 'captcha_script_override' ) );

		$rows[] = $this->row(
			__( 'نشانی جایگزین اسکریپت', 'signa' ),
			'' === $override ? __( 'خالی', 'signa' ) : $override,
			'info',
			'' === $override ? __( 'اگر اسکریپت رسمی روی سایت باز نشود، می‌توانید آینهٔ خودتان را اینجا بگذارید.', 'signa' ) : __( 'اول این نشانی امتحان می‌شود، بعد نشانی رسمی.', 'signa' )
		);

		$rows[] = $this->row(
			__( 'فهرست اسکریپت‌ها', 'signa' ),
			number_format_i18n( count( $captcha['scripts'] ) ) . ' ' . __( 'نشانی', 'signa' ),
			count( $captcha['scripts'] ) > 0 ? 'ok' : 'fail',
			$captcha['scripts'] ? implode( ' · ', $captcha['scripts'] ) : __( 'هیچ نشانی‌ای برای بارگذاری نیست؛ یعنی ویجت هیچ‌وقت نمی‌آید.', 'signa' )
		);

		$rows[] = $this->row(
			__( 'اگر سرویس قطع باشد', 'signa' ),
			$captcha['failOpen'] ? __( 'ورود بسته نمی‌شود', 'signa' ) : __( 'ورود بسته می‌شود', 'signa' ),
			$captcha['failOpen'] ? 'ok' : 'warn',
			$captcha['failOpen']
				? __( 'هانی‌پات و سقف ارسال فعال می‌مانند و رویداد captcha.fail_open ثبت می‌شود.', 'signa' )
				: __( 'با قطعی سرویس کپچا، هیچ ورودی‌ای پذیرفته نمی‌شود.', 'signa' )
		);

		/*
		 * What the guard has actually been doing. A captcha that never renders
		 * is not a theory: it shows up as `guard.rejected / captcha_missing` in
		 * the events, one row per visitor who tried to log in. Counting those
		 * rows here turns "the captcha does not load" into a number and a fix.
		 */
		$rejected = $this->captchaRejects( 7 );

		if ( $rejected['total'] > 0 ) {
			$rows[] = $this->captchaRejectRow( $rejected );
		}

		if ( $rejected['failOpen'] > 0 ) {
			$rows[] = $this->row(
				__( 'عبور بدون کپچا (۷ روز)', 'signa' ),
				number_format_i18n( $rejected['failOpen'] ) . ' ' . __( 'درخواست', 'signa' ),
				'success' === $rejected['failOpenReason'] ? 'warn' : 'info',
				'success' === $rejected['failOpenReason']
					? __( 'سرویس کپچا از سمت سرور پاسخ نداد و «باز ماندن ورود» روشن است؛ هانی‌پات و سقف ارسال همچنان اعمال شدند.', 'signa' )
					: __( 'مرورگر این کاربران نتوانست کپچا را بیاورد و «باز ماندن ورود» روشن است؛ هانی‌پات و سقف ارسال همچنان اعمال شدند.', 'signa' )
			);
		}

		$rows[] = $this->row(
			__( 'نمایش در این مرورگر', 'signa' ),
			__( 'در همین پنجره ادامه دارد…', 'signa' ),
			'info',
			__( 'ادامه در همین پنجره و در مرورگر شما.', 'signa' )
		);

		return $this->result(
			__( 'آزمایش امنیت و کپچا', 'signa' ),
			__( 'از سمت سرور و در همین مرورگر.', 'signa' ),
			$rows
		);
	}

	/**
	 * Who the guards are told to leave alone.
	 *
	 * A trusted number skips the captcha *by design* (admin settings ›
	 * امنیت › فهرست معاف), and the site owner's own number is the first one
	 * anyone puts there — usually while testing, and then it is forgotten. From
	 * the visitor's side that is indistinguishable from a captcha that is off,
	 * so the row says it out loud, with the number of the person reading it.
	 */
	private function trustedRow(): array {
		$trusted = new Trusted( $this->settings );

		if ( ! $trusted->enabled() ) {
			return $this->row(
				__( 'فهرست معاف', 'signa' ),
				__( 'خاموش', 'signa' ),
				'info',
				__( 'هیچ شماره‌ای از کپچا معاف نیست.', 'signa' )
			);
		}

		$count   = count( $trusted->rules() );
		$own     = $this->ownPhone();
		$exempt  = '' !== $own && $trusted->matches( $own );

		return $this->row(
			__( 'فهرست معاف', 'signa' ),
			number_format_i18n( $count ) . ' ' . __( 'شماره', 'signa' ),
			$exempt ? 'warn' : 'info',
			$exempt
				? sprintf(
					/* translators: %s: the administrator's own masked phone number */
					__( 'شمارهٔ خودتان (%s) در این فهرست است؛ به همین دلیل کپچا از شما پرسیده نشد. برای آزمایش واقعی برداریدش.', 'signa' ),
					$this->mask( $own )
				)
				: __( 'شماره‌های این فهرست بدون کپچا و بدون محدودیت رد می‌شوند؛ بقیه نه.', 'signa' )
		);
	}

	/**
	 * The administrator's own number, read from the same profile key the form
	 * writes to, so the row above can name it.
	 */
	private function ownPhone(): string {
		$user = get_current_user_id();

		if ( ! $user ) {
			return '';
		}

		return trim( (string) get_user_meta( $user, $this->settings->str( 'phone_meta_key', 'signa_phone' ), true ) );
	}

	/**
	 * How many requests the guard turned away — and, for the ones without a
	 * captcha token, who was on the other end.
	 *
	 * The old row counted `captcha_missing` and then said the widget had not
	 * loaded in users' browsers. That was a guess, it was often wrong (a script
	 * posting to the endpoint has no browser at all), and it contradicted the
	 * green «بارگذاری در مرورگر» row three lines above it in the same window.
	 * The user agent is recorded with each rejection now, so the sentence can be
	 * about what actually happened.
	 *
	 * @param int $days Window.
	 * @return array{total:int,captcha:int,browser:int,script:int,refused:int,failOpen:int,failOpenReason:string}
	 */
	private function captchaRejects( int $days ): array {
		$out = array(
			'total'          => 0,
			'captcha'        => 0,
			'browser'        => 0,
			'script'         => 0,
			'refused'        => 0,
			'failOpen'       => 0,
			'failOpenReason' => '',
		);

		$tally = $this->logs->tally( 'guard.rejected', $days );

		foreach ( (array) $tally as $count ) {
			$out['total'] += (int) $count;
		}

		foreach ( $this->logs->query(
			array(
				'event' => 'guard.rejected',
				'hours' => $days * 24,
				'limit' => 500,
			)
		) as $row ) {
			$code = isset( $row->error_code ) ? (string) $row->error_code : '';

			if ( 'captcha_missing' === $code ) {
				$out['captcha']++;

				if ( $this->looksLikeBrowser( LogStore::metaOf( $row, 'ua' ) ) ) {
					$out['browser']++;
				} else {
					$out['script']++;
				}

				continue;
			}

			if ( 0 === strpos( $code, 'captcha_' ) ) {
				$out['refused']++;
			}
		}

		foreach ( $this->logs->query(
			array(
				'event' => 'captcha.fail_open',
				'hours' => $days * 24,
				'limit' => 500,
			)
		) as $row ) {
			$out['failOpen']++;

			$reason = LogStore::metaOf( $row, 'reason' );
			$out['failOpenReason'] = '' !== $reason ? $reason : ( isset( $row->error_code ) ? (string) $row->error_code : '' );
		}

		return $out;
	}

	/**
	 * Did this rejection come from a browser, or from something holding a script?
	 *
	 * It is a heuristic and it is labelled as one: every browser sends a user
	 * agent, and a bot hitting the endpoint directly usually sends `curl`, a
	 * library name, or nothing at all. The point is not to be certain — it is to
	 * stop telling the administrator something about their visitors that the
	 * evidence does not support.
	 */
	private function looksLikeBrowser( string $ua ): bool {
		$ua = strtolower( trim( $ua ) );

		if ( '' === $ua ) {
			return false;
		}

		foreach ( array( 'curl', 'wget', 'python', 'java/', 'go-http', 'okhttp', 'axios', 'node-fetch', 'postman', 'guzzle', 'libwww', 'httpclient' ) as $library ) {
			if ( false !== strpos( $ua, $library ) ) {
				return false;
			}
		}

		return false !== strpos( $ua, 'mozilla' );
	}

	/**
	 * The row, in the words its own evidence supports.
	 *
	 * @param array<string,mixed> $rejected Counts from captchaRejects().
	 */
	private function captchaRejectRow( array $rejected ): array {
		$total   = (int) $rejected['total'];
		$missing = (int) $rejected['captcha'];
		$browser = (int) $rejected['browser'];
		$script  = (int) $rejected['script'];
		$refused = (int) $rejected['refused'];

		if ( 0 === $missing ) {
			return $this->row(
				__( 'ردشدن به‌خاطر کپچا (۷ روز)', 'signa' ),
				number_format_i18n( $total ) . ' ' . __( 'درخواست', 'signa' ),
				'info',
				sprintf(
					/* translators: %d: number of requests whose token the provider refused */
					__( 'کپچا نبود؛ %d درخواست توکن داشت و سرویس ردش کرد (امتیاز پایین یا توکن تکراری).', 'signa' ),
					$refused
				)
			);
		}

		$status = $browser > 0 ? 'fail' : 'ok';

		$note = $script > 0
			? sprintf(
				/* translators: %d: number of requests that carried no user agent of a browser */
				__( '%d درخواست از ربات یا اسکریپت بود (بدون مرورگر)؛ کپچا کار خودش را کرد.', 'signa' ),
				$script
			)
			: '';

		if ( $browser > 0 ) {
			$note .= ( '' !== $note ? ' ' : '' ) . sprintf(
				/* translators: %d: number of requests that came from a real browser without a token */
				__( '%d درخواست از مرورگر واقعی بود و توکن نرسید؛ اگر تکرار شد «باز ماندن ورود» یا نشانی جایگزین اسکریپت را ببینید.', 'signa' ),
				$browser
			);
		}

		return $this->row(
			__( 'ردشدن به‌خاطر کپچا (۷ روز)', 'signa' ),
			number_format_i18n( $total ) . ' ' . __( 'درخواست', 'signa' ),
			$status,
			$note
		);
	}

	private function triggerLabel( string $trigger ): string {
		$labels = array(
			'always'  => __( 'همیشه', 'signa' ),
			'suspect' => __( 'بعد از رفتار مشکوک', 'signa' ),
			'auto'    => __( 'خودکار', 'signa' ),
			'none'    => __( 'هیچ‌وقت', 'signa' ),
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
			__( 'فرم عضویت', 'signa' ),
			$shown ? __( 'روشن', 'signa' ) : __( 'خاموش', 'signa' ),
			'info',
			$shown ? '' : __( 'شمارهٔ تازه، حساب جدید نمی‌سازد.', 'signa' )
		);

		$rows[] = $this->row(
			__( 'ترتیب گام‌ها', 'signa' ),
			$this->fields->isCodeFirst() ? __( 'اول کد، بعد مشخصات', 'signa' ) : __( 'اول مشخصات، بعد کد', 'signa' ),
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
			__( 'فیلدهای فعال', 'signa' ),
			$names ? implode( ' · ', $names ) : __( 'هیچ فیلدی', 'signa' ),
			$names ? 'ok' : 'warn',
			$names ? '' : __( 'فرم عضویت بدون هیچ فیلد اضافه‌ای ثبت می‌شود.', 'signa' )
		);

		$rows[] = $this->row( __( 'فیلدهای اجباری', 'signa' ), $required ? implode( ' · ', $required ) : __( 'هیچ‌کدام', 'signa' ), 'info' );

		$rows[] = $this->row(
			__( 'کلید متای شماره', 'signa' ),
			$this->settings->str( 'phone_meta_key', 'signa_phone' ),
			'' === trim( $this->settings->str( 'phone_meta_key', 'signa_phone' ) ) ? 'fail' : 'ok',
			__( 'شمارهٔ تأییدشده در همین کلید پروفایل ذخیره می‌شود.', 'signa' )
		);

		$lookup = $this->settings->items( 'lookup_meta_keys' );

		$rows[] = $this->row(
			__( 'کلیدهای جست‌وجو', 'signa' ),
			$lookup ? implode( ', ', $lookup ) : __( 'خالی', 'signa' ),
			$lookup ? 'ok' : 'warn',
			$lookup ? __( 'برای پیدا کردن حساب‌های قدیمیِ همین سایت.', 'signa' ) : __( 'کاربر قدیمی با کلید دیگری پیدا نمی‌شود.', 'signa' )
		);

		if ( $unknown ) {
			$rows[] = $this->row( __( 'فیلد ناشناس', 'signa' ), implode( ' · ', $unknown ), 'fail', __( 'این شناسه‌ها در فهرست فیلدهای شناخته‌شده نیستند و ممکن است ذخیره نشوند.', 'signa' ) );
		}

		$rows[] = $this->row(
			__( 'نام کاربری', 'signa' ),
			$this->settings->str( 'username_from', 'phone' ),
			'info',
			__( 'ایمیل به‌عنوان نام کاربری یا برای بازیابی استفاده می‌شود.', 'signa' )
		);

		return $this->result(
			__( 'آزمایش فرم عضویت', 'signa' ),
			__( 'گام‌ها، فیلدها و مقصد ذخیره.', 'signa' ),
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
				__( 'رنگ تأکید روی کارت سفید', 'signa' ),
				$accent,
				'#ffffff',
				__( 'بلندی، پیوندها و دکمه‌های متن‌دار.', 'signa' ),
			),
			array(
				__( 'متن سفید روی رنگ تأکید', 'signa' ),
				'#ffffff',
				$accent,
				__( 'دکمهٔ اصلی «دریافت کد» با همین رنگ ساخته می‌شود.', 'signa' ),
			),
			array(
				__( 'متن روی پس‌زمینهٔ فرم', 'signa' ),
				'#101828',
				$surface,
				__( 'پس‌زمینه‌ای که برای کارت فرم انتخاب کرده‌اید.', 'signa' ),
			),
		);

		foreach ( $pairs as $pair ) {
			$ratio = Colour::ratio( $pair[1], $pair[2] );

			if ( null === $ratio ) {
				$rows[] = $this->row( $pair[0], __( 'قابل‌خواندن نبود', 'signa' ), 'warn', sprintf( /* translators: 1: colour, 2: colour */ __( 'رنگ‌های %1$s و %2$s شناسایی نشدند.', 'signa' ), $pair[1], $pair[2] ) );
				continue;
			}

			$pass = $ratio >= 4.5;

			$rows[] = $this->row(
				$pair[0],
				number_format_i18n( $ratio, 2 ) . ':1',
				$pass ? 'ok' : 'fail',
				$pass
					? ( '' !== $pair[3] ? $pair[3] : '' )
					: sprintf( /* translators: %s: minimum ratio */ __( 'کمتر از %s:1 — متن سخت خوانده می‌شود. یک رنگ تأکید تیره‌تر انتخاب کنید.', 'signa' ), '4.5' )
			);
		}

		$rows[] = $this->row( __( 'رنگ تأکید', 'signa' ), $accent, 'info', __( 'هم دکمه‌ها، هم گرادیان‌ها و هم حالت هاور از همین رنگ ساخته می‌شوند.', 'signa' ) );
		$rows[] = $this->row( __( 'پس‌زمینهٔ فرم', 'signa' ), $surface, 'info' );
		$rows[] = $this->row( __( 'گردی گوشه‌ها', 'signa' ), number_format_i18n( $this->settings->int( 'radius', 14 ) ) . ' px', 'info' );
		$rows[] = $this->row( __( 'عرض فرم', 'signa' ), number_format_i18n( $this->settings->int( 'width', 420 ) ) . ' px', 'info' );

		/*
		 * "Your form does not look like your preview" is a font question more
		 * often than a colour one: a theme with no Persian glyphs decides how the
		 * form reads, unless the form brings its own font — which it does now.
		 */
		$font = $this->settings->str( 'form_font', 'vazirmatn' );

		$rows[] = $this->row(
			__( 'جداسازی از پوسته', 'signa' ),
			$this->settings->bool( 'style_isolation', true ) ? __( 'روشن', 'signa' ) : __( 'خاموش', 'signa' ),
			$this->settings->bool( 'style_isolation', true ) ? 'ok' : 'warn',
			$this->settings->bool( 'style_isolation', true )
				? __( 'فرم داخل Shadow DOM رندر می‌شود؛ CSS قالب به آن نمی‌رسد.', 'signa' )
				: __( 'با خاموش بودن این گزینه، قالب سایت می‌تواند ظاهر فرم را عوض کند.', 'signa' )
		);

		$rows[] = $this->row(
			__( 'فونت فرم', 'signa' ),
			$this->fontLabel(),
			'theme' === $font ? 'info' : 'ok',
			'theme' === $font
				? __( 'فرم فونت پوسته را به ارث می‌برد؛ اگر پوسته فونت فارسی نداشته باشد، شکل فرم فرق می‌کند.', 'signa' )
				: __( 'همان فونتی که پیش‌نمایش با آن ساخته شده، همراه افزونه روی همین سایت سرو می‌شود.', 'signa' )
		);

		return $this->result(
			__( 'آزمایش ظاهر فرم', 'signa' ),
			__( 'رنگ‌های واقعی فرم و نسبت کنتراست.', 'signa' ),
			$rows
		);
	}

	/* ---------------------------------------------------------------------
	 * فروشگاه
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,mixed>
	 */
	/**
	 * The active theme's version, as WordPress reports it.
	 */
	private function themeVersion(): string {
		$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;

		if ( ! is_object( $theme ) || ! method_exists( $theme, 'get' ) ) {
			return __( 'فعال', 'signa' );
		}

		$name    = (string) $theme->get( 'Name' );
		$version = (string) $theme->get( 'Version' );

		if ( '' === $name ) {
			return __( 'فعال', 'signa' );
		}

		return '' === $version ? $name : $name . ' ' . $version;
	}

	/**
	 * Which font the form prints with, in one word.
	 */
	private function fontLabel(): string {
		$choice = $this->settings->str( 'form_font', 'vazirmatn' );

		if ( 'theme' === $choice ) {
			$theme = wp_get_theme();
			$name  = $theme instanceof \WP_Theme ? $theme->get( 'Name' ) : '';

			return '' !== (string) $name
				? sprintf( /* translators: %s: theme name */ __( 'پوسته: %s', 'signa' ), (string) $name )
				: __( 'پوسته', 'signa' );
		}

		if ( 'custom' === $choice ) {
			$custom = Sanitizer::fontFamily( $this->settings->str( 'form_font_custom' ) );

			return '' !== $custom ? $custom : __( 'وزیرمتن (خط دلخواه خالی است)', 'signa' );
		}

		return __( 'وزیرمتن (همراه افزونه)', 'signa' );
	}

	private function store(): array {
		$rows = array();
		$woo  = class_exists( 'WooCommerce' );

		$rows[] = $this->row(
			__( 'ووکامرس', 'signa' ),
			$woo ? ( defined( 'WC_VERSION' ) ? WC_VERSION : __( 'فعال', 'signa' ) ) : __( 'فعال نیست', 'signa' ),
			$woo ? 'ok' : 'warn',
			$woo ? '' : __( 'تنظیمات این بخش تا نصب ووکامرس اثری ندارند.', 'signa' )
		);

		if ( $woo ) {
			$rows[] = $this->row(
				__( 'فرم حساب کاربری', 'signa' ),
				$this->settings->bool( 'woo_account_form', true ) ? __( 'جایگزین می‌شود', 'signa' ) : __( 'دست‌نخورده', 'signa' ),
				'info',
				__( 'فرم ورود ووکامرس در صفحهٔ «حساب کاربری» با فرم OTP عوض می‌شود.', 'signa' )
			);

			$rows[] = $this->row(
				__( 'ورود پیش از تسویه', 'signa' ),
				$this->settings->bool( 'woo_checkout_gate', true ) ? __( 'اجباری', 'signa' ) : __( 'اختیاری', 'signa' ),
				'info'
			);

			$rows[] = $this->row(
				__( 'همگام‌سازی شماره صورتحساب', 'signa' ),
				$this->settings->bool( 'sync_billing_phone', true ) ? __( 'روشن', 'signa' ) : __( 'خاموش', 'signa' ),
				$this->settings->bool( 'sync_billing_phone', true ) ? 'ok' : 'info',
				$this->settings->bool( 'sync_billing_phone', true ) ? __( 'شمارهٔ تأییدشده در billing_phone هم نوشته می‌شود.', 'signa' ) : ''
			);

			$rows[] = $this->row(
				__( 'اتصال سفارش‌های مهمان', 'signa' ),
				$this->settings->bool( 'link_guest_orders', true ) ? __( 'روشن', 'signa' ) : __( 'خاموش', 'signa' ),
				'info',
				$this->settings->bool( 'link_guest_orders', true ) ? __( 'سفارش‌های قدیمی با همان شماره به حساب تازه وصل می‌شوند.', 'signa' ) : ''
			);

			$rows[] = $this->row(
				__( 'شمارهٔ صورتحساب در سایت', 'signa' ),
				$this->hasBillingPhone() ? __( 'پیدا شد', 'signa' ) : __( 'پیدا نشد', 'signa' ),
				$this->hasBillingPhone() ? 'ok' : 'warn',
				$this->hasBillingPhone() ? '' : __( 'هیچ کاربری کلید billing_phone ندارد؛ اگر ووکامرس تازه نصب شده این طبیعی است.', 'signa' )
			);
		}

		/*
		 * The WoodMart login panel: the theme prints it on its own hook, and the
		 * plugin wraps that hook. Whether the theme is here at all, and whether the
		 * form is meant to go into it, are the two facts an owner needs — the
		 * rest is visible on the site by opening the header's account dropdown.
		 */
		if ( \Signa\Integrations\WoodMart::detected() ) {
			$enabled = $this->settings->bool( 'woodmart_sidebar', true );

			$rows[] = $this->row(
				__( 'وودمارت', 'signa' ),
				$this->themeVersion(),
				'ok'
			);

			$rows[] = $this->row(
				__( 'سایدبار ورود وودمارت', 'signa' ),
				$enabled ? __( 'روشن', 'signa' ) : __( 'خاموش', 'signa' ),
				$enabled ? 'ok' : 'info',
				$enabled
					? __( 'فرم OTP داخل پنل ورود هدر رندر می‌شود؛ هیچ فایلی از پوسته تغییر نمی‌کند.', 'signa' )
					: __( 'پنل ورود وودمارت دست‌نخورده می‌ماند.', 'signa' )
			);

			if ( $enabled ) {
				$hides  = \Signa\Integrations\WoodMart::hidesAccountBlock( $this->settings );
				$closed = ! $this->settings->bool( 'woodmart_account_block', true );

				$rows[] = $this->row(
					__( 'بخش «ساخت حساب» وودمارت', 'signa' ),
					$hides ? __( 'برداشته می‌شود', 'signa' ) : __( 'می‌ماند', 'signa' ),
					$hides ? 'ok' : 'info',
					$hides
						? __( 'آیکن، متن و لینک ساخت حساب وودمارت از پنل برداشته می‌شود؛ فرم افزونه خودش عضویت می‌سازد.', 'signa' )
						: ( $closed
							? __( 'خودتان خواسته‌اید این بخش بماند.', 'signa' )
							: __( 'چون عضویت در فرم افزونه خاموش است، این بخش می‌ماند تا راه ثبت‌نام باز بماند.', 'signa' ) )
				);

				$rows[] = $this->row(
					__( 'فرم رمز عبور وودمارت', 'signa' ),
					'replace' === $this->settings->str( 'woodmart_mode', 'replace' ) ? __( 'جایگزین می‌شود', 'signa' ) : __( 'می‌ماند', 'signa' ),
					'info',
					'replace' === $this->settings->str( 'woodmart_mode', 'replace' )
						? __( 'کاربر در پنل فقط شماره موبایل و کد را می‌بیند.', 'signa' )
						: __( 'ورود با رمز عبور هم در دسترس می‌ماند و فرم OTP زیرش می‌آید.', 'signa' )
				);
			}
		}

		return $this->result(
			__( 'آزمایش فروشگاه', 'signa' ),
			__( 'وضعیت ووکامرس و اثر تنظیمات.', 'signa' ),
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
			__( 'ثبت رویدادها', 'signa' ),
			$enabled ? __( 'روشن', 'signa' ) : __( 'خاموش', 'signa' ),
			$enabled ? 'ok' : 'warn',
			$enabled ? '' : __( 'با ثبت خاموش، هیچ رویدادی نوشته نمی‌شود و گزارش‌ها خالی می‌مانند.', 'signa' )
		);

		$missing = $this->schema->missingTables();

		$rows[] = $this->row(
			__( 'جدول رویدادها', 'signa' ),
			in_array( $this->logs->table(), $missing, true ) ? __( 'ساخته نشده', 'signa' ) : __( 'موجود', 'signa' ),
			in_array( $this->logs->table(), $missing, true ) ? 'fail' : 'ok',
			$this->logs->table()
		);

		$before = $this->logs->totals();

		$rows[] = $this->row(
			__( 'رویدادهای ثبت‌شده', 'signa' ),
			number_format_i18n( (int) $before['total'] ),
			'info',
			sprintf( /* translators: %s: number of errors */ __( '%s موردش خطا بوده است.', 'signa' ), number_format_i18n( (int) $before['errors'] ) )
		);

		$rows[] = $this->row(
			__( 'نگهداری', 'signa' ),
			number_format_i18n( $this->settings->int( 'logs_keep_days', 7 ) ) . ' ' . __( 'روز', 'signa' ),
			'info',
			__( 'رویدادهای قدیمی‌تر در پاک‌سازی روزانه حذف می‌شوند.', 'signa' )
		);

		$rows[] = $this->row( __( 'حالت اشکال‌زدایی', 'signa' ), $this->settings->bool( 'debug', false ) ? __( 'روشن', 'signa' ) : __( 'خاموش', 'signa' ), 'info', __( 'در حالت روشن، هر رویداد در error_log هم نوشته می‌شود.', 'signa' ) );

		// The real test: write one event and find it again.
		$event  = 'admin.self_test';
		$wrote  = $this->logs->write( 'debug', $event, __( 'آزمایش خودکار پیشخوان: اگر این خط را می‌بینید، ثبت رویداد کار می‌کند.', 'signa' ), array( 'channel' => 'admin' ) );
		$found  = $wrote ? $this->logs->count( array( 'event' => $event, 'hours' => 1 ) ) : 0;

		$round_trip = $wrote && $found > 0;

		$rows[] = $this->row(
			__( 'نوشتن و خواندن', 'signa' ),
			$round_trip ? __( 'درست', 'signa' ) : ( $enabled ? __( 'ناموفق', 'signa' ) : __( 'اجرا نشد', 'signa' ) ),
			$round_trip ? 'ok' : ( $enabled ? 'fail' : 'warn' ),
			$round_trip
				? sprintf( /* translators: %s: event name */ __( 'یک رویداد %s نوشته شد و بلافاصله پیدا شد؛ در فهرست رویدادها هم دیده می‌شود.', 'signa' ), $event )
				: ( $enabled ? __( 'نوشتن رویداد در دیتابیس ناموفق بود؛ گزارش‌ها نمی‌توانند چیزی نشان دهند.', 'signa' ) : __( 'برای اجرای این آزمایش اول «ثبت رویدادها» را روشن کنید.', 'signa' ) )
		);

		return $this->result(
			__( 'آزمایش داده و رویدادها', 'signa' ),
			__( 'یک رویداد نوشته و بلافاصله خوانده می‌شود.', 'signa' ),
			$rows
		);
	}
}
