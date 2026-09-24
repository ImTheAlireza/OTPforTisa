<?php
/**
 * Settings screen.
 *
 * Saving goes through the native Settings API (`options.php`), which keeps
 * nonces, capability checks and sanitising in one well-tested place.
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
use Signa\Log\Report;
use Signa\Registration\FieldCatalog;
use Signa\Registration\FieldSchema;
use Signa\User\AccessPolicy;

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

		echo '<div class="wrap signa-wrap" dir="rtl">';

		$this->header( $tab );

		ScreenNav::render( Menu::ROOT );

		if ( 'reports' !== $tab ) {
			$this->overview();
		}

		echo '<div class="signa-layout">';
		$this->tabs( $tab );

		/*
		 * The reports tab is a report, not a form: there is nothing to save, so it
		 * skips the form and its submit button and draws the same body the reports
		 * screen draws.
		 */
		if ( 'reports' === $tab ) {
			echo '<div class="signa-tabbody">';
			$this->reports->body( $this->reports->range() );
			echo '</div>';

			echo '</div></div>';

			return;
		}

		echo '<div class="signa-panel">';
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '" class="signa-form">';

		settings_fields( 'signa_group' );

		$this->section( $tab );

		echo '<div class="signa-form__footer">';
		submit_button( __( 'ذخیره تنظیمات', 'signa' ), 'primary large', 'submit', false );
		echo '<span class="signa-form__saved" data-signa-saved hidden>' . esc_html__( 'ذخیره شد', 'signa' ) . '</span>';
		echo '</div>';

		echo '</form></div></div></div>';
	}

	private function header( string $tab ): void {
		$labels = $this->tabLabels();

		echo '<div class="signa-header">';
		echo '<div class="signa-header__title"><h1>' . esc_html__( 'سیگنا', 'signa' ) . '</h1>';
		echo '<span class="signa-header__tag">' . esc_html( isset( $labels[ $tab ] ) ? $labels[ $tab ] : '' ) . '</span></div>';
		echo '<div class="signa-header__meta">';
		echo '<span class="signa-badge ' . ( $this->settings->bool( 'enabled', true ) ? 'is-on' : 'is-off' ) . '">'
			. esc_html( $this->settings->bool( 'enabled', true ) ? __( 'فعال', 'signa' ) : __( 'غیرفعال', 'signa' ) ) . '</span>';
		echo '<span class="signa-badge">' . esc_html( sprintf( /* translators: %s: plugin version */ __( 'نسخه %s', 'signa' ), SIGNA_VERSION ) ) . '</span>';
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
		echo '<nav class="signa-tabs" aria-label="' . esc_attr__( 'بخش‌های تنظیمات', 'signa' ) . '"><ul>';

		foreach ( $this->tabLabels() as $id => $label ) {
			$url = self::tabUrl( $id );

			printf(
				'<li><a href="%1$s" class="signa-tab%2$s">%3$s</a></li>',
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
			'general'      => __( 'عمومی', 'signa' ),
			'code'         => __( 'کد و کانال‌ها', 'signa' ),
			'gateways'     => __( 'سامانه‌های پیامکی', 'signa' ),
			'security'     => __( 'امنیت و محدودیت', 'signa' ),
			'registration' => __( 'فرم عضویت', 'signa' ),
			'design'       => __( 'ظاهر فرم', 'signa' ),
			'store'        => __( 'فروشگاه', 'signa' ),
			'data'         => __( 'داده و رویدادها', 'signa' ),
			'reports'      => __( 'گزارش‌ها و آمار', 'signa' ),
		);
	}

	private function section( string $tab ): void {
		echo '<div class="signa-section" data-tab="' . esc_attr( $tab ) . '">';

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
			__( 'آزمایش این بخش', 'signa' ),
			function () use ( $kind, $button, $extra, $attrs ) {
				echo '<p class="signa-inline">';

				printf(
					'<button type="button" class="button" data-signa-check="%1$s"%2$s>%3$s</button>',
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
		echo '<section class="signa-card"><h2>' . esc_html( $title ) . '</h2>';

		if ( '' !== $intro ) {
			echo '<p class="signa-card__intro">' . esc_html( $intro ) . '</p>';
		}

		$body();

		echo '</section>';
	}

	private function generalSection(): void {
		$c = $this->controls;

		$this->card(
			__( 'رفتار ورود', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'فعال‌سازی افزونه', 'signa' ), function () use ( $c ) {
					$c->toggle( 'enabled', __( 'ورود و عضویت با کد یکبارمصرف فعال باشد', 'signa' ) );
				} );

				$c->row( __( 'حالت احراز', 'signa' ), function () use ( $c ) {
					$c->cards(
						'auth_mode',
						array(
							'smart'         => array( 'label' => __( 'هوشمند', 'signa' ), 'desc' => __( 'کاربر موجود وارد می‌شود و کاربر تازه عضو', 'signa' ) ),
							'login_only'    => array( 'label' => __( 'فقط ورود', 'signa' ), 'desc' => __( 'عضویت خودکار بسته است', 'signa' ) ),
							'register_only' => array( 'label' => __( 'فقط عضویت', 'signa' ), 'desc' => __( 'مناسب فرم‌های ثبت‌نام', 'signa' ) ),
						)
					);
				} );

				$c->row( __( 'جایگزینی صفحه ورود وردپرس', 'signa' ), function () use ( $c ) {
					$c->toggle( 'replace_wp_login', __( 'فرم OTP روی wp-login.php نمایش داده شود', 'signa' ), __( 'فرم کلاسیک وردپرس پنهان می‌شود.', 'signa' ) );
				} );

				$c->row(
					__( 'ورود فقط با کد', 'signa' ),
					function () use ( $c ) {
						$c->toggle( 'password_login_off', __( 'ورود با نام کاربری و گذرواژه بسته شود', 'signa' ) );
					},
					__( 'جلوی ارسال گذرواژه به wp-login.php را می‌گیرد، نه فقط پنهان‌کردن فرم. راه بازگشت: کد اضطراری.', 'signa' )
				);

				$c->row(
					__( 'ماندن در حساب', 'signa' ),
					function () use ( $c ) {
						$c->toggle( 'remember_login', __( 'کاربر ۱۴ روز وارد بماند', 'signa' ) );
					},
					__( 'اگر خاموش باشد، نشست با بسته‌شدن مرورگر تمام می‌شود.', 'signa' )
				);

				$c->row( __( 'پنهان‌سازی وجود حساب', 'signa' ), function () use ( $c ) {
					$c->toggle( 'prevent_enumeration', __( 'پیام‌ها یکسان باشند', 'signa' ), __( 'هیچ‌کس نمی‌فهمد شماره‌اش قبلاً ثبت شده یا نه.', 'signa' ) );
				} );

				$c->row( __( 'سازگاری با کش صفحه', 'signa' ), function () use ( $c ) {
					$c->cards(
						'cache_mode',
						array(
							'auto'   => array(
								'label' => __( 'nonce تازه از سرور', 'signa' ),
								'desc'  => __( 'هنگام باز شدن فرم یک nonce تازه گرفته می‌شود و در صورت رد شدن، یک بار دیگر تلاش می‌شود.', 'signa' ),
							),
							'inline' => array(
								'label' => __( 'چاپ در صفحه', 'signa' ),
								'desc'  => __( 'بدون درخواست اضافه؛ فقط وقتی کش صفحه و CDN خاموش است.', 'signa' ),
							),
						)
					);
				}, __( 'اگر کش صفحه، وارنیش یا Cloudflare دارید حالت «nonce تازه از سرور» را نگه دارید؛ وگرنه فرم خطای ۴۰۳ می‌گیرد.', 'signa' ) );
			}
		);

		$this->card(
			__( 'مقصد پس از ورود', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'پس از ورود', 'signa' ), function () use ( $c ) {
					$c->text( 'login_redirect', home_url( '/' ), 'url' );
				}, __( 'خالی بگذارید تا کاربر به حساب کاربری (یا صفحه اصلی) برود.', 'signa' ) );

				$c->row( __( 'پس از عضویت', 'signa' ), function () use ( $c ) {
					$c->text( 'register_redirect', '', 'url' );
				} );
			}
		);

		$this->testCard(
			'general',
			__( 'آزمایش تنظیمات عمومی', 'signa' )
		);
	}

	/**
	 * Host name shown in the WebOTP hint, e.g. `example.com`.
	 */
	private function webOtpDomain(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) && '' !== $host ? strtolower( $host ) : __( 'دامنه شما', 'signa' );
	}

	private function codeSection(): void {
		$c = $this->controls;

		$this->card(
			__( 'کد یکبارمصرف', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'طول کد', 'signa' ), function () use ( $c ) {
					$c->number( 'code_length', 4, 8, __( 'رقم', 'signa' ) );
				} );

				$c->row( __( 'اعتبار کد', 'signa' ), function () use ( $c ) {
					$c->number( 'code_ttl', 30, 3600, __( 'ثانیه', 'signa' ) );
				} );

				$c->row( __( 'حداکثر تلاش برای وارد کردن کد', 'signa' ), function () use ( $c ) {
					$c->number( 'verify_attempts', 2, 15, __( 'بار', 'signa' ) );
				} );

				$c->row( __( 'فاصله بین دو ارسال', 'signa' ), function () use ( $c ) {
					$c->number( 'resend_delay', 10, 1800, __( 'ثانیه', 'signa' ) );
				} );

				$c->row( __( 'بررسی خودکار کد', 'signa' ), function () use ( $c ) {
					$c->toggle( 'auto_verify', __( 'به‌محض کامل شدن کد، بدون زدن دکمه بررسی شود', 'signa' ) );
				} );

				$c->row( __( 'مهلت پاسخ سرور', 'signa' ), function () use ( $c ) {
					$c->number( 'request_timeout', 5, 60, __( 'ثانیه', 'signa' ) );
				} );

				$c->row( __( 'محل نگهداری کد', 'signa' ), function () use ( $c ) {
					$c->cards(
						'code_store',
						array(
							'database' => array( 'label' => __( 'جدول اختصاصی', 'signa' ), 'desc' => __( 'پیش‌فرض؛ بدون نیاز به کش شیء', 'signa' ) ),
							'cache'    => array( 'label' => __( 'کش شیء', 'signa' ), 'desc' => __( 'سبک‌تر؛ فقط با Redis/Memcached پایدار', 'signa' ) ),
						)
					);
				} );
			}
		);

		$this->card(
			__( 'کانال‌های ارسال', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'کانال اصلی', 'signa' ), function () use ( $c ) {
					$c->select(
						'channel',
						array(
							'sms'   => __( 'پیامک', 'signa' ),
							'email' => __( 'ایمیل', 'signa' ),
						)
					);
				} );

				$c->row( __( 'کانال‌های فعال', 'signa' ), function () use ( $c ) {
					$c->checkboxList(
						'channels_enabled',
						array(
							'sms'   => __( 'پیامک', 'signa' ),
							'email' => __( 'ایمیل', 'signa' ),
						)
					);
				}, __( 'اگر کانال اصلی ناموفق باشد، به کانال فعال بعدی می‌رویم.', 'signa' ) );

				$c->row( __( 'جابه‌جایی خودکار', 'signa' ), function () use ( $c ) {
					$c->toggle( 'failover_enabled', __( 'در صورت خطای موقت، کانال/سامانه بعدی امتحان شود', 'signa' ) );
				} );

				$c->row( __( 'متن پیامک', 'signa' ), function () use ( $c ) {
					$c->textarea( 'sms_template', 2, 'کد ورود: {code}' );
				}, __( 'نشانه‌ها: {code} {phone} {minutes} {site} {domain} {webotp}', 'signa' ) );

				$c->row( __( 'خواندن خودکار کد (WebOTP)', 'signa' ), function () use ( $c ) {
					$c->toggle( 'webotp_enabled', __( 'خط شناسایی به انتهای پیامک اضافه شود', 'signa' ) );
				}, sprintf(
					/* translators: %s: the WebOTP binding line, for example @example.com #12345 */
					__( 'خط %s به انتهای پیامک متنی اضافه می‌شود (هزینهٔ چند نویسه بیشتر). برای الگوها نشانهٔ {webotp}.', 'signa' ),
					'@' . $this->webOtpDomain() . ' #12345'
				) );
			}
		);

		$this->card(
			__( 'کانال ایمیل', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'موضوع ایمیل', 'signa' ), function () use ( $c ) {
					$c->text( 'email_subject' );
				} );

				$c->row( __( 'متن ایمیل', 'signa' ), function () use ( $c ) {
					$c->textarea( 'email_body', 4 );
				}, __( 'نشانه‌ها: {code} {minutes} {site}', 'signa' ) );

				$c->row( __( 'فرستنده', 'signa' ), function () use ( $c ) {
					$c->text( 'email_from', get_option( 'admin_email' ), 'email' );
				}, __( 'خالی بگذارید تا از آدرس پیش‌فرض وردپرس استفاده شود.', 'signa' ) );
			}
		);

		$this->testCard(
			'code',
			__( 'آزمایش ساخت کد', 'signa' ),
			__( 'هیچ پیامکی ارسال نمی‌شود.', 'signa' )
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
			__( 'انتخاب سامانه', 'signa' ),
			function () use ( $c, $options ) {
				$c->row( __( 'سامانه اصلی', 'signa' ), function () use ( $c, $options ) {
					$c->select( 'sms_gateway', $options );
				} );

				$c->row( __( 'سامانه پشتیبان', 'signa' ), function () use ( $c, $options ) {
					$c->select( 'sms_backup_gateway', array( '' => __( 'بدون پشتیبان', 'signa' ) ) + $options );
				}, __( 'فقط برای خطاهای موقت (تایم‌اوت، خطای ۵xx، اتمام اعتبار) استفاده می‌شود.', 'signa' ) );

				/*
				 * The switch that answers «راه حلش چیه» for a site whose own
				 * wp-config.php blocks outbound HTTP. It is labelled with what
				 * it bypasses, and the note says which of the two answers is
				 * better — a plugin that quietly walked around the site's
				 * setting would be worse than the problem it solves.
				 */
				$c->row( __( 'ارسال مستقیم', 'signa' ), function () use ( $c ) {
					$c->toggle( 'direct_send', __( 'نادیده گرفتن WP_HTTP_BLOCK_EXTERNAL برای پیامک', 'signa' ) );
				}, __( 'افزونه درخواست را با cURL مستقیم می‌فرستد؛ فقط اگر به wp-config.php دسترسی ندارید.', 'signa' ) );
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
						$c->notice( __( 'این سامانه در حال حاضر سامانه اصلی است.', 'signa' ), 'success' );
					} elseif ( ! empty( $state['backup'] ) ) {
						$c->notice( __( 'این سامانه به‌عنوان پشتیبان انتخاب شده است.', 'signa' ) );
					} elseif ( ! empty( $state['missing'] ) ) {
						$c->notice( __( 'اعتبارنامه این سامانه کامل نیست.', 'signa' ), 'warning' );
					}

					if ( ! empty( $state['docs'] ) ) {
						$c->description( sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( (string) $state['docs'] ), esc_html__( 'مستندات سامانه', 'signa' ) ) );
					}
				}
			);
		}

		$this->controls->description(
			__( 'اعتبارنامه‌ها را می‌توان در wp-config.php هم گذاشت: <code>SIGNA_SMSIR_API_KEY</code>.', 'signa' )
		);

		$this->testCard(
			'gateways',
			__( 'آزمایش سامانه‌های پیامکی', 'signa' ),
			'',
			function () {
				printf(
					'<button type="button" class="button button-primary" data-signa-sms-test>%s</button>',
					esc_html__( 'ارسال پیامک آزمایشی', 'signa' )
				);
			},
			''
		);
	}

	private function securitySection(): void {
		$c = $this->controls;

		$this->card(
			__( 'محدودیت ارسال', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'فعال بودن محدودیت‌ها', 'signa' ), function () use ( $c ) {
					$c->toggle( 'throttle_enabled', __( 'شمارنده‌های ارسال و تأیید اعمال شوند', 'signa' ) );
				} );

				$c->row( __( 'بازه شمارش', 'signa' ), function () use ( $c ) {
					$c->number( 'window_minutes', 1, 1440, __( 'دقیقه', 'signa' ) );
				} );

				$c->row( __( 'سقف هر شماره', 'signa' ), function () use ( $c ) {
					$c->number( 'limit_per_phone', 1, 100, __( 'ارسال در بازه', 'signa' ) );
				} );

				$c->row( __( 'سقف هر آدرس IP', 'signa' ), function () use ( $c ) {
					$c->number( 'limit_per_ip', 1, 500, __( 'ارسال در بازه', 'signa' ) );
				} );

				$c->row( __( 'سقف روزانه هر IP', 'signa' ), function () use ( $c ) {
					$c->number( 'limit_per_ip_daily', 1, 5000, __( 'ارسال در روز', 'signa' ) );
				} );

				$c->row( __( 'سقف تأیید کد هر IP', 'signa' ), function () use ( $c ) {
					$c->number( 'limit_verify_per_ip', 5, 1000, __( 'تلاش در بازه', 'signa' ) );
				} );

				$c->row(
					__( 'سقف روزانهٔ کل سایت', 'signa' ),
					function () use ( $c ) {
						$c->number( 'limit_per_site_daily', 0, 100000, __( 'ارسال در روز', 'signa' ) );
					},
					__( 'سقف‌های بالا برای هر شماره و هر IP است؛ این یکی برای خود سایت است. صفر = بی‌سقف.', 'signa' )
				);
			}
		);

		$this->card(
			__( 'شماره‌های مورد اعتماد', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'فعال بودن فهرست', 'signa' ), function () use ( $c ) {
					$c->toggle( 'trusted_enabled', __( 'شماره‌های این فهرست از کپچا و محدودیت‌ها معاف باشند', 'signa' ) );
				} );

				$c->row( __( 'شماره‌ها', 'signa' ), function () use ( $c ) {
					$c->textarea( 'trusted_numbers', 4, "09121234567\n0912*\n0935*4567" );
				}, __( 'هر خط یک شماره، پیش‌شماره (۰۹۱۲) یا الگو (۰۹۳۵*۴۵۶۷).', 'signa' ) );

				$c->row( __( 'معافیت از', 'signa' ), function () use ( $c ) {
					$c->text( 'trusted_skip', 'captcha,throttle' );
				}, __( 'نام گاردها با کاما: captcha و throttle. فهرست مسدود هرگز نادیده گرفته نمی‌شود.', 'signa' ) );
			}
		);

		$this->card(
			__( 'پروکسی و IP واقعی', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'سرآشد معتبر', 'signa' ), function () use ( $c ) {
					$c->select(
						'proxy_mode',
						array(
							'none'       => __( 'هیچ (فقط REMOTE_ADDR)', 'signa' ),
							'cloudflare' => __( 'کلادفلر (CF-Connecting-IP)', 'signa' ),
							'forwarded'  => __( 'X-Forwarded-For', 'signa' ),
							'real_ip'    => __( 'X-Real-IP', 'signa' ),
						)
					);
				}, __( 'سرآشد تنها از پروکسی‌های مورد اعتماد پایین پذیرفته می‌شود.', 'signa' ) );

				$c->row( __( 'پروکسی‌های مورد اعتماد', 'signa' ), function () use ( $c ) {
					$c->text( 'trusted_proxies', '173.245.48.0/20, 127.0.0.1' );
				}, __( 'IP یا CIDR، با کاما جدا کنید.', 'signa' ) );
			}
		);

		$this->card(
			__( 'کپچا', 'signa' ),
			function () use ( $c ) {
				$captcha = $this->captcha;

				$c->row( __( 'سرویس', 'signa' ), function () use ( $c, $captcha ) {
					$c->select( 'captcha_provider', $captcha->labels() );
				} );

				$c->row( __( 'کلید سایت', 'signa' ), function () use ( $c ) {
					$c->text( 'captcha_site_key' );
				} );

				$c->row( __( 'کلید خصوصی', 'signa' ), function () use ( $c ) {
					$c->secret( 'captcha_secret_key' );
				} );

				$c->row( __( 'حداقل امتیاز (reCAPTCHA v3)', 'signa' ), function () use ( $c ) {
					$c->text( 'captcha_score', '0.5' );
				} );

				$c->row( __( 'زمان نمایش', 'signa' ), function () use ( $c ) {
					$c->cards(
						'captcha_trigger',
						array(
							'always'      => array( 'label' => __( 'همیشه', 'signa' ), 'desc' => __( 'هر درخواست ارسال کد', 'signa' ) ),
							'after_limit' => array( 'label' => __( 'پس از چند تلاش', 'signa' ), 'desc' => __( 'تجربه روان‌تر برای انسان‌ها', 'signa' ) ),
						)
					);
				} );

				$c->row(
					__( 'وقتی سرویس کپچا در دسترس نیست', 'signa' ),
					function () use ( $c ) {
						$c->toggle(
							'captcha_fail_open',
							__( 'ورود را نبند (پیشنهاد می‌شود)', 'signa' ),
							__( 'هانی‌پات و سقف ارسال فعال می‌مانند', 'signa' )
						);
					},
					__( 'اگر سرویس کپچا در دسترس نباشد: روشن = کاربر رد نمی‌شود، خاموش = هر ورودی کپچا می‌خواهد.', 'signa' )
				);

				$c->row(
					__( 'نشانی جایگزین اسکریپت', 'signa' ),
					function () use ( $c ) {
						$c->text( 'captcha_script_override', 'https://my-mirror.example/1/api.js' );
					},
					__( 'اختیاری؛ برای وقتی دامنهٔ رسمی سرویس روی سایت شما باز نمی‌شود.', 'signa' )
				);

				$c->row( __( 'مهلت بارگذاری اسکریپت', 'signa' ), function () use ( $c ) {
					$c->number( 'captcha_timeout', 3000, 20000, __( 'میلی‌ثانیه', 'signa' ) );
				} );

				$c->row( __( 'حالت آرکپچا', 'signa' ), function () use ( $c ) {
					$c->toggle( 'captcha_arcaptcha_v3', __( 'نسخه ۳ (امتیازی/نامرئی)', 'signa' ), __( 'اگر حساب آرکپچای شما v3 است روشن کنید', 'signa' ) );
				} );
			}
		);

		$this->card(
			__( 'نقش‌های محافظت‌شده', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'مسدودسازی ورود پیامکی نقش‌های حساس', 'signa' ), function () use ( $c ) {
					$c->toggle( 'guard_roles', __( 'این نقش‌ها فقط با رمز عبور وارد شوند', 'signa' ) );
				} );

				$c->row( __( 'نقش‌ها', 'signa' ), function () use ( $c ) {
					$c->text( 'guarded_roles', 'administrator,editor,shop_manager' );
				}, __( 'نام نقش‌ها با کاما. مدیران کل همیشه مسدود هستند.', 'signa' ) );

				$c->row( __( 'نقش پیش‌فرض کاربران تازه', 'signa' ), function () use ( $c ) {
					$c->select( 'default_role', AccessPolicy::selectableRoles() );
				} );
			}
		);

		$this->accessPointerCard();

		$this->testCard(
			'security',
			__( 'آزمایش کپچا در این مرورگر', 'signa' ),
			'',
			null,
			' data-signa-captcha-test'
		);
	}

	/**
	 * Pointer to the access screen: the two levers there are emergency tools,
	 * not settings, so they are edited on their own page.
	 */
	private function accessPointerCard(): void {
		$this->card(
			__( 'دسترسی اضطراری و فهرست مسدود', 'signa' ),
			function () {

				printf(
					'<p><a class="button" href="%1$s">%2$s</a></p>',
					esc_url( admin_url( 'admin.php?page=' . AccessScreen::SLUG ) ),
					esc_html__( 'باز کردن صفحه دسترسی و مسدودی', 'signa' )
				);
			}
		);
	}

	private function registrationSection(): void {
		$c = $this->controls;

		$this->card(
			__( 'عضویت', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'فعال بودن عضویت', 'signa' ), function () use ( $c ) {
					$c->toggle( 'registration_enabled', __( 'شماره‌های تازه بتوانند حساب بسازند', 'signa' ) );
				} );

				$c->row( __( 'عضویت خودکار', 'signa' ), function () use ( $c ) {
					$c->toggle( 'auto_register', __( 'پس از تأیید کد، حساب ساخته شود', 'signa' ) );
				} );

				$c->row( __( 'ترتیب مراحل', 'signa' ), function () use ( $c ) {
					$c->cards(
						'registration_flow',
						array(
							'fields_then_code' => array( 'label' => __( 'اول فرم، بعد کد', 'signa' ), 'desc' => __( 'کمترین پیامک هدررفته', 'signa' ) ),
							'code_then_fields' => array( 'label' => __( 'اول کد، بعد فرم', 'signa' ), 'desc' => __( 'شماره پیش از دریافت اطلاعات تأیید می‌شود', 'signa' ) ),
						)
					);
				} );

				$c->row( __( 'ایمیل', 'signa' ), function () use ( $c ) {
					$c->select(
						'email_mode',
						array(
							'off'      => __( 'جمع‌آوری نشود', 'signa' ),
							'optional' => __( 'اختیاری', 'signa' ),
							'required' => __( 'الزامی', 'signa' ),
						)
					);
				} );

				$c->row( __( 'نام کاربری', 'signa' ), function () use ( $c ) {
					$c->select(
						'username_from',
						array(
							'phone'          => __( 'بر پایه شماره', 'signa' ),
							'phone_prefixed' => __( 'شماره با پیشوند user', 'signa' ),
							'email'          => __( 'بر پایه ایمیل', 'signa' ),
						)
					);
				} );

				$c->row( __( 'نام نمایشی', 'signa' ), function () use ( $c ) {
					$c->select(
						'display_name_from',
						array(
							'full_name'  => __( 'نام و نام خانوادگی', 'signa' ),
							'first_name' => __( 'فقط نام', 'signa' ),
							'phone'      => __( 'شماره ماسک‌شده', 'signa' ),
						)
					);
				} );

				$c->row( __( 'ایمیل خوش‌آمد', 'signa' ), function () use ( $c ) {
					$c->toggle( 'send_welcome_email', __( 'پس از عضویت ایمیل خوش‌آمد ارسال شود', 'signa' ) );
				} );

				$c->row( __( 'عنوان فرم عضویت', 'signa' ), function () use ( $c ) {
					$c->text( 'register_heading' );
				} );

				$c->row( __( 'توضیح فرم عضویت', 'signa' ), function () use ( $c ) {
					$c->textarea( 'register_subheading', 2 );
				} );
			}
		);

		$this->card(
			__( 'فیلدها', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'مجموعه آماده', 'signa' ), function () use ( $c ) {
					$c->select( 'field_preset', FieldCatalog::labels() );
				} );

				$this->fieldRepeater();
			}
		);

		$this->controls->description(
			sprintf(
				/* translators: %d: number of active fields */
				esc_html__( 'اکنون %d فیلد در فرم عضویت فعال است.', 'signa' ),
				count( $this->schema->active() )
			)
		);

		$this->testCard(
			'registration',
			__( 'آزمایش فرم عضویت', 'signa' )
		);
	}

	private function fieldRepeater(): void {
		$fields = (array) $this->settings->arr( 'fields' );
		$base   = $this->controls->name( 'fields' );

		echo '<div class="signa-repeater" data-signa-repeater data-base="' . esc_attr( $base ) . '">';
		echo '<div class="signa-repeater__head"><span>' . esc_html__( 'شناسه', 'signa' ) . '</span><span>' . esc_html__( 'برچسب', 'signa' ) . '</span><span>' . esc_html__( 'نوع', 'signa' ) . '</span><span>' . esc_html__( 'کلید متا', 'signa' ) . '</span><span>' . esc_html__( 'الزامی', 'signa' ) . '</span><span></span></div>';
		echo '<div class="signa-repeater__rows" data-signa-rows>';

		foreach ( $fields as $index => $field ) {
			$this->repeaterRow( $base, (int) $index, (array) $field );
		}

		echo '</div>';

		printf(
			'<button type="button" class="button" data-signa-add-row>%s</button>',
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

		printf( '<input type="text" name="%1$s[id]" value="%2$s" dir="ltr" placeholder="city">', esc_attr( $name ), esc_attr( $id ) );
		printf( '<input type="text" name="%1$s[label]" value="%2$s" placeholder="%3$s">', esc_attr( $name ), esc_attr( $label ), esc_attr__( 'برچسب', 'signa' ) );

		echo '<select name="' . esc_attr( $name ) . '[type]">';
		foreach ( FieldCatalog::types() as $option ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $option ), selected( $type, $option, false ) );
		}
		echo '</select>';

		printf( '<input type="text" name="%1$s[meta_key]" value="%2$s" dir="ltr" placeholder="signa_city">', esc_attr( $name ), esc_attr( $meta ) );

		echo '<select name="' . esc_attr( $name ) . '[target]">';
		foreach ( array( 'meta' => __( 'متا', 'signa' ), 'core' => __( 'هسته وردپرس', 'signa' ), 'wc' => __( 'ووکامرس', 'signa' ) ) as $value => $text ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $target, $value, false ), esc_html( $text ) );
		}
		echo '</select>';

		printf(
			'<label class="signa-check"><input type="hidden" name="%1$s[required]" value="0"><input type="checkbox" name="%1$s[required]" value="1"%2$s></label>',
			esc_attr( $name ),
			checked( ! empty( $field['required'] ) && '0' !== $field['required'], true, false )
		);

		printf( '<button type="button" class="button-link signa-repeater__remove" data-signa-remove-row>%s</button>', esc_html__( 'حذف', 'signa' ) );

		echo '</div>';
	}

	private function designSection(): void {
		$c = $this->controls;

		$this->card(
			__( 'پوسته و رنگ', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'پوسته', 'signa' ), function () use ( $c ) {
					$c->cards(
						'skin',
						array(
							'line'  => array( 'label' => __( 'خطی', 'signa' ), 'desc' => __( 'کمترین تزئین، سریع‌ترین بارگذاری', 'signa' ) ),
							'card'  => array( 'label' => __( 'کارت', 'signa' ), 'desc' => __( 'کارت سایه‌دار روی پس‌زمینه', 'signa' ) ),
							'glass' => array( 'label' => __( 'شیشه‌ای', 'signa' ), 'desc' => __( 'لایه نیمه‌شفاف و محو', 'signa' ) ),
							'slate' => array( 'label' => __( 'تیره', 'signa' ), 'desc' => __( 'مناسب صفحات تیره', 'signa' ) ),
							'pill'  => array( 'label' => __( 'گرد', 'signa' ), 'desc' => __( 'گوشه‌های کاملاً گرد', 'signa' ) ),
						)
					);
				} );

				$c->row( __( 'رنگ تأکیدی', 'signa' ), function () use ( $c ) {
					$c->color( 'accent' );
				} );

				$c->row( __( 'رنگ زمینه فرم', 'signa' ), function () use ( $c ) {
					$c->color( 'surface' );
				} );

				$c->row( __( 'گردی گوشه‌ها', 'signa' ), function () use ( $c ) {
					$c->number( 'radius', 0, 40, 'px' );
				} );

				$c->row( __( 'عرض فرم', 'signa' ), function () use ( $c ) {
					$c->number( 'width', 280, 900, 'px' );
				} );

				$c->row(
					__( 'فونت فرم', 'signa' ),
					function () use ( $c ) {
						$c->select(
							'form_font',
							array(
								'vazirmatn' => __( 'وزیرمتن (همراه افزونه)', 'signa' ),
								'theme'     => __( 'فونت پوسته', 'signa' ),
								'custom'    => __( 'فونت دلخواه', 'signa' ),
							)
						);
						$c->text( 'form_font_custom', 'Vazirmatn, Tahoma, sans-serif' );
					},
					__( '«وزیرمتن» همان فونت پیش‌نمایش است و همراه افزونه می‌آید.', 'signa' )
				);

				$c->row(
					__( 'جداسازی استایل از پوسته', 'signa' ),
					function () use ( $c ) {
						$c->toggle( 'style_isolation', __( 'فرم داخل Shadow DOM رندر شود', 'signa' ) );
					},
					__( 'روشن: قالب سایت نمی‌تواند فونت، رنگ و شکل کنترل‌های فرم را عوض کند.', 'signa' )
				);

				$c->row( __( 'چینش', 'signa' ), function () use ( $c ) {
					$c->select(
						'align',
						array(
							'center' => __( 'مرکز', 'signa' ),
							'start'  => __( 'ابتدا', 'signa' ),
							'end'    => __( 'انتها', 'signa' ),
						)
					);
				} );

				$c->row( __( 'ورودی کد', 'signa' ), function () use ( $c ) {
					$c->cards(
						'code_input',
						array(
							'boxes'  => array( 'label' => __( 'خانه‌های جدا', 'signa' ), 'desc' => __( 'هر رقم در یک کادر', 'signa' ) ),
							'single' => array( 'label' => __( 'یک کادر', 'signa' ), 'desc' => __( 'ساده و فشرده', 'signa' ) ),
						)
					);
				} );
			}
		);

		$this->card(
			__( 'برند و متن‌ها', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'نمایش لوگو', 'signa' ), function () use ( $c ) {
					$c->toggle( 'show_brand', __( 'لوگو بالای فرم نمایش داده شود', 'signa' ) );
				} );

				$c->row( __( 'آدرس لوگو', 'signa' ), function () use ( $c ) {
					$c->text( 'brand_logo', '', 'url' );
					echo ' <button type="button" class="button" data-signa-pick-media="' . esc_attr( $c->id( 'brand_logo' ) ) . '">' . esc_html__( 'انتخاب از کتابخانه', 'signa' ) . '</button>';
				} );

				$c->row( __( 'عرض لوگو', 'signa' ), function () use ( $c ) {
					$c->number( 'brand_width', 32, 320, 'px' );
				} );

				$c->row( __( 'عنوان فرم', 'signa' ), function () use ( $c ) {
					$c->text( 'form_heading' );
				} );

				$c->row( __( 'توضیح فرم', 'signa' ), function () use ( $c ) {
					$c->textarea( 'form_subheading', 2 );
				} );

				$c->row( __( 'دکمه دریافت کد', 'signa' ), function () use ( $c ) {
					$c->text( 'label_send' );
				} );

				$c->row( __( 'دکمه ورود', 'signa' ), function () use ( $c ) {
					$c->text( 'label_verify' );
				} );

				$c->row( __( 'ارسال دوباره', 'signa' ), function () use ( $c ) {
					$c->text( 'label_resend' );
				} );

				$c->row( __( 'ویرایش شماره', 'signa' ), function () use ( $c ) {
					$c->text( 'label_edit_phone' );
				} );

				$c->row( __( 'متن قوانین', 'signa' ), function () use ( $c ) {
					$c->toggle( 'terms_enabled', __( 'نمایش متن قوانین زیر فرم', 'signa' ) );
					$c->text( 'terms_text' );
					$c->text( 'terms_url', '', 'url' );
				} );
			}
		);

		$this->card(
			__( 'CSS و JS دلخواه', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'محل اعمال', 'signa' ), function () use ( $c ) {
					$c->select(
						'custom_code_scope',
						array(
							'form_pages' => __( 'فقط صفحات دارای فرم', 'signa' ),
							'everywhere' => __( 'همه صفحات', 'signa' ),
						)
					);
				} );

				$c->row( __( 'CSS دلخواه', 'signa' ), function () use ( $c ) {
					$c->textarea( 'custom_css', 6, '.signa { }' );
				} );

				$c->row( __( 'JS دلخواه', 'signa' ), function () use ( $c ) {
					$c->textarea( 'custom_js', 6 );
				}, __( 'فقط برای مدیران دارای دسترسی unfiltered_html ذخیره می‌شود.', 'signa' ) );
			}
		);

		$this->testCard(
			'design',
			__( 'آزمایش رنگ‌ها و کنتراست', 'signa' )
		);
	}

	private function storeSection(): void {
		$c = $this->controls;

		if ( ! class_exists( 'WooCommerce' ) ) {
			$c->notice( __( 'ووکامرس فعال نیست؛ این تنظیمات فعلاً اثری ندارند.', 'signa' ), 'warning' );
		}

		$this->card(
			__( 'وودمارت', 'signa' ),
			function () use ( $c ) {
				$detected = \Signa\Integrations\WoodMart::detected();

				$c->row(
					__( 'پوسته', 'signa' ),
					function () use ( $detected ) {
						printf(
							'<span class="signa-badge %s">%s</span>',
							$detected ? 'is-on' : 'is-off',
							esc_html( $detected ? __( 'وودمارت شناسایی شد', 'signa' ) : __( 'وودمارت فعال نیست', 'signa' ) )
						);
					},
					$detected ? '' : __( 'این تنظیمات فقط روی سایت‌های وودمارت اثر دارند.', 'signa' )
				);

				$c->row( __( 'فرم در سایدبار ورود', 'signa' ), function () use ( $c ) {
					$c->toggle( 'woodmart_sidebar', __( 'فرم OTP در پنل ورود وودمارت رندر شود', 'signa' ) );
				}, __( 'پنل ورود همان کشوی حساب کاربری در هدر است؛ فرم داخل خودش، با همان AJAX و کپچا.', 'signa' ) );

				$c->row( __( 'فرم رمز عبور وودمارت', 'signa' ), function () use ( $c ) {
					$c->select(
						'woodmart_mode',
						array(
							'replace' => __( 'جایگزین شود', 'signa' ),
							'append'  => __( 'بماند (فرم OTP زیرش بیاید)', 'signa' ),
						)
					);
				}, __( 'هیچ فایلی از پوسته تغییر نمی‌کند؛ فرم در همان جای پنل جایگزین می‌شود.', 'signa' ) );

				$c->row( __( 'بخش «ساخت حساب» وودمارت', 'signa' ), function () use ( $c ) {
					$c->toggle( 'woodmart_account_block', __( 'آیکن، متن و لینک «ساخت حساب» وودمارت هم برداشته شود', 'signa' ) );
				}, __( 'فرم خود افزونه عضویت دارد. اگر عضویت افزونه خاموش باشد، این بخش می‌ماند تا راه ثبت‌نام باز بماند.', 'signa' ) );
			}
		);

		$this->card(
			__( 'ووکامرس', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'فرم حساب کاربری', 'signa' ), function () use ( $c ) {
					$c->toggle( 'woo_account_form', __( 'فرم ورود «حساب کاربری» با فرم OTP جایگزین شود', 'signa' ) );
				} );

				$c->row( __( 'ورود اجباری پیش از تسویه حساب', 'signa' ), function () use ( $c ) {
					$c->toggle( 'woo_checkout_gate', __( 'کاربر مهمان به صفحه تسویه حساب نرسد', 'signa' ) );
				} );

				$c->row( __( 'صفحه ورود', 'signa' ), function () use ( $c ) {
					$c->text( 'woo_checkout_page', wp_login_url(), 'url' );
				} );

				$c->row( __( 'پیام صفحه تسویه حساب', 'signa' ), function () use ( $c ) {
					$c->textarea( 'woo_checkout_notice', 2 );
				} );

				$c->row( __( 'همگام‌سازی شماره صورتحساب', 'signa' ), function () use ( $c ) {
					$c->toggle( 'sync_billing_phone', __( 'شماره تأییدشده در billing_phone هم ذخیره شود', 'signa' ) );
				} );

				$c->row( __( 'اتصال سفارش‌های مهمان', 'signa' ), function () use ( $c ) {
					$c->toggle( 'link_guest_orders', __( 'پس از عضویت، سفارش‌های مهمان با همان شماره به حساب متصل شوند', 'signa' ) );
				} );
			}
		);

		$this->card(
			__( 'المنتور', 'signa' ),
			function () use ( $c ) {
				$c->description(
					__( 'ویجت «فرم ورود پیامکی سیگنا» در دستهٔ سیگنا المنتور است.', 'signa' )
				);
			}
		);

		$this->testCard(
			'store',
			__( 'آزمایش فروشگاه', 'signa' )
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

		echo '<div class="signa-overview">';

		$this->egressNotice();

		if ( ! $this->settings->bool( 'logs_enabled', true ) ) {
			$this->controls->notice( __( 'ثبت رویدادها خاموش است؛ آماری برای نمایش نیست.', 'signa' ), 'warning' );
		} else {
			$counts = $this->logs->countByEvent(
				array_merge( Report::requestEvents(), array( Report::CREATED ) ),
				self::OVERVIEW_DAYS
			);

			$kpis = Report::kpis( $counts );

			$cells = array(
				array( __( 'درخواست‌ها', 'signa' ), number_format_i18n( (int) $kpis['requests'] ), '' ),
				array( __( 'موفق', 'signa' ), number_format_i18n( (int) $kpis['sent'] ), 'is-good' ),
				array( __( 'ناموفق', 'signa' ), number_format_i18n( (int) $kpis['failed'] ), (int) $kpis['failed'] > 0 ? 'is-bad' : '' ),
				array( __( 'نرخ موفقیت', 'signa' ), number_format_i18n( (float) $kpis['rate'], 1 ) . '٪', 'is-rate' ),
			);

			echo '<div class="signa-kpis">';

			foreach ( $cells as $cell ) {
				printf(
					'<div class="signa-kpi %1$s"><span class="signa-kpi__value">%2$s</span><span class="signa-kpi__label">%3$s</span></div>',
					esc_attr( $cell[2] ),
					esc_html( $cell[1] ),
					esc_html( $cell[0] )
				);
			}

			echo '</div>';
		}

		echo '<div class="signa-overview__side">';

		printf(
			'<a class="button" href="%1$s">%2$s</a>',
			esc_url( $reports ),
			esc_html__( 'گزارش‌ها', 'signa' )
		);

		printf(
			'<p class="signa-muted">%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of days */
					__( 'آمار %d روز گذشته', 'signa' ),
					self::OVERVIEW_DAYS
				)
			)
		);

		echo '</div></div>';
	}

	/**
	 * The one sentence the owner needs before pressing any button.
	 *
	 * Everything else about the outbound block answers a question the owner has
	 * already asked (a failed test, a failed send). This one is on every screen,
	 * above the tabs, because a site whose wp-config.php blocks outbound HTTP
	 * cannot send a single SMS — and finding that out from a test result is a
	 * worse afternoon than finding it out from a banner.
	 *
	 * It is shown only when it is true for the gateway they configured, and it
	 * names both answers: the switch in this panel, and the two lines in
	 * wp-config.php. Turning the switch on makes it disappear on its own.
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

		$this->controls->notice(
			sprintf(
				/* translators: %s: the host this site refuses to reach */
				__( 'این سایت اجازهٔ درخواست خروجی به %s را نمی‌دهد؛ تا آن خط عوض نشود هیچ پیامکی فرستاده نمی‌شود.', 'signa' ),
				$host
			) . ' '
			. __( 'یا در wp-config.php خط WP_HTTP_BLOCK_EXTERNAL را false کنید، یا در سامانه‌های پیامکی «ارسال مستقیم» را روشن کنید.', 'signa' ),
			'warning'
		);
	}

	private function dataSection(): void {
		$c = $this->controls;

		$this->card(
			__( 'رویدادها', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'ثبت رویدادها', 'signa' ), function () use ( $c ) {
					$c->toggle( 'logs_enabled', __( 'رویدادها در جدول اختصاصی ذخیره شوند', 'signa' ) );
				} );

				$c->row( __( 'نگهداری', 'signa' ), function () use ( $c ) {
					$c->number( 'logs_keep_days', 1, 90, __( 'روز', 'signa' ) );
				} );

				$c->row(
					__( 'سقف تعداد ردیف‌ها', 'signa' ),
					function () use ( $c ) {
						$c->number( 'logs_max_rows', 0, 5000000, __( 'ردیف', 'signa' ) );
					},
					__( 'نگهداری «هفت روز» زیر حمله می‌تواند میلیون‌ها ردیف شود. این سقف قدیمی‌ترین‌ها را حذف می‌کند. صفر = بی‌سقف.', 'signa' )
				);

				$c->row( __( 'حالت اشکال‌زدایی', 'signa' ), function () use ( $c ) {
					$c->toggle( 'debug', __( 'رویدادها در error_log هم نوشته شوند', 'signa' ), __( 'فقط وقتی WP_DEBUG فعال است.', 'signa' ) );
				} );
			},
			__( 'شماره موبایل هرگز به‌صورت خام ذخیره نمی‌شود؛ فقط اثر انگشت HMAC و نسخه ماسک‌شده.', 'signa' )
		);

		$this->card(
			__( 'گزارش و رویدادها', 'signa' ),
			function () use ( $c ) {
				$c->row(
					__( 'صفحه‌ها', 'signa' ),
					function () {
						printf(
							'<a class="button" href="%1$s">%2$s</a> <a class="button" href="%3$s">%4$s</a>',
							esc_url( self::tabUrl( 'reports' ) ),
							esc_html__( 'گزارش‌ها', 'signa' ),
							esc_url( admin_url( 'admin.php?page=' . LogsScreen::SLUG ) ),
							esc_html__( 'تک‌تک رویدادها', 'signa' )
						);
					}
				);
			}
		);

		$this->card(
			__( 'کلیدهای داده', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'کلید متای اصلی شماره', 'signa' ), function () use ( $c ) {
					$c->text( 'phone_meta_key', 'signa_phone' );
				}, __( 'تغییر این کلید، داده‌های موجود را منتقل نمی‌کند.', 'signa' ) );

				$c->row( __( 'کلیدهای جست‌وجوی جانبی', 'signa' ), function () use ( $c ) {
					$c->text( 'lookup_meta_keys', 'billing_phone,digits_phone' );
				}, __( 'برای پیدا کردن حساب‌های قدیمی؛ با کاما جدا کنید.', 'signa' ) );
			}
		);

		$this->card(
			__( 'حذف داده‌ها', 'signa' ),
			function () use ( $c ) {
				$c->row( __( 'هنگام حذف افزونه', 'signa' ), function () use ( $c ) {
					$c->toggle( 'wipe_on_uninstall', __( 'جدول‌ها و تنظیمات هم پاک شوند', 'signa' ), __( 'متای شماره کاربران در هر صورت نگه داشته می‌شود.', 'signa' ) );
				} );
			}
		);

		$this->testCard(
			'data',
			__( 'آزمایش ثبت رویداد', 'signa' )
		);
	}
}
