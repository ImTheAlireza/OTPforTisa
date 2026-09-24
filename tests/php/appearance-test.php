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
 *      declares `--signa-font: inherit` — so the *theme's* font, Persian glyphs
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
 * @package Signa\Tests
 */

require __DIR__ . '/bootstrap.php';

use Signa\Admin\Controls;
use Signa\Admin\Dashboard;
use Signa\Admin\SettingsScreen;
use Signa\Captcha\Manager;
use Signa\Config\Sanitizer;
use Signa\Config\Settings;
use Signa\Diagnostics\SelfTest;
use Signa\Front\Assets;
use Signa\Front\FormRenderer;
use Signa\Gateway\Registry;
use Signa\Log\LogStore;
use Signa\Log\Logger;
use Signa\Log\Redactor;
use Signa\Registration\FieldSchema;
use Signa\Admin\ReportScreen;

/* -------------------------------------------------------------------------
 * 1. The font ships with the plugin, and the stylesheet finds it
 */

$signa_fonts   = glob( SIGNA_PATH . 'assets/fonts/*.woff2' );
$signa_fonts   = is_array( $signa_fonts ) ? $signa_fonts : array();
$signa_css     = (string) file_get_contents( SIGNA_PATH . 'assets/css/front.css' );
$signa_faces   = preg_match_all( '/@font-face\s*\{[^}]*\}/', $signa_css, $signa_face_blocks );

signa_check( 'the form ships a Persian font of its own (six subset files)', 6 === count( $signa_fonts ) );
signa_check( 'and the licence that lets it travel with the plugin', is_readable( SIGNA_PATH . 'assets/fonts/OFL.txt' ) );

$signa_bad_magic = 0;

foreach ( $signa_fonts as $signa_font ) {
	// A truncated download is a 404 at render time, in every visitor's browser.
	if ( 'wOF2' !== substr( (string) file_get_contents( $signa_font, false, null, 0, 4 ), 0, 4 ) ) {
		$signa_bad_magic++;
	}
}

signa_check( 'every font file is a real woff2 (no truncated asset)', 0 === $signa_bad_magic );
signa_check( 'the stylesheet declares one face per weight and subset', 6 === $signa_faces );

/*
 * Each `src` is resolved the way a browser resolves it — relative to the
 * stylesheet — so a renamed or missing file fails here and not on the site.
 */
$signa_missing = array();

foreach ( (array) $signa_face_blocks[0] as $signa_block ) {
	if ( ! preg_match( '/url\(([^)]+)\)/', $signa_block, $signa_url ) ) {
		$signa_missing[] = 'no url';
		continue;
	}

	$signa_path = SIGNA_PATH . 'assets/css/' . trim( $signa_url[1] );

	if ( ! is_readable( $signa_path ) ) {
		$signa_missing[] = basename( $signa_url[1] );
	}
}

signa_check( 'and every @font-face points at a file that exists', array() === $signa_missing );

/*
 * Two faces for the same family, weight and style are not additive: without
 * `unicode-range` the second one simply replaces the first, and half the
 * glyphs disappear. This is the whole reason the ranges are in the file.
 */
$signa_with_range = preg_match_all( '/unicode-range:/', $signa_css );

signa_check( 'each face claims only its own codepoints, so both subsets survive', 6 === $signa_with_range );
/*
 * The split matters: Persian digits exist only in the Arabic file and ASCII
 * only in the Latin one, so if the two ranges were swapped the form would fall
 * back to another font for exactly the characters it is read by — the code the
 * visitor types and the number they check it against.
 */
signa_check( 'Persian digits are claimed by the Arabic subset', preg_match( '/arabic[^}]*U\+06F0-06F9|U\+06F0-06F9[^}]*arabic/s', $signa_css ) === 1 );
signa_check( 'Latin digits are claimed by the Latin subset', preg_match( '/latin[^}]*U\+0020-007E|U\+0020-007E[^}]*latin/s', $signa_css ) === 1 );
signa_check( 'the design font is the default the stylesheet uses', false !== strpos( $signa_css, "--signa-font: 'Vazirmatn'" ) );

/* -------------------------------------------------------------------------
 * 2. A theme cannot restyle the form's controls
 */

$signa_block = (string) preg_replace( '/\s+/', ' ', $signa_css );

signa_check(
	'controls are re-declared with the form in front of them, so `.entry-content input` loses',
	false !== strpos( $signa_block, '.signa .signa-field__input,' ) && false !== strpos( $signa_block, '.signa .signa-btn {' )
);
signa_check(
	'and a theme cannot put its own font, spacing or letter case on them',
	false !== strpos( $signa_block, ".signa .signa-code__box, .signa .signa-btn { font-family: inherit; letter-spacing: normal; text-transform: none;" )
);
signa_check(
	'the single code field keeps the tracking it uses to centre digits',
	false !== strpos( $signa_block, '.signa-code-single .signa-code__bulk { direction: ltr; text-align: center; letter-spacing: 0.5em;' )
);

/* -------------------------------------------------------------------------
 * 3. The setting: which font the form prints with
 */

$GLOBALS['signa_options'] = array();

$signa_settings = new Settings();

signa_check( 'a fresh install prints the shipped font, not the theme\'s', 'vazirmatn' === Settings::defaults()['form_font'] );
signa_check( 'and the theme\'s font is still one choice away', in_array( 'theme', Sanitizer::spec()['form_font']['choices'], true ) );

signa_check( 'a font list survives sanitising', "'Vazirmatn', Tahoma, sans-serif" === Sanitizer::fontFamily( "'Vazirmatn', Tahoma, sans-serif" ) );
signa_check( 'a font that tries to end its declaration cannot', false === strpos( Sanitizer::fontFamily( 'Tahoma;} body{display:none' ), ';' ) );
signa_check( 'and neither can a tag', false === strpos( Sanitizer::fontFamily( '<script>alert(1)</script>Tahoma' ), '<' ) );
signa_check( 'an empty font falls back instead of emptying the attribute', '' === Sanitizer::fontFamily( ';;;{}' ) );

/*
 * `inlineStyle()` is where per-request values are printed on the element. A
 * variable declared on `.signa` in the stylesheet beats one inherited from
 * `:root`, so anything set only on `:root` is decoration: it never reached the
 * form. That is exactly what had happened to «رنگ زمینه فرم».
 */

/** A renderer built from the settings array given. */
function signa_appearance_renderer( array $settings_array ): FormRenderer {
	$GLOBALS['signa_options']['signa_settings'] = $settings_array;

	$settings = new Settings();
	$logs     = new LogStore( $settings );
	$captcha  = new Manager( $settings, new Logger( $settings, new Redactor(), $logs ) );

	return new FormRenderer( $settings, new FieldSchema( $settings ), $captcha, new \Signa\Support\View(), new Assets( $settings, $captcha ) );
}

/** What the form would print in its `style` attribute. */
function signa_appearance_style( FormRenderer $renderer ): string {
	$method = new ReflectionMethod( FormRenderer::class, 'inlineStyle' );
	$method->setAccessible( true );

	return (string) $method->invoke( $renderer, array() );
}

$signa_style = signa_appearance_style( signa_appearance_renderer( array(
	'accent'           => '#b91c1c',
	'surface'          => '#fff7ed',
	'form_font'        => 'custom',
	'form_font_custom' => 'Tahoma, sans-serif',
) ) );

signa_check( 'the form prints its own font on the element', false !== strpos( $signa_style, '--signa-font:Tahoma, sans-serif' ) );
signa_check( 'the surface colour now actually reaches the form', false !== strpos( $signa_style, '--signa-surface:#fff7ed' ) );
signa_check( 'and the accent still does', false !== strpos( $signa_style, '--signa-accent:#b91c1c' ) );

signa_check(
	'choosing the theme means inheriting, and says so',
	false !== strpos( signa_appearance_style( signa_appearance_renderer( array( 'form_font' => 'theme' ) ) ), '--signa-font:inherit' )
);

signa_check(
	'the shipped font is what a default install prints',
	false !== strpos( signa_appearance_style( signa_appearance_renderer( array() ) ), "--signa-font:'Vazirmatn'" )
);

/* -------------------------------------------------------------------------
 * 4. The two rows that answer the owner's question
 */

$GLOBALS['signa_options']['signa_settings'] = array(
	'captcha_provider'   => 'recaptcha_v3',
	'captcha_site_key'   => 'site-key',
	'captcha_secret_key' => 'secret-key',
	'captcha_trigger'    => 'always',
	'form_font'          => 'vazirmatn',
	'phone_meta_key'     => 'signa_phone',
	'trusted_enabled'    => '0',
);

$signa_settings = new Settings();
$signa_logs     = new LogStore( $signa_settings );
$signa_captcha  = new Manager( $signa_settings, new Logger( $signa_settings, new Redactor(), $signa_logs ) );

/**
 * A SelfTest with the three collaborators the two sections under test use.
 *
 * Building the whole object would mean building the OTP service, the code
 * store and the gateway registry to ask one question about a font.
 */
function signa_appearance_test( Settings $settings, LogStore $logs, Manager $captcha ): SelfTest {
	$test = ( new ReflectionClass( SelfTest::class ) )->newInstanceWithoutConstructor();

	foreach ( array( 'settings' => $settings, 'logs' => $logs, 'captcha' => $captcha ) as $name => $value ) {
		$property = new ReflectionProperty( SelfTest::class, $name );
		$property->setAccessible( true );
		$property->setValue( $test, $value );
	}

	return $test;
}

/** One row of a finished report, by label. */
function signa_appearance_row( array $report, string $label ): array {
	foreach ( $report['rows'] as $row ) {
		if ( $label === $row['label'] ) {
			return $row;
		}
	}

	return array( 'label' => $label, 'value' => '', 'status' => 'missing', 'note' => '' );
}

$signa_test      = signa_appearance_test( $signa_settings, $signa_logs, $signa_captcha );
$signa_security  = $signa_test->run( 'security' );
$signa_visibility = signa_appearance_row( $signa_security, 'چالش دیدنی است؟' );

signa_check( 'the panel says when the challenge is invisible', false !== strpos( $signa_visibility['value'], 'بی‌صدا' ) );
signa_check( 'and tells the owner what to pick instead', false !== strpos( $signa_visibility['note'], 'ARCaptcha' ) );

$GLOBALS['signa_options']['signa_settings']['captcha_provider'] = 'hcaptcha';
$signa_settings = new Settings();
$signa_captcha  = new Manager( $signa_settings, new Logger( $signa_settings, new Redactor(), $signa_logs ) );

$signa_visibility = signa_appearance_row( signa_appearance_test( $signa_settings, $signa_logs, $signa_captcha )->run( 'security' ), 'چالش دیدنی است؟' );

signa_check( 'a challenge a visitor can see is reported as visible', 'بله' === $signa_visibility['value'] );

/* --- the exempt list, and the number of the person reading the row -------- */

$GLOBALS['signa_options']['signa_settings'] = array_merge(
	$GLOBALS['signa_options']['signa_settings'],
	array( 'trusted_enabled' => '1', 'trusted_numbers' => '09121234567', 'trusted_skip' => 'captcha,throttle' )
);

$GLOBALS['signa_current_user']         = 7;
$GLOBALS['signa_user_meta'][7]         = array( 'signa_phone' => '09121234567' );

$signa_settings = new Settings();
$signa_test     = signa_appearance_test( $signa_settings, $signa_logs, new Manager( $signa_settings, new Logger( $signa_settings, new Redactor(), $signa_logs ) ) );
$signa_exempt   = signa_appearance_row( $signa_test->run( 'security' ), 'فهرست معاف' );

signa_check( 'a number on the exempt list is called out by name', false !== strpos( $signa_exempt['note'], 'شمارهٔ خودتان' ) );
signa_check( 'and the phone is masked, never printed in full', false === strpos( $signa_exempt['note'], '09121234567' ) );
signa_check( 'the row is a warning, not a footnote', 'warn' === $signa_exempt['status'] );

$GLOBALS['signa_user_meta'][7] = array( 'signa_phone' => '09129999999' );
$signa_settings                = new Settings();
$signa_exempt                  = signa_appearance_row( signa_appearance_test( $signa_settings, $signa_logs, new Manager( $signa_settings, new Logger( $signa_settings, new Redactor(), $signa_logs ) ) )->run( 'security' ), 'فهرست معاف' );

signa_check( 'someone else\'s exempt number is not reported as yours', false === strpos( $signa_exempt['note'], 'شمارهٔ خودتان' ) );
signa_check( 'and the row still explains what the list does', false !== strpos( $signa_exempt['note'], 'بدون کپچا' ) );

/* --- after_limit is the other silent case --------------------------------- */

$GLOBALS['signa_options']['signa_settings']['captcha_trigger'] = 'after_limit';
$signa_settings = new Settings();
$signa_when     = signa_appearance_row(
	signa_appearance_test( $signa_settings, $signa_logs, new Manager( $signa_settings, new Logger( $signa_settings, new Redactor(), $signa_logs ) ) )->run( 'security' ),
	'زمان نمایش'
);

signa_check( 'the first attempts passing without a challenge is spelled out', false !== strpos( $signa_when['note'], 'بدون چالش' ) );

/* --- the font row --------------------------------------------------------- */

$signa_design = signa_appearance_row( $signa_test->run( 'design' ), 'فونت فرم' );

signa_check( 'the appearance test names the font the form uses', false !== strpos( $signa_design['value'], 'وزیرمتن' ) );
signa_check( 'and that it is the one the preview shows', false !== strpos( $signa_design['note'], 'پیش‌نمایش' ) );

$GLOBALS['signa_options']['signa_settings']['form_font'] = 'theme';
$signa_settings = new Settings();
$signa_design   = signa_appearance_row(
	signa_appearance_test( $signa_settings, $signa_logs, new Manager( $signa_settings, new Logger( $signa_settings, new Redactor(), $signa_logs ) ) )->run( 'design' ),
	'فونت فرم'
);

signa_check( 'with the theme\'s font chosen, it warns that the theme decides', false !== strpos( $signa_design['note'], 'پوسته' ) );

$GLOBALS['signa_options']['signa_settings']['form_font'] = 'custom';
$GLOBALS['signa_options']['signa_settings']['form_font_custom'] = 'Tahoma, sans-serif';
$signa_settings = new Settings();
$signa_design   = signa_appearance_row(
	signa_appearance_test( $signa_settings, $signa_logs, new Manager( $signa_settings, new Logger( $signa_settings, new Redactor(), $signa_logs ) ) )->run( 'design' ),
	'فونت فرم'
);

signa_check( 'a custom stack is reported as it is typed', 'Tahoma, sans-serif' === $signa_design['value'] );

/* -------------------------------------------------------------------------
 * 4b. Style isolation: the form is rendered where the theme cannot reach
 */

$GLOBALS['signa_options']['signa_settings'] = array();

signa_check( 'isolation is on for a fresh install', '1' === Settings::defaults()['style_isolation'] );
signa_check( 'and it can be turned off, because that is a choice a site may make', in_array( 'style_isolation', array_keys( Sanitizer::spec() ), true ) );

$signa_settings = new Settings();
$signa_logs     = new LogStore( $signa_settings );
$signa_captcha  = new Manager( $signa_settings, new Logger( $signa_settings, new Redactor(), $signa_logs ) );
$signa_assets   = new Assets( $signa_settings, $signa_captcha );
$signa_config   = $signa_assets->clientConfig();

signa_check( 'the browser is told to isolate', true === $signa_config['isolate'] );
signa_check( 'it is handed the stylesheet to inject', false !== strpos( (string) $signa_config['css'], 'assets/css/front.css' ) );
signa_check( 'with the same ?ver the <link> used, so the fetch is a cache hit', false !== strpos( (string) $signa_config['css'], 'ver=' . SIGNA_VERSION ) );
signa_check( 'and the base the font URLs are rewritten against', 'assets/' === substr( (string) $signa_config['assets'], -7 ) );

/*
 * Inside a shadow root a `:root` block cannot reach, so the values have to
 * travel as values. Their shape is what the stylesheet expects, and the accent
 * shades have to be derived — a fixed hover colour is the bug the owner already
 * reported once, on a crimson form that hovered green.
 */
$signa_vars = (array) $signa_config['vars'];

signa_check( 'the plugin\'s variables travel to the shadow as values', '#0f766e' === $signa_vars['signa-accent'] );
signa_check( 'the darker shade is derived from the accent, not fixed', '#0f766e' !== $signa_vars['signa-accent-strong'] && 0 === strpos( (string) $signa_vars['signa-accent-strong'], '#' ) );
signa_check( 'the wash is translucent', 0 === strpos( (string) $signa_vars['signa-accent-soft'], 'rgba(' ) );
signa_check( 'and radius and width keep their units', 'px' === substr( (string) $signa_vars['signa-width'], -2 ) && 'px' === substr( (string) $signa_vars['signa-radius'], -2 ) );

$GLOBALS['signa_options']['signa_settings'] = array( 'accent' => '#b91c1c' );

$signa_vars = (array) ( new Assets( new Settings(), new Manager( new Settings(), new Logger( new Settings(), new Redactor(), $signa_logs ) ) ) )->clientConfig()['vars'];

signa_check( 'changing the accent changes the hover shade with it', 'rgba(185, 28, 28, 0.14)' === $signa_vars['signa-accent-soft'] && '#b91c1c' === $signa_vars['signa-accent'] );

/* --- the panel says whether it is on ------------------------------------ */

$GLOBALS['signa_options']['signa_settings'] = array( 'form_font' => 'vazirmatn' );

$signa_settings = new Settings();
$signa_logs     = new LogStore( $signa_settings );
$signa_isolation = signa_appearance_row(
	signa_appearance_test( $signa_settings, $signa_logs, new Manager( $signa_settings, new Logger( $signa_settings, new Redactor(), $signa_logs ) ) )->run( 'design' ),
	'جداسازی از پوسته'
);

signa_check( 'the appearance test reports isolation as on', 'روشن' === $signa_isolation['value'] && 'ok' === $signa_isolation['status'] );
signa_check( 'and names what it protects the form from', false !== strpos( $signa_isolation['note'], 'CSS قالب' ) );

$GLOBALS['signa_options']['signa_settings'] = array( 'style_isolation' => '0' );

$signa_settings  = new Settings();
$signa_isolation = signa_appearance_row(
	signa_appearance_test( $signa_settings, $signa_logs, new Manager( $signa_settings, new Logger( $signa_settings, new Redactor(), $signa_logs ) ) )->run( 'design' ),
	'جداسازی از پوسته'
);

signa_check( 'turning it off is reported as a warning, not as silence', 'خاموش' === $signa_isolation['value'] && 'warn' === $signa_isolation['status'] );

/* -------------------------------------------------------------------------
 * 5. The setting has a control on the screen
 */

$GLOBALS['signa_may_manage'] = true;
$GLOBALS['signa_options']['signa_settings'] = array();

$signa_screen = new SettingsScreen(
	new Settings(),
	new Controls( new Settings() ),
	new Registry( new Settings() ),
	new FieldSchema( new Settings() ),
	new Manager( new Settings(), new Logger( new Settings(), new Redactor(), new LogStore( new Settings() ) ) ),
	new LogStore( new Settings() ),
	new ReportScreen( new LogStore( new Settings() ), new Settings() ),
	new Dashboard( new Settings(), new Registry( new Settings() ), new LogStore( new Settings() ) )
);

$_GET['tab'] = 'formskin';

ob_start();
$signa_screen->render();
$signa_html = (string) ob_get_clean();

signa_check( 'the settings screen offers the font choice', false !== strpos( $signa_html, 'name="signa_settings[form_font]"' ) );
signa_check( 'with the shipped font selected, so the default is what is read', preg_match( '/value="vazirmatn" selected/', $signa_html ) === 1 );
signa_check( 'and a box for a custom stack', false !== strpos( $signa_html, 'signa_settings[form_font_custom]' ) );
signa_check( 'the settings screen offers the isolation switch', false !== strpos( $signa_html, 'signa_settings[style_isolation]' ) );

signa_finish();
