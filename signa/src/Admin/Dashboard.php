<?php
/**
 * The first thing an owner sees: is it working, and what is left to do?
 *
 * Three health tiles, the last day in four numbers, a setup checklist, the
 * latest errors each with a link to the setting that fixes it, and the latest
 * events. Everything here is read from what the plugin already stores — the
 * gateway registry, the settings and the event table — so nothing on this page
 * is a guess.
 *
 * @package Signa
 */

namespace Signa\Admin;

use Signa\Config\Settings;
use Signa\Gateway\Registry;
use Signa\Log\LogStore;
use Signa\Log\Report;

defined( 'ABSPATH' ) || exit;

final class Dashboard {

	/** The strip covers the last day; the reports cover longer ranges. */
	const DAYS = 1;

	/** @var Settings */
	private $settings;

	/** @var Registry */
	private $gateways;

	/** @var LogStore */
	private $logs;

	public function __construct( Settings $settings, Registry $gateways, LogStore $logs ) {
		$this->settings = $settings;
		$this->gateways = $gateways;
		$this->logs     = $logs;
	}

	public function render(): void {
		$report  = $this->gateways->report();
		$primary = $this->settings->str( 'sms_gateway' );
		$state   = isset( $report[ $primary ] ) ? $report[ $primary ] : array();

		$this->health( $state );
		$this->strip();
		$this->checklist( $state );

		echo '<div class="signa-duo">';
		$this->recentErrors();
		$this->recentEvents();
		echo '</div>';

		$this->shortcuts();
	}

	/* Health ---------------------------------------------------------------- */

	/**
	 * @param array<string,mixed> $state The registry's report for the primary gateway.
	 */
	private function health( array $state ): void {
		$tiles = array( $this->gatewayTile( $state ), $this->channelTile(), $this->loginTile() );

		echo '<section class="signa-card" id="signa-card-health">';
		Layout::cardHead(
			__( 'سلامت افزونه', 'signa' ),
			'check',
			__( 'نمای کلی اتصال و فعالیت', 'signa' ),
			sprintf(
				'<button type="button" class="signa-btn signa-btn--soft signa-btn--sm" data-signa-sms-test>%1$s<span>%2$s</span></button>',
				Icons::svg( 'send', 14 ),
				esc_html__( 'ارسال پیامک آزمایشی', 'signa' )
			)
		);
		echo '<div class="signa-card__body"><div class="signa-health">';

		foreach ( $tiles as $tile ) {
			printf(
				'<div class="signa-htile is-%1$s"><span class="signa-htile__dot" aria-hidden="true"></span><div><p class="signa-htile__t">%2$s</p><p class="signa-htile__d">%3$s</p></div><span class="signa-screen-reader-text">%4$s</span></div>',
				esc_attr( $tile['tone'] ),
				esc_html( $tile['title'] ),
				esc_html( $tile['text'] ),
				esc_html( $this->toneWord( $tile['tone'] ) )
			);
		}

		echo '</div></div></section>';
	}

	private function toneWord( string $tone ): string {
		$words = array(
			'good' => __( 'وضعیت: سالم', 'signa' ),
			'warn' => __( 'وضعیت: نیاز به توجه', 'signa' ),
			'bad'  => __( 'وضعیت: نیاز به رسیدگی', 'signa' ),
			'info' => __( 'وضعیت: اطلاع', 'signa' ),
		);

		return isset( $words[ $tone ] ) ? $words[ $tone ] : '';
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array{title:string,text:string,tone:string}
	 */
	private function gatewayTile( array $state ): array {
		$title = __( 'سامانهٔ پیامکی', 'signa' );

		if ( array() === $state ) {
			return array( 'title' => $title, 'text' => __( 'هنوز سامانه‌ای انتخاب نشده است.', 'signa' ), 'tone' => 'bad' );
		}

		$label = (string) $state['label'];

		if ( empty( $state['ready'] ) ) {
			return array(
				'title' => $title,
				/* translators: %s: gateway name */
				'text'  => sprintf( __( '%s: اعتبارنامه یا پیکربندی کامل نیست.', 'signa' ), $label ),
				'tone'  => 'bad',
			);
		}

		if ( ! empty( $state['resting'] ) ) {
			return array(
				'title' => $title,
				/* translators: %s: gateway name */
				'text'  => sprintf( __( '%s پس از چند خطای پیاپی موقتاً کنار گذاشته شده است.', 'signa' ), $label ),
				'tone'  => 'warn',
			);
		}

		$health = isset( $state['health'] ) && is_array( $state['health'] ) ? $state['health'] : array();
		$text   = isset( $state['health_text'] ) ? trim( (string) $state['health_text'] ) : '';

		if ( array_key_exists( 'ok', $health ) && ! $health['ok'] ) {
			return array(
				'title' => $title,
				/* translators: 1: gateway name, 2: what the last attempt said */
				'text'  => '' !== $text ? sprintf( __( '%1$s — %2$s', 'signa' ), $label, $text ) : sprintf( __( '%s: آخرین ارسال ناموفق بود.', 'signa' ), $label ),
				'tone'  => 'warn',
			);
		}

		return array(
			'title' => $title,
			/* translators: 1: gateway name, 2: what the last attempt said */
			'text'  => '' !== $text ? sprintf( __( '%1$s آماده است — %2$s', 'signa' ), $label, $text ) : sprintf( __( '%s آماده است.', 'signa' ), $label ),
			'tone'  => 'good',
		);
	}

	/**
	 * @return array{title:string,text:string,tone:string}
	 */
	private function channelTile(): array {
		$names   = array(
			'sms'   => __( 'پیامک', 'signa' ),
			'email' => __( 'ایمیل', 'signa' ),
		);
		$primary = $this->settings->str( 'channel', 'sms' );
		$enabled = $this->settings->arr( 'channels_enabled' );
		$parts   = array();

		foreach ( $enabled as $channel ) {
			if ( ! isset( $names[ $channel ] ) ) {
				continue;
			}

			$parts[] = $names[ $channel ] . ' (' . ( $channel === $primary ? __( 'اصلی', 'signa' ) : __( 'پشتیبان', 'signa' ) ) . ')';
		}

		if ( array() === $parts && isset( $names[ $primary ] ) ) {
			$parts[] = $names[ $primary ] . ' (' . __( 'اصلی', 'signa' ) . ')';
		}

		return array(
			'title' => __( 'کانال‌های فعال', 'signa' ),
			'text'  => implode( ' · ', $parts ),
			'tone'  => count( $parts ) > 1 ? 'good' : 'info',
		);
	}

	/**
	 * @return array{title:string,text:string,tone:string}
	 */
	private function loginTile(): array {
		$modes = array(
			'smart'         => __( 'هوشمند', 'signa' ),
			'login_only'    => __( 'فقط ورود', 'signa' ),
			'register_only' => __( 'فقط عضویت', 'signa' ),
		);

		if ( ! $this->settings->bool( 'enabled', true ) ) {
			return array( 'title' => __( 'ورود با کد', 'signa' ), 'text' => __( 'خاموش است؛ فرم در سایت نمایش داده نمی‌شود.', 'signa' ), 'tone' => 'bad' );
		}

		$mode = $this->settings->str( 'auth_mode', 'smart' );

		return array(
			'title' => __( 'ورود با کد', 'signa' ),
			/* translators: %s: authentication mode */
			'text'  => sprintf( __( 'فعال — حالت «%s»', 'signa' ), isset( $modes[ $mode ] ) ? $modes[ $mode ] : $mode ),
			'tone'  => 'good',
		);
	}

	/* Numbers --------------------------------------------------------------- */

	private function strip(): void {
		if ( ! $this->settings->bool( 'logs_enabled', true ) ) {
			echo '<div class="signa-notice signa-notice--warning">' . Icons::svg( 'alert' ) . '<p>' . esc_html__( 'ثبت رویدادها خاموش است؛ آماری برای نمایش نیست.', 'signa' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.

			return;
		}

		$kpis = Report::kpis(
			$this->logs->countByEvent( array_merge( Report::requestEvents(), array( Report::CREATED ) ), self::DAYS )
		);

		$cells = array(
			array( __( 'درخواست کد', 'signa' ), number_format_i18n( (int) $kpis['requests'] ), 'send', '' ),
			array( __( 'موفق', 'signa' ), number_format_i18n( (int) $kpis['sent'] ), 'check', 'is-good' ),
			array( __( 'ناموفق', 'signa' ), number_format_i18n( (int) $kpis['failed'] ), 'alert', (int) $kpis['failed'] > 0 ? 'is-bad' : 'is-quiet' ),
			array( __( 'نرخ موفقیت', 'signa' ), number_format_i18n( (float) $kpis['rate'], 1 ) . '٪', 'chart', 'is-rate' ),
		);

		echo '<div class="signa-kpis signa-kpis--4">';

		foreach ( $cells as $cell ) {
			ReportScreen::kpi( $cell[0], $cell[1], $cell[2], $cell[3] );
		}

		echo '</div>';
		echo '<p class="signa-kpis__cap">' . esc_html__( 'آمار ۲۴ ساعت گذشته', 'signa' ) . ' · <a href="' . esc_url( SettingsScreen::tabUrl( 'reports' ) ) . '">' . esc_html__( 'گزارش کامل', 'signa' ) . '</a></p>';
	}

	/* Checklist ------------------------------------------------------------- */

	/**
	 * @param array<string,mixed> $state
	 */
	private function checklist( array $state ): void {
		$steps = array(
			array(
				'done'  => ! empty( $state['ready'] ),
				'label' => __( 'اتصال سامانهٔ پیامکی', 'signa' ),
				'note'  => '',
				'url'   => SettingsScreen::tabUrl( 'channels' ) . '#signa-card-gateways',
				'goto'  => 'channels',
			),
			array(
				'done'  => in_array( $this->settings->str( 'channel', 'sms' ), $this->settings->arr( 'channels_enabled' ), true ),
				'label' => __( 'پیکربندی کانال اصلی', 'signa' ),
				'note'  => '',
				'url'   => SettingsScreen::tabUrl( 'channels' ),
				'goto'  => 'channels',
			),
			array(
				'done'  => $this->tested(),
				'label' => __( 'ارسال پیامک آزمایشی', 'signa' ),
				'note'  => '',
				'url'   => '',
				'goto'  => '',
			),
			array(
				'done'  => $this->settings->bool( 'replace_wp_login' ) || $this->settings->bool( 'woo_account_form' ),
				'label' => __( 'نمایش فرم به‌جای ورود وردپرس یا ووکامرس', 'signa' ),
				'note'  => __( '(اختیاری)', 'signa' ),
				'url'   => SettingsScreen::tabUrl( 'login' ) . '#signa-card-behaviour',
				'goto'  => 'login',
			),
		);

		$done  = count( array_filter( array_column( $steps, 'done' ) ) );
		$total = count( $steps );

		echo '<section class="signa-card" id="signa-card-setup">';
		Layout::cardHead(
			__( 'راه‌اندازی سریع', 'signa' ),
			'bolt',
			/* translators: 1: steps done, 2: all steps */
			sprintf( __( '%1$s از %2$s مرحله انجام شده', 'signa' ), number_format_i18n( $done ), number_format_i18n( $total ) )
		);

		echo '<div class="signa-card__body">';
		printf(
			'<div class="signa-pbar" role="progressbar" aria-valuemin="0" aria-valuemax="%1$d" aria-valuenow="%2$d" aria-label="%3$s"><i style="width:%4$s%%"></i></div>',
			(int) $total,
			(int) $done,
			esc_attr__( 'پیشرفت راه‌اندازی', 'signa' ),
			esc_attr( (string) round( ( $done / max( 1, $total ) ) * 100 ) )
		);

		echo '<ul class="signa-checks-list">';

		foreach ( $steps as $step ) {
			echo '<li class="signa-ck ' . ( $step['done'] ? 'is-done' : 'is-todo' ) . '">';
			echo '<span class="signa-ck__st" aria-hidden="true">' . ( $step['done'] ? Icons::svg( 'check', 13 ) : '' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
			echo '<span class="signa-ck__t">' . esc_html( $step['label'] ) . '<span class="signa-screen-reader-text"> — ' . esc_html( $step['done'] ? __( 'انجام شده', 'signa' ) : __( 'مانده', 'signa' ) ) . '</span></span>';

			if ( '' !== $step['note'] ) {
				echo '<span class="signa-ck__note">' . esc_html( $step['note'] ) . '</span>';
			}

			if ( ! $step['done'] ) {
				echo '<span class="signa-ck__go">';

				if ( '' === $step['url'] ) {
					echo '<button type="button" class="signa-btn signa-btn--gh signa-btn--xs" data-signa-sms-test>' . esc_html__( 'انجامش بده', 'signa' ) . '</button>';
				} else {
					echo '<a class="signa-btn signa-btn--gh signa-btn--xs" href="' . esc_url( $step['url'] ) . '">' . esc_html__( 'انجامش بده', 'signa' ) . '</a>';
				}

				echo '</span>';
			}

			echo '</li>';
		}

		echo '</ul></div></section>';
	}

	/**
	 * Has a message ever gone out — from the test button or from a real visitor?
	 */
	private function tested(): bool {
		if ( array() !== $this->logs->query( array( 'event' => 'admin.test_send', 'limit' => 1 ) ) ) {
			return true;
		}

		return array() !== $this->logs->query( array( 'event' => Report::SENT, 'limit' => 1 ) );
	}

	/* Errors and events ----------------------------------------------------- */

	private function recentErrors(): void {
		$rows = array();

		foreach ( $this->logs->query( array( 'limit' => 60 ) ) as $row ) {
			if ( in_array( (string) $row->event, Report::failureEvents(), true ) || in_array( (string) $row->severity, array( 'error', 'critical' ), true ) ) {
				$rows[] = $row;
			}

			if ( count( $rows ) >= 4 ) {
				break;
			}
		}

		echo '<section class="signa-card" id="signa-card-errors">';
		Layout::cardHead( __( 'آخرین خطاها', 'signa' ), 'alert', __( 'هر خطا با راه مستقیم رفعش', 'signa' ) );
		echo '<div class="signa-card__body">';

		if ( array() === $rows ) {
			echo '<p class="signa-empty">' . esc_html__( 'خطای تازه‌ای ثبت نشده است.', 'signa' ) . '</p>';
		} else {
			echo '<ul class="signa-frows">';

			foreach ( $rows as $row ) {
				$fix  = $this->fix( $row );
				$text = '' !== trim( (string) $row->message ) ? (string) $row->message : Report::label( (string) $row->event );

				printf(
					'<li class="signa-frow"><span class="signa-frow__when">%1$s</span><span class="signa-frow__what" title="%2$s">%3$s</span>%4$s<a class="signa-frow__fix" href="%5$s">%6$s</a></li>',
					esc_html( ReportScreen::ago( (string) $row->created_at ) ),
					esc_attr( $text ),
					esc_html( Report::label( (string) $row->event ) . ( '' !== trim( (string) $row->message ) ? ' — ' . $text : '' ) ),
					'' !== (string) $row->error_code ? '<code class="signa-code" dir="ltr">' . esc_html( (string) $row->error_code ) . '</code>' : '',
					esc_url( $fix[1] ),
					esc_html( $fix[0] . ' ←' )
				);
			}

			echo '</ul>';
		}

		echo '</div></section>';
	}

	/**
	 * Where an error is fixed: a label and an address.
	 *
	 * @param object $row A log row.
	 * @return array{0:string,1:string}
	 */
	private function fix( $row ): array {
		$event = (string) $row->event;
		$code  = strtolower( (string) $row->error_code );

		if ( 0 === strpos( $event, 'captcha.' ) || false !== strpos( $code, 'captcha' ) ) {
			return array( __( 'برو به کپچا', 'signa' ), SettingsScreen::tabUrl( 'security' ) . '#signa-card-captcha' );
		}

		if ( Report::REJECTED === $event ) {
			if ( false !== strpos( $code, 'block' ) ) {
				return array( __( 'دیدن مسدودی‌ها', 'signa' ), admin_url( 'admin.php?page=' . AccessScreen::SLUG ) );
			}

			if ( false !== strpos( $code, 'role' ) ) {
				return array( __( 'نقش‌های محافظت‌شده', 'signa' ), SettingsScreen::tabUrl( 'security' ) . '#signa-card-roles' );
			}

			return array( __( 'برو به سقف‌ها', 'signa' ), SettingsScreen::tabUrl( 'security' ) . '#signa-card-limits' );
		}

		if ( Report::FAILED === $event || 0 === strpos( $event, 'gateway.' ) ) {
			return array( __( 'بررسی سامانه', 'signa' ), SettingsScreen::tabUrl( 'channels' ) . '#signa-card-gateways' );
		}

		if ( 'registration.failed' === $event || 'user.create_failed' === $event ) {
			return array( __( 'بررسی عضویت', 'signa' ), SettingsScreen::tabUrl( 'login' ) . '#signa-card-registration' );
		}

		if ( 'lookup.ambiguous' === $event ) {
			return array( __( 'بررسی شماره‌ها', 'signa' ), admin_url( 'admin.php?page=' . ToolsScreen::SLUG ) );
		}

		return array( __( 'دیدن رویداد', 'signa' ), add_query_arg( 'event', $event, admin_url( 'admin.php?page=' . LogsScreen::SLUG ) ) );
	}

	private function recentEvents(): void {
		echo '<section class="signa-card" id="signa-card-events">';
		Layout::cardHead( __( 'آخرین رویدادها', 'signa' ), 'list' );
		echo '<div class="signa-card__body">';

		ReportScreen::eventsTable( $this->logs->query( array( 'limit' => 5 ) ), true );

		printf(
			'<p class="signa-card__more"><a href="%1$s">%2$s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . LogsScreen::SLUG ) ),
			esc_html__( 'دیدن همهٔ رویدادها ←', 'signa' )
		);

		echo '</div></section>';
	}

	private function shortcuts(): void {
		$links = array();
		$form  = $this->formUrl();

		if ( '' !== $form ) {
			$links[] = array( $form, 'eye', __( 'مشاهدهٔ فرم ورود', 'signa' ), true );
		}

		$links[] = array( SettingsScreen::tabUrl( 'reports' ), 'chart', __( 'گزارش کامل', 'signa' ), false );
		$links[] = array( SettingsScreen::tabUrl( 'advanced' ), 'sliders', __( 'تنظیمات پیشرفته', 'signa' ), false );

		echo '<div class="signa-shortcuts">';

		foreach ( $links as $link ) {
			printf(
				'<a class="signa-btn signa-btn--gh" href="%1$s"%2$s>%3$s<span>%4$s</span></a>',
				esc_url( $link[0] ),
				$link[3] ? ' target="_blank" rel="noopener"' : '',
				Icons::svg( $link[1], 15 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
				esc_html( $link[2] )
			);
		}

		echo '</div>';
	}

	/**
	 * A page the form is actually on, if the plugin put it somewhere it knows.
	 */
	private function formUrl(): string {
		if ( $this->settings->bool( 'replace_wp_login' ) ) {
			return wp_login_url();
		}

		if ( $this->settings->bool( 'woo_account_form' ) && function_exists( 'wc_get_page_permalink' ) ) {
			return (string) wc_get_page_permalink( 'myaccount' );
		}

		return '';
	}
}

