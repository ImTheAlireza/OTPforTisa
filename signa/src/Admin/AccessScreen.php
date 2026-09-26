<?php

namespace Signa\Admin;

use Signa\Access\EmergencyToken;
use Signa\Blocklist\Blocklist;
use Signa\Blocklist\Rule;
use Signa\Bootable;
use Signa\Config\Settings;
use Signa\Log\Logger;

defined( 'ABSPATH' ) || exit;

final class AccessScreen implements Bootable {
	const SLUG = 'signa-access';
	private $settings;
	private $blocklist;
	private $emergency;
	private $logger;

	public function __construct( Settings $settings, Blocklist $blocklist, EmergencyToken $emergency, Logger $logger ) {
		$this->settings  = $settings;
		$this->blocklist = $blocklist;
		$this->emergency = $emergency;
		$this->logger    = $logger;
	}

	public function boot(): void {
		add_action( 'admin_post_signa_emergency_issue', array( $this, 'issueEmergency' ) );
		add_action( 'admin_post_signa_emergency_revoke', array( $this, 'revokeEmergency' ) );
		add_action( 'admin_post_signa_block_add', array( $this, 'addRules' ) );
		add_action( 'admin_post_signa_block_remove', array( $this, 'removeRule' ) );
		add_action( 'admin_post_signa_block_clear', array( $this, 'clearRules' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		Layout::open( self::SLUG, $this->settings, __( 'دسترسی و مسدودی', 'signa' ) );

		echo '<div class="signa-sechead"><div class="signa-sechead__text"><h2 class="signa-sechead__title">' . esc_html__( 'دسترسی و مسدودی', 'signa' ) . '</h2>';
		echo '<p class="signa-sechead__desc">' . esc_html__( 'دو اهرم اضطراری: کدی برای روزی که پیامک قطع است، و فهرستی از شماره‌هایی که اجازه ورود ندارند.', 'signa' ) . '</p></div></div>';

		$this->notice();
		$this->emergencyCard();
		$this->blocklistCard();

		Layout::close();
	}

	private function emergencyCard(): void {
		$summary = $this->emergency->summary();
		$reveal  = $this->emergency->pull( get_current_user_id() );

		echo '<section class="signa-panel signa-card"><h2>' . esc_html__( 'کد اضطراری', 'signa' ) . '</h2>';
		echo '<p class="signa-card__intro">' . esc_html__( 'برای وقتی که پیامک قطع است. فقط برای حساب‌های موجود؛ کاربر تازه نمی‌سازد.', 'signa' ) . '</p>';

		if ( '' !== $reveal ) {
			echo '<div class="signa-secret"><span class="signa-secret__label">' . esc_html__( 'کد تازه شما (فقط همین یک‌بار نمایش داده می‌شود)', 'signa' ) . '</span>';
			echo '<code class="signa-secret__value" dir="ltr">' . esc_html( $reveal ) . '</code></div>';
		}

		$this->emergencyStatus( $summary );

		if ( $summary['armed'] ) {
			$this->revokeForm();
		}

		$this->issueForm();

		echo '</section>';
	}

	private function emergencyStatus( array $summary ): void {
		echo '<div class="signa-statbar">';

		if ( $summary['armed'] ) {
			$this->stat( 'is-good', $this->humanDuration( (int) $summary['left'] ), __( 'زمان باقی‌مانده', 'signa' ) );
			$this->stat( 'is-good', (string) (int) $summary['uses_left'] . ' / ' . (string) (int) $summary['use_limit'], __( 'استفاده باقی‌مانده', 'signa' ) );
		} else {
			$this->stat( 'is-bad', __( 'غیرفعال', 'signa' ), __( 'وضعیت', 'signa' ) );
		}

		$this->stat( '', $summary['ip_lock'] ? __( 'فقط IP سازنده', 'signa' ) : __( 'بدون قید IP', 'signa' ), __( 'قید شبکه', 'signa' ) );

		$phones = (array) $summary['phones'];
		$this->stat(
			'',
			array() === $phones ? __( 'همه حساب‌ها', 'signa' ) : (string) count( $phones ),
			__( 'محدوده شماره', 'signa' )
		);

		$this->stat( 0 < (int) $summary['fails'] ? 'is-bad' : '', (string) (int) $summary['fails'], __( 'تلاش ناموفق', 'signa' ) );

		echo '</div>';

		if ( array() !== $phones ) {
			echo '<p class="signa-desc">' . esc_html__( 'محدود به: ', 'signa' ) . '<code dir="ltr">' . esc_html( implode( ', ', $phones ) ) . '</code></p>';
		}
	}

	private function stat( string $class, string $value, string $label ): void {
		printf(
			'<div class="signa-stat %1$s"><span class="signa-stat__value">%2$s</span><span class="signa-stat__label">%3$s</span></div>',
			esc_attr( $class ),
			esc_html( $value ),
			esc_html( $label )
		);
	}

	private function issueForm(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="signa-access-form">';
		echo '<input type="hidden" name="action" value="signa_emergency_issue">';
		wp_nonce_field( 'signa_emergency_issue' );

		echo '<div class="signa-inline">';
		printf(
			'<input type="text" class="regular-text signa-input" dir="ltr" inputmode="numeric" autocomplete="off" name="emergency_code" data-signa-emergency-code placeholder="%s" value="">',
			esc_attr__( 'کد ۶ تا ۱۲ رقمی', 'signa' )
		);
		echo '<button type="button" class="button" data-signa-emergency-generate>' . esc_html__( 'تولید تصادفی', 'signa' ) . '</button>';
		echo '</div>';

		echo '<div class="signa-inline">';

		printf(
			'<label class="signa-field"><span>%s</span><input type="number" class="small-text signa-input" dir="ltr" name="emergency_minutes" value="30" min="5" max="1440"> <em>%s</em></label>',
			esc_html__( 'اعتبار', 'signa' ),
			esc_html__( 'دقیقه', 'signa' )
		);

		printf(
			'<label class="signa-field"><span>%s</span><input type="number" class="small-text signa-input" dir="ltr" name="emergency_uses" value="1" min="1" max="50"> <em>%s</em></label>',
			esc_html__( 'تعداد استفاده', 'signa' ),
			esc_html__( 'بار', 'signa' )
		);

		echo '<label class="signa-check"><input type="checkbox" name="emergency_ip_lock" value="1" checked><span>' . esc_html__( 'فقط از همین IP کار کند', 'signa' ) . '</span></label>';

		echo '</div>';

		printf(
			'<label class="signa-field signa-field--wide"><span>%s</span><input type="text" class="regular-text signa-input" dir="ltr" name="emergency_phones" placeholder="09121234567, 09351234567"><em>%s</em></label>',
			esc_html__( 'محدود به این شماره‌ها', 'signa' ),
			esc_html__( 'خالی بگذارید تا روی هر حساب موجود کار کند.', 'signa' )
		);

		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'ساخت کد اضطراری', 'signa' ) . '</button></p>';
		echo '</form>';
	}

	private function revokeForm(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="signa-access-form signa-access-form--danger">';
		echo '<input type="hidden" name="action" value="signa_emergency_revoke">';
		wp_nonce_field( 'signa_emergency_revoke' );
		echo '<p><button type="submit" class="button signa-danger">' . esc_html__( 'لغو فوری کد اضطراری', 'signa' ) . '</button></p>';
		echo '</form>';
	}

	public function issueEmergency(): void {
		$this->guard( 'signa_emergency_issue' );

		$code = isset( $_POST['emergency_code'] ) ? sanitize_text_field( wp_unslash( $_POST['emergency_code'] ) ) : '';
		$code = preg_replace( '/[^0-9]/', '', \Signa\Support\Phone::latinDigits( $code ) );

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

			$normalized = \Signa\Support\Phone::normalize( $candidate );

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
		$this->guard( 'signa_emergency_revoke' );

		$this->emergency->revoke();
		$this->logger->warning( 'emergency.revoked', array( 'by' => get_current_user_id() ) );

		$this->back( 'revoked' );
	}

	private function blocklistCard(): void {
		$rules = $this->blocklist->all();

		echo '<section class="signa-panel signa-card"><h2>' . esc_html__( 'فهرست مسدود', 'signa' ) . '</h2>';
		echo '<p class="signa-card__intro">' . esc_html__( 'پیش از هر بررسی دیگری رد می‌شوند و هزینه‌ای مصرف نمی‌شود. پیش‌شماره: چند رقم؛ بازه: ستاره (۰۹۱۲*۴۵).', 'signa' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="signa-access-form">';
		echo '<input type="hidden" name="action" value="signa_block_add">';
		wp_nonce_field( 'signa_block_add' );

		printf(
			'<label class="signa-field signa-field--wide"><span>%s</span><textarea class="large-text signa-input" dir="ltr" name="block_patterns" rows="3" placeholder="09121234567\n0912*\n0935*4567"></textarea><em>%s</em></label>',
			esc_html__( 'شماره‌ها یا پیش‌شماره‌ها', 'signa' ),
			esc_html__( 'هر مورد در یک خط؛ با کاما هم می‌شود.', 'signa' )
		);

		echo '<div class="signa-inline">';

		printf(
			'<label class="signa-field"><span>%s</span><input type="text" class="regular-text signa-input" name="block_note" placeholder="%s"></label>',
			esc_html__( 'یادداشت', 'signa' ),
			esc_attr__( 'مثلاً: اسپم ثبت‌نام', 'signa' )
		);

		printf(
			'<label class="signa-field"><span>%s</span><input type="number" class="small-text signa-input" dir="ltr" name="block_days" value="0" min="0" max="365"><em>%s</em></label>',
			esc_html__( 'مدت', 'signa' ),
			esc_html__( 'روز (صفر یعنی همیشه)', 'signa' )
		);

		echo '<button type="submit" class="button button-primary">' . esc_html__( 'افزودن', 'signa' ) . '</button>';
		echo '</div></form>';

		if ( array() === $rules ) {
			echo '<p class="signa-note">' . esc_html__( 'هنوز چیزی مسدود نشده است.', 'signa' ) . '</p>';
			echo '</section>';

			return;
		}

		echo '<table class="widefat striped signa-rules"><thead><tr>';
		echo '<th>' . esc_html__( 'الگو', 'signa' ) . '</th>';
		echo '<th>' . esc_html__( 'نوع', 'signa' ) . '</th>';
		echo '<th>' . esc_html__( 'یادداشت', 'signa' ) . '</th>';
		echo '<th>' . esc_html__( 'انقضا', 'signa' ) . '</th>';
		echo '<th>' . esc_html__( 'افزوده شده', 'signa' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $rules as $rule ) {
			$this->ruleRow( $rule );
		}

		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="signa-access-form signa-access-form--danger">';
		echo '<input type="hidden" name="action" value="signa_block_clear">';
		wp_nonce_field( 'signa_block_clear' );
		echo '<p><button type="submit" class="button">' . esc_html__( 'پاک کردن کل فهرست', 'signa' ) . '</button></p>';
		echo '</form>';

		echo '</section>';
	}

	private function ruleRow( Rule $rule ): void {
		$until = $rule->until();

		echo '<tr' . ( $rule->isExpired() ? ' class="is-muted"' : '' ) . '>';
		echo '<td><code dir="ltr">' . esc_html( $rule->pattern() ) . '</code></td>';
		echo '<td>' . esc_html( $rule->label() ) . '</td>';
		echo '<td>' . ( '' !== $rule->note() ? esc_html( $rule->note() ) : '&mdash;' ) . '</td>';
		echo '<td>' . ( $until > 0 ? esc_html( $this->stamp( $until ) ) : esc_html__( 'همیشگی', 'signa' ) ) . '</td>';
		echo '<td>' . ( '' !== $rule->addedAt() ? esc_html( $rule->addedAt() ) : '&mdash;' ) . '</td>';
		echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="signa_block_remove">';
		echo '<input type="hidden" name="rule_id" value="' . esc_attr( $rule->id() ) . '">';
		wp_nonce_field( 'signa_block_remove' );
		echo '<button type="submit" class="button-link signa-remove">' . esc_html__( 'حذف', 'signa' ) . '</button>';
		echo '</form></td>';
		echo '</tr>';
	}

	public function addRules(): void {
		$this->guard( 'signa_block_add' );

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
		$this->guard( 'signa_block_remove' );

		$id      = isset( $_POST['rule_id'] ) ? sanitize_key( wp_unslash( $_POST['rule_id'] ) ) : '';
		$removed = '' !== $id && $this->blocklist->remove( $id );

		$this->back( $removed ? 'unblocked' : 'missing' );
	}

	public function clearRules(): void {
		$this->guard( 'signa_block_clear' );

		$count = $this->blocklist->count();
		$this->blocklist->clear();

		$this->logger->notice( 'blocklist.cleared', array( 'count' => $count, 'by' => get_current_user_id() ) );

		$this->back( 'cleared', array( 'added' => $count ) );
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'signa' ) );
		}

		check_admin_referer( $action );
	}

	private function back( string $message, array $extra = array() ): void {
		$args = array_merge( array( 'page' => self::SLUG, 'signa_msg' => $message ), $extra );

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function notice(): void {
		$key = isset( $_GET['signa_msg'] ) ? sanitize_key( wp_unslash( $_GET['signa_msg'] ) ) : '';

		if ( '' === $key ) {
			return;
		}

		$added = isset( $_GET['added'] ) ? (int) $_GET['added'] : 0;
		$skipped = isset( $_GET['skipped'] ) ? (int) $_GET['skipped'] : 0;

		$map = array(
			'issued'      => array( 'success', __( 'کد اضطراری ساخته شد. همین حالا آن را جایی امن یادداشت کنید؛ کد ذخیره نمی‌شود و دوباره نمایش داده نخواهد شد.', 'signa' ) ),
			'revoked'     => array( 'success', __( 'کد اضطراری باطل شد.', 'signa' ) ),
			'code_short'  => array( 'error', sprintf(  __( 'کد باید دست‌کم %d رقم باشد.', 'signa' ), EmergencyToken::MIN_LENGTH ) ),
			'blocked'     => array( 'success', sprintf(  __( '%1$d مورد اضافه شد و %2$d مورد تکراری یا نامعتبر بود.', 'signa' ), $added, $skipped ) ),
			'unblocked'   => array( 'success', __( 'مورد از فهرست حذف شد.', 'signa' ) ),
			'cleared'     => array( 'success', sprintf(  __( '%d مورد از فهرست پاک شد.', 'signa' ), $added ) ),
			'missing'     => array( 'error', __( 'چنین موردی در فهرست نبود.', 'signa' ) ),
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

	private function stamp( int $timestamp ): string {
		$format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );

		return (string) wp_date( $format, $timestamp );
	}

	private function humanDuration( int $seconds ): string {
		if ( $seconds <= 0 ) {
			return __( 'پایان‌یافته', 'signa' );
		}

		if ( $seconds < 60 ) {
			return sprintf( __( '%d ثانیه', 'signa' ), $seconds );
		}

		if ( $seconds < 3600 ) {
			return sprintf( __( '%d دقیقه', 'signa' ), (int) floor( $seconds / 60 ) );
		}

		return sprintf( __( '%d ساعت', 'signa' ), (int) floor( $seconds / 3600 ) );
	}

	private function clientIp(): string {
		return \Signa\Support\ClientIp::current(
			$this->settings->str( 'proxy_mode', 'none' ),
			$this->settings->str( 'trusted_proxies' )
		);
	}
}
