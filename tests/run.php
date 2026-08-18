<?php
/**
 * Test runner.
 *
 * Run it from a WordPress install that has this plugin and WooCommerce active:
 *
 *     wp eval-file tests/run.php            # everything
 *     wp eval-file tests/run.php delivery   # only matching suites
 *
 * The suite works against the live install because the rules worth testing
 * (who receives what, what the message may contain, what happens on a refusal)
 * only mean something with a real WooCommerce behind them.
 *
 * @package PushNotifications\Tests
 */

// No `declare( strict_types = 1 )` here: `wp eval-file` runs this file through
// eval(), where the declaration cannot be the first statement. The files it
// includes are loaded normally and keep theirs.

namespace PushNotifications\Tests;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/helpers.php';

if ( ! function_exists( 'push_notify_send' ) ) {
	echo "The plugin is not active in this install.\n";
	exit( 1 );
}

// Sending happens where the test can see it, instead of in a background job.
add_filter( 'push_notify_async', '__return_false' );

$only  = isset( $args[0] ) ? (string) $args[0] : '';
$files = glob( __DIR__ . '/test-*.php' );
sort( $files );

$suites = array();

foreach ( (array) $files as $file ) {
	$name = basename( (string) $file, '.php' );

	if ( '' !== $only && false === stripos( $name, $only ) ) {
		continue;
	}

	$cases = require $file;

	if ( is_array( $cases ) ) {
		$suites[ substr( $name, 5 ) ] = $cases;
	}
}

if ( ! $suites ) {
	echo "No test suites found.\n";
	exit( 1 );
}

$passed  = 0;
$skipped = 0;
$failed  = array();

echo "\n";

foreach ( $suites as $suite => $cases ) {
	printf( "  %s\n", $suite );

	foreach ( $cases as $description => $case ) {
		try {
			$case();
			++$passed;
			printf( "    ok   %s\n", $description );
		} catch ( Skipped $e ) {
			++$skipped;
			printf( "    skip %s (%s)\n", $description, $e->getMessage() );
		} catch ( \Throwable $e ) {
			$failed[] = array( $suite, $description, $e->getMessage() );
			printf( "    FAIL %s\n", $description );
		} finally {
			Cleanup::run();
		}
	}

	echo "\n";
}

if ( $failed ) {
	echo "  Failures:\n";

	foreach ( $failed as list( $suite, $description, $message ) ) {
		printf( "    %s / %s\n      %s\n", $suite, $description, $message );
	}

	echo "\n";
}

printf( "  %d passed, %d skipped, %d failed\n\n", $passed, $skipped, count( $failed ) );

exit( $failed ? 1 : 0 );
