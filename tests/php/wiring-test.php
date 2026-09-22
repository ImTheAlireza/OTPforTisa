<?php
/**
 * The service container: does every wire plug into a socket that exists?
 *
 * This file exists because of a real failure. `Diagnostics\SelfTest` was added
 * to `AdminController` as a tenth constructor argument, and the binding in
 * `Plugin::register()` was not updated to pass it. Nothing in the test suite
 * looked at wiring, so every gate stayed green, the package shipped, and the
 * site that installed it died on every request with:
 *
 *     ArgumentCountError: Too few arguments to function
 *     AdminController::__construct(), 9 passed ... exactly 10 expected
 *
 * The container is late-bound, so a mismatch like that is invisible until the
 * service is first resolved — which, for `Http\Api`, is every request. These
 * checks read the bindings out of `Plugin.php` and compare them with the real
 * classes, using reflection so the signatures cannot drift out of date:
 *
 *   1. every binding passes exactly as many arguments as the class needs;
 *   2. every class the boot sequence resolves is bound (no "not registered");
 *   3. every `$c->make()` inside a binding points at something bound, too.
 *
 * @package TisaOtp\Tests
 */

require __DIR__ . '/bootstrap.php';

/**
 * Split a call's argument list on the commas that belong to it.
 *
 * @param string $inner Text between the parentheses.
 * @return string[]
 */
function tisa_split_args( string $inner ): array {
	$args  = array();
	$depth = 0;
	$quote = '';
	$buf   = '';
	$len   = strlen( $inner );

	for ( $i = 0; $i < $len; $i++ ) {
		$char = $inner[ $i ];

		if ( '' !== $quote ) {
			$buf .= $char;

			if ( '\\' === $char ) {
				$buf .= isset( $inner[ $i + 1 ] ) ? $inner[ ++$i ] : '';
				continue;
			}

			if ( $char === $quote ) {
				$quote = '';
			}

			continue;
		}

		if ( "'" === $char || '"' === $char ) {
			$quote = $char;
			$buf  .= $char;
			continue;
		}

		if ( '(' === $char || '[' === $char ) {
			$depth++;
		} elseif ( ')' === $char || ']' === $char ) {
			$depth--;
		}

		if ( ',' === $char && 0 === $depth ) {
			$args[] = trim( $buf );
			$buf    = '';
			continue;
		}

		$buf .= $char;
	}

	if ( '' !== trim( $buf ) ) {
		$args[] = trim( $buf );
	}

	return $args;
}

/**
 * The text between the parentheses that open at $start.
 *
 * @param string $source Full source text.
 * @param int    $start  Index of the opening parenthesis.
 */
function tisa_inside( string $source, int $start ): string {
	$depth = 0;
	$quote = '';

	for ( $i = $start, $len = strlen( $source ); $i < $len; $i++ ) {
		$char = $source[ $i ];

		if ( '' !== $quote ) {
			if ( '\\' === $char ) {
				$i++;
				continue;
			}

			if ( $char === $quote ) {
				$quote = '';
			}

			continue;
		}

		if ( "'" === $char || '"' === $char ) {
			$quote = $char;
			continue;
		}

		if ( '(' === $char ) {
			$depth++;
		} elseif ( ')' === $char ) {
			$depth--;

			if ( 0 === $depth ) {
				return substr( $source, $start + 1, $i - $start - 1 );
			}
		}
	}

	return '';
}

/**
 * The services Plugin::start() resolves on every request.
 *
 * @param string $source Plugin source text.
 * @return string[]
 */
function tisa_bootables( string $source ): array {
	$start = strpos( $source, 'private function bootables()' );
	$end   = strpos( $source, 'private function register()' );

	if ( false === $start || false === $end ) {
		return array();
	}

	preg_match_all(
		'/([A-Za-z][A-Za-z0-9_\\\\]*::class)/',
		substr( $source, $start, $end - $start ),
		$found
	);

	return array_map(
		static function ( $id ) {
			return 'TisaOtp\\' . str_replace( '::class', '', $id );
		},
		array_unique( $found[1] )
	);
}

$source = file_get_contents( TISA_OTP_PATH . 'src/Plugin.php' );

tisa_start( 'every binding feeds its class exactly what it asks for' );

/*
 * `$c->bind( Something::class, static function ( Container $c ) { ... } )`
 * with the `new` call inside it.
 */
preg_match_all(
	'/\$c->bind\(\s*([A-Za-z][A-Za-z0-9_\\\\]*)::class,\s*static function\s*\(\s*Container \$c\s*\)\s*\{(.*?)\n\t\t\} \);/s',
	$source,
	$bindings,
	PREG_SET_ORDER
);

tisa_check( 'the bindings were found at all', count( $bindings ) > 20 );

$bound = array();

preg_match_all( '/\$c->bind\(\s*([A-Za-z][A-Za-z0-9_\\\\]*)::class\s*,/', $source, $registered );

foreach ( $registered[1] as $id ) {
	$bound[ 'TisaOtp\\' . $id ] = true;
}

$wiring = array();

foreach ( $bindings as $binding ) {
	$bound[ 'TisaOtp\\' . $binding[1] ] = true;
}

foreach ( $bindings as $binding ) {
	$id   = 'TisaOtp\\' . $binding[1];
	$body = $binding[2];

	// The service this binding returns, which is what the arguments must match.
	if ( ! preg_match( '/return new ([A-Za-z][A-Za-z0-9_\\\\]*)\(/', $body, $created ) ) {
		// A factory that decides at runtime (the code store) cannot be read here.
		continue;
	}

	$class = 'TisaOtp\\' . $created[1];
	$from  = strpos( $body, 'return new ' . $created[1] . '(' );
	$args  = tisa_split_args( tisa_inside( $body, $from + strlen( 'return new ' . $created[1] ) ) );

	$reflection = null;

	if ( class_exists( $class ) ) {
		$reflection = new ReflectionClass( $class );
	}

	if ( null === $reflection || ! $reflection->getConstructor() ) {
		tisa_check( $class . ' exists and has a constructor', false );
		continue;
	}

	$needed = $reflection->getConstructor()->getNumberOfRequiredParameters();
	$total  = $reflection->getConstructor()->getNumberOfParameters();
	$given  = count( $args );

	tisa_check(
		$class . ' is given every argument it requires' . ( $given < $needed || $given > $total ? ' (' . $given . ' given, ' . $needed . '–' . $total . ' expected)' : '' ),
		$given >= $needed && $given <= $total
	);

	$wiring[ $id ] = array(
		'class' => $class,
		'args'  => $args,
	);
}

tisa_start( 'nothing the boot sequence resolves is missing' );

foreach ( tisa_bootables( $source ) as $id ) {
	tisa_check( $id . ' is bound before it is resolved', isset( $bound[ $id ] ) );
}

tisa_start( 'and neither is anything a binding asks for' );

foreach ( $wiring as $id => $call ) {
	foreach ( $call['args'] as $arg ) {
		if ( ! preg_match( '/\$c->make\(\s*([A-Za-z][A-Za-z0-9_\\\\]*)::class\s*\)/', $arg, $made ) ) {
			continue;
		}

		$target = 'TisaOtp\\' . $made[1];

		tisa_check( $target . ' (wanted by ' . $call['class'] . ') is bound', isset( $bound[ $target ] ) );
	}
}

tisa_start( 'the arguments arrive in the order the constructors declare' );

foreach ( $wiring as $call ) {
	$constructor = ( new ReflectionClass( $call['class'] ) )->getConstructor();
	$types       = array();

	foreach ( $constructor->getParameters() as $parameter ) {
		$hint = $parameter->getType();

		if ( $hint instanceof ReflectionNamedType && ! $hint->isBuiltin() ) {
			$name    = str_replace( 'self', $call['class'], $hint->getName() );
			$types[] = 0 === strpos( $name, 'TisaOtp' ) ? $name : 'TisaOtp\\' . ltrim( $name, '\\' );
		} else {
			$types[] = '';
		}
	}

	$mismatch = array();

	foreach ( $call['args'] as $index => $arg ) {
		$given = '';

		if ( preg_match( '/\$c->make\(\s*([A-Za-z][A-Za-z0-9_\\\\]*)::class\s*\)/', $arg, $made ) ) {
			$given = 'TisaOtp\\' . $made[1];
		} else {
			continue;
		}

		if ( isset( $types[ $index ] ) && '' !== $types[ $index ] && $types[ $index ] !== $given ) {
			$mismatch[] = $call['class'] . ' #' . ( $index + 1 ) . ': ' . $types[ $index ] . ' expected, ' . $given . ' given';
		}
	}

	tisa_same( $call['class'] . ' is wired in order', array(), $mismatch );
}

tisa_start( 'the exact failure of 1.3.2 is reproduced here, and it is caught' );

/*
 * On 2026-09-22 the container handed AdminController nine arguments while the
 * constructor wanted ten. This builds the same nine — real objects of the
 * declared types, made without running their constructors — and then does with
 * the throw what Plugin::start() does with it: hands it to the guard.
 */
$nine = array(
	TisaOtp\Config\Settings::class,
	TisaOtp\Channel\Dispatcher::class,
	TisaOtp\Otp\OtpService::class,
	TisaOtp\Throttle\Throttle::class,
	TisaOtp\Log\Logger::class,
	TisaOtp\Log\LogStore::class,
	TisaOtp\Import\Runner::class,
	TisaOtp\Gateway\Registry::class,
	TisaOtp\Captcha\Manager::class,
);

$objects = array();

foreach ( $nine as $type ) {
	$objects[] = ( new ReflectionClass( $type ) )->newInstanceWithoutConstructor();
}

$caught = null;

try {
	$constructor = ( new ReflectionClass( TisaOtp\Http\AdminController::class ) )->getConstructor();
	( new ReflectionClass( TisaOtp\Http\AdminController::class ) )->newInstanceArgs( $objects );
	unset( $constructor );
} catch ( \Throwable $error ) {
	$caught = $error;
}

tisa_check( 'nine arguments where ten are required throws', $caught instanceof ArgumentCountError );
tisa_check( 'and the message is the one the site showed', null !== $caught && false !== strpos( $caught->getMessage(), 'Too few arguments' ) );

if ( null !== $caught ) {
	TisaOtp\Install\Guard::record( TisaOtp\Http\AdminController::class, $caught );
}

tisa_check( 'the guard takes it instead of the site dying', TisaOtp\Install\Guard::failed( TisaOtp\Http\AdminController::class ) );

$notice = new ReflectionMethod( TisaOtp\Install\Guard::class, 'printFailures' );
$notice->setAccessible( true );

ob_start();
$notice->invoke( new TisaOtp\Install\Guard() );
$markup = (string) ob_get_clean();

tisa_check( 'and the administrator sees which service did not start', false !== strpos( $markup, 'AdminController' ) );
tisa_check( 'with the way out of it', false !== strpos( $markup, 'جایگزینی با نسخهٔ بارگذاری‌شده' ) );

tisa_finish();
