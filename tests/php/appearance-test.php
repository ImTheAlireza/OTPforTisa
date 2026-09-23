<?php
/**
 * Why the site does not look like the preview — and the two answers the panel
 * has to give before anyone believes the plugin again.
 *
 * The owner logged in on their own site, saw a form that did not look like the
 * one in the preview, and was not asked for a captcha. Nothing about that is a
 * mystery once the sources are read:
 *
 *   1. the preview renders with Vazirmatn loaded from a CDN, while the form
 *      declares `--tisa-font: inherit` — so the *theme's* font, Persian glyphs
 *      or not, decides how the form reads. WordPress rewrites nothing; the
 *      theme's stylesheet is simply the one that wins;
 *   2. the captcha was silent for one of three reasons that look identical from
 *      the outside: the challenge is invisible by design (v3), the number is on
 *      the exempt list, or there is no captcha configured at all.
 *
 * So the plugin now ships the font it was designed in, keeps a theme from
 * restyling its controls, and says which of those three cases is true — in the
 * panel, next to the numbers.
 *
 * @package TisaOtp\Tests
 */

require __DIR__ . '/bootstrap.php';

use TisaOtp\Admin\Controls;
use TisaOtp\Admin\SettingsScreen;
use TisaOtp\Captcha\Manager;
use TisaOtp\Config\Sanitizer;
use TisaOtp\Config\Settings;
use TisaOtp\Diagnostics\SelfTest;
use TisaOtp\Front\Assets;
use TisaOtp\Front\FormRenderer;
use TisaOtp\Gateway\Registry;
use TisaOtp\Log\LogStore;
use TisaOtp\Log\Logger;
use TisaOtp\Log\Redactor;
use TisaOtp\Registration\FieldSchema;
use TisaOtp\Admin\ReportScreen;

/* -------------------------------------------------------------------------
 * 1. The font ships with the plugin, and the stylesheet finds it
 */

$tisa_fonts   = glob( TISA_OTP_PATH . 'assets/fonts/*.woff2' );
$tisa_fonts   = is_array( $tisa_fonts ) ? $tisa_fonts : array();
$tisa_css     = (string) file_get_contents( TISA_OTP_PATH . 'assets/css/front.css' );
$tisa_faces   = preg_match_all( '/@font-face\s*\{[^}]*\}/', $tisa_css, $tisa_face_blocks );

tisa_check( 'the form ships a Persian font of its own (six subset files)', 6 === count( $tisa_fonts ) );
tisa_check( 'and the licence that lets it travel with the plugin', is_readable( TISA_OTP_PATH . 'assets/fonts/OFL.txt' ) );

$tisa_bad_magic = 0;

foreach ( $tisa_fonts as $tisa_font ) {
	// A truncated download is a 404 at render time, in every visitor's browser.
	if ( 'wOF2' !== substr( (string) file_get_contents( $tisa_font, false, null, 0, 4 ), 0, 4 ) ) {
		$tisa_bad_magic++;
	}
}

tisa_check( 'every font file is a real woff2 (no truncated asset)', 0 === $tisa_bad_magic );
tisa_check( 'the stylesheet declares one face per weight and subset', 6 === $tisa_faces );

/*
 * Each `src` is resolved the way a browser resolves it — relative to the
 * stylesheet — so a renamed or missing file fails here and not on the site.
 */
$tisa_missing = array();

foreach ( (array) $tisa_face_blocks[0] as $tisa_block ) {
	if ( ! preg_match( '/url\(([^)]+)\)/', $tisa_block, $tisa_url ) ) {
		$tisa_missing[] = 'no url';
		continue;
	}

	$tisa_path = TISA_OTP_PATH . 'assets/css/' . trim( $tisa_url[1] );

	if ( ! is_readable( $tisa_path ) ) {
		$tisa_missing[] = basename( $tisa_url[1] );
	}
}

tisa_check( 'and every @font-face points at a file that exists', array() === $tisa_missing );

/*
 * Two faces for the same family, weight and style are not additive: without
 * `unicode-range` the second one simply replaces the first, and half the
 * glyphs disappear. This is the whole reason the ranges are in the file.
 */
$tisa_with_range = preg_match_all( '/unicode-range:/', $tisa_css );

tisa_check( 'each face claims only its own codepoints, so both subsets survive', 6 === $tisa_with_range );
/*
 * The split matters: Persian digits exist only in the Arabic file and ASCII
 * only in the Latin one, so if the two ranges were swapped the form would fall
 * back to another font for exactly the characters it is read by — the code the
 * visitor types and the number they check it against.
 */
tisa_check( 'Persian digits are claimed by the Arabic subset', preg_match( '/arabic[^}]*U\+06F0-06F9|U\+06F0-06F9[^}]*arabic/s', $tisa_css ) === 1 );
tisa_check( 'Latin digits are claimed by the Latin subset', preg_match( '/latin[^}]*U\+0020-007E|U\+0020-007E[^}]*latin/s', $tisa_css ) === 1 );
tisa_check( 'the design font is the default the stylesheet uses', false !== strpos( $tisa_css, "--tisa-font: 'Vazirmatn'" ) );

/* -------------------------------------------------------------------------
 * 2. A theme cannot restyle the form's controls
 */

$tisa_block = (string) preg_replace( '/\s+/', ' ', $tisa_css );

tisa_check(
	'controls are re-declared with the form in front of them, so `.entry-content input` loses',
	false !== strpos( $tisa_block, '.tisa-otp .tisa-field__input,' ) && false !== strpos( $tisa_block, '.tisa-otp .tisa-btn {' )
);
tisa_check(
	'and a theme cannot put its own font, spacing or letter case on them',
	false !== strpos( $tisa_block, ".tisa-otp .tisa-code__box, .tisa-otp .tisa-btn { font-family: inherit; letter-spacing: normal; text-transform: none;" )
);
tisa_check(
	'the single code field keeps the tracking it uses to centre digits',
	false !== strpos( $tisa_block, '.tisa-code-single .tisa-code__bulk { direction: ltr; text-align: center; letter-spacing: 0.5em;' )
);

/* -------------------------------------------------------------------------
 * 3. The setting: which font the form prints with
 */

$GLOBALS['tisa_options'] = array();

$tisa_settings = new Settings();

tisa_check( 'a fresh install prints the shipped font, not the theme\'s', 'vazirmatn' === Settings::defaults()['form_font'] );
tisa_check( 'and the theme\'s font is still one choice away', in_array( 'theme', Sanitizer::spec()['form_font']['choices'], true ) );

tisa_check( 'a font list survives sanitising', "'Vazirmatn', Tahoma, sans-serif" === Sanitizer::fontFamily( "'Vazirmatn', Tahoma, sans-serif" ) );
tisa_check( 'a font that tries to end its declaration cannot', false === strpos( Sanitizer::fontFamily( 'Tahoma;} body{display:none' ), ';' ) );
tisa_check( 'and neither can a tag', false === strpos( Sanitizer::fontFamily( '<script>alert(1)</script>Tahoma' ), '<' ) );
tisa_check( 'an empty font falls back instead of emptying the attribute', '' === Sanitizer::fontFamily( ';;;{}' ) );

/*
 * `inlineStyle()` is where per-request values are printed on the element. A
 * variable declared on `.tisa-otp` in the stylesheet beats one inherited from
 * `:root`, so anything set only on `:root` is decoration: it never reached the
 * form. That is exactly what had happened to «رنگ زمینه فرم».
 */

/** A renderer built from the settings array given. */
function tisa_appearance_renderer( array $settings_array ): FormRenderer {
	$GLOBALS['tisa_options']['tisa_otp_settings'] = $settings_array;

	$settings = new Settings();
	$logs     = new LogStore( $settings );
	$captcha  = new Manager( $settings, new Logger( $settings, new Redactor(), $logs ) );

	return new FormRenderer( $settings, new FieldSchema( $settings ), $captcha, new \TisaOtp\Support\View(), new Assets( $settings, $captcha ) );
}

/** What the form would print in its `style` attribute. */
function tisa_appearance_style( FormRenderer $renderer ): string {
	$method = new ReflectionMethod( FormRenderer::class, 'inlineStyle' );
	$method->setAccessible( true );

	return (string) $method->invoke( $renderer, array() );
}

$tisa_style = tisa_appearance_style( tisa_appearance_renderer( array(
	'accent'           => '#b91c1c',
	'surface'          => '#fff7ed',
	'form_font'        => 'custom',
	'form_font_custom' => 'Tahoma, sans-serif',
) ) );

tisa_check( 'the form prints its own font on the element', false !== strpos( $tisa_style, '--tisa-font:Tahoma, sans-serif' ) );
tisa_check( 'the surface colour now actually reaches the form', false !== strpos( $tisa_style, '--tisa-surface:#fff7ed' ) );
tisa_check( 'and the accent still does', false !== strpos( $tisa_style, '--tisa-accent:#b91c1c' ) );

tisa_check(
	'choosing the theme means inheriting, and says so',
	false !== strpos( tisa_appearance_style( tisa_appearance_renderer( array( 'form_font' => 'theme' ) ) ), '--tisa-font:inherit' )
);

tisa_check(
	'the shipped font is what a default install prints',
	false !== strpos( tisa_appearance_style( tisa_appearance_renderer( array() ) ), "--tisa-font:'Vazirmatn'" )
);

/* -------------------------------------------------------------------------
 * 4. The two rows that answer the owner's question
 */

$GLOBALS['tisa_options']['tisa_otp_settings'] = array(
	'captcha_provider'   => 'recaptcha_v3',
	'captcha_site_key'   => 'site-key',
	'captcha_secret_key' => 'secret-key',
	'captcha_trigger'    => 'always',
	'form_font'          => 'vazirmatn',
	'phone_meta_key'     => 'tisa_phone',
	'trusted_enabled'    => '0',
);

$tisa_settings = new Settings();
$tisa_logs     = new LogStore( $tisa_settings );
$tisa_captcha  = new Manager( $tisa_settings, new Logger( $tisa_settings, new Redactor(), $tisa_logs ) );

/**
 * A SelfTest with the three collaborators the two sections under test use.
 *
 * Building the whole object would mean building the OTP service, the code
 * store and the gateway registry to ask one question about a font.
 */
function tisa_appearance_test( Settings $settings, LogStore $logs, Manager $captcha ): SelfTest {
	$test = ( new ReflectionClass( SelfTest::class ) )->newInstanceWithoutConstructor();

	foreach ( array( 'settings' => $settings, 'logs' => $logs, 'captcha' => $captcha ) as $name => $value ) {
		$property = new ReflectionProperty( SelfTest::class, $name );
		$property->setAccessible( true );
		$property->setValue( $test, $value );
	}

	return $test;
}

/** One row of a finished report, by label. */
function tisa_appearance_row( array $report, string $label ): array {
	foreach ( $report['rows'] as $row ) {
		if ( $label === $row['label'] ) {
			return $row;
		}
	}

	return array( 'label' => $label, 'value' => '', 'status' => 'missing', 'note' => '' );
}

$tisa_test      = tisa_appearance_test( $tisa_settings, $tisa_logs, $tisa_captcha );
$tisa_security  = $tisa_test->run( 'security' );
$tisa_visibility = tisa_appearance_row( $tisa_security, 'چالش دیدنی است؟' );

tisa_check( 'the panel says when the challenge is invisible', false !== strpos( $tisa_visibility['value'], 'بی‌صدا' ) );
tisa_check( 'and tells the owner what to pick instead', false !== strpos( $tisa_visibility['note'], 'ARCaptcha' ) );

$GLOBALS['tisa_options']['tisa_otp_settings']['captcha_provider'] = 'hcaptcha';
$tisa_settings = new Settings();
$tisa_captcha  = new Manager( $tisa_settings, new Logger( $tisa_settings, new Redactor(), $tisa_logs ) );

$tisa_visibility = tisa_appearance_row( tisa_appearance_test( $tisa_settings, $tisa_logs, $tisa_captcha )->run( 'security' ), 'چالش دیدنی است؟' );

tisa_check( 'a challenge a visitor can see is reported as visible', 'بله' === $tisa_visibility['value'] );

/* --- the exempt list, and the number of the person reading the row -------- */

$GLOBALS['tisa_options']['tisa_otp_settings'] = array_merge(
	$GLOBALS['tisa_options']['tisa_otp_settings'],
	array( 'trusted_enabled' => '1', 'trusted_numbers' => '09121234567', 'trusted_skip' => 'captcha,throttle' )
);

$GLOBALS['tisa_current_user']         = 7;
$GLOBALS['tisa_user_meta'][7]         = array( 'tisa_phone' => '09121234567' );

$tisa_settings = new Settings();
$tisa_test     = tisa_appearance_test( $tisa_settings, $tisa_logs, new Manager( $tisa_settings, new Logger( $tisa_settings, new Redactor(), $tisa_logs ) ) );
$tisa_exempt   = tisa_appearance_row( $tisa_test->run( 'security' ), 'فهرست معاف' );

tisa_check( 'a number on the exempt list is called out by name', false !== strpos( $tisa_exempt['note'], 'شمارهٔ خودتان' ) );
tisa_check( 'and the phone is masked, never printed in full', false === strpos( $tisa_exempt['note'], '09121234567' ) );
tisa_check( 'the row is a warning, not a footnote', 'warn' === $tisa_exempt['status'] );

$GLOBALS['tisa_user_meta'][7] = array( 'tisa_phone' => '09129999999' );
$tisa_settings                = new Settings();
$tisa_exempt                  = tisa_appearance_row( tisa_appearance_test( $tisa_settings, $tisa_logs, new Manager( $tisa_settings, new Logger( $tisa_settings, new Redactor(), $tisa_logs ) ) )->run( 'security' ), 'فهرست معاف' );

tisa_check( 'someone else\'s exempt number is not reported as yours', false === strpos( $tisa_exempt['note'], 'شمارهٔ خودتان' ) );
tisa_check( 'and the row still explains what the list does', false !== strpos( $tisa_exempt['note'], 'بدون کپچا' ) );

/* --- after_limit is the other silent case --------------------------------- */

$GLOBALS['tisa_options']['tisa_otp_settings']['captcha_trigger'] = 'after_limit';
$tisa_settings = new Settings();
$tisa_when     = tisa_appearance_row(
	tisa_appearance_test( $tisa_settings, $tisa_logs, new Manager( $tisa_settings, new Logger( $tisa_settings, new Redactor(), $tisa_logs ) ) )->run( 'security' ),
	'زمان نمایش'
);

tisa_check( 'the first attempts passing without a challenge is spelled out', false !== strpos( $tisa_when['note'], 'بدون چالش' ) );

/* --- the font row --------------------------------------------------------- */

$tisa_design = tisa_appearance_row( $tisa_test->run( 'design' ), 'فونت فرم' );

tisa_check( 'the appearance test names the font the form uses', false !== strpos( $tisa_design['value'], 'وزیرمتن' ) );
tisa_check( 'and that it is the one the preview shows', false !== strpos( $tisa_design['note'], 'پیش‌نمایش' ) );

$GLOBALS['tisa_options']['tisa_otp_settings']['form_font'] = 'theme';
$tisa_settings = new Settings();
$tisa_design   = tisa_appearance_row(
	tisa_appearance_test( $tisa_settings, $tisa_logs, new Manager( $tisa_settings, new Logger( $tisa_settings, new Redactor(), $tisa_logs ) ) )->run( 'design' ),
	'فونت فرم'
);

tisa_check( 'with the theme\'s font chosen, it warns that the theme decides', false !== strpos( $tisa_design['note'], 'پوسته' ) );

$GLOBALS['tisa_options']['tisa_otp_settings']['form_font'] = 'custom';
$GLOBALS['tisa_options']['tisa_otp_settings']['form_font_custom'] = 'Tahoma, sans-serif';
$tisa_settings = new Settings();
$tisa_design   = tisa_appearance_row(
	tisa_appearance_test( $tisa_settings, $tisa_logs, new Manager( $tisa_settings, new Logger( $tisa_settings, new Redactor(), $tisa_logs ) ) )->run( 'design' ),
	'فونت فرم'
);

tisa_check( 'a custom stack is reported as it is typed', 'Tahoma, sans-serif' === $tisa_design['value'] );

/* -------------------------------------------------------------------------
 * 4b. Style isolation: the form is rendered where the theme cannot reach
 */

$GLOBALS['tisa_options']['tisa_otp_settings'] = array();

tisa_check( 'isolation is on for a fresh install', '1' === Settings::defaults()['style_isolation'] );
tisa_check( 'and it can be turned off, because that is a choice a site may make', in_array( 'style_isolation', array_keys( Sanitizer::spec() ), true ) );

$tisa_settings = new Settings();
$tisa_logs     = new LogStore( $tisa_settings );
$tisa_captcha  = new Manager( $tisa_settings, new Logger( $tisa_settings, new Redactor(), $tisa_logs ) );
$tisa_assets   = new Assets( $tisa_settings, $tisa_captcha );
$tisa_config   = $tisa_assets->clientConfig();

tisa_check( 'the browser is told to isolate', true === $tisa_config['isolate'] );
tisa_check( 'it is handed the stylesheet to inject', false !== strpos( (string) $tisa_config['css'], 'assets/css/front.css' ) );
tisa_check( 'with the same ?ver the <link> used, so the fetch is a cache hit', false !== strpos( (string) $tisa_config['css'], 'ver=' . TISA_OTP_VERSION ) );
tisa_check( 'and the base the font URLs are rewritten against', 'assets/' === substr( (string) $tisa_config['assets'], -7 ) );

/*
 * Inside a shadow root a `:root` block cannot reach, so the values have to
 * travel as values. Their shape is what the stylesheet expects, and the accent
 * shades have to be derived — a fixed hover colour is the bug the owner already
 * reported once, on a crimson form that hovered green.
 */
$tisa_vars = (array) $tisa_config['vars'];

tisa_check( 'the plugin\'s variables travel to the shadow as values', '#0f766e' === $tisa_vars['tisa-accent'] );
tisa_check( 'the darker shade is derived from the accent, not fixed', '#0f766e' !== $tisa_vars['tisa-accent-strong'] && 0 === strpos( (string) $tisa_vars['tisa-accent-strong'], '#' ) );
tisa_check( 'the wash is translucent', 0 === strpos( (string) $tisa_vars['tisa-accent-soft'], 'rgba(' ) );
tisa_check( 'and radius and width keep their units', 'px' === substr( (string) $tisa_vars['tisa-width'], -2 ) && 'px' === substr( (string) $tisa_vars['tisa-radius'], -2 ) );

$GLOBALS['tisa_options']['tisa_otp_settings'] = array( 'accent' => '#b91c1c' );

$tisa_vars = (array) ( new Assets( new Settings(), new Manager( new Settings(), new Logger( new Settings(), new Redactor(), $tisa_logs ) ) ) )->clientConfig()['vars'];

tisa_check( 'changing the accent changes the hover shade with it', 'rgba(185, 28, 28, 0.14)' === $tisa_vars['tisa-accent-soft'] && '#b91c1c' === $tisa_vars['tisa-accent'] );

/* --- the panel says whether it is on ------------------------------------ */

$GLOBALS['tisa_options']['tisa_otp_settings'] = array( 'form_font' => 'vazirmatn' );

$tisa_settings = new Settings();
$tisa_logs     = new LogStore( $tisa_settings );
$tisa_isolation = tisa_appearance_row(
	tisa_appearance_test( $tisa_settings, $tisa_logs, new Manager( $tisa_settings, new Logger( $tisa_settings, new Redactor(), $tisa_logs ) ) )->run( 'design' ),
	'جداسازی از پوسته'
);

tisa_check( 'the appearance test reports isolation as on', 'روشن' === $tisa_isolation['value'] && 'ok' === $tisa_isolation['status'] );
tisa_check( 'and names what it protects the form from', false !== strpos( $tisa_isolation['note'], 'CSS قالب' ) );

$GLOBALS['tisa_options']['tisa_otp_settings'] = array( 'style_isolation' => '0' );

$tisa_settings  = new Settings();
$tisa_isolation = tisa_appearance_row(
	tisa_appearance_test( $tisa_settings, $tisa_logs, new Manager( $tisa_settings, new Logger( $tisa_settings, new Redactor(), $tisa_logs ) ) )->run( 'design' ),
	'جداسازی از پوسته'
);

tisa_check( 'turning it off is reported as a warning, not as silence', 'خاموش' === $tisa_isolation['value'] && 'warn' === $tisa_isolation['status'] );

/* -------------------------------------------------------------------------
 * 5. The setting has a control on the screen
 */

$GLOBALS['tisa_may_manage'] = true;
$GLOBALS['tisa_options']['tisa_otp_settings'] = array();

$tisa_screen = new SettingsScreen(
	new Settings(),
	new Controls( new Settings() ),
	new Registry( new Settings() ),
	new FieldSchema( new Settings() ),
	new Manager( new Settings(), new Logger( new Settings(), new Redactor(), new LogStore( new Settings() ) ) ),
	new LogStore( new Settings() ),
	new ReportScreen( new LogStore( new Settings() ), new Settings() )
);

$_GET['tab'] = 'design';

ob_start();
$tisa_screen->render();
$tisa_html = (string) ob_get_clean();

tisa_check( 'the settings screen offers the font choice', false !== strpos( $tisa_html, 'name="tisa_otp_settings[form_font]"' ) );
tisa_check( 'with the shipped font selected, so the default is what is read', preg_match( '/value="vazirmatn" selected/', $tisa_html ) === 1 );
tisa_check( 'and a box for a custom stack', false !== strpos( $tisa_html, 'tisa_otp_settings[form_font_custom]' ) );
tisa_check( 'the settings screen offers the isolation switch', false !== strpos( $tisa_html, 'tisa_otp_settings[style_isolation]' ) );

tisa_finish();
