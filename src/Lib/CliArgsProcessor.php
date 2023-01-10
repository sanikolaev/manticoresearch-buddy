<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

final class CliArgsProcessor {

	private const LONG_OPTS  = [
		'threads:', 'telemetry-period:', 'disable-telemetry', 'debug', 'version', 'help', 'listen:',
	];
	private const DEFAULT_OPTS = ['listen' => '127.0.0.1:9308'];

	/**
	 * Build help message for cli call
	 *
	 * @return string
	 */
	private static function help(): string {
		$script = $_SERVER['argv'][0];
		// In case we run it manualy, and not with built release script, we should add executor
		if (basename($script) === 'main.php') {
			$script = 'manticore-executor src/main.php';
		}

		return "Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)\n\n"
			. "Usage: $script [ARGUMENTS]\n\n"
			. "Arguments are:\n"
			. "--listen               HTTP endpoint to accept Manticore requests\n"
			. "--version              display the current version of Buddy\n"
			. "--help                 display this help message\n"
			. "--telemetry-period=[N] set period for telemetry when we do snapshots\n"
			. "--disable-telemetry    disables telemetry for Buddy\n"
			. "--threads=[N]          start N threads on launch, default is 4\n"
			. "--debug                enable debug mode for testing\n"
			. "Examples:\n"
			. "$script --debug\n"
			. "$script --disable-telemetry\n\n";
	}

	/**
	 * Build version message for the cli
	 *
	 * @return string
	 */
	private static function version(): string {
		return 'Manticore Buddy v' . buddy_version() . "\n"
			. "Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)\n"
		;
	}

	/**
	 * Process cli arguments passed
	 *
	 * @param ?array{
	 *  threads?:int,
	 *  telemetry-period?:int,
	 *  disable-telemetry?:bool,
	 *  debug?:bool,
	 *  help?:bool,
	 *  version?:bool,
	 *  listen?:string
	 * } $opts
	 * @return array{
	 *  threads?:int,
	 *  telemetry-period?:int,
	 *  disable-telemetry?:bool,
	 *  debug?:bool,
	 *  help?:bool,
	 *  version?:bool,
	 *  listen:string
	 * }
	 */
	public static function run(?array $opts = null): array {
		if (!isset($opts)) {
			$opts = getopt('', self::LONG_OPTS);
		}
		/** @var array{
		 *  threads?:int,
		 *  telemetry-period?:int,
		 *  disable-telemetry?:bool,
		 *  debug?:bool,
		 *  help?:bool,
		 *  version?:bool,
		 *  listen:string
		 * } $opts
		 */
		$opts = array_replace(self::DEFAULT_OPTS, $opts); // @phpstan-ignore-line

		if (isset($opts['help'])) {
			echo self::help();
			exit(0);
		}

		if (isset($opts['version'])) {
			echo self::version();
			exit(0);
		}


		static::parseThreads($opts);
		static::parseDisableTelemetry($opts);
		static::parseDebug($opts);
		static::parseTelemetryPeriod($opts);
		static::parseListen($opts);

		return $opts;
	}

	/**
	 * @param array{threads?:int} $opts
	 * @return void
	 */
	protected static function parseThreads(array $opts): void {
		if (!isset($opts['threads'])) {
			return;
		}

		if ($opts['threads'] < 1 || $opts['threads'] > 256) {
			echo "The --threads value must be in the range of 1 to 256.\n";
			exit(1);
		}

		putenv('THREADS=' . (int)$opts['threads']);
	}

	/**
	 * @param array{disable-telemetry?:bool} $opts
	 * @return void
	 */
	protected static function parseDisableTelemetry(array $opts): void {
		if (isset($opts['disable-telemetry'])) {
			putenv('TELEMETRY=0');
		} else {
			putenv('TELEMETRY=1');
		}
	}

	/**
	 * @param array{debug?:bool} $opts
	 * @return void
	 */
	protected static function parseDebug(array $opts): void {
		if (!isset($opts['debug'])) {
			return;
		}
		putenv('DEBUG=1');
	}

	/**
	 * @param array{telemetry-period?:int} $opts
	 * @return void
	 */
	protected static function parseTelemetryPeriod(array $opts): void {
		if (!isset($opts['telemetry-period'])) {
			return;
		}

		if ($opts['telemetry-period'] < 5 || $opts['telemetry-period'] > 1800) {
			echo "The --telemetry-period must be in range of 5 to 1800 secs.\n";
			exit(1);
		}
		putenv('TELEMETRY_PERIOD=' . (int)$opts['telemetry-period']);
	}

	/**
	 * @param array{listen:string} $opts
	 * @return void
	 */
	protected static function parseListen(array $opts): void {
		if (str_starts_with($opts['listen'], 'http://0.0.0.0')) {
			$opts['listen'] = 'http://127.0.0.1' . substr($opts['listen'], 14);
		}
		putenv("LISTEN={$opts['listen']}");
	}
}
