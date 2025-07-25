<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

class MetricThreadTest extends TestCase {

	const SEARCHD_LOG_PATH = '/var/log/manticore-test/searchd.log';

	use TestFunctionalTrait {
		setUpBeforeClass as traitSetUpBeforeClass;
	}
	public static function setUpBeforeClass(): void {
		static::setSearchdArgs(['--log-level=debugvv']);

		static::traitSetUpBeforeClass();
	}

	public function testMetricThreadPrintDebugMessages(): void {
		sleep(11);
		system('echo "127.0.0.1 telemetry.manticoresearch.com" >> /etc/hosts');
		echo  static::SEARCHD_LOG_PATH;
		$labels = (string)system('grep labels: ' . static::SEARCHD_LOG_PATH);
		$this->assertStringContainsString('"collector":"buddy"', $labels);
		$this->assertStringContainsString('"buddy_version"', $labels);
		$this->assertStringContainsString('"manticore_version"', $labels);
		$this->assertStringContainsString('"columnar_version"', $labels);
		$this->assertStringContainsString('"secondary_version"', $labels);
		$this->assertStringContainsString('"knn_version"', $labels);
		$this->assertStringContainsString('"manticore_binlog_enabled"', $labels);
		$this->assertStringContainsString('"manticore_binlog_enabled"', $labels);
		$this->assertStringContainsString('"manticore_secondary_indexes_enabled"', $labels);

		$metrics = (string)system('grep metrics: ' . static::SEARCHD_LOG_PATH);
		$this->assertStringContainsString('"uptime"', $metrics);
		$this->assertStringContainsString('"command_search"', $metrics);
		$this->assertStringContainsString('"command_excerpt"', $metrics);
		$this->assertStringContainsString('"command_update"', $metrics);
		$this->assertStringContainsString('"command_persist"', $metrics);
		$this->assertStringContainsString('"command_status"', $metrics);
		$this->assertStringContainsString('"command_flushattrs"', $metrics);
		$this->assertStringContainsString('"command_sphinxql"', $metrics);
		$this->assertStringContainsString('"command_ping"', $metrics);
		$this->assertStringContainsString('"command_delete"', $metrics);
		$this->assertStringContainsString('"command_set"', $metrics);
		$this->assertStringContainsString('"command_insert"', $metrics);
		$this->assertStringContainsString('"command_replace"', $metrics);
		$this->assertStringContainsString('"command_commit"', $metrics);
		$this->assertStringContainsString('"command_suggest"', $metrics);
		$this->assertStringContainsString('"command_json"', $metrics);
		$this->assertStringContainsString('"command_callpq"', $metrics);
		$this->assertStringContainsString('"command_cluster"', $metrics);
		$this->assertStringContainsString('"command_getfield"', $metrics);
		$this->assertStringContainsString('"workers_total"', $metrics);

		$output = [];
		exec('grep metric: ' . static::SEARCHD_LOG_PATH, $output);
		$executes = implode(PHP_EOL, $output);
		$this->assertStringContainsString('metric: add ["invocation",1]', $executes);
		$this->assertStringContainsString('metric: checkAndSnapshot', $executes);
	}
}
