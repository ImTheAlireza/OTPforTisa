<?php
/**
 * Access screen: the emergency code and the number blocklist.
 *
 * Both features are emergency levers rather than everyday settings, so they
 * live on their own page instead of inside the settings form: they are written
 * by their own POST handlers, need their own nonces, and one of them can be
 * pulled at any moment from a phone.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Admin;

use TisaOtp\Access\EmergencyToken;
use TisaOtp\Blocklist\Blocklist;
use TisaOtp\Blocklist\Rule;
use TisaOtp\Bootable;
use TisaOtp\Config\Settings;
use TisaOtp\Log\Logger;

defined( 'ABSPATH' ) || exit;

final class AccessScreen implements Bootable {

	const SLUG = 'tisa-otp-access';

	/** @var Settings */
	private $settings;

	/** @var Blocklist */
	private $blocklist;

	/** @var EmergencyToken */
	private $emergency;

	/** @var Logger */
	private $logger;

	public function __construct( Settings $settings, Blocklist $blocklist, EmergencyToken $emergency, Logger $logger ) {
		$this->settings  = $settings;
		$this->blocklist = $blocklist;
		$this->emergency = $emergency;
		$this->logger    = $logger;
	}

	public function boot(): void {
		add_action( 'admin_post_tisa_otp_emergency_issue', array( $this, 'issueEmergency' ) );
		add_action( 'admin_post_tisa_otp_emergency_revoke', array( $this, 'revokeEmergency' ) );
		add_action( 'admin_post_tisa_otp_block_add', array( $this, 'addRules' ) );
		add_action( 'admin_post_tisa_otp_block_remove', array( $this, 'removeRule' ) );
		add_action( 'admin_post_tisa_otp_block_clear', array( $this, 'clearRules' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap tisa-wrap" dir="rtl">';
		echo '<div class="tisa-header"><div class="tisa-header__title"><h1>' . esc_html__( 'دسترسی و مسدودی', 'tisa-otp' ) . '</h1>';
		echo '<p>' . esc_html__( 'دو اهرم اضطراری: کدی برای روزی که پیامک قطع است، و فهرستی از شماره‌هایی که اجازه ورود ندارند.', 'tisa-otp' ) . '</p>';
		echo '</div></div>';

		ScreenNav::render( self::SLUG );

		$this->notice();
		$this->emergencyCard();
		$this->blocklistCard();

		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Emergency code
	 * ------------------------------------------------------------------ */

	private function emergencyCard(): void {
		$summary = $this->emergency->summary();
		$reveal  = $this->emergency->pull( get_current_user_id() );

		echo '<section class="tisa-panel tisa-card"><h2>' . esc_html__( 'کد اضطراری', 'tisa-otp' ) . '</h2>';
		echo '<p class="tisa-card__intro">' . esc_html__( 'برای وقتی که پیامک قطع است. فقط برای حساب‌های موجود؛ کاربر تازه نمی‌سازد.', 'tisa-otp' ) . '</p>';

		if ( '' !== $reveal ) {
			echo '<div class="tisa-secret"><span class="tisa-secret__label">' . esc_html__( 'کد تازه شما (فقط همین یک‌بار نمایش داده می‌شود)', 'tisa-otp' ) . '</span>';
			echo '<code class="tisa-secret__value" dir="ltr">' . esc_html( $reveal ) . '</code></div>';
		}

		$this->emergencyStatus( $summary );

		if ( $summary['armed'] ) {
			$this->revokeForm();
		}

		$this->issueForm();

		echo '</section>';
	}

	/**
	 * @param array<string,mixed> $summary
	 */
	private function emergencyStatus( array $summary ): void {
		echo '<div class="tisa-statbar">';

		if ( $summary['armed'] ) {
			$this->stat( 'is-good', $this->humanDuration( (int) $summary['left'] ), __( 'زمان باقی‌مانده', 'tisa-otp' ) );
			$this->stat( 'is-good', (string) (int) $summary['uses_left'] . ' / ' . (string) (int) $summary['use_limit'], __( 'استفاده باقی‌مانده', 'tisa-otp' ) );
		} else {
			$this->stat( 'is-bad', __( 'غیرفعال', 'tisa-otp' ), __( 'وضعیت', 'tisa-otp' ) );
		}

		$this->stat( '', $summary['ip_lock'] ? __( 'فقط IP سازنده', 'tisa-otp' ) : __( 'بدون قید IP', 'tisa-otp' ), __( 'قید شبکه', 'tisa-otp' ) );

		$phones = (array) $summary['phones'];
		$this->stat(
			'',
			array() === $phones ? __( 'همه حساب‌ها', 'tisa-otp' ) : (string) count( $phones ),
			__( 'محدوده شماره', 'tisa-otp' )
		);

		$this->stat( 0 < (int) $summary['fails'] ? 'is-bad' : '', (string) (int) $summary['fails'], __( 'تلاش ناموفق', 'tisa-otp' ) );

		echo '</div>';

		if ( array() !== $phones ) {
			echo '<p class="tisa-desc">' . esc_html__( 'محدود به: ', 'tisa-otp' ) . '<code dir="ltr">' . esc_html( implode( ', ', $phones ) ) . '</code></p>';
		}
	}

	private function stat( string $class, string $value, string $label ): void {
		printf(
			'<div class="tisa-stat %1$s"><span class="tisa-stat__value">%2$s</span><span class="tisa-stat__label">%3$s</span></div>',
			esc_attr( $class ),
			esc_html( $value ),
			esc_html( $label )
		);
	}

	private function issueForm(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tisa-access-form">';
		echo '<input type="hidden" name="action" value="tisa_otp_emergency_issue">';
		wp_nonce_field( 'tisa_otp_emergency_issue' );

		echo '<div class="tisa-inline">';
		printf(
			'<input type="text" class="regular-text tisa-input" dir="ltr" inputmode="numeric" autocomplete="off" name="emergency_code" data-tisa-emergency-code placeholder="%s" value="">',
			esc_attr__( 'کد ۶ تا ۱۲ رقمی', 'tisa-otp' )
		);
		echo '<button type="button" class="button" data-tisa-emergency-generate>' . esc_html__( 'تولید تصادفی', 'tisa-otp' ) . '</button>';
		echo '</div>';

		echo '<div class="tisa-inline">';

		printf(
			'<label class="tisa-field"><span>%s</span><input type="number" class="small-text tisa-input" dir="ltr" name="emergency_minutes" value="30" min="5" max="1440"> <em>%s</em></label>',
			esc_html__( 'اعتبار', 'tisa-otp' ),
			esc_html__( 'دقیقه', 'tisa-otp' )
		);

		printf(
			'<label class="tisa-field"><span>%s</span><input type="number" class="small-text tisa-input" dir="ltr" name="emergency_uses" value="1" min="1" max="50"> <em>%s</em></label>',
			esc_html__( 'تعداد استفاده', 'tisa-otp' ),
			esc_html__( 'بار', 'tisa-otp' )
		);

		echo '<label class="tisa-check"><input type="checkbox" name="emergency_ip_lock" value="1" checked><span>' . esc_html__( 'فقط از همین IP کار کند', 'tisa-otp' ) . '</span></label>';

		echo '</div>';

		printf(
			'<label class="tisa-field tisa-field--wide"><span>%s</span><input type="text" class="regular-text tisa-input" dir="ltr" name="emergency_phones" placeholder="09121234567, 09351234567"><em>%s</em></label>',
			esc_html__( 'محدود به این شماره‌ها', 'tisa-otp' ),
			esc_html__( 'خالی بگذارید تا روی هر حساب موجود کار کند.', 'tisa-otp' )
		);

		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'ساخت کد اضطراری', 'tisa-otp' ) . '</button></p>';
		echo '</form>';
	}

	private function revokeForm(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tisa-access-form tisa-access-form--danger">';
		echo '<input type="hidden" name="action" value="tisa_otp_emergency_revoke">';
		wp_nonce_field( 'tisa_otp_emergency_revoke' );
		echo '<p><button type="submit" class="button tisa-danger">' . esc_html__( 'لغو فوری کد اضطراری', 'tisa-otp' ) . '</button></p>';
		echo '</form>';
	}

	public function issueEmergency(): void {
		$this->guard( 'tisa_otp_emergency_issue' );

		$code = isset( $_POST['emergency_code'] ) ? sanitize_text_field( wp_unslash( $_POST['emergency_code'] ) ) : '';
		$code = preg_replace( '/[^0-9]/', '', \TisaOtp\Support\Phone::latinDigits( $code ) );

		if ( ! is_string( $code ) || strlen( $code ) < EmergencyToken::MIN_LENGTH ) {
			$this->back( 'code_short' );
		}

		$minutes = isset( $_POST['emergency_minutes'] ) ? (int) $_POST['emergency_minutes'] : 30;
		$uses    = isset( $_POST['emergency_uses'] ) ? (int) $_POST['emergency_uses'] : 1;
		$ipLock  = isset( $_POST['emergency_ip_lock'] );
		$raw     = isset( $_POST['emergency_phones'] ) ? sanitize_text_field( wp_unslash( $_POST['emergency_phones'] ) ) : '';

		$phones = array();
		foreach ( preg_split( '/[\s,،]+/', $raw ) ?: array() as $candidate ) {
			$candidate = trim( (string) $candidate );

			if ( '' === $candidate ) {
				continue;
			}

			$normalized = \TisaOtp\Support\Phone::normalize( $candidate );

			if ( '' === $normalized ) {
				continue;
			}

			$phones[] = $normalized;
		}

		$issued = $this->emergency->issue( $code, $minutes, $uses, $ipLock, $phones, $this->clientIp() );

		if ( array() === $issued ) {
			$this->back( 'code_short' );
		}

		$this->emergency->hold( $issued['code'], get_current_user_id() );

		$this->logger->warning(
			'emergency.issued',
			array(
				'minutes' => $minutes,
				'uses'    => $uses,
				'ip_lock' => $ipLock,
				'phones'  => count( $phones ),
			)
		);

		$this->back( 'issued' );
	}

	public function revokeEmergency(): void {
		$this->guard( 'tisa_otp_emergency_revoke' );

		$this->emergency->revoke();
		$this->logger->warning( 'emergency.revoked', array( 'by' => get_current_user_id() ) );

		$this->back( 'revoked' );
	}

	/* ---------------------------------------------------------------------
	 * Blocklist
	 * ------------------------------------------------------------------ */

	private function blocklistCard(): void {
		$rules = $this->blocklist->all();

		echo '<section class="tisa-panel tisa-card"><h2>' . esc_html__( 'فهرست مسدود', 'tisa-otp' ) . '</h2>';
		echo '<p class="tisa-card__intro">' . esc_html__( 'پیش از هر بررسی دیگری رد می‌شوند و هزینه‌ای مصرف نمی‌شود. پیش‌شماره: چند رقم؛ بازه: ستاره (۰۹۱۲*۴۵).', 'tisa-otp' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tisa-access-form">';
		echo '<input type="hidden" name="action" value="tisa_otp_block_add">';
		wp_nonce_field( 'tisa_otp_block_add' );

		printf(
			'<label class="tisa-field tisa-field--wide"><span>%s</span><textarea class="large-text tisa-input" dir="ltr" name="block_patterns" rows="3" placeholder="09121234567\n0912*\n0935*4567"></textarea><em>%s</em></label>',
			esc_html__( 'شماره‌ها یا پیش‌شماره‌ها', 'tisa-otp' ),
			esc_html__( 'هر مورد در یک خط؛ با کاما هم می‌شود.', 'tisa-otp' )
		);

		echo '<div class="tisa-inline">';

		printf(
			'<label class="tisa-field"><span>%s</span><input type="text" class="regular-text tisa-input" name="block_note" placeholder="%s"></label>',
			esc_html__( 'یادداشت', 'tisa-otp' ),
			esc_attr__( 'مثلاً: اسپم ثبت‌نام', 'tisa-otp' )
		);

		printf(
			'<label class="tisa-field"><span>%s</span><input type="number" class="small-text tisa-input" dir="ltr" name="block_days" value="0" min="0" max="365"><em>%s</em></label>',
			esc_html__( 'مدت', 'tisa-otp' ),
			esc_html__( 'روز (۰ = همیشگی)', 'tisa-otp' )
		);

		echo '<button type="submit" class="button button-primary">' . esc_html__( 'افزودن', 'tisa-otp' ) . '</button>';
		echo '</div></form>';

		if ( array() === $rules ) {
			echo '<p class="tisa-note">' . esc_html__( 'هنوز چیزی مسدود نشده است.', 'tisa-otp' ) . '</p>';
			echo '</section>';

			return;
		}

		echo '<table class="widefat striped tisa-rules"><thead><tr>';
		echo '<th>' . esc_html__( 'الگو', 'tisa-otp' ) . '</th>';
		echo '<th>' . esc_html__( 'نوع', 'tisa-otp' ) . '</th>';
		echo '<th>' . esc_html__( 'یادداشت', 'tisa-otp' ) . '</th>';
		echo '<th>' . esc_html__( 'انقضا', 'tisa-otp' ) . '</th>';
		echo '<th>' . esc_html__( 'افزوده شده', 'tisa-otp' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $rules as $rule ) {
			$this->ruleRow( $rule );
		}

		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tisa-access-form tisa-access-form--danger">';
		echo '<input type="hidden" name="action" value="tisa_otp_block_clear">';
		wp_nonce_field( 'tisa_otp_block_clear' );
		echo '<p><button type="submit" class="button">' . esc_html__( 'پاک کردن کل فهرست', 'tisa-otp' ) . '</button></p>';
		echo '</form>';

		echo '</section>';
	}

	private function ruleRow( Rule $rule ): void {
		$until = $rule->until();

		echo '<tr' . ( $rule->isExpired() ? ' class="is-muted"' : '' ) . '>';
		echo '<td><code dir="ltr">' . esc_html( $rule->pattern() ) . '</code></td>';
		echo '<td>' . esc_html( $rule->label() ) . '</td>';
		echo '<td>' . ( '' !== $rule->note() ? esc_html( $rule->note() ) : '&mdash;' ) . '</td>';
		echo '<td>' . ( $until > 0 ? esc_html( $this->stamp( $until ) ) : esc_html__( 'همیشگی', 'tisa-otp' ) ) . '</td>';
		echo '<td>' . ( '' !== $rule->addedAt() ? esc_html( $rule->addedAt() ) : '&mdash;' ) . '</td>';
		echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="tisa_otp_block_remove">';
		echo '<input type="hidden" name="rule_id" value="' . esc_attr( $rule->id() ) . '">';
		wp_nonce_field( 'tisa_otp_block_remove' );
		echo '<button type="submit" class="button-link tisa-remove">' . esc_html__( 'حذف', 'tisa-otp' ) . '</button>';
		echo '</form></td>';
		echo '</tr>';
	}

	public function addRules(): void {
		$this->guard( 'tisa_otp_block_add' );

		$blob  = isset( $_POST['block_patterns'] ) ? sanitize_textarea_field( wp_unslash( $_POST['block_patterns'] ) ) : '';
		$note  = isset( $_POST['block_note'] ) ? sanitize_text_field( wp_unslash( $_POST['block_note'] ) ) : '';
		$days  = isset( $_POST['block_days'] ) ? max( 0, min( 365, (int) $_POST['block_days'] ) ) : 0;
		$until = $days > 0 ? time() + ( $days * DAY_IN_SECONDS ) : 0;

		list( $added, $skipped ) = $this->blocklist->addMany( $blob, $note, $until );

		$this->logger->notice(
			'blocklist.updated',
			array(
				'added'   => $added,
				'skipped' => $skipped,
				'by'      => get_current_user_id(),
			)
		);

		$this->back( 'blocked', array( 'added' => $added, 'skipped' => $skipped ) );
	}

	public function removeRule(): void {
		$this->guard( 'tisa_otp_block_remove' );

		$id      = isset( $_POST['rule_id'] ) ? sanitize_key( wp_unslash( $_POST['rule_id'] ) ) : '';
		$removed = '' !== $id && $this->blocklist->remove( $id );

		$this->back( $removed ? 'unblocked' : 'missing' );
	}

	public function clearRules(): void {
		$this->guard( 'tisa_otp_block_clear' );

		$count = $this->blocklist->count();
		$this->blocklist->clear();

		$this->logger->notice( 'blocklist.cleared', array( 'count' => $count, 'by' => get_current_user_id() ) );

		$this->back( 'cleared', array( 'added' => $count ) );
	}

	/* ---------------------------------------------------------------------
	 * Plumbing
	 * ------------------------------------------------------------------ */

	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'tisa-otp' ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * @param array<string,int> $extra
	 */
	private function back( string $message, array $extra = array() ): void {
		$args = array_merge( array( 'page' => self::SLUG, 'tisa_msg' => $message ), $extra );

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only banner.
		$key = isset( $_GET['tisa_msg'] ) ? sanitize_key( wp_unslash( $_GET['tisa_msg'] ) ) : '';

		if ( '' === $key ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$added = isset( $_GET['added'] ) ? (int) $_GET['added'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped = isset( $_GET['skipped'] ) ? (int) $_GET['skipped'] : 0;

		$map = array(
			'issued'      => array( 'success', __( 'کد اضطراری ساخته شد. همین حالا آن را جایی امن یادداشت کنید؛ کد ذخیره نمی‌شود و دوباره نمایش داده نخواهد شد.', 'tisa-otp' ) ),
			'revoked'     => array( 'success', __( 'کد اضطراری باطل شد.', 'tisa-otp' ) ),
			'code_short'  => array( 'error', sprintf( /* translators: %d: minimum digits */ __( 'کد باید دست‌کم %d رقم باشد.', 'tisa-otp' ), EmergencyToken::MIN_LENGTH ) ),
			'blocked'     => array( 'success', sprintf( /* translators: 1: added, 2: skipped */ __( '%1$d مورد اضافه شد و %2$d مورد تکراری یا نامعتبر بود.', 'tisa-otp' ), $added, $skipped ) ),
			'unblocked'   => array( 'success', __( 'مورد از فهرست حذف شد.', 'tisa-otp' ) ),
			'cleared'     => array( 'success', sprintf( /* translators: %d: count */ __( '%d مورد از فهرست پاک شد.', 'tisa-otp' ), $added ) ),
			'missing'     => array( 'error', __( 'چنین موردی در فهرست نبود.', 'tisa-otp' ) ),
		);

		if ( ! isset( $map[ $key ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			'error' === $map[ $key ][0] ? 'error' : 'success',
			esc_html( $map[ $key ][1] )
		);
	}

	/**
	 * Local time, formatted for the admin.
	 */
	private function stamp( int $timestamp ): string {
		$format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );

		return (string) wp_date( $format, $timestamp );
	}

	private function humanDuration( int $seconds ): string {
		if ( $seconds <= 0 ) {
			return __( 'پایان‌یافته', 'tisa-otp' );
		}

		if ( $seconds < 60 ) {
			/* translators: %d: seconds */
			return sprintf( __( '%d ثانیه', 'tisa-otp' ), $seconds );
		}

		if ( $seconds < 3600 ) {
			/* translators: %d: minutes */
			return sprintf( __( '%d دقیقه', 'tisa-otp' ), (int) floor( $seconds / 60 ) );
		}

		/* translators: %d: hours */
		return sprintf( __( '%d ساعت', 'tisa-otp' ), (int) floor( $seconds / 3600 ) );
	}

	/**
	 * Client address, honouring the trusted-proxy setting.
	 */
	private function clientIp(): string {
		return \TisaOtp\Support\ClientIp::current(
			$this->settings->str( 'proxy_mode', 'none' ),
			$this->settings->str( 'trusted_proxies' )
		);
	}
}
