<?php
/**
 * The settings page: a dashboard, six sections of settings and the reports.
 *
 * The six setting sections share one form that posts to the native Settings
 * API (`options.php`), which keeps nonces, capability checks and sanitising in
 * one well-tested place. With script, admin.js switches between them without a
 * page load and saves the same POST in the background; without it, every link
 * is a real address and the save button is a real submit.
 *
 * The dashboard and the reports are pages, not forms: nothing on them is
 * saved, so they are drawn only when they are the page being opened.
 *
 * @package Signa
 */

namespace Signa\Admin;

use Signa\Captcha\Manager;
use Signa\Config\Sanitizer;
use Signa\Config\Settings;
use Signa\Support\Transport;
use Signa\Gateway\Registry;
use Signa\Log\LogStore;
use Signa\Registration\FieldCatalog;
use Signa\Registration\FieldSchema;
use Signa\User\AccessPolicy;

defined( 'ABSPATH' ) || exit;

final class SettingsScreen {

	/** @var Settings */
	private $settings;

	/** @var Controls */
	private $controls;

	/** @var Registry */
	private $gateways;

	/** @var FieldSchema */
	private $schema;

	/** @var Manager */
	private $captcha;

	/** @var LogStore */
	private $logs;

	/** @var ReportScreen */
	private $reports;

	/** @var Dashboard */
	private $dashboard;

	public function __construct( Settings $settings, Controls $controls, Registry $gateways, FieldSchema $schema, Manager $captcha, LogStore $logs, ReportScreen $reports, Dashboard $dashboard ) {
		$this->settings  = $settings;
		$this->controls  = $controls;
		$this->gateways  = $gateways;
		$this->schema    = $schema;
		$this->captcha   = $captcha;
		$this->logs      = $logs;
		$this->reports   = $reports;
		$this->dashboard = $dashboard;
	}

	/**
	 * Sanitize callback registered with register_setting().
	 *
	 * @param mixed $input
	 */
	public function sanitize( $input ): array {
		return Sanitizer::sanitize( is_array( $input ) ? $input : array(), $this->settings->all() );
	}

	/**
	 * Address of one section.
	 *
	 * Everything that points at a section builds its URL here, so a link written
	 * in one place cannot disagree with the navigation.
	 */
	public static function tabUrl( string $tab ): string {
		return admin_url( 'admin.php?page=' . Menu::ROOT . '&tab=' . $tab );
	}

	/**
	 * Section ids, with the addresses of the pre-2.0 tabs mapped onto them so an
	 * old bookmark still lands somewhere sensible.
	 */
	public function currentTab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dash'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$legacy = array(
			'general'      => 'login',
			'registration' => 'login',
			'code'         => 'channels',
			'gateways'     => 'channels',
			'design'       => 'formskin',
			'store'        => 'integ',
			'data'         => 'advanced',
		);

		if ( isset( $legacy[ $tab ] ) ) {
			$tab = $legacy[ $tab ];
		}

		return array_key_exists( $tab, ScreenNav::sections() ) ? $tab : 'dash';
	}

	/**
	 * What each section is for, and which self-tests prove it.
	 *
	 * @return array<string,array{desc:string,tests:array<string,string>}>
	 */
	private function sectionMeta(): array {
		return array(
			'login'    => array(
				'desc'  => __( 'چه کسی، چطور و به کجا وارد می‌شود؛ و حساب‌های تازه چطور ساخته می‌شوند.', 'signa' ),
				'tests' => array(
					'general'      => __( 'آزمایش تنظیمات ورود', 'signa' ),
					'registration' => __( 'آزمایش فرم عضویت', 'signa' ),
				),
			),
			'channels' => array(
				'desc'  => __( 'سامانهٔ پیامکی، کانال‌های ارسال، کد و متن پیام‌ها.', 'signa' ),
				'tests' => array(
					'gateways' => __( 'آزمایش سامانه‌های پیامکی', 'signa' ),
					'code'     => __( 'آزمایش ساخت کد', 'signa' ),
				),
			),
			'formskin' => array(
				'desc'  => __( 'پوسته، رنگ، متن‌ها و فیلدهای فرم؛ پیش‌نمایش کنار صفحه با هر تغییر به‌روز می‌شود.', 'signa' ),
				'tests' => array(
					'design' => __( 'آزمایش رنگ‌ها و کنتراست', 'signa' ),
				),
			),
			'security' => array(
				'desc'  => __( 'سقف‌های ارسال، کپچا، نقش‌های محافظت‌شده و شماره‌های مورد اعتماد.', 'signa' ),
				'tests' => array(
					'security' => __( 'آزمایش کپچا در این مرورگر', 'signa' ),
				),
			),
			'integ'    => array(
				'desc'  => __( 'ووکامرس، پوستهٔ وودمارت و ویجت المنتور.', 'signa' ),
				'tests' => array(
					'store' => __( 'آزمایش فروشگاه', 'signa' ),
				),
			),
			'advanced' => array(
				'desc'  => __( 'ثبت رویدادها، کلیدهای داده، ارسال مستقیم و حذف داده‌ها.', 'signa' ),
				'tests' => array(
					'data' => __( 'آزمایش ثبت رویداد', 'signa' ),
				),
			),
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab      = $this->currentTab();
		$sections = ScreenNav::sections();

		Layout::open( $tab, $this->settings, $sections[ $tab ][0] );

		$this->egressNotice();

		/*
		 * The dashboard and the reports are pages, not forms: there is nothing to
		 * save, so they skip the form and its save bar. The reports page draws the
		 * same body the reports screen draws.
		 */
		if ( 'dash' === $tab ) {
			$this->dashboard->render();
			Layout::close();

			return;
		}

		if ( 'reports' === $tab ) {
			$this->pageHead( $sections['reports'][0], __( 'آمار ارسال، دلیل‌های شکست و آخرین رویدادها؛ همه از جدول رویدادهای خود افزونه.', 'signa' ) );
			$this->reports->body( $this->reports->range() );
			Layout::close();

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '" class="signa-form" id="signa-settings" data-signa-settings novalidate>';

		settings_fields( 'signa_group' );

		$meta = $this->sectionMeta();

		foreach ( ScreenNav::formSections() as $id ) {
			printf(
				'<section class="signa-section" id="signa-section-%1$s" data-signa-pane="%1$s" aria-labelledby="signa-section-%1$s-title"%2$s>',
				esc_attr( $id ),
				$tab === $id ? '' : ' hidden'
			);

			$this->sectionHead( $id, $sections[ $id ][0], $meta[ $id ]['desc'], $meta[ $id ]['tests'] );

			$method = $id . 'Section';
			$this->{$method}();

			echo '</section>';
		}

		$this->saveBar();

		echo '</form>';

		Layout::close();
	}

	/* Frame pieces ---------------------------------------------------------- */

	private function pageHead( string $title, string $desc ): void {
		echo '<div class="signa-sechead"><div class="signa-sechead__text">';
		echo '<h2 class="signa-sechead__title">' . esc_html( $title ) . '</h2>';
		echo '<p class="signa-sechead__desc">' . esc_html( $desc ) . '</p>';
		echo '</div></div>';
	}

	/**
	 * Title of a section and the self-tests that prove it.
	 *
	 * Every section can prove itself without leaving it: the buttons open a
	 * modal that is filled from `/admin/check`.
	 *
	 * @param array<string,string> $tests kind => button label
	 */
	private function sectionHead( string $id, string $title, string $desc, array $tests ): void {
		echo '<div class="signa-sechead"><div class="signa-sechead__text">';
		echo '<h2 class="signa-sechead__title" id="signa-section-' . esc_attr( $id ) . '-title">' . esc_html( $title ) . '</h2>';
		echo '<p class="signa-sechead__desc">' . esc_html( $desc ) . '</p>';
		echo '</div>';

		echo '<div class="signa-sechead__side" role="group" aria-label="' . esc_attr__( 'آزمایش این بخش', 'signa' ) . '">';
		echo '<span class="signa-sechead__cap">' . esc_html__( 'آزمایش این بخش', 'signa' ) . '</span>';

		foreach ( $tests as $kind => $label ) {
			printf(
				'<button type="button" class="signa-btn signa-btn--soft signa-btn--sm" data-signa-check="%1$s"%2$s>%3$s<span>%4$s</span></button>',
				esc_attr( $kind ),
				'security' === $kind ? ' data-signa-captcha-test' : '',
				Icons::svg( 'check', 14 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
				esc_html( $label )
			);
		}

		echo '</div></div>';
	}

	/**
	 * One card: tile, heading, a line of description, and an optional control
	 * on the far side of the header.
	 *
	 * @param callable|null $side Prints into the header's far side.
	 */
	private function card( string $title, callable $body, string $desc = '', string $icon = 'sliders', ?callable $side = null, string $id = '', string $class = '' ): void {
		printf(
			'<section class="signa-card%1$s"%2$s>',
			'' !== $class ? ' ' . esc_attr( $class ) : '',
			'' !== $id ? ' id="signa-card-' . esc_attr( $id ) . '"' : ''
		);

		echo '<header class="signa-card__head">';
		echo '<span class="signa-tile">' . Icons::svg( $icon, 18 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
		echo '<div class="signa-card__heading"><h3 class="signa-card__title">' . esc_html( $title ) . '</h3>';

		if ( '' !== $desc ) {
			echo '<p class="signa-card__desc">' . esc_html( $desc ) . '</p>';
		}

		echo '</div>';

		if ( null !== $side ) {
			echo '<div class="signa-card__side">';
			$side();
			echo '</div>';
		}

		echo '</header><div class="signa-card__body">';
		$body();
		echo '</div></section>';
	}

	/**
	 * A folded group for settings most sites never touch.
	 */
	private function accordion( string $title, callable $body, string $chip = '', string $icon = 'sliders', bool $open = false, string $chipClass = '' ): void {
		echo '<details class="signa-acc"' . ( $open ? ' open' : '' ) . '><summary>';
		echo Icons::svg( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
		echo '<span class="signa-acc__t">' . esc_html( $title ) . '</span><span class="signa-acc__side">';

		if ( '' !== $chip ) {
			echo '<span class="signa-chip' . ( '' !== $chipClass ? ' ' . esc_attr( $chipClass ) : '' ) . '">' . esc_html( $chip ) . '</span>';
		}

		echo Icons::svg( 'chevron', 16, 'signa-acc__chev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
		echo '</span></summary><div class="signa-acc__bd">';
		$body();
		echo '</div></details>';
	}

	/**
	 * Clickable placeholders: a click puts the token into the field at the caret.
	 *
	 * @param string[] $tokens
	 */
	private function tokens( string $target, array $tokens ): void {
		echo '<div class="signa-tokens" role="group" aria-label="' . esc_attr__( 'نشانه‌های قابل درج', 'signa' ) . '">';

		foreach ( $tokens as $token ) {
			printf(
				'<button type="button" class="signa-code signa-token" data-signa-token="%1$s" data-target="%2$s" dir="ltr">%1$s</button>',
				esc_attr( $token ),
				esc_attr( $target )
			);
		}

		echo '</div>';
	}

	private function saveBar(): void {
		echo '<div class="signa-savebar" data-signa-savebar data-state="clean" role="region" aria-label="' . esc_attr__( 'ذخیرهٔ تنظیمات', 'signa' ) . '">';
		echo '<span class="signa-savebar__st" data-signa-save-state role="status" aria-live="polite">' . esc_html__( 'تنظیمات ذخیره شده‌اند', 'signa' ) . '</span>';
		echo '<button type="button" class="signa-savebar__rst" data-signa-reset>' . esc_html__( 'بازنشانی', 'signa' ) . '</button>';
		printf(
			'<button type="submit" name="submit" class="signa-btn signa-btn--pri" data-signa-save>%1$s<span>%2$s</span></button>',
			Icons::svg( 'check', 15 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
			esc_html__( 'ذخیره تنظیمات', 'signa' )
		);
		echo '</div>';
	}

	/* Sections -------------------------------------------------------------- */

	private function loginSection(): void {
		$c = $this->controls;

		$this->card(
			__( 'رفتار ورود', 'signa' ),
			function () use ( $c ) {
				$c->toggleRow( 'enabled', __( 'فعال‌سازی افزونه', 'signa' ), __( 'ورود و عضویت با کد یک‌بارمصرف فعال باشد.', 'signa' ) );

				$c->field(
					__( 'حالت احراز', 'signa' ),
					function () use ( $c ) {
						$c->cards(
							'auth_mode',
							array(
								'smart'         => array( 'label' => __( 'هوشمند', 'signa' ), 'desc' => __( 'کاربر موجود وارد می‌شود و کاربر تازه عضو', 'signa' ) ),
								'login_only'    => array( 'label' => __( 'فقط ورود', 'signa' ), 'desc' => __( 'عضویت خودکار بسته است', 'signa' ) ),
								'register_only' => array( 'label' => __( 'فقط عضویت', 'signa' ), 'desc' => __( 'مناسب فرم‌های ثبت‌نام', 'signa' ) ),
							),
							__( 'حالت احراز', 'signa' )
						);
					}
				);

				$c->toggleRow( 'replace_wp_login', __( 'جایگزینی صفحهٔ ورود وردپرس', 'signa' ), __( 'فرم OTP روی wp-login.php نمایش داده شود؛ فرم کلاسیک پنهان می‌شود.', 'signa' ) );
				$c->toggleRow( 'password_login_off', __( 'ورود فقط با کد', 'signa' ), __( 'ورود با گذرواژه واقعاً بسته می‌شود، نه فقط پنهان. راه بازگشت: کد اضطراری.', 'signa' ) );
				$c->toggleRow( 'remember_login', __( 'ماندن در حساب', 'signa' ), __( 'کاربر ۱۴ روز وارد بماند؛ خاموش = نشست با بستن مرورگر تمام می‌شود.', 'signa' ) );
				$c->toggleRow( 'prevent_enumeration', __( 'پنهان‌سازی وجود حساب', 'signa' ), __( 'پیام‌ها یکسان باشند تا معلوم نشود شماره‌ای قبلاً ثبت شده یا نه.', 'signa' ) );
			},
			__( 'خط‌مشی کلی احراز و نشست کاربر', 'signa' ),
			'login',
			null,
			'behaviour'
		);

		$this->card(
			__( 'سازگاری با کش صفحه', 'signa' ),
			function () use ( $c ) {
				$c->cards(
					'cache_mode',
					array(
						'auto'   => array(
							'label' => __( 'nonce تازه از سرور', 'signa' ),
							'desc'  => __( 'هنگام باز شدن فرم یک nonce تازه گرفته می‌شود و اگر رد شد، یک بار دیگر تلاش می‌شود.', 'signa' ),
						),
						'inline' => array(
							'label' => __( 'چاپ در صفحه', 'signa' ),
							'desc'  => __( 'بدون درخواست اضافه؛ فقط وقتی کش صفحه و CDN خاموش است.', 'signa' ),
						),
					),
					__( 'سازگاری با کش صفحه', 'signa' )
				);

				$c->notice( __( 'اگر کش صفحه، وارنیش یا Cloudflare دارید، «nonce تازه از سرور» را نگه دارید؛ وگرنه فرم خطای ۴۰۳ می‌گیرد.', 'signa' ) );
			},
			'',
			'refresh'
		);

		$this->card(
			__( 'مقصد پس از ورود', 'signa' ),
			function () use ( $c ) {
				$c->grid(
					2,
					function () use ( $c ) {
						$c->field( __( 'پس از ورود', 'signa' ), function () use ( $c ) {
							$c->text( 'login_redirect', home_url( '/' ), 'url' );
						}, __( 'خالی بگذارید تا کاربر به حساب کاربری (یا صفحهٔ اصلی) برود.', 'signa' ), $c->id( 'login_redirect' ) );

						$c->field( __( 'پس از عضویت', 'signa' ), function () use ( $c ) {
							$c->text( 'register_redirect', '', 'url' );
						}, __( 'خالی = همان مقصد پس از ورود.', 'signa' ), $c->id( 'register_redirect' ) );
					}
				);
			},
			'',
			'globe'
		);

		$this->card(
			__( 'فرم عضویت', 'signa' ),
			function () use ( $c ) {
				$c->toggleRow( 'registration_enabled', __( 'فعال بودن عضویت', 'signa' ), __( 'شماره‌های تازه بتوانند حساب بسازند.', 'signa' ) );
				$c->toggleRow( 'auto_register', __( 'عضویت خودکار', 'signa' ), __( 'پس از تأیید کد، حساب ساخته شود.', 'signa' ) );

				$c->field(
					__( 'ترتیب مراحل', 'signa' ),
					function () use ( $c ) {
						$c->cards(
							'registration_flow',
							array(
								'code_then_fields' => array( 'label' => __( 'اول کد، بعد فرم', 'signa' ), 'desc' => __( 'شماره پیش از دریافت اطلاعات تأیید می‌شود', 'signa' ) ),
								'fields_then_code' => array( 'label' => __( 'اول فرم، بعد کد', 'signa' ), 'desc' => __( 'کمترین پیامک هدررفته', 'signa' ) ),
							),
							__( 'ترتیب مراحل', 'signa' )
						);
					}
				);

				$c->grid(
					3,
					function () use ( $c ) {
						$c->field( __( 'ایمیل', 'signa' ), function () use ( $c ) {
							$c->select(
								'email_mode',
								array(
									'off'      => __( 'جمع‌آوری نشود', 'signa' ),
									'optional' => __( 'اختیاری', 'signa' ),
									'required' => __( 'الزامی', 'signa' ),
								)
							);
						}, '', $c->id( 'email_mode' ) );

						$c->field( __( 'نام کاربری', 'signa' ), function () use ( $c ) {
							$c->select(
								'username_from',
								array(
									'phone'          => __( 'بر پایهٔ شماره', 'signa' ),
									'phone_prefixed' => __( 'شماره با پیشوند user', 'signa' ),
									'email'          => __( 'بر پایهٔ ایمیل', 'signa' ),
								)
							);
						}, '', $c->id( 'username_from' ) );

						$c->field( __( 'نام نمایشی', 'signa' ), function () use ( $c ) {
							$c->select(
								'display_name_from',
								array(
									'full_name'  => __( 'نام و نام خانوادگی', 'signa' ),
									'first_name' => __( 'فقط نام', 'signa' ),
									'phone'      => __( 'شمارهٔ ماسک‌شده', 'signa' ),
								)
							);
						}, '', $c->id( 'display_name_from' ) );
					}
				);

				$c->row( __( 'نقش کاربران تازه', 'signa' ), function () use ( $c ) {
					$c->select( 'default_role', AccessPolicy::selectableRoles() );
				}, __( 'نقشی که حساب ساخته‌شده از فرم می‌گیرد.', 'signa' ), $c->id( 'default_role' ) );

				$c->toggleRow( 'send_welcome_email', __( 'ایمیل خوش‌آمد', 'signa' ), __( 'پس از عضویت ایمیل خوش‌آمد ارسال شود.', 'signa' ) );

				$c->grid(
					2,
					function () use ( $c ) {
						$c->field( __( 'عنوان فرم عضویت', 'signa' ), function () use ( $c ) {
							$c->text( 'register_heading', '', 'text', false );
						}, '', $c->id( 'register_heading' ) );

						$c->field( __( 'توضیح فرم عضویت', 'signa' ), function () use ( $c ) {
							$c->textarea( 'register_subheading', 2 );
						}, '', $c->id( 'register_subheading' ) );
					}
				);

				$c->richNotice(
					sprintf(
						/* translators: %s: link to the form appearance section */
						__( 'فیلدهای مرحلهٔ دوم (نشانی، شهر، کد پستی…) در %s مدیریت می‌شوند تا همهٔ ظاهر فرم یک‌جا باشد.', 'signa' ),
						'<a href="' . esc_url( self::tabUrl( 'formskin' ) . '#signa-card-fields' ) . '" data-signa-goto="formskin"><b>' . esc_html__( 'ظاهر فرم ← فیلدها', 'signa' ) . '</b></a>'
					)
				);
			},
			__( 'رفتار حساب‌های تازه', 'signa' ),
			'user-add',
			null,
			'registration'
		);
	}

	/**
	 * Host name shown in the WebOTP hint, e.g. `example.com`.
	 */
	private function webOtpDomain(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) && '' !== $host ? strtolower( $host ) : __( 'دامنهٔ شما', 'signa' );
	}

	private function channelsSection(): void {
		$c       = $this->controls;
		$options = $this->gateways->labels();
		$report  = $this->gateways->report();

		$this->card(
			__( 'سامانه‌های پیامکی', 'signa' ),
			function () use ( $c, $options, $report ) {
				$c->grid(
					2,
					function () use ( $c, $options ) {
						$c->field( __( 'سامانهٔ اصلی', 'signa' ), function () use ( $c, $options ) {
							$c->select( 'sms_gateway', $options );
						}, '', $c->id( 'sms_gateway' ) );

						$c->field( __( 'سامانهٔ پشتیبان', 'signa' ), function () use ( $c, $options ) {
							$c->select( 'sms_backup_gateway', array( '' => __( 'بدون پشتیبان', 'signa' ) ) + $options );
						}, __( 'فقط برای خطاهای موقت: تایم‌اوت، خطای ۵xx، اتمام اعتبار.', 'signa' ), $c->id( 'sms_backup_gateway' ) );
					}
				);

				echo '<div class="signa-accs">';

				foreach ( $this->gateways->all() as $id => $driver ) {
					$this->gatewayAccordion( (string) $id, $driver, isset( $report[ $id ] ) ? $report[ $id ] : array() );
				}

				echo '</div>';

				$c->richNotice( __( 'اعتبارنامه‌ها را می‌توانید در wp-config.php هم بگذارید، مثلاً <code>SIGNA_SMSIR_API_KEY</code>؛ امن‌تر از پایگاه داده.', 'signa' ) );
			},
			__( 'فقط سامانهٔ اصلی باز است؛ بقیه تا لزوم جمع‌اند.', 'signa' ),
			'send',
			function () {
				printf(
					'<button type="button" class="signa-btn signa-btn--soft signa-btn--sm" data-signa-sms-test>%1$s<span>%2$s</span></button>',
					Icons::svg( 'send', 14 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
					esc_html__( 'ارسال پیامک آزمایشی', 'signa' )
				);
			},
			'gateways'
		);

		$this->card(
			__( 'کانال‌ها و قواعد ارسال', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'کانال اصلی', 'signa' ), function () use ( $c ) {
					$c->select(
						'channel',
						array(
							'sms'   => __( 'پیامک', 'signa' ),
							'email' => __( 'ایمیل', 'signa' ),
						)
					);
				}, __( 'اولویت اول ارسال کد.', 'signa' ), $c->id( 'channel' ) );

				$c->row( __( 'کانال‌های فعال', 'signa' ), function () use ( $c ) {
					$c->checkboxList(
						'channels_enabled',
						array(
							'sms'   => __( 'پیامک', 'signa' ),
							'email' => __( 'ایمیل', 'signa' ),
						)
					);
				}, __( 'اگر کانال اصلی ناموفق باشد، کانال فعال بعدی امتحان می‌شود.', 'signa' ) );

				$c->toggleRow( 'failover_enabled', __( 'جابه‌جایی خودکار', 'signa' ), __( 'در صورت خطای موقت، کانال یا سامانهٔ بعدی امتحان شود.', 'signa' ) );

				$c->toggleRow(
					'webotp_enabled',
					__( 'خواندن خودکار کد (WebOTP)', 'signa' ),
					sprintf(
						/* translators: %s: the WebOTP binding line, for example @example.com #12345 */
						__( 'خط %s به انتهای پیامک متنی اضافه می‌شود تا مرورگر کد را خودش بخواند.', 'signa' ),
						'@' . $this->webOtpDomain() . ' #12345'
					)
				);
			},
			__( 'کد به کدام کانال برود و چطور', 'signa' ),
			'sliders'
		);

		$this->card(
			__( 'کد یک‌بارمصرف', 'signa' ),
			function () use ( $c ) {
				$c->grid(
					3,
					function () use ( $c ) {
						$c->field( __( 'طول کد', 'signa' ), function () use ( $c ) {
							$c->number( 'code_length', 4, 8, __( 'رقم', 'signa' ) );
						}, '', $c->id( 'code_length' ) );

						$c->field( __( 'اعتبار کد', 'signa' ), function () use ( $c ) {
							$c->number( 'code_ttl', 30, 3600, __( 'ثانیه', 'signa' ) );
						}, '', $c->id( 'code_ttl' ) );

						$c->field( __( 'مهلت پاسخ سرور', 'signa' ), function () use ( $c ) {
							$c->number( 'request_timeout', 5, 60, __( 'ثانیه', 'signa' ) );
						}, '', $c->id( 'request_timeout' ) );

						$c->field( __( 'حداکثر تلاش برای کد', 'signa' ), function () use ( $c ) {
							$c->number( 'verify_attempts', 2, 15, __( 'بار', 'signa' ) );
						}, '', $c->id( 'verify_attempts' ) );

						$c->field( __( 'فاصلهٔ بین دو ارسال', 'signa' ), function () use ( $c ) {
							$c->number( 'resend_delay', 10, 1800, __( 'ثانیه', 'signa' ) );
						}, '', $c->id( 'resend_delay' ) );
					}
				);

				$c->toggleRow( 'auto_verify', __( 'بررسی خودکار کد', 'signa' ), __( 'به‌محض کامل شدن کد، بدون زدن دکمه بررسی شود.', 'signa' ) );

				$c->field(
					__( 'محل نگهداری کد', 'signa' ),
					function () use ( $c ) {
						$c->cards(
							'code_store',
							array(
								'database' => array( 'label' => __( 'جدول اختصاصی', 'signa' ), 'desc' => __( 'پیش‌فرض؛ بدون نیاز به کش شیء', 'signa' ) ),
								'cache'    => array( 'label' => __( 'کش شیء', 'signa' ), 'desc' => __( 'سبک‌تر؛ فقط با Redis/Memcached پایدار', 'signa' ) ),
							),
							__( 'محل نگهداری کد', 'signa' )
						);
					}
				);
			},
			'',
			'key'
		);

		$this->card(
			__( 'متن پیام‌ها', 'signa' ),
			function () use ( $c ) {
				$c->field( __( 'متن پیامک', 'signa' ), function () use ( $c ) {
					$c->textarea( 'sms_template', 2, 'کد ورود {site}: {code}' );
					$this->tokens( $c->id( 'sms_template' ), array( '{code}', '{site}', '{minutes}', '{phone}', '{domain}', '{webotp}' ) );
				}, '', $c->id( 'sms_template' ) );

				$c->grid(
					2,
					function () use ( $c ) {
						$c->field( __( 'موضوع ایمیل', 'signa' ), function () use ( $c ) {
							$c->text( 'email_subject', '', 'text', false );
						}, '', $c->id( 'email_subject' ) );

						$c->field( __( 'فرستنده', 'signa' ), function () use ( $c ) {
							$c->text( 'email_from', (string) get_option( 'admin_email' ), 'email' );
						}, __( 'خالی = نشانی پیش‌فرض وردپرس.', 'signa' ), $c->id( 'email_from' ) );
					}
				);

				$c->field( __( 'متن ایمیل', 'signa' ), function () use ( $c ) {
					$c->textarea( 'email_body', 3 );
					$this->tokens( $c->id( 'email_body' ), array( '{code}', '{minutes}', '{site}' ) );
				}, '', $c->id( 'email_body' ) );
			},
			'',
			'mail'
		);
	}

	/**
	 * One gateway, folded unless it is the one in use.
	 *
	 * @param array<string,mixed> $state The registry's report for this gateway.
	 */
	private function gatewayAccordion( string $id, $driver, array $state ): void {
		$c = $this->controls;

		$active = ! empty( $state['active'] );
		$backup = ! empty( $state['backup'] );
		$title  = $driver->label();

		if ( $active ) {
			$title .= ' — ' . __( 'سامانهٔ اصلی', 'signa' );
		} elseif ( $backup ) {
			$title .= ' — ' . __( 'پشتیبان', 'signa' );
		}

		if ( ! empty( $state['ready'] ) ) {
			$chip  = __( 'آماده', 'signa' );
			$class = 'signa-chip--ok';
		} elseif ( ! empty( $state['missing'] ) ) {
			$chip  = __( 'نیاز به تکمیل', 'signa' );
			$class = $active ? 'signa-chip--warn' : '';
		} else {
			$chip  = __( 'ایراد پیکربندی', 'signa' );
			$class = 'signa-chip--warn';
		}

		$this->accordion(
			$title,
			function () use ( $c, $driver, $state, $active, $backup ) {
				$fields = $driver->fields();

				$c->grid(
					count( $fields ) >= 3 ? 3 : 2,
					function () use ( $c, $fields ) {
						foreach ( $fields as $key => $field ) {
							$label = isset( $field['label'] ) ? (string) $field['label'] : (string) $key;
							$hint  = isset( $field['hint'] ) ? (string) $field['hint'] : '';
							$type  = isset( $field['type'] ) ? (string) $field['type'] : 'text';

							$c->field(
								$label,
								function () use ( $c, $key, $type ) {
									if ( 'password' === $type ) {
										$c->secret( (string) $key );
									} else {
										$c->text( (string) $key );
									}
								},
								$hint,
								$c->id( (string) $key )
							);
						}
					}
				);

				// Only the gateways in use are worth a list of what is wrong with them.
				if ( ( $active || $backup ) && ! empty( $state['issues'] ) && is_array( $state['issues'] ) ) {
					foreach ( $state['issues'] as $issue ) {
						if ( is_scalar( $issue ) && '' !== (string) $issue ) {
							$c->notice( (string) $issue, 'warning' );
						}
					}
				}

				if ( $active ) {
					$c->notice( __( 'این سامانه در حال حاضر سامانهٔ اصلی است.', 'signa' ), 'success' );
				} elseif ( $backup ) {
					$c->notice( __( 'این سامانه به‌عنوان پشتیبان انتخاب شده است.', 'signa' ) );
				} elseif ( ! empty( $state['missing'] ) ) {
					$c->notice( __( 'اعتبارنامهٔ این سامانه کامل نیست.', 'signa' ), 'warning' );
				}

				if ( ! empty( $state['docs'] ) ) {
					printf(
						'<p class="signa-footnote"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
						esc_url( (string) $state['docs'] ),
						esc_html__( 'مستندات سامانه', 'signa' )
					);
				}
			},
			$chip,
			'send',
			$active,
			$class
		);
	}

	private function formskinSection(): void {
		$c = $this->controls;

		echo '<div class="signa-split"><div class="signa-split__main">';

		$this->card(
			__( 'پوسته', 'signa' ),
			function () use ( $c ) {
				$c->cards(
					'skin',
					array(
						'line'  => array( 'label' => __( 'خطی', 'signa' ), 'desc' => __( 'کمترین تزئین، سریع‌ترین بارگذاری', 'signa' ) ),
						'card'  => array( 'label' => __( 'کارت', 'signa' ), 'desc' => __( 'کارت سایه‌دار روی پس‌زمینه', 'signa' ) ),
						'glass' => array( 'label' => __( 'شیشه‌ای', 'signa' ), 'desc' => __( 'لایهٔ نیمه‌شفاف و محو', 'signa' ) ),
						'slate' => array( 'label' => __( 'تیره', 'signa' ), 'desc' => __( 'مناسب صفحه‌های تیره', 'signa' ) ),
						'pill'  => array( 'label' => __( 'گرد', 'signa' ), 'desc' => __( 'گوشه‌های کاملاً گرد', 'signa' ) ),
					),
					__( 'پوسته', 'signa' )
				);
			},
			'',
			'palette'
		);

		$this->card(
			__( 'اندازه و رنگ', 'signa' ),
			function () use ( $c ) {
				$c->grid(
					2,
					function () use ( $c ) {
						$c->field( __( 'رنگ تأکیدی', 'signa' ), function () use ( $c ) {
							$c->color( 'accent' );
						}, '', $c->id( 'accent' ) );

						$c->field( __( 'رنگ زمینهٔ فرم', 'signa' ), function () use ( $c ) {
							$c->color( 'surface' );
						}, '', $c->id( 'surface' ) );

						$c->field( __( 'گردی گوشه‌ها', 'signa' ), function () use ( $c ) {
							$c->number( 'radius', 0, 40, 'px' );
						}, '', $c->id( 'radius' ) );

						$c->field( __( 'عرض فرم', 'signa' ), function () use ( $c ) {
							$c->number( 'width', 280, 900, 'px' );
						}, '', $c->id( 'width' ) );

						$c->field( __( 'چینش', 'signa' ), function () use ( $c ) {
							$c->select(
								'align',
								array(
									'center' => __( 'وسط', 'signa' ),
									'start'  => __( 'ابتدا', 'signa' ),
									'end'    => __( 'انتها', 'signa' ),
								)
							);
						}, '', $c->id( 'align' ) );

						$c->field( __( 'فونت', 'signa' ), function () use ( $c ) {
							$c->select(
								'form_font',
								array(
									'vazirmatn' => __( 'وزیرمتن (همراه افزونه)', 'signa' ),
									'theme'     => __( 'فونت پوسته', 'signa' ),
									'custom'    => __( 'فونت دلخواه', 'signa' ),
								)
							);
						}, '', $c->id( 'form_font' ) );
					}
				);

				$c->field( __( 'نام فونت دلخواه', 'signa' ), function () use ( $c ) {
					$c->text( 'form_font_custom', 'Vazirmatn, Tahoma, sans-serif' );
				}, __( 'فقط وقتی «فونت دلخواه» انتخاب شده باشد.', 'signa' ), $c->id( 'form_font_custom' ), 'signa-f--solo' );

				$c->toggleRow( 'style_isolation', __( 'جداسازی استایل (Shadow DOM)', 'signa' ), __( 'قالب سایت نتواند فونت، رنگ و شکل کنترل‌های فرم را عوض کند.', 'signa' ) );

				$c->field(
					__( 'ورودی کد', 'signa' ),
					function () use ( $c ) {
						$c->cards(
							'code_input',
							array(
								'boxes'  => array( 'label' => __( 'خانه‌های جدا', 'signa' ), 'desc' => __( 'هر رقم در یک خانه', 'signa' ) ),
								'single' => array( 'label' => __( 'یک کادر', 'signa' ), 'desc' => __( 'ساده و فشرده', 'signa' ) ),
							),
							__( 'ورودی کد', 'signa' )
						);
					}
				);
			},
			'',
			'sliders'
		);

		$this->card(
			__( 'برند و متن‌ها', 'signa' ),
			function () use ( $c ) {
				$c->toggleRow( 'show_brand', __( 'نمایش لوگو', 'signa' ), __( 'لوگو بالای فرم نمایش داده شود.', 'signa' ) );

				$c->grid(
					2,
					function () use ( $c ) {
						$c->field( __( 'نشانی لوگو', 'signa' ), function () use ( $c ) {
							echo '<span class="signa-inline-field">';
							$c->text( 'brand_logo', '', 'url' );
							echo '<button type="button" class="signa-btn signa-btn--gh signa-btn--sm" data-signa-pick-media="' . esc_attr( $c->id( 'brand_logo' ) ) . '">' . esc_html__( 'کتابخانه', 'signa' ) . '</button>';
							echo '</span>';
						}, '', $c->id( 'brand_logo' ) );

						$c->field( __( 'عرض لوگو', 'signa' ), function () use ( $c ) {
							$c->number( 'brand_width', 32, 320, 'px' );
						}, '', $c->id( 'brand_width' ) );

						$c->field( __( 'عنوان فرم', 'signa' ), function () use ( $c ) {
							$c->text( 'form_heading', '', 'text', false );
						}, '', $c->id( 'form_heading' ) );

						$c->field( __( 'توضیح فرم', 'signa' ), function () use ( $c ) {
							$c->textarea( 'form_subheading', 2 );
						}, '', $c->id( 'form_subheading' ) );

						$c->field( __( 'دکمهٔ دریافت کد', 'signa' ), function () use ( $c ) {
							$c->text( 'label_send', '', 'text', false );
						}, '', $c->id( 'label_send' ) );

						$c->field( __( 'دکمهٔ ورود', 'signa' ), function () use ( $c ) {
							$c->text( 'label_verify', '', 'text', false );
						}, '', $c->id( 'label_verify' ) );

						$c->field( __( 'ارسال دوباره', 'signa' ), function () use ( $c ) {
							$c->text( 'label_resend', '', 'text', false );
						}, '', $c->id( 'label_resend' ) );

						$c->field( __( 'ویرایش شماره', 'signa' ), function () use ( $c ) {
							$c->text( 'label_edit_phone', '', 'text', false );
						}, '', $c->id( 'label_edit_phone' ) );
					}
				);

				$c->toggleRow( 'terms_enabled', __( 'متن قوانین', 'signa' ), __( 'یک خط قوانین زیر فرم نمایش داده شود.', 'signa' ) );

				$c->grid(
					2,
					function () use ( $c ) {
						$c->field( __( 'متن قوانین', 'signa' ), function () use ( $c ) {
							$c->text( 'terms_text', '', 'text', false );
						}, '', $c->id( 'terms_text' ) );

						$c->field( __( 'نشانی صفحهٔ قوانین', 'signa' ), function () use ( $c ) {
							$c->text( 'terms_url', '', 'url' );
						}, '', $c->id( 'terms_url' ) );
					}
				);
			},
			'',
			'user-add'
		);

		$this->card(
			__( 'فیلدهای عضویت', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'مجموعهٔ آماده', 'signa' ), function () use ( $c ) {
					$c->select( 'field_preset', FieldCatalog::labels() );
				}, '', $c->id( 'field_preset' ) );

				$this->fieldRepeater();

				$c->description(
					sprintf(
						/* translators: %d: number of active fields */
						esc_html__( 'اکنون %d فیلد در فرم عضویت فعال است.', 'signa' ),
						count( $this->schema->active() )
					)
				);
			},
			__( 'مرحلهٔ دوم فرم، وقتی شماره‌ای تازه است', 'signa' ),
			'fields',
			null,
			'fields'
		);

		$this->accordion(
			__( 'CSS و JS دلخواه', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'محل اعمال', 'signa' ), function () use ( $c ) {
					$c->select(
						'custom_code_scope',
						array(
							'form_pages' => __( 'فقط صفحه‌های دارای فرم', 'signa' ),
							'everywhere' => __( 'همهٔ صفحه‌ها', 'signa' ),
						)
					);
				}, '', $c->id( 'custom_code_scope' ) );

				$c->field( __( 'CSS دلخواه', 'signa' ), function () use ( $c ) {
					$c->textarea( 'custom_css', 5, '.signa { }', true );
				}, '', $c->id( 'custom_css' ) );

				$c->field( __( 'JS دلخواه', 'signa' ), function () use ( $c ) {
					$c->textarea( 'custom_js', 4, '', true );
				}, __( 'فقط برای مدیران دارای دسترسی unfiltered_html ذخیره می‌شود.', 'signa' ), $c->id( 'custom_js' ) );
			},
			__( 'پیشرفته', 'signa' ),
			'code'
		);

		echo '</div>';

		$this->preview();

		echo '</div>';
	}

	/**
	 * The real form, drawn by the real template, next to the settings.
	 *
	 * The frame loads the preview endpoint once; admin.js then posts the
	 * unsaved values to the same endpoint and swaps the result in, so what the
	 * owner sees is what the site will print — not a drawing of it.
	 */
	private function preview(): void {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . FormPreview::ACTION ), FormPreview::ACTION );

		echo '<aside class="signa-pv" data-signa-preview data-url="' . esc_url( $url ) . '" aria-label="' . esc_attr__( 'پیش‌نمایش زنده', 'signa' ) . '">';
		echo '<div class="signa-pv__bar"><span class="signa-pv__title">' . Icons::svg( 'eye', 14 ) . esc_html__( 'پیش‌نمایش زنده', 'signa' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
		echo '<span class="signa-pv__steps" role="group" aria-label="' . esc_attr__( 'مرحلهٔ فرم', 'signa' ) . '">';

		foreach ( array( 'phone' => __( 'شماره', 'signa' ), 'code' => __( 'کد', 'signa' ), 'fields' => __( 'عضویت', 'signa' ) ) as $step => $label ) {
			printf(
				'<button type="button" class="signa-pv__step%1$s" data-signa-preview-step="%2$s" aria-pressed="%3$s">%4$s</button>',
				'phone' === $step ? ' is-current' : '',
				esc_attr( $step ),
				'phone' === $step ? 'true' : 'false',
				esc_html( $label )
			);
		}

		echo '</span></div>';
		echo '<div class="signa-pv__frame"><iframe class="signa-pv__iframe" title="' . esc_attr__( 'پیش‌نمایش فرم ورود', 'signa' ) . '" src="' . esc_url( $url ) . '" loading="lazy"></iframe></div>';
		echo '<p class="signa-footnote">' . esc_html__( 'همان قالب و همان استایل سایت؛ تغییرها پیش از ذخیره هم این‌جا دیده می‌شوند.', 'signa' ) . '</p>';
		echo '</aside>';
	}

	private function fieldRepeater(): void {
		$fields = (array) $this->settings->arr( 'fields' );
		$base   = $this->controls->name( 'fields' );

		echo '<div class="signa-repeater" data-signa-repeater data-base="' . esc_attr( $base ) . '">';
		echo '<div class="signa-repeater__head" aria-hidden="true"><span>' . esc_html__( 'شناسه', 'signa' ) . '</span><span>' . esc_html__( 'برچسب', 'signa' ) . '</span><span>' . esc_html__( 'نوع', 'signa' ) . '</span><span>' . esc_html__( 'کلید متا', 'signa' ) . '</span><span>' . esc_html__( 'ذخیره در', 'signa' ) . '</span><span>' . esc_html__( 'الزامی', 'signa' ) . '</span><span></span></div>';
		echo '<div class="signa-repeater__rows" data-signa-rows>';

		foreach ( $fields as $index => $field ) {
			$this->repeaterRow( $base, (int) $index, (array) $field );
		}

		echo '</div>';

		printf(
			'<button type="button" class="signa-btn signa-btn--gh signa-btn--sm" data-signa-add-row>%s</button>',
			esc_html__( 'افزودن فیلد', 'signa' )
		);

		echo '<template data-signa-row-template>';
		$this->repeaterRow( $base, '__i__', array() );
		echo '</template>';

		echo '</div>';
	}

	private function repeaterRow( string $base, $index, array $field ): void {
		$name   = $base . '[' . $index . ']';
		$id     = isset( $field['id'] ) ? (string) $field['id'] : '';
		$label  = isset( $field['label'] ) ? (string) $field['label'] : '';
		$type   = isset( $field['type'] ) ? (string) $field['type'] : 'text';
		$meta   = isset( $field['meta_key'] ) ? (string) $field['meta_key'] : '';
		$target = isset( $field['target'] ) ? (string) $field['target'] : 'meta';

		echo '<div class="signa-repeater__row">';

		printf( '<input type="text" class="signa-inp signa-inp--mono" name="%1$s[id]" value="%2$s" dir="ltr" placeholder="city" aria-label="%3$s">', esc_attr( $name ), esc_attr( $id ), esc_attr__( 'شناسه', 'signa' ) );
		printf( '<input type="text" class="signa-inp" name="%1$s[label]" value="%2$s" placeholder="%3$s" aria-label="%3$s">', esc_attr( $name ), esc_attr( $label ), esc_attr__( 'برچسب', 'signa' ) );

		echo '<select class="signa-inp" name="' . esc_attr( $name ) . '[type]" aria-label="' . esc_attr__( 'نوع', 'signa' ) . '">';
		foreach ( FieldCatalog::types() as $option ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $option ), selected( $type, $option, false ) );
		}
		echo '</select>';

		printf( '<input type="text" class="signa-inp signa-inp--mono" name="%1$s[meta_key]" value="%2$s" dir="ltr" placeholder="signa_city" aria-label="%3$s">', esc_attr( $name ), esc_attr( $meta ), esc_attr__( 'کلید متا', 'signa' ) );

		echo '<select class="signa-inp" name="' . esc_attr( $name ) . '[target]" aria-label="' . esc_attr__( 'ذخیره در', 'signa' ) . '">';
		foreach ( array( 'meta' => __( 'متا', 'signa' ), 'core' => __( 'هستهٔ وردپرس', 'signa' ), 'wc' => __( 'ووکامرس', 'signa' ) ) as $value => $text ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $target, $value, false ), esc_html( $text ) );
		}
		echo '</select>';

		printf(
			'<label class="signa-check"><input type="hidden" name="%1$s[required]" value="0"><input type="checkbox" class="signa-chk" name="%1$s[required]" value="1"%2$s aria-label="%3$s"></label>',
			esc_attr( $name ),
			checked( ! empty( $field['required'] ) && '0' !== $field['required'], true, false ),
			esc_attr__( 'الزامی', 'signa' )
		);

		printf( '<button type="button" class="signa-repeater__remove" data-signa-remove-row aria-label="%1$s">%2$s</button>', esc_attr__( 'حذف این فیلد', 'signa' ), Icons::svg( 'trash', 15 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.

		echo '</div>';
	}

	private function securitySection(): void {
		$c = $this->controls;

		$this->card(
			__( 'سقف‌های ارسال و تلاش', 'signa' ),
			function () use ( $c ) {
				$c->grid(
					3,
					function () use ( $c ) {
						$c->field( __( 'بازهٔ شمارش', 'signa' ), function () use ( $c ) {
							$c->number( 'window_minutes', 1, 1440, __( 'دقیقه', 'signa' ) );
						}, '', $c->id( 'window_minutes' ) );

						$c->field( __( 'هر شماره در بازه', 'signa' ), function () use ( $c ) {
							$c->number( 'limit_per_phone', 1, 100, __( 'ارسال', 'signa' ) );
						}, '', $c->id( 'limit_per_phone' ) );

						$c->field( __( 'هر IP در بازه', 'signa' ), function () use ( $c ) {
							$c->number( 'limit_per_ip', 1, 500, __( 'ارسال', 'signa' ) );
						}, '', $c->id( 'limit_per_ip' ) );

						$c->field( __( 'هر IP در روز', 'signa' ), function () use ( $c ) {
							$c->number( 'limit_per_ip_daily', 1, 5000, __( 'ارسال', 'signa' ) );
						}, '', $c->id( 'limit_per_ip_daily' ) );

						$c->field( __( 'تأیید کد هر IP', 'signa' ), function () use ( $c ) {
							$c->number( 'limit_verify_per_ip', 5, 1000, __( 'در بازه', 'signa' ) );
						}, '', $c->id( 'limit_verify_per_ip' ) );

						$c->field( __( 'کل سایت در روز', 'signa' ), function () use ( $c ) {
							$c->number( 'limit_per_site_daily', 0, 100000, __( 'ارسال', 'signa' ) );
						}, __( 'صفر = بی‌سقف.', 'signa' ), $c->id( 'limit_per_site_daily' ) );
					}
				);

				echo '<p class="signa-footnote">' . esc_html__( 'خاموش کردن کلید بالا همهٔ سقف‌ها را یک‌جا کنار می‌گذارد؛ فقط برای محیط آزمایش.', 'signa' ) . '</p>';
			},
			__( 'جلوگیری از سوءاستفاده و هزینهٔ پیامک', 'signa' ),
			'shield',
			function () use ( $c ) {
				$c->toggle( 'throttle_enabled', __( 'فعال', 'signa' ) );
			},
			'limits'
		);

		$captcha = $this->captcha;

		$this->card(
			__( 'کپچا', 'signa' ),
			function () use ( $c, $captcha ) {
				$c->row( __( 'سرویس', 'signa' ), function () use ( $c, $captcha ) {
					$c->select( 'captcha_provider', $captcha->labels() );
				}, '', $c->id( 'captcha_provider' ) );

				$c->grid(
					2,
					function () use ( $c ) {
						$c->field( __( 'کلید سایت', 'signa' ), function () use ( $c ) {
							$c->text( 'captcha_site_key' );
						}, '', $c->id( 'captcha_site_key' ) );

						$c->field( __( 'کلید خصوصی', 'signa' ), function () use ( $c ) {
							$c->secret( 'captcha_secret_key' );
						}, '', $c->id( 'captcha_secret_key' ) );
					}
				);

				$c->field(
					__( 'زمان نمایش', 'signa' ),
					function () use ( $c ) {
						$c->cards(
							'captcha_trigger',
							array(
								'always'      => array( 'label' => __( 'همیشه', 'signa' ), 'desc' => __( 'هر درخواست ارسال کد', 'signa' ) ),
								'after_limit' => array( 'label' => __( 'پس از چند تلاش', 'signa' ), 'desc' => __( 'تجربهٔ روان‌تر برای انسان‌ها', 'signa' ) ),
							),
							__( 'زمان نمایش', 'signa' )
						);
					}
				);

				$c->toggleRow( 'captcha_fail_open', __( 'اگر سرویس کپچا قطع بود', 'signa' ), __( 'روشن = کاربر رد نمی‌شود (هانی‌پات و سقف‌ها فعال می‌مانند)؛ خاموش = بدون کپچا ورودی نیست.', 'signa' ) );
				$c->toggleRow( 'captcha_arcaptcha_v3', __( 'آرکپچا نسخهٔ ۳', 'signa' ), __( 'اگر حساب آرکپچای شما v3 (امتیازی و نامرئی) است روشن کنید.', 'signa' ) );

				$c->grid(
					2,
					function () use ( $c ) {
						$c->field( __( 'حداقل امتیاز (reCAPTCHA v3)', 'signa' ), function () use ( $c ) {
							$c->text( 'captcha_score', '0.5' );
						}, '', $c->id( 'captcha_score' ) );

						$c->field( __( 'مهلت بارگذاری اسکریپت', 'signa' ), function () use ( $c ) {
							$c->number( 'captcha_timeout', 3000, 20000, __( 'میلی‌ثانیه', 'signa' ) );
						}, '', $c->id( 'captcha_timeout' ) );
					}
				);

				$c->field( __( 'نشانی جایگزین اسکریپت', 'signa' ), function () use ( $c ) {
					$c->text( 'captcha_script_override', 'https://my-mirror.example/1/api.js' );
				}, __( 'اختیاری؛ برای وقتی دامنهٔ رسمی سرویس روی شبکهٔ کاربر باز نمی‌شود.', 'signa' ), $c->id( 'captcha_script_override' ), 'signa-f--solo' );
			},
			__( 'جلوگیری از ربات در فرم ورود', 'signa' ),
			'lock',
			null,
			'captcha'
		);

		$this->card(
			__( 'نقش‌ها و معافیت‌ها', 'signa' ),
			function () use ( $c ) {
				$c->toggleRow( 'guard_roles', __( 'بستن ورود پیامکی نقش‌های حساس', 'signa' ), __( 'این نقش‌ها فقط با گذرواژه وارد شوند.', 'signa' ) );

				$c->field( __( 'نقش‌ها', 'signa' ), function () use ( $c ) {
					$c->text( 'guarded_roles', 'administrator,editor,shop_manager' );
				}, __( 'نام نقش‌ها با کاما؛ مدیر کل همیشه محافظت می‌شود.', 'signa' ), $c->id( 'guarded_roles' ), 'signa-f--solo' );

				$c->toggleRow( 'trusted_enabled', __( 'شماره‌های مورد اعتماد', 'signa' ), __( 'شماره‌های این فهرست از کپچا و سقف‌ها معاف باشند.', 'signa' ) );

				$c->grid(
					2,
					function () use ( $c ) {
						$c->field( __( 'شماره‌ها', 'signa' ), function () use ( $c ) {
							$c->textarea( 'trusted_numbers', 3, "09121234567\n0912*\n0935*4567", true );
						}, __( 'هر خط یک شماره، پیش‌شماره (0912) یا الگو (0935*4567).', 'signa' ), $c->id( 'trusted_numbers' ) );

						$c->field( __( 'معافیت از', 'signa' ), function () use ( $c ) {
							$c->text( 'trusted_skip', 'captcha,throttle' );
						}, __( 'captcha و throttle با کاما؛ فهرست مسدود هرگز نادیده گرفته نمی‌شود.', 'signa' ), $c->id( 'trusted_skip' ) );
					}
				);
			},
			'',
			'key',
			null,
			'roles'
		);

		$this->accordion(
			__( 'پروکسی و IP واقعی', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'سرآیند معتبر', 'signa' ), function () use ( $c ) {
					$c->select(
						'proxy_mode',
						array(
							'none'       => __( 'هیچ (فقط REMOTE_ADDR)', 'signa' ),
							'cloudflare' => __( 'کلادفلر (CF-Connecting-IP)', 'signa' ),
							'forwarded'  => 'X-Forwarded-For',
							'real_ip'    => 'X-Real-IP',
						)
					);
				}, __( 'سرآیند فقط از پروکسی‌های مورد اعتماد پذیرفته می‌شود.', 'signa' ), $c->id( 'proxy_mode' ) );

				$c->field( __( 'پروکسی‌های مورد اعتماد', 'signa' ), function () use ( $c ) {
					$c->text( 'trusted_proxies', '173.245.48.0/20, 127.0.0.1' );
				}, __( 'IP یا CIDR، با کاما؛ پشت Cloudflare لازم است.', 'signa' ), $c->id( 'trusted_proxies' ), 'signa-f--solo' );
			},
			__( 'معمولاً لازم نیست', 'signa' ),
			'globe'
		);
	}

	private function integSection(): void {
		$c = $this->controls;

		$woo      = class_exists( 'WooCommerce' );
		$woodmart = \Signa\Integrations\WoodMart::detected();
		$elementor = defined( 'ELEMENTOR_VERSION' ) || class_exists( '\Elementor\Plugin' );

		$this->card(
			__( 'ووکامرس', 'signa' ),
			function () use ( $c, $woo ) {
				if ( ! $woo ) {
					$c->notice( __( 'ووکامرس فعال نیست؛ این تنظیمات فعلاً اثری ندارند.', 'signa' ), 'warning' );
				}

				$c->toggleRow( 'woo_account_form', __( 'جایگزینی فرم «حساب کاربری»', 'signa' ), __( 'فرم ورود پیش‌فرض ووکامرس به فرم OTP تبدیل شود.', 'signa' ) );
				$c->toggleRow( 'woo_checkout_gate', __( 'ورود اجباری پیش از تسویه', 'signa' ), __( 'کاربر مهمان به صفحهٔ تسویه حساب نرسد.', 'signa' ) );

				$c->grid(
					2,
					function () use ( $c ) {
						$c->field( __( 'صفحهٔ ورود', 'signa' ), function () use ( $c ) {
							$c->text( 'woo_checkout_page', wp_login_url(), 'url' );
						}, '', $c->id( 'woo_checkout_page' ) );

						$c->field( __( 'پیام صفحهٔ تسویه', 'signa' ), function () use ( $c ) {
							$c->textarea( 'woo_checkout_notice', 2 );
						}, '', $c->id( 'woo_checkout_notice' ) );
					}
				);

				$c->toggleRow( 'sync_billing_phone', __( 'همگام‌سازی شمارهٔ صورتحساب', 'signa' ), __( 'شمارهٔ تأییدشده در billing_phone هم ذخیره شود.', 'signa' ) );
				$c->toggleRow( 'link_guest_orders', __( 'اتصال سفارش‌های مهمان', 'signa' ), __( 'پس از عضویت، سفارش‌های مهمان با همان شماره به حساب وصل شوند.', 'signa' ) );
			},
			'',
			'cart',
			function () use ( $woo ) {
				echo '<span class="signa-chip ' . ( $woo ? 'signa-chip--ok' : '' ) . '">' . esc_html( $woo ? __( 'فعال', 'signa' ) : __( 'نصب نیست', 'signa' ) ) . '</span>';
			},
			'woo'
		);

		$this->card(
			__( 'وودمارت (پوسته)', 'signa' ),
			function () use ( $c, $woodmart ) {
				if ( ! $woodmart ) {
					$c->notice( __( 'این تنظیمات فقط روی سایت‌های وودمارت اثر دارند.', 'signa' ) );
				}

				$c->toggleRow( 'woodmart_sidebar', __( 'فرم در سایدبار ورود', 'signa' ), __( 'فرم OTP در کشوی ورود وودمارت رندر شود؛ همان AJAX و کپچا.', 'signa' ) );

				$c->row( __( 'فرم رمز عبور وودمارت', 'signa' ), function () use ( $c ) {
					$c->select(
						'woodmart_mode',
						array(
							'replace' => __( 'جایگزین شود', 'signa' ),
							'append'  => __( 'بماند (فرم OTP زیرش بیاید)', 'signa' ),
						)
					);
				}, __( 'هیچ فایلی از پوسته تغییر نمی‌کند.', 'signa' ), $c->id( 'woodmart_mode' ) );

				$c->toggleRow( 'woodmart_account_block', __( 'حذف بخش «ساخت حساب» وودمارت', 'signa' ), __( 'فرم افزونه خودش عضویت دارد؛ اگر عضویت خاموش باشد، این بخش می‌ماند.', 'signa' ) );
			},
			'',
			'globe',
			function () use ( $woodmart ) {
				echo '<span class="signa-chip ' . ( $woodmart ? 'signa-chip--ok' : '' ) . '">' . esc_html( $woodmart ? __( 'شناسایی شد', 'signa' ) : __( 'فعال نیست', 'signa' ) ) . '</span>';
			},
			'woodmart'
		);

		$this->card(
			__( 'المنتور', 'signa' ),
			function () use ( $c ) {
				$c->notice( __( 'ویجت «فرم ورود پیامکی سیگنا» در دستهٔ «سیگنا» ویرایشگر المنتور است؛ بکشید و رها کنید.', 'signa' ) );
			},
			'',
			'link',
			function () use ( $elementor ) {
				echo '<span class="signa-chip ' . ( $elementor ? 'signa-chip--ok' : '' ) . '">' . esc_html( $elementor ? __( 'ویجت آماده', 'signa' ) : __( 'فعال نیست', 'signa' ) ) . '</span>';
			},
			'elementor'
		);
	}

	private function advancedSection(): void {
		$c = $this->controls;

		$this->card(
			__( 'رویدادها و نگهداری', 'signa' ),
			function () use ( $c ) {
				$c->toggleRow( 'logs_enabled', __( 'ثبت رویدادها', 'signa' ), __( 'رویدادها در جدول اختصاصی افزونه ذخیره شوند.', 'signa' ) );

				$c->grid(
					2,
					function () use ( $c ) {
						$c->field( __( 'نگهداری', 'signa' ), function () use ( $c ) {
							$c->number( 'logs_keep_days', 1, 90, __( 'روز', 'signa' ) );
						}, '', $c->id( 'logs_keep_days' ) );

						$c->field( __( 'سقف تعداد ردیف‌ها', 'signa' ), function () use ( $c ) {
							$c->number( 'logs_max_rows', 0, 5000000, __( 'ردیف', 'signa' ) );
						}, __( 'زیر حمله می‌تواند میلیون‌ها ردیف شود؛ صفر = بی‌سقف.', 'signa' ), $c->id( 'logs_max_rows' ) );
					}
				);

				$c->toggleRow( 'debug', __( 'حالت اشکال‌زدایی', 'signa' ), __( 'رویدادها در error_log هم نوشته شوند؛ فقط وقتی WP_DEBUG فعال است.', 'signa' ) );

				printf(
					'<p class="signa-actions-row"><a class="signa-btn signa-btn--gh signa-btn--sm" href="%1$s">%2$s<span>%3$s</span></a><a class="signa-btn signa-btn--gh signa-btn--sm" href="%4$s">%5$s<span>%6$s</span></a></p>',
					esc_url( self::tabUrl( 'reports' ) ),
					Icons::svg( 'chart', 14 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
					esc_html__( 'گزارش‌ها', 'signa' ),
					esc_url( admin_url( 'admin.php?page=' . LogsScreen::SLUG ) ),
					Icons::svg( 'list', 14 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
					esc_html__( 'تک‌تک رویدادها', 'signa' )
				);
			},
			__( 'شمارهٔ موبایل هرگز خام ذخیره نمی‌شود؛ فقط اثر انگشت HMAC و نسخهٔ ماسک‌شده.', 'signa' ),
			'database',
			null,
			'logs'
		);

		$this->card(
			__( 'کلیدهای داده و ارسال', 'signa' ),
			function () use ( $c ) {
				$c->grid(
					2,
					function () use ( $c ) {
						$c->field( __( 'کلید متای اصلی شماره', 'signa' ), function () use ( $c ) {
							$c->text( 'phone_meta_key', 'signa_phone' );
						}, __( 'تغییر این کلید، داده‌های موجود را منتقل نمی‌کند.', 'signa' ), $c->id( 'phone_meta_key' ) );

						$c->field( __( 'کلیدهای جست‌وجوی جانبی', 'signa' ), function () use ( $c ) {
							$c->text( 'lookup_meta_keys', 'billing_phone,digits_phone' );
						}, __( 'برای پیدا کردن حساب‌های قدیمی؛ با کاما.', 'signa' ), $c->id( 'lookup_meta_keys' ) );
					}
				);

				/*
				 * The switch that answers «راه حلش چیه» for a site whose own
				 * wp-config.php blocks outbound HTTP. It is labelled with what it
				 * bypasses, and the hint says which of the two answers is better —
				 * a plugin that quietly walked around the site's setting would be
				 * worse than the problem it solves.
				 */
				$c->toggleRow(
					'direct_send',
					__( 'ارسال مستقیم (نادیده گرفتن WP_HTTP_BLOCK_EXTERNAL)', 'signa' ),
					__( 'درخواست پیامک با cURL مستقیم فرستاده می‌شود؛ فقط اگر به wp-config.php دسترسی ندارید.', 'signa' )
				);
			},
			'',
			'key',
			null,
			'direct'
		);

		$this->card(
			__( 'دسترسی اضطراری و فهرست مسدود', 'signa' ),
			function () {
				printf(
					'<p class="signa-actions-row"><a class="signa-btn signa-btn--gh signa-btn--sm" href="%1$s">%2$s<span>%3$s</span></a></p>',
					esc_url( admin_url( 'admin.php?page=' . AccessScreen::SLUG ) ),
					Icons::svg( 'ban', 14 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
					esc_html__( 'صفحهٔ دسترسی و مسدودی', 'signa' )
				);
				echo '<p class="signa-footnote">' . esc_html__( 'اگر ورود با کد از کار افتاد، کد اضطراری یک‌بارمصرف همان‌جاست.', 'signa' ) . '</p>';
			},
			'',
			'ban'
		);

		$this->card(
			__( 'حذف داده‌ها', 'signa' ),
			function () use ( $c ) {
				$c->toggleRow( 'wipe_on_uninstall', __( 'هنگام حذف افزونه', 'signa' ), __( 'جدول‌ها و تنظیمات هم پاک شوند؛ متای شمارهٔ کاربران در هر صورت می‌ماند.', 'signa' ) );
			},
			'',
			'trash',
			null,
			'wipe',
			'signa-card--danger'
		);
	}

	/* Notices --------------------------------------------------------------- */

	/**
	 * The one sentence the owner needs before pressing any button.
	 *
	 * A site whose wp-config.php blocks outbound HTTP cannot send a single SMS
	 * — and finding that out from a test result is a worse afternoon than
	 * finding it out from a banner. It is shown only when it is true for the
	 * gateway they configured, and it names both answers: the switch in this
	 * panel, and the line in wp-config.php. Turning the switch on hides it.
	 */
	private function egressNotice(): void {
		if ( $this->settings->bool( 'direct_send', false ) ) {
			return;
		}

		$host = '';

		foreach ( $this->gateways->deliveryOrder() as $id ) {
			$plan = $this->gateways->planFor( (string) $id );

			if ( ! empty( $plan['endpoint'] ) ) {
				$host = (string) wp_parse_url( (string) $plan['endpoint'], PHP_URL_HOST );
				break;
			}
		}

		if ( '' === $host || ! Transport::egressBlocked( $host ) ) {
			return;
		}

		$this->controls->richNotice(
			esc_html(
				sprintf(
					/* translators: %s: the host this site refuses to reach */
					__( 'این سایت اجازهٔ درخواست خروجی به %s را نمی‌دهد؛ تا آن خط عوض نشود هیچ پیامکی فرستاده نمی‌شود.', 'signa' ),
					$host
				)
			) . ' '
			. esc_html__( 'یا در wp-config.php خط WP_HTTP_BLOCK_EXTERNAL را false کنید، یا «ارسال مستقیم» را روشن کنید:', 'signa' )
			. ' <a href="' . esc_url( self::tabUrl( 'advanced' ) . '#signa-card-direct' ) . '" data-signa-goto="advanced">' . esc_html__( 'پیشرفته ← ارسال مستقیم', 'signa' ) . '</a>',
			'warning'
		);
	}
}
