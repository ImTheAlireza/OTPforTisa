<?php
/**
 * Settings screen.
 *
 * Saving goes through the native Settings API (`options.php`), which keeps
 * nonces, capability checks and sanitising in one well-tested place.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Admin;

use TisaOtp\Captcha\Manager;
use TisaOtp\Config\Sanitizer;
use TisaOtp\Config\Settings;
use TisaOtp\Gateway\Registry;
use TisaOtp\Log\LogStore;
use TisaOtp\Log\Report;
use TisaOtp\Registration\FieldCatalog;
use TisaOtp\Registration\FieldSchema;
use TisaOtp\User\AccessPolicy;

defined( 'ABSPATH' ) || exit;

final class SettingsScreen {

	const OVERVIEW_DAYS = 7;

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

	public function __construct( Settings $settings, Controls $controls, Registry $gateways, FieldSchema $schema, Manager $captcha, LogStore $logs, ReportScreen $reports ) {
		$this->settings = $settings;
		$this->controls = $controls;
		$this->gateways = $gateways;
		$this->schema   = $schema;
		$this->captcha  = $captcha;
		$this->logs     = $logs;
		$this->reports  = $reports;
	}

	/**
	 * Sanitize callback registered with register_setting().
	 *
	 * @param mixed $input
	 */
	public function sanitize( $input ): array {
		return Sanitizer::sanitize( is_array( $input ) ? $input : array(), $this->settings->all() );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = $this->currentTab();

		echo '<div class="wrap tisa-wrap" dir="rtl">';

		$this->header( $tab );

		ScreenNav::render( Menu::ROOT );

		if ( 'reports' !== $tab ) {
			$this->overview();
		}

		echo '<div class="tisa-layout">';
		$this->tabs( $tab );

		/*
		 * The reports tab is a report, not a form: there is nothing to save, so it
		 * skips the form and its submit button and draws the same body the reports
		 * screen draws.
		 */
		if ( 'reports' === $tab ) {
			echo '<div class="tisa-tabbody">';
			$this->reports->body( $this->reports->range() );
			echo '</div>';

			echo '</div></div>';

			return;
		}

		echo '<div class="tisa-panel">';
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '" class="tisa-form">';

		settings_fields( 'tisa_otp_group' );

		$this->section( $tab );

		echo '<div class="tisa-form__footer">';
		submit_button( __( 'ذخیره تنظیمات', 'tisa-otp' ), 'primary large', 'submit', false );
		echo '<span class="tisa-form__saved" data-tisa-saved hidden>' . esc_html__( 'ذخیره شد', 'tisa-otp' ) . '</span>';
		echo '</div>';

		echo '</form></div></div></div>';
	}

	private function header( string $tab ): void {
		$labels = $this->tabLabels();

		echo '<div class="tisa-header">';
		echo '<div class="tisa-header__title"><h1>' . esc_html__( 'تیسا OTP', 'tisa-otp' ) . '</h1>';
		echo '<span class="tisa-header__tag">' . esc_html( isset( $labels[ $tab ] ) ? $labels[ $tab ] : '' ) . '</span></div>';
		echo '<div class="tisa-header__meta">';
		echo '<span class="tisa-badge ' . ( $this->settings->bool( 'enabled', true ) ? 'is-on' : 'is-off' ) . '">'
			. esc_html( $this->settings->bool( 'enabled', true ) ? __( 'فعال', 'tisa-otp' ) : __( 'غیرفعال', 'tisa-otp' ) ) . '</span>';
		echo '<span class="tisa-badge">' . esc_html( sprintf( /* translators: %s: plugin version */ __( 'نسخه %s', 'tisa-otp' ), TISA_OTP_VERSION ) ) . '</span>';
		echo '</div></div>';
	}

	/**
	 * Address of one settings tab.
	 *
	 * Everything that points at a tab builds its URL here, so a link written in
	 * one section cannot disagree with the tab row above it.
	 */
	public static function tabUrl( string $tab ): string {
		return admin_url( 'admin.php?page=' . Menu::ROOT . '&tab=' . $tab );
	}

	private function tabs( string $current ): void {
		echo '<nav class="tisa-tabs" aria-label="' . esc_attr__( 'بخش‌های تنظیمات', 'tisa-otp' ) . '"><ul>';

		foreach ( $this->tabLabels() as $id => $label ) {
			$url = self::tabUrl( $id );

			printf(
				'<li><a href="%1$s" class="tisa-tab%2$s">%3$s</a></li>',
				esc_url( $url ),
				$current === $id ? ' is-current' : '',
				esc_html( $label )
			);
		}

		echo '</ul></nav>';
	}

	private function currentTab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return array_key_exists( $tab, $this->tabLabels() ) ? $tab : 'general';
	}

	private function tabLabels(): array {
		return array(
			'general'      => __( 'عمومی', 'tisa-otp' ),
			'code'         => __( 'کد و کانال‌ها', 'tisa-otp' ),
			'gateways'     => __( 'سامانه‌های پیامکی', 'tisa-otp' ),
			'security'     => __( 'امنیت و محدودیت', 'tisa-otp' ),
			'registration' => __( 'فرم عضویت', 'tisa-otp' ),
			'design'       => __( 'ظاهر فرم', 'tisa-otp' ),
			'store'        => __( 'فروشگاه', 'tisa-otp' ),
			'data'         => __( 'داده و رویدادها', 'tisa-otp' ),
			'reports'      => __( 'گزارش‌ها و آمار', 'tisa-otp' ),
		);
	}

	private function section( string $tab ): void {
		echo '<div class="tisa-section" data-tab="' . esc_attr( $tab ) . '">';

		switch ( $tab ) {
			case 'code':
				$this->codeSection();
				break;
			case 'gateways':
				$this->gatewaySection();
				break;
			case 'security':
				$this->securitySection();
				break;
			case 'registration':
				$this->registrationSection();
				break;
			case 'design':
				$this->designSection();
				break;
			case 'store':
				$this->storeSection();
				break;
			case 'data':
				$this->dataSection();
				break;
			case 'reports':
				// Drawn by the reports screen itself; see render().
				break;
			case 'general':
			default:
				$this->generalSection();
		}

		echo '</div>';
	}

	/**
	 * The "test this section" card.
	 *
	 * Every tab can prove itself without leaving it: the button opens a modal
	 * that is filled from `/admin/check`. Nothing here needs a second screen, a
	 * second URL or a save button.
	 *
	 * @param string        $kind   Which self-test to run.
	 * @param string        $button Button label.
	 * @param string        $intro  One line about what the test actually does.
	 * @param callable|null $extra  Extra controls, such as the real send button.
	 * @param string        $attrs  Extra attributes for the button.
	 */
	private function testCard( string $kind, string $button, string $intro = '', ?callable $extra = null, string $attrs = '' ): void {
		$this->card(
			__( 'آزمایش این بخش', 'tisa-otp' ),
			function () use ( $kind, $button, $extra, $attrs ) {
				echo '<p class="tisa-inline">';

				printf(
					'<button type="button" class="button" data-tisa-check="%1$s"%2$s>%3$s</button>',
					esc_attr( $kind ),
					$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup written in this file.
					esc_html( $button )
				);

				if ( null !== $extra ) {
					$extra();
				}

				echo '</p>';
			},
			$intro
		);
	}

	private function card( string $title, callable $body, string $intro = '' ): void {
		echo '<section class="tisa-card"><h2>' . esc_html( $title ) . '</h2>';

		if ( '' !== $intro ) {
			echo '<p class="tisa-card__intro">' . esc_html( $intro ) . '</p>';
		}

		$body();

		echo '</section>';
	}

	private function generalSection(): void {
		$c = $this->controls;

		$this->card(
			__( 'رفتار ورود', 'tisa-otp' ),
			function () use ( $c ) {
				$c->row( __( 'فعال‌سازی افزونه', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'enabled', __( 'ورود و عضویت با کد یکبارمصرف فعال باشد', 'tisa-otp' ) );
				} );

				$c->row( __( 'حالت احراز', 'tisa-otp' ), function () use ( $c ) {
					$c->cards(
						'auth_mode',
						array(
							'smart'         => array( 'label' => __( 'هوشمند', 'tisa-otp' ), 'desc' => __( 'کاربر موجود وارد می‌شود و کاربر تازه عضو', 'tisa-otp' ) ),
							'login_only'    => array( 'label' => __( 'فقط ورود', 'tisa-otp' ), 'desc' => __( 'عضویت خودکار بسته است', 'tisa-otp' ) ),
							'register_only' => array( 'label' => __( 'فقط عضویت', 'tisa-otp' ), 'desc' => __( 'مناسب فرم‌های ثبت‌نام', 'tisa-otp' ) ),
						)
					);
				} );

				$c->row( __( 'جایگزینی صفحه ورود وردپرس', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'replace_wp_login', __( 'فرم OTP روی wp-login.php نمایش داده شود', 'tisa-otp' ), __( 'فرم کلاسیک وردپرس پنهان می‌شود.', 'tisa-otp' ) );
				} );

				$c->row( __( 'پنهان‌سازی وجود حساب', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'prevent_enumeration', __( 'پیام‌ها یکسان باشند', 'tisa-otp' ), __( 'هیچ‌کس نمی‌فهمد شماره‌اش قبلاً ثبت شده یا نه.', 'tisa-otp' ) );
				} );

				$c->row( __( 'سازگاری با کش صفحه', 'tisa-otp' ), function () use ( $c ) {
					$c->cards(
						'cache_mode',
						array(
							'auto'   => array(
								'label' => __( 'nonce تازه از سرور', 'tisa-otp' ),
								'desc'  => __( 'هنگام باز شدن فرم یک nonce تازه گرفته می‌شود و در صورت رد شدن، یک بار دیگر تلاش می‌شود.', 'tisa-otp' ),
							),
							'inline' => array(
								'label' => __( 'چاپ در صفحه', 'tisa-otp' ),
								'desc'  => __( 'بدون درخواست اضافه؛ فقط وقتی کش صفحه و CDN خاموش است.', 'tisa-otp' ),
							),
						)
					);
				}, __( 'اگر کش صفحه، وارنیش یا Cloudflare دارید حالت «nonce تازه از سرور» را نگه دارید؛ وگرنه فرم خطای ۴۰۳ می‌گیرد.', 'tisa-otp' ) );
			}
		);

		$this->card(
			__( 'مقصد پس از ورود', 'tisa-otp' ),
			function () use ( $c ) {
				$c->row( __( 'پس از ورود', 'tisa-otp' ), function () use ( $c ) {
					$c->text( 'login_redirect', home_url( '/' ), 'url' );
				}, __( 'خالی بگذارید تا کاربر به حساب کاربری (یا صفحه اصلی) برود.', 'tisa-otp' ) );

				$c->row( __( 'پس از عضویت', 'tisa-otp' ), function () use ( $c ) {
					$c->text( 'register_redirect', '', 'url' );
				} );
			}
		);

		$this->testCard(
			'general',
			__( 'آزمایش تنظیمات عمومی', 'tisa-otp' )
		);
	}

	/**
	 * Host name shown in the WebOTP hint, e.g. `example.com`.
	 */
	private function webOtpDomain(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) && '' !== $host ? strtolower( $host ) : __( 'دامنه شما', 'tisa-otp' );
	}

	private function codeSection(): void {
		$c = $this->controls;

		$this->card(
			__( 'کد یکبارمصرف', 'tisa-otp' ),
			function () use ( $c ) {
				$c->row( __( 'طول کد', 'tisa-otp' ), function () use ( $c ) {
					$c->number( 'code_length', 4, 8, __( 'رقم', 'tisa-otp' ) );
				} );

				$c->row( __( 'اعتبار کد', 'tisa-otp' ), function () use ( $c ) {
					$c->number( 'code_ttl', 30, 3600, __( 'ثانیه', 'tisa-otp' ) );
				} );

				$c->row( __( 'حداکثر تلاش برای وارد کردن کد', 'tisa-otp' ), function () use ( $c ) {
					$c->number( 'verify_attempts', 2, 15, __( 'بار', 'tisa-otp' ) );
				} );

				$c->row( __( 'فاصله بین دو ارسال', 'tisa-otp' ), function () use ( $c ) {
					$c->number( 'resend_delay', 10, 1800, __( 'ثانیه', 'tisa-otp' ) );
				} );

				$c->row( __( 'بررسی خودکار کد', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'auto_verify', __( 'به‌محض کامل شدن کد، بدون زدن دکمه بررسی شود', 'tisa-otp' ) );
				} );

				$c->row( __( 'مهلت پاسخ سرور', 'tisa-otp' ), function () use ( $c ) {
					$c->number( 'request_timeout', 5, 60, __( 'ثانیه', 'tisa-otp' ) );
				} );

				$c->row( __( 'محل نگهداری کد', 'tisa-otp' ), function () use ( $c ) {
					$c->cards(
						'code_store',
						array(
							'database' => array( 'label' => __( 'جدول اختصاصی', 'tisa-otp' ), 'desc' => __( 'پیش‌فرض؛ بدون نیاز به کش شیء', 'tisa-otp' ) ),
							'cache'    => array( 'label' => __( 'کش شیء', 'tisa-otp' ), 'desc' => __( 'سبک‌تر؛ فقط با Redis/Memcached پایدار', 'tisa-otp' ) ),
						)
					);
				} );
			}
		);

		$this->card(
			__( 'کانال‌های ارسال', 'tisa-otp' ),
			function () use ( $c ) {
				$c->row( __( 'کانال اصلی', 'tisa-otp' ), function () use ( $c ) {
					$c->select(
						'channel',
						array(
							'sms'   => __( 'پیامک', 'tisa-otp' ),
							'email' => __( 'ایمیل', 'tisa-otp' ),
						)
					);
				} );

				$c->row( __( 'کانال‌های فعال', 'tisa-otp' ), function () use ( $c ) {
					$c->checkboxList(
						'channels_enabled',
						array(
							'sms'   => __( 'پیامک', 'tisa-otp' ),
							'email' => __( 'ایمیل', 'tisa-otp' ),
						)
					);
				}, __( 'اگر کانال اصلی ناموفق باشد، به کانال فعال بعدی می‌رویم.', 'tisa-otp' ) );

				$c->row( __( 'جابه‌جایی خودکار', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'failover_enabled', __( 'در صورت خطای موقت، کانال/سامانه بعدی امتحان شود', 'tisa-otp' ) );
				} );

				$c->row( __( 'متن پیامک', 'tisa-otp' ), function () use ( $c ) {
					$c->textarea( 'sms_template', 2, 'کد ورود: {code}' );
				}, __( 'نشانه‌ها: {code} {phone} {minutes} {site} {domain} {webotp}', 'tisa-otp' ) );

				$c->row( __( 'خواندن خودکار کد (WebOTP)', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'webotp_enabled', __( 'خط شناسایی به انتهای پیامک اضافه شود', 'tisa-otp' ) );
				}, sprintf(
					/* translators: %s: the WebOTP binding line, for example @example.com #12345 */
					__( 'خط %s به انتهای پیامک متنی اضافه می‌شود (هزینهٔ چند نویسه بیشتر). برای الگوها نشانهٔ {webotp}.', 'tisa-otp' ),
					'@' . $this->webOtpDomain() . ' #12345'
				) );
			}
		);

		$this->card(
			__( 'کانال ایمیل', 'tisa-otp' ),
			function () use ( $c ) {
				$c->row( __( 'موضوع ایمیل', 'tisa-otp' ), function () use ( $c ) {
					$c->text( 'email_subject' );
				} );

				$c->row( __( 'متن ایمیل', 'tisa-otp' ), function () use ( $c ) {
					$c->textarea( 'email_body', 4 );
				}, __( 'نشانه‌ها: {code} {minutes} {site}', 'tisa-otp' ) );

				$c->row( __( 'فرستنده', 'tisa-otp' ), function () use ( $c ) {
					$c->text( 'email_from', get_option( 'admin_email' ), 'email' );
				}, __( 'خالی بگذارید تا از آدرس پیش‌فرض وردپرس استفاده شود.', 'tisa-otp' ) );
			}
		);

		$this->testCard(
			'code',
			__( 'آزمایش ساخت کد', 'tisa-otp' ),
			__( 'هیچ پیامکی ارسال نمی‌شود.', 'tisa-otp' )
		);
	}

	private function gatewaySection(): void {
		$c       = $this->controls;
		$labels  = $this->gateways->labels();
		$options = array();

		foreach ( $labels as $id => $label ) {
			$options[ $id ] = $label;
		}

		$this->card(
			__( 'انتخاب سامانه', 'tisa-otp' ),
			function () use ( $c, $options ) {
				$c->row( __( 'سامانه اصلی', 'tisa-otp' ), function () use ( $c, $options ) {
					$c->select( 'sms_gateway', $options );
				} );

				$c->row( __( 'سامانه پشتیبان', 'tisa-otp' ), function () use ( $c, $options ) {
					$c->select( 'sms_backup_gateway', array( '' => __( 'بدون پشتیبان', 'tisa-otp' ) ) + $options );
				}, __( 'فقط برای خطاهای موقت (تایم‌اوت، خطای ۵xx، اتمام اعتبار) استفاده می‌شود.', 'tisa-otp' ) );
			}
		);

		foreach ( $this->gateways->all() as $id => $driver ) {
			$report = $this->gateways->report();
			$state  = isset( $report[ $id ] ) ? $report[ $id ] : array();

			$this->card(
				$driver->label(),
				function () use ( $c, $driver, $state ) {
					foreach ( $driver->fields() as $key => $field ) {
						$label = isset( $field['label'] ) ? (string) $field['label'] : $key;
						$hint  = isset( $field['hint'] ) ? (string) $field['hint'] : '';
						$type  = isset( $field['type'] ) ? (string) $field['type'] : 'text';

						$c->row(
							$label,
							function () use ( $c, $key, $type ) {
								if ( 'password' === $type ) {
									$c->secret( $key );
								} else {
									$c->text( $key );
								}
							},
							$hint
						);
					}

					if ( ! empty( $state['active'] ) ) {
						$c->notice( __( 'این سامانه در حال حاضر سامانه اصلی است.', 'tisa-otp' ), 'success' );
					} elseif ( ! empty( $state['backup'] ) ) {
						$c->notice( __( 'این سامانه به‌عنوان پشتیبان انتخاب شده است.', 'tisa-otp' ) );
					} elseif ( ! empty( $state['missing'] ) ) {
						$c->notice( __( 'اعتبارنامه این سامانه کامل نیست.', 'tisa-otp' ), 'warning' );
					}

					if ( ! empty( $state['docs'] ) ) {
						$c->description( sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( (string) $state['docs'] ), esc_html__( 'مستندات سامانه', 'tisa-otp' ) ) );
					}
				}
			);
		}

		$this->controls->description(
			__( 'اعتبارنامه‌ها را می‌توان در wp-config.php هم گذاشت: <code>TISA_OTP_SMSIR_API_KEY</code>.', 'tisa-otp' )
		);

		$this->testCard(
			'gateways',
			__( 'آزمایش سامانه‌های پیامکی', 'tisa-otp' ),
			function () {
				printf(
					'<button type="button" class="button button-primary" data-tisa-sms-test>%s</button>',
					esc_html__( 'ارسال پیامک آزمایشی', 'tisa-otp' )
				);
			},
			''
		);
	}

	private function securitySection(): void {
		$c = $this->controls;

		$this->card(
			__( 'محدودیت ارسال', 'tisa-otp' ),
			function () use ( $c ) {
				$c->row( __( 'فعال بودن محدودیت‌ها', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'throttle_enabled', __( 'شمارنده‌های ارسال و تأیید اعمال شوند', 'tisa-otp' ) );
				} );

				$c->row( __( 'بازه شمارش', 'tisa-otp' ), function () use ( $c ) {
					$c->number( 'window_minutes', 1, 1440, __( 'دقیقه', 'tisa-otp' ) );
				} );

				$c->row( __( 'سقف هر شماره', 'tisa-otp' ), function () use ( $c ) {
					$c->number( 'limit_per_phone', 1, 100, __( 'ارسال در بازه', 'tisa-otp' ) );
				} );

				$c->row( __( 'سقف هر آدرس IP', 'tisa-otp' ), function () use ( $c ) {
					$c->number( 'limit_per_ip', 1, 500, __( 'ارسال در بازه', 'tisa-otp' ) );
				} );

				$c->row( __( 'سقف روزانه هر IP', 'tisa-otp' ), function () use ( $c ) {
					$c->number( 'limit_per_ip_daily', 1, 5000, __( 'ارسال در روز', 'tisa-otp' ) );
				} );

				$c->row( __( 'سقف تأیید کد هر IP', 'tisa-otp' ), function () use ( $c ) {
					$c->number( 'limit_verify_per_ip', 5, 1000, __( 'تلاش در بازه', 'tisa-otp' ) );
				} );
			}
		);

		$this->card(
			__( 'شماره‌های مورد اعتماد', 'tisa-otp' ),
			function () use ( $c ) {
				$c->row( __( 'فعال بودن فهرست', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'trusted_enabled', __( 'شماره‌های این فهرست از کپچا و محدودیت‌ها معاف باشند', 'tisa-otp' ) );
				} );

				$c->row( __( 'شماره‌ها', 'tisa-otp' ), function () use ( $c ) {
					$c->textarea( 'trusted_numbers', 4, "09121234567\n0912*\n0935*4567" );
				}, __( 'هر خط یک شماره، پیش‌شماره (۰۹۱۲) یا الگو (۰۹۳۵*۴۵۶۷).', 'tisa-otp' ) );

				$c->row( __( 'معافیت از', 'tisa-otp' ), function () use ( $c ) {
					$c->text( 'trusted_skip', 'captcha,throttle' );
				}, __( 'نام گاردها با کاما: captcha و throttle. فهرست مسدود هرگز نادیده گرفته نمی‌شود.', 'tisa-otp' ) );
			}
		);

		$this->card(
			__( 'پروکسی و IP واقعی', 'tisa-otp' ),
			function () use ( $c ) {
				$c->row( __( 'سرآشد معتبر', 'tisa-otp' ), function () use ( $c ) {
					$c->select(
						'proxy_mode',
						array(
							'none'       => __( 'هیچ (فقط REMOTE_ADDR)', 'tisa-otp' ),
							'cloudflare' => __( 'کلادفلر (CF-Connecting-IP)', 'tisa-otp' ),
							'forwarded'  => __( 'X-Forwarded-For', 'tisa-otp' ),
							'real_ip'    => __( 'X-Real-IP', 'tisa-otp' ),
						)
					);
				}, __( 'سرآشد تنها از پروکسی‌های مورد اعتماد پایین پذیرفته می‌شود.', 'tisa-otp' ) );

				$c->row( __( 'پروکسی‌های مورد اعتماد', 'tisa-otp' ), function () use ( $c ) {
					$c->text( 'trusted_proxies', '173.245.48.0/20, 127.0.0.1' );
				}, __( 'IP یا CIDR، با کاما جدا کنید.', 'tisa-otp' ) );
			}
		);

		$this->card(
			__( 'کپچا', 'tisa-otp' ),
			function () use ( $c ) {
				$captcha = $this->captcha;

				$c->row( __( 'سرویس', 'tisa-otp' ), function () use ( $c, $captcha ) {
					$c->select( 'captcha_provider', $captcha->labels() );
				} );

				$c->row( __( 'کلید سایت', 'tisa-otp' ), function () use ( $c ) {
					$c->text( 'captcha_site_key' );
				} );

				$c->row( __( 'کلید خصوصی', 'tisa-otp' ), function () use ( $c ) {
					$c->secret( 'captcha_secret_key' );
				} );

				$c->row( __( 'حداقل امتیاز (reCAPTCHA v3)', 'tisa-otp' ), function () use ( $c ) {
					$c->text( 'captcha_score', '0.5' );
				} );

				$c->row( __( 'زمان نمایش', 'tisa-otp' ), function () use ( $c ) {
					$c->cards(
						'captcha_trigger',
						array(
							'always'      => array( 'label' => __( 'همیشه', 'tisa-otp' ), 'desc' => __( 'هر درخواست ارسال کد', 'tisa-otp' ) ),
							'after_limit' => array( 'label' => __( 'پس از چند تلاش', 'tisa-otp' ), 'desc' => __( 'تجربه روان‌تر برای انسان‌ها', 'tisa-otp' ) ),
						)
					);
				} );

				$c->row(
					__( 'وقتی سرویس کپچا در دسترس نیست', 'tisa-otp' ),
					function () use ( $c ) {
						$c->toggle(
							'captcha_fail_open',
							__( 'ورود را نبند (پیشنهاد می‌شود)', 'tisa-otp' ),
							__( 'هانی‌پات و سقف ارسال فعال می‌مانند', 'tisa-otp' )
						);
					},
					__( 'اگر سرویس کپچا در دسترس نباشد: روشن = کاربر رد نمی‌شود، خاموش = هر ورودی کپچا می‌خواهد.', 'tisa-otp' )
				);

				$c->row(
					__( 'نشانی جایگزین اسکریپت', 'tisa-otp' ),
					function () use ( $c ) {
						$c->text( 'captcha_script_override', 'https://my-mirror.example/1/api.js' );
					},
					__( 'اختیاری؛ برای وقتی دامنهٔ رسمی سرویس روی سایت شما باز نمی‌شود.', 'tisa-otp' )
				);

				$c->row( __( 'مهلت بارگذاری اسکریپت', 'tisa-otp' ), function () use ( $c ) {
					$c->number( 'captcha_timeout', 3000, 20000, __( 'میلی‌ثانیه', 'tisa-otp' ) );
				} );

				$c->row( __( 'حالت آرکپچا', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'captcha_arcaptcha_v3', __( 'نسخه ۳ (امتیازی/نامرئی)', 'tisa-otp' ), __( 'اگر حساب آرکپچای شما v3 است روشن کنید', 'tisa-otp' ) );
				} );
			}
		);

		$this->card(
			__( 'نقش‌های محافظت‌شده', 'tisa-otp' ),
			function () use ( $c ) {
				$c->row( __( 'مسدودسازی ورود پیامکی نقش‌های حساس', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'guard_roles', __( 'این نقش‌ها فقط با رمز عبور وارد شوند', 'tisa-otp' ) );
				} );

				$c->row( __( 'نقش‌ها', 'tisa-otp' ), function () use ( $c ) {
					$c->text( 'guarded_roles', 'administrator,editor,shop_manager' );
				}, __( 'نام نقش‌ها با کاما. مدیران کل همیشه مسدود هستند.', 'tisa-otp' ) );

				$c->row( __( 'نقش پیش‌فرض کاربران تازه', 'tisa-otp' ), function () use ( $c ) {
					$c->select( 'default_role', AccessPolicy::selectableRoles() );
				} );
			}
		);

		$this->accessPointerCard();

		$this->testCard(
			'security',
			__( 'آزمایش کپچا در این مرورگر', 'tisa-otp' ),
			null,
			' data-tisa-captcha-test'
		);
	}

	/**
	 * Pointer to the access screen: the two levers there are emergency tools,
	 * not settings, so they are edited on their own page.
	 */
	private function accessPointerCard(): void {
		$this->card(
			__( 'دسترسی اضطراری و فهرست مسدود', 'tisa-otp' ),
			function () {

				printf(
					'<p><a class="button" href="%1$s">%2$s</a></p>',
					esc_url( admin_url( 'admin.php?page=' . AccessScreen::SLUG ) ),
					esc_html__( 'باز کردن صفحه دسترسی و مسدودی', 'tisa-otp' )
				);
			}
		);
	}

	private function registrationSection(): void {
		$c = $this->controls;

		$this->card(
			__( 'عضویت', 'tisa-otp' ),
			function () use ( $c ) {
				$c->row( __( 'فعال بودن عضویت', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'registration_enabled', __( 'شماره‌های تازه بتوانند حساب بسازند', 'tisa-otp' ) );
				} );

				$c->row( __( 'عضویت خودکار', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'auto_register', __( 'پس از تأیید کد، حساب ساخته شود', 'tisa-otp' ) );
				} );

				$c->row( __( 'ترتیب مراحل', 'tisa-otp' ), function () use ( $c ) {
					$c->cards(
						'registration_flow',
						array(
							'fields_then_code' => array( 'label' => __( 'اول فرم، بعد کد', 'tisa-otp' ), 'desc' => __( 'کمترین پیامک هدررفته', 'tisa-otp' ) ),
							'code_then_fields' => array( 'label' => __( 'اول کد، بعد فرم', 'tisa-otp' ), 'desc' => __( 'شماره پیش از دریافت اطلاعات تأیید می‌شود', 'tisa-otp' ) ),
						)
					);
				} );

				$c->row( __( 'ایمیل', 'tisa-otp' ), function () use ( $c ) {
					$c->select(
						'email_mode',
						array(
							'off'      => __( 'جمع‌آوری نشود', 'tisa-otp' ),
							'optional' => __( 'اختیاری', 'tisa-otp' ),
							'required' => __( 'الزامی', 'tisa-otp' ),
						)
					);
				} );

				$c->row( __( 'نام کاربری', 'tisa-otp' ), function () use ( $c ) {
					$c->select(
						'username_from',
						array(
							'phone'          => __( 'بر پایه شماره', 'tisa-otp' ),
							'phone_prefixed' => __( 'شماره با پیشوند user', 'tisa-otp' ),
							'email'          => __( 'بر پایه ایمیل', 'tisa-otp' ),
						)
					);
				} );

				$c->row( __( 'نام نمایشی', 'tisa-otp' ), function () use ( $c ) {
					$c->select(
						'display_name_from',
						array(
							'full_name'  => __( 'نام و نام خانوادگی', 'tisa-otp' ),
							'first_name' => __( 'فقط نام', 'tisa-otp' ),
							'phone'      => __( 'شماره ماسک‌شده', 'tisa-otp' ),
						)
					);
				} );

				$c->row( __( 'ایمیل خوش‌آمد', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'send_welcome_email', __( 'پس از عضویت ایمیل خوش‌آمد ارسال شود', 'tisa-otp' ) );
				} );

				$c->row( __( 'عنوان فرم عضویت', 'tisa-otp' ), function () use ( $c ) {
					$c->text( 'register_heading' );
				} );

				$c->row( __( 'توضیح فرم عضویت', 'tisa-otp' ), function () use ( $c ) {
					$c->textarea( 'register_subheading', 2 );
				} );
			}
		);

		$this->card(
			__( 'فیلدها', 'tisa-otp' ),
			function () use ( $c ) {
				$c->row( __( 'مجموعه آماده', 'tisa-otp' ), function () use ( $c ) {
					$c->select( 'field_preset', FieldCatalog::labels() );
				} );

				$this->fieldRepeater();
			}
		);

		$this->controls->description(
			sprintf(
				/* translators: %d: number of active fields */
				esc_html__( 'اکنون %d فیلد در فرم عضویت فعال است.', 'tisa-otp' ),
				count( $this->schema->active() )
			)
		);

		$this->testCard(
			'registration',
			__( 'آزمایش فرم عضویت', 'tisa-otp' )
		);
	}

	private function fieldRepeater(): void {
		$fields = (array) $this->settings->arr( 'fields' );
		$base   = $this->controls->name( 'fields' );

		echo '<div class="tisa-repeater" data-tisa-repeater data-base="' . esc_attr( $base ) . '">';
		echo '<div class="tisa-repeater__head"><span>' . esc_html__( 'شناسه', 'tisa-otp' ) . '</span><span>' . esc_html__( 'برچسب', 'tisa-otp' ) . '</span><span>' . esc_html__( 'نوع', 'tisa-otp' ) . '</span><span>' . esc_html__( 'کلید متا', 'tisa-otp' ) . '</span><span>' . esc_html__( 'الزامی', 'tisa-otp' ) . '</span><span></span></div>';
		echo '<div class="tisa-repeater__rows" data-tisa-rows>';

		foreach ( $fields as $index => $field ) {
			$this->repeaterRow( $base, (int) $index, (array) $field );
		}

		echo '</div>';

		printf(
			'<button type="button" class="button" data-tisa-add-row>%s</button>',
			esc_html__( 'افزودن فیلد', 'tisa-otp' )
		);

		echo '<template data-tisa-row-template>';
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

		echo '<div class="tisa-repeater__row">';

		printf( '<input type="text" name="%1$s[id]" value="%2$s" dir="ltr" placeholder="city">', esc_attr( $name ), esc_attr( $id ) );
		printf( '<input type="text" name="%1$s[label]" value="%2$s" placeholder="%3$s">', esc_attr( $name ), esc_attr( $label ), esc_attr__( 'برچسب', 'tisa-otp' ) );

		echo '<select name="' . esc_attr( $name ) . '[type]">';
		foreach ( FieldCatalog::types() as $option ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $option ), selected( $type, $option, false ) );
		}
		echo '</select>';

		printf( '<input type="text" name="%1$s[meta_key]" value="%2$s" dir="ltr" placeholder="tisa_city">', esc_attr( $name ), esc_attr( $meta ) );

		echo '<select name="' . esc_attr( $name ) . '[target]">';
		foreach ( array( 'meta' => __( 'متا', 'tisa-otp' ), 'core' => __( 'هسته وردپرس', 'tisa-otp' ), 'wc' => __( 'ووکامرس', 'tisa-otp' ) ) as $value => $text ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $target, $value, false ), esc_html( $text ) );
		}
		echo '</select>';

		printf(
			'<label class="tisa-check"><input type="hidden" name="%1$s[required]" value="0"><input type="checkbox" name="%1$s[required]" value="1"%2$s></label>',
			esc_attr( $name ),
			checked( ! empty( $field['required'] ) && '0' !== $field['required'], true, false )
		);

		printf( '<button type="button" class="button-link tisa-repeater__remove" data-tisa-remove-row>%s</button>', esc_html__( 'حذف', 'tisa-otp' ) );

		echo '</div>';
	}

	private function designSection(): void {
		$c = $this->controls;

		$this->card(
			__( 'پوسته و رنگ', 'tisa-otp' ),
			function () use ( $c ) {
				$c->row( __( 'پوسته', 'tisa-otp' ), function () use ( $c ) {
					$c->cards(
						'skin',
						array(
							'line'  => array( 'label' => __( 'خطی', 'tisa-otp' ), 'desc' => __( 'کمترین تزئین، سریع‌ترین بارگذاری', 'tisa-otp' ) ),
							'card'  => array( 'label' => __( 'کارت', 'tisa-otp' ), 'desc' => __( 'کارت سایه‌دار روی پس‌زمینه', 'tisa-otp' ) ),
							'glass' => array( 'label' => __( 'شیشه‌ای', 'tisa-otp' ), 'desc' => __( 'لایه نیمه‌شفاف و محو', 'tisa-otp' ) ),
							'slate' => array( 'label' => __( 'تیره', 'tisa-otp' ), 'desc' => __( 'مناسب صفحات تیره', 'tisa-otp' ) ),
							'pill'  => array( 'label' => __( 'گرد', 'tisa-otp' ), 'desc' => __( 'گوشه‌های کاملاً گرد', 'tisa-otp' ) ),
						)
					);
				} );

				$c->row( __( 'رنگ تأکیدی', 'tisa-otp' ), function () use ( $c ) {
					$c->color( 'accent' );
				} );

				$c->row( __( 'رنگ زمینه فرم', 'tisa-otp' ), function () use ( $c ) {
					$c->color( 'surface' );
				} );

				$c->row( __( 'گردی گوشه‌ها', 'tisa-otp' ), function () use ( $c ) {
					$c->number( 'radius', 0, 40, 'px' );
				} );

				$c->row( __( 'عرض فرم', 'tisa-otp' ), function () use ( $c ) {
					$c->number( 'width', 280, 900, 'px' );
				} );

				$c->row( __( 'چینش', 'tisa-otp' ), function () use ( $c ) {
					$c->select(
						'align',
						array(
							'center' => __( 'مرکز', 'tisa-otp' ),
							'start'  => __( 'ابتدا', 'tisa-otp' ),
							'end'    => __( 'انتها', 'tisa-otp' ),
						)
					);
				} );

				$c->row( __( 'ورودی کد', 'tisa-otp' ), function () use ( $c ) {
					$c->cards(
						'code_input',
						array(
							'boxes'  => array( 'label' => __( 'خانه‌های جدا', 'tisa-otp' ), 'desc' => __( 'هر رقم در یک کادر', 'tisa-otp' ) ),
							'single' => array( 'label' => __( 'یک کادر', 'tisa-otp' ), 'desc' => __( 'ساده و فشرده', 'tisa-otp' ) ),
						)
					);
				} );
			}
		);

		$this->card(
			__( 'برند و متن‌ها', 'tisa-otp' ),
			function () use ( $c ) {
				$c->row( __( 'نمایش لوگو', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'show_brand', __( 'لوگو بالای فرم نمایش داده شود', 'tisa-otp' ) );
				} );

				$c->row( __( 'آدرس لوگو', 'tisa-otp' ), function () use ( $c ) {
					$c->text( 'brand_logo', '', 'url' );
					echo ' <button type="button" class="button" data-tisa-pick-media="' . esc_attr( $c->id( 'brand_logo' ) ) . '">' . esc_html__( 'انتخاب از کتابخانه', 'tisa-otp' ) . '</button>';
				} );

				$c->row( __( 'عرض لوگو', 'tisa-otp' ), function () use ( $c ) {
					$c->number( 'brand_width', 32, 320, 'px' );
				} );

				$c->row( __( 'عنوان فرم', 'tisa-otp' ), function () use ( $c ) {
					$c->text( 'form_heading' );
				} );

				$c->row( __( 'توضیح فرم', 'tisa-otp' ), function () use ( $c ) {
					$c->textarea( 'form_subheading', 2 );
				} );

				$c->row( __( 'دکمه دریافت کد', 'tisa-otp' ), function () use ( $c ) {
					$c->text( 'label_send' );
				} );

				$c->row( __( 'دکمه ورود', 'tisa-otp' ), function () use ( $c ) {
					$c->text( 'label_verify' );
				} );

				$c->row( __( 'ارسال دوباره', 'tisa-otp' ), function () use ( $c ) {
					$c->text( 'label_resend' );
				} );

				$c->row( __( 'ویرایش شماره', 'tisa-otp' ), function () use ( $c ) {
					$c->text( 'label_edit_phone' );
				} );

				$c->row( __( 'متن قوانین', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'terms_enabled', __( 'نمایش متن قوانین زیر فرم', 'tisa-otp' ) );
					$c->text( 'terms_text' );
					$c->text( 'terms_url', '', 'url' );
				} );
			}
		);

		$this->card(
			__( 'CSS و JS دلخواه', 'tisa-otp' ),
			function () use ( $c ) {
				$c->row( __( 'محل اعمال', 'tisa-otp' ), function () use ( $c ) {
					$c->select(
						'custom_code_scope',
						array(
							'form_pages' => __( 'فقط صفحات دارای فرم', 'tisa-otp' ),
							'everywhere' => __( 'همه صفحات', 'tisa-otp' ),
						)
					);
				} );

				$c->row( __( 'CSS دلخواه', 'tisa-otp' ), function () use ( $c ) {
					$c->textarea( 'custom_css', 6, '.tisa-otp { }' );
				} );

				$c->row( __( 'JS دلخواه', 'tisa-otp' ), function () use ( $c ) {
					$c->textarea( 'custom_js', 6 );
				}, __( 'فقط برای مدیران دارای دسترسی unfiltered_html ذخیره می‌شود.', 'tisa-otp' ) );
			}
		);

		$this->testCard(
			'design',
			__( 'آزمایش رنگ‌ها و کنتراست', 'tisa-otp' )
		);
	}

	private function storeSection(): void {
		$c = $this->controls;

		if ( ! class_exists( 'WooCommerce' ) ) {
			$c->notice( __( 'ووکامرس فعال نیست؛ این تنظیمات فعلاً اثری ندارند.', 'tisa-otp' ), 'warning' );
		}

		$this->card(
			__( 'ووکامرس', 'tisa-otp' ),
			function () use ( $c ) {
				$c->row( __( 'فرم حساب کاربری', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'woo_account_form', __( 'فرم ورود «حساب کاربری» با فرم OTP جایگزین شود', 'tisa-otp' ) );
				} );

				$c->row( __( 'ورود اجباری پیش از تسویه حساب', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'woo_checkout_gate', __( 'کاربر مهمان به صفحه تسویه حساب نرسد', 'tisa-otp' ) );
				} );

				$c->row( __( 'صفحه ورود', 'tisa-otp' ), function () use ( $c ) {
					$c->text( 'woo_checkout_page', wp_login_url(), 'url' );
				} );

				$c->row( __( 'پیام صفحه تسویه حساب', 'tisa-otp' ), function () use ( $c ) {
					$c->textarea( 'woo_checkout_notice', 2 );
				} );

				$c->row( __( 'همگام‌سازی شماره صورتحساب', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'sync_billing_phone', __( 'شماره تأییدشده در billing_phone هم ذخیره شود', 'tisa-otp' ) );
				} );

				$c->row( __( 'اتصال سفارش‌های مهمان', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'link_guest_orders', __( 'پس از عضویت، سفارش‌های مهمان با همان شماره به حساب متصل شوند', 'tisa-otp' ) );
				} );
			}
		);

		$this->card(
			__( 'المنتور', 'tisa-otp' ),
			function () use ( $c ) {
				$c->description(
					__( 'ویجت «فرم ورود پیامکی تیسا» در دستهٔ تیسا OTP المنتور است.', 'tisa-otp' )
				);
			}
		);

		$this->testCard(
			'store',
			__( 'آزمایش فروشگاه', 'tisa-otp' )
		);
	}

	/**
	 * The last week, on every settings tab.
	 *
	 * The plugin has a full reports screen, but nothing on the screen people
	 * actually open said so — and nothing anywhere said "is it working?". Four
	 * numbers answer that before a single setting is read, and the button next
	 * to them opens the screen that explains them.
	 */
	private function overview(): void {
		$reports = self::tabUrl( 'reports' );

		echo '<div class="tisa-overview">';

		if ( ! $this->settings->bool( 'logs_enabled', true ) ) {
			$this->controls->notice( __( 'ثبت رویدادها خاموش است؛ آماری برای نمایش نیست.', 'tisa-otp' ), 'warning' );
		} else {
			$counts = $this->logs->countByEvent(
				array_merge( Report::requestEvents(), array( Report::CREATED ) ),
				self::OVERVIEW_DAYS
			);

			$kpis = Report::kpis( $counts );

			$cells = array(
				array( __( 'درخواست‌ها', 'tisa-otp' ), number_format_i18n( (int) $kpis['requests'] ), '' ),
				array( __( 'موفق', 'tisa-otp' ), number_format_i18n( (int) $kpis['sent'] ), 'is-good' ),
				array( __( 'ناموفق', 'tisa-otp' ), number_format_i18n( (int) $kpis['failed'] ), (int) $kpis['failed'] > 0 ? 'is-bad' : '' ),
				array( __( 'نرخ موفقیت', 'tisa-otp' ), number_format_i18n( (float) $kpis['rate'], 1 ) . '٪', 'is-rate' ),
			);

			echo '<div class="tisa-kpis">';

			foreach ( $cells as $cell ) {
				printf(
					'<div class="tisa-kpi %1$s"><span class="tisa-kpi__value">%2$s</span><span class="tisa-kpi__label">%3$s</span></div>',
					esc_attr( $cell[2] ),
					esc_html( $cell[1] ),
					esc_html( $cell[0] )
				);
			}

			echo '</div>';
		}

		echo '<div class="tisa-overview__side">';

		printf(
			'<a class="button" href="%1$s">%2$s</a>',
			esc_url( $reports ),
			esc_html__( 'گزارش‌ها', 'tisa-otp' )
		);

		printf(
			'<p class="tisa-muted">%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of days */
					__( 'آمار %d روز گذشته', 'tisa-otp' ),
					self::OVERVIEW_DAYS
				)
			)
		);

		echo '</div></div>';
	}

	private function dataSection(): void {
		$c = $this->controls;

		$this->card(
			__( 'رویدادها', 'tisa-otp' ),
			function () use ( $c ) {
				$c->row( __( 'ثبت رویدادها', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'logs_enabled', __( 'رویدادها در جدول اختصاصی ذخیره شوند', 'tisa-otp' ) );
				} );

				$c->row( __( 'نگهداری', 'tisa-otp' ), function () use ( $c ) {
					$c->number( 'logs_keep_days', 1, 90, __( 'روز', 'tisa-otp' ) );
				} );

				$c->row( __( 'حالت اشکال‌زدایی', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'debug', __( 'رویدادها در error_log هم نوشته شوند', 'tisa-otp' ), __( 'فقط وقتی WP_DEBUG فعال است.', 'tisa-otp' ) );
				} );
			},
			__( 'شماره موبایل هرگز به‌صورت خام ذخیره نمی‌شود؛ فقط اثر انگشت HMAC و نسخه ماسک‌شده.', 'tisa-otp' )
		);

		$this->card(
			__( 'گزارش و رویدادها', 'tisa-otp' ),
			function () use ( $c ) {
				$c->row(
					__( 'صفحه‌ها', 'tisa-otp' ),
					function () {
						printf(
							'<a class="button" href="%1$s">%2$s</a> <a class="button" href="%3$s">%4$s</a>',
							esc_url( self::tabUrl( 'reports' ) ),
							esc_html__( 'گزارش‌ها', 'tisa-otp' ),
							esc_url( admin_url( 'admin.php?page=' . LogsScreen::SLUG ) ),
							esc_html__( 'تک‌تک رویدادها', 'tisa-otp' )
						);
					}
				);
			}
		);

		$this->card(
			__( 'کلیدهای داده', 'tisa-otp' ),
			function () use ( $c ) {
				$c->row( __( 'کلید متای اصلی شماره', 'tisa-otp' ), function () use ( $c ) {
					$c->text( 'phone_meta_key', 'tisa_phone' );
				}, __( 'تغییر این کلید، داده‌های موجود را منتقل نمی‌کند.', 'tisa-otp' ) );

				$c->row( __( 'کلیدهای جست‌وجوی جانبی', 'tisa-otp' ), function () use ( $c ) {
					$c->text( 'lookup_meta_keys', 'billing_phone,digits_phone' );
				}, __( 'برای پیدا کردن حساب‌های قدیمی؛ با کاما جدا کنید.', 'tisa-otp' ) );
			}
		);

		$this->card(
			__( 'حذف داده‌ها', 'tisa-otp' ),
			function () use ( $c ) {
				$c->row( __( 'هنگام حذف افزونه', 'tisa-otp' ), function () use ( $c ) {
					$c->toggle( 'wipe_on_uninstall', __( 'جدول‌ها و تنظیمات هم پاک شوند', 'tisa-otp' ), __( 'متای شماره کاربران در هر صورت نگه داشته می‌شود.', 'tisa-otp' ) );
				} );
			}
		);

		$this->testCard(
			'data',
			__( 'آزمایش ثبت رویداد', 'tisa-otp' )
		);
	}
}
