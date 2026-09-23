<?php
/**
 * Builds the WoodMart preview page from the plugin's real output.
 *
 * The page at `preview/public/woodmart.html` is not hand-written: this script
 * renders the theme's login panel, runs it through the integration, and writes
 * the result into the preview's chrome. So the page the owner looks at is the
 * markup the plugin really prints, not an artist's impression of it.
 *
 * The two attributes that address the REST API are pointed at the preview's
 * mock endpoints — the preview is a stand-in for a live site, and it has no
 * PHP and no SMS gateway behind it. Everything else is the renderer's own
 * output, byte for byte.
 *
 * Usage:
 *   php tools/woodmart-demo.php            # print the page (redirect it)
 *   php tools/woodmart-demo.php --write    # write preview/public/woodmart.html
 *   php tools/woodmart-demo.php --check    # fail if the committed page is stale
 *
 * Printing by default is deliberate: the sandbox's WebAssembly PHP cannot
 * write to this filesystem, so a redirect is how the page is produced here.
 *
 * @package TisaOtp\Tools
 */

$root = dirname( __DIR__ );

require $root . '/tests/php/bootstrap.php';

/**
 * The panel WoodMart prints, in the shape the theme prints it.
 *
 * Kept in step with `tests/php/woodmart-test.php`, which asserts the same
 * markup is what the integration expects to find.
 */
function tisa_demo_panel(): string {
	return <<<'HTML'
<div class="login-form-side wd-side-hidden wd-right color-scheme-light">
	<div class="widget-heading">
		<span class="title">ورود / ثبت‌نام</span>
		<a href="#" class="close-side-widget">بستن</a>
	</div>
	<div class="widget woocommerce widget_shopping_cart_content"></div>
	<div class="woocommerce-notices-wrapper"></div>
	<form method="post" class="login woocommerce-form woocommerce-form-login hidden-form">
		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
			<label for="username">نام کاربری یا ایمیل</label>
			<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="username" id="username" autocomplete="username">
		</p>
		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
			<label for="password">گذرواژه</label>
			<input class="woocommerce-Input woocommerce-Input--text input-text" type="password" name="password" id="password" autocomplete="current-password">
		</p>
		<p class="form-row">
			<label class="woocommerce-form-login__rememberme"><input name="rememberme" type="checkbox" value="forever"> مرا به خاطر بسپار</label>
			<button type="submit" class="woocommerce-button button woocommerce-form-login__submit" name="login">ورود</button>
		</p>
		<p class="lost-password"><a href="#">گذرواژه را فراموش کرده‌اید؟</a></p>
	</form>
	<div class="create-account-question">
		<p>هنوز حساب کاربری ندارید؟</p>
		<a href="#?action=register" class="btn btn-style-link btn-color-primary create-account-button">ساخت حساب</a>
	</div>
</div>
HTML;
}

/* The theme, as the integration sees it. */
if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce { // phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps -- WooCommerce class.
		/** @var string */
		public $version = '9.4.0';
	}
}

function woodmart_woocommerce_installed(): bool {
	return true;
}

function whb_get_settings(): array {
	return array( 'account' => array( 'login_dropdown' => true, 'form_display' => 'side' ) );
}

function woodmart_sidebar_login_form(): void {
	echo tisa_demo_panel();
}

$GLOBALS['tisa_template'] = 'woodmart';
$GLOBALS['tisa_options']['tisa_otp_settings'] = array(
	'woodmart_sidebar' => '1',
	'woodmart_mode'    => 'replace',
);

/**
 * A WoodMart integration, wired to the settings the demo stands for.
 *
 * A fresh one per panel: the integration injects once per request, which is
 * exactly the behaviour a page needs.
 */
function tisa_demo_integration( string $mode ): TisaOtp\Integrations\WoodMart {
	$GLOBALS['tisa_options']['tisa_otp_settings']['woodmart_mode'] = $mode;

	$settings = new TisaOtp\Config\Settings();
	$logs     = new TisaOtp\Log\LogStore( $settings );
	$captcha  = new TisaOtp\Captcha\Manager( $settings, new TisaOtp\Log\Logger( $settings, new TisaOtp\Log\Redactor(), $logs ) );
	$assets   = new TisaOtp\Front\Assets( $settings, $captcha );

	return new TisaOtp\Integrations\WoodMart(
		$settings,
		new TisaOtp\Front\FormRenderer( $settings, new TisaOtp\Registration\FieldSchema( $settings ), $captcha, new TisaOtp\Support\View(), $assets ),
		$assets
	);
}

/* Two modes, one panel each: what the theme prints, and what comes out. */
$after     = tisa_demo_integration( 'replace' )->swap( tisa_demo_panel() );
$alongside = tisa_demo_integration( 'append' )->swap( tisa_demo_panel() );

/**
 * The panel, wired to the preview's stubs instead of a live WordPress.
 *
 * Three things are swapped. The REST addresses point at the preview's mock
 * endpoints — the preview is a stand-in for a live site and has neither PHP nor
 * an SMS gateway behind it. And the form's "printed at" timestamp is pinned, so
 * the file this script writes is the same file tomorrow: without that, the
 * `--check` gate in CI could never pass.
 */
function tisa_demo_localise( string $html ): string {
	$html = str_replace(
		array(
			'https://example.test/wp-json/tisa-otp/v1/',
			'https://example.test/wp-json/tisa-otp/v1/form-config',
		),
		array( '/mock/tisa-otp/v1/', '/mock/tisa-otp/v1/form-config' ),
		$html
	);

	$html = (string) preg_replace(
		'/class="tisa-otp__rendered" value="[0-9]+"/',
		'class="tisa-otp__rendered" value="0"',
		$html
	);

	/*
	 * The signed form token carries the minute it was minted in, so it is
	 * different every run — and the page it lives in has to be the same file
	 * every run for the gate below to mean anything. The preview's own token
	 * does the same job here.
	 */
	return (string) preg_replace(
		'/data-form-token="[^"]*"/',
		'data-form-token="demo-form-token"',
		$html
	);
}

$after     = tisa_demo_localise( $after );
$alongside = tisa_demo_localise( $alongside );

/* The "before" column is the theme's own markup, untouched. */
$before = tisa_demo_panel();

$page = tisa_demo_page( $before, $after, $alongside );
$argv = isset( $argv ) ? (array) $argv : array();

if ( in_array( '--check', $argv, true ) ) {
	$committed = is_readable( $root . '/preview/public/woodmart.html' ) ? (string) file_get_contents( $root . '/preview/public/woodmart.html' ) : '';

	if ( $committed === $page ) {
		echo "woodmart.html matches the plugin's current output\n";
		exit( 0 );
	}

	fwrite( STDERR, "preview/public/woodmart.html is stale — run: php tools/woodmart-demo.php\n" );
	exit( 1 );
}

if ( in_array( '--write', $argv, true ) ) {
	file_put_contents( $root . '/preview/public/woodmart.html', $page );
	echo 'wrote preview/public/woodmart.html (' . number_format( strlen( $page ) ) . " bytes)\n";
	exit( 0 );
}

echo $page;

/**
 * The page itself: the preview's chrome, the two panels, and the notes.
 */
function tisa_demo_page( string $before, string $after, string $alongside ): string {
	$drawer = static function ( string $id, string $label, string $html, string $note ): string {
		return <<<HTML
		<section class="wm-col">
			<h2 class="wm-col__title">{$label}</h2>
			<p class="wm-col__note">{$note}</p>
			<div class="wm-shell" data-drawer>
				<div class="wm-backdrop" data-close></div>
				<aside class="wm-side{$id}" id="{$id}" aria-label="{$label}">
					{$html}
				</aside>
			</div>
			<button type="button" class="wm-open" data-open="{$id}">کشو را باز کن</button>
		</section>
HTML;
	};

	$body = $drawer(
		'wm-before',
		'پیش از افزونه',
		$before,
		'این همان چیزی است که وودمارت خودش چاپ می‌کند: نام کاربری و گذرواژه.'
	);

	$body .= $drawer(
		'wm-after',
		'با افزونه، حالت «جایگزین شود»',
		$after,
		'فرم ووکامرس از داخل همان پنل برداشته می‌شود و فرم OTP جای آن می‌آید. عنوان، دکمهٔ بستن و بخش «ساخت حساب» دست‌نخورده‌اند.'
	);

	$body .= $drawer(
		'wm-alongside',
		'حالت «بماند»',
		$alongside,
		'اگر بخواهید ورود با گذرواژه هم بماند، فرم OTP زیر آن و بالای «ساخت حساب» می‌آید و هیچ فرمی داخل فرم دیگر نمی‌رود.'
	);

	return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تیسا — فرم OTP داخل سایدبار ورود وودمارت</title>
<!--
	پوستهٔ واقعی وودمارت این صفحه را نمی‌سازد: اینجا فقط چهارچوب پوسته (هدر، کشو،
	رنگ‌های تیره) بازسازی شده تا فرم در همان جایی دیده شود که روی سایت دیده
	می‌شود. خود فرم، خروجی واقعی افزونه است.
-->
<link rel="stylesheet" href="/plugin-assets/css/front.css">
<style>
	:root { color-scheme: dark; }
	* { box-sizing: border-box; }
	body {
		margin: 0;
		background: #14161a;
		color: #e8eaed;
		font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif;
		line-height: 1.8;
	}
	.wm-bar {
		display: flex; gap: 14px; align-items: center; flex-wrap: wrap;
		padding: 14px 22px; background: #0f1114; border-bottom: 1px solid #262a31;
		position: sticky; top: 0; z-index: 5;
	}
	.wm-bar a { color: #9fb4c7; text-decoration: none; font-size: 14px; padding: 4px 10px; border-radius: 999px; }
	.wm-bar a.is-here { background: #1d2127; color: #fff; }
	.wm-bar strong { font-size: 15px; margin-inline-end: 8px; }
	.wm-lead { max-width: 900px; padding: 26px 22px 6px; }
	.wm-lead h1 { font-size: 22px; margin: 0 0 8px; }
	.wm-lead p { margin: 0 0 10px; color: #b6bdc7; font-size: 15px; }
	.wm-lead code { background: #1d2127; padding: 2px 6px; border-radius: 6px; font-size: 13px; }
	.wm-grid { display: grid; gap: 18px; grid-template-columns: repeat(auto-fit, minmax(330px, 1fr)); padding: 20px 22px 60px; }
	.wm-col { background: #191c21; border: 1px solid #262a31; border-radius: 16px; padding: 16px; }
	.wm-col__title { font-size: 16px; margin: 0 0 6px; }
	.wm-col__note { margin: 0 0 14px; font-size: 13.5px; color: #a9b1bc; }
	.wm-shell { position: relative; min-height: 460px; border-radius: 12px; overflow: hidden; background: #101216; border: 1px solid #23272e; }
	.wm-backdrop { position: absolute; inset: 0; background: rgba(0, 0, 0, .45); opacity: 0; transition: opacity .25s; }
	.wm-side {
		position: absolute; inset-block: 0; inset-inline-end: 0; width: min(360px, 92%);
		background: #1b1e23; border-inline-start: 1px solid #2b3037;
		overflow-y: auto; transform: translateX(-102%);
		transition: transform .32s ease;
	}
	[dir="rtl"] .wm-side { transform: translateX(102%); }
	.wm-shell.is-open .wm-side { transform: none; }
	.wm-shell.is-open .wm-backdrop { opacity: 1; }
	.wm-backdrop[data-close] { cursor: pointer; }
	.wm-open {
		margin-top: 12px; background: #0f766e; color: #fff; border: 0; border-radius: 10px;
		padding: 9px 16px; font: inherit; font-size: 14px; cursor: pointer;
	}
	/* چهارچوب پوسته: عنوان کشو، دکمهٔ بستن، و فرم قدیمی ووکامرس */
	.wm-side .widget-heading { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px 10px; }
	.wm-side .widget-heading .title { font-weight: 700; }
	.wm-side .close-side-widget { color: #8d97a3; text-decoration: none; font-size: 13px; }
	.wm-side form.login { display: grid; gap: 10px; padding: 0 20px 18px; }
	.wm-side form.login label { display: block; font-size: 13px; color: #a9b1bc; margin-bottom: 4px; }
	.wm-side form.login input[type="text"],
	.wm-side form.login input[type="password"] {
		width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid #333a43;
		background: #14171b; color: #e8eaed; font: inherit;
	}
	.wm-side .woocommerce-form-login__submit {
		background: #333941; color: #fff; border: 0; border-radius: 10px; padding: 10px 18px; font: inherit; cursor: pointer;
	}
	.wm-side .create-account-question { padding: 0 20px 22px; font-size: 14px; color: #a9b1bc; }
	.wm-side .create-account-question .create-account-button { color: #7fd1c8; text-decoration: none; }
	.wm-side .woocommerce-notices-wrapper:empty { display: none; }
</style>
</head>
<body>
<nav class="wm-bar">
	<strong>تیسا</strong>
	<a href="/">فرم ورود</a>
	<a href="/account">حساب کاربری</a>
	<a href="/admin">پنل مدیریت</a>
	<a class="is-here" href="/woodmart">سایدبار وودمارت</a>
	<a href="/download/tisa-otp.zip" download>دانلود افزونه</a>
</nav>

<main>
	<section class="wm-lead">
		<h1>فرم OTP داخل سایدبار ورود وودمارت</h1>
		<p>
			ستون وسط، خروجی واقعی افزونه است: پوسته وودمارت پنل ورود را در فوتر چاپ می‌کند و افزونه همان
			فراخوانی را قاب می‌گیرد و فقط فرم ووکامرس داخل پنل را عوض می‌کند. هیچ فایلی از پوسته تغییر نمی‌کند.
		</p>
		<p>
			همین سه ستون در پیش‌نمایش ساخته شده‌اند تا حالت‌ها را کنار هم ببینید؛ روی سایت واقعی فقط یکی از آن دو
			اتفاق می‌افتد، بسته به تنظیم <code>سایدبار ورود وودمارت</code> در پنل.
		</p>
	</section>

	<div class="wm-grid">
{$body}
	</div>
</main>

<script>
	/* Stands in for wp_localize_script( 'tisa-otp-front', 'tisaOtp', ... ) */
	window.tisaOtp = {
		restUrl: '/mock/tisa-otp/v1/',
		nonce: 'demo-nonce',
		configUrl: '/mock/tisa-otp/v1/form-config',
		cacheMode: 'auto',
		autoVerify: true,
		webOtp: false,
		timeoutMs: 15000,
		enabled: true,
		codeLength: 5,
		codeInput: 'boxes',
		rescueAfter: 30,
		cooldown: 60,
		ttl: 120,
		channel: 'sms',
		skin: 'line',
		formToken: 'demo-form-token',
		captcha: { enabled: false, provider: 'none', config: {} },
		isolate: true,
		css: '/plugin-assets/css/front.css',
		assets: '/plugin-assets/',
		vars: {
			'--tisa-accent': '#7fd1c8',
			'--tisa-accent-strong': '#5bb3aa',
			'--tisa-accent-soft': 'rgba(127, 209, 200, .16)',
			'--tisa-surface': '#1b1e23',
			'--tisa-radius': '12px',
			'--tisa-width': '100%'
		}
	};
</script>
<script src="/plugin-assets/js/front.js" defer></script>
<script>
	document.addEventListener('click', function (event) {
		var open = event.target.closest('[data-open]');
		var close = event.target.closest('[data-close]');

		if (open) {
			var shell = document.getElementById(open.getAttribute('data-open')).closest('[data-drawer]');
			document.querySelectorAll('[data-drawer].is-open').forEach(function (other) {
				if (other !== shell) { other.classList.remove('is-open'); }
			});
			shell.classList.toggle('is-open');
		}

		if (close) {
			close.closest('[data-drawer]').classList.remove('is-open');
		}
	});

	/* One panel open on load, so the form is visible without a click. */
	window.addEventListener('DOMContentLoaded', function () {
		document.querySelector('#wm-after').closest('[data-drawer]').classList.add('is-open');
	});
</script>
</body>
</html>
HTML;
}
