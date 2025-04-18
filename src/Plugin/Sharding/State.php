<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Sharding;

use Ds\Vector;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use RuntimeException;

final class State {
	public readonly string $table;
	protected Cluster $cluster;

	const STATE_DEFAULTS = [
		'cluster' => '', // the name of the initial cluster
		'cluster_hash' => '', // the hash of active nodes
		'master' => '', // the node as string for current master
	];

	/**
	 * Initialize the state with current client that used to get all data
	 * @param Client $client
	 * @return void
	 */
	public function __construct(
		protected Client $client
	) {
		$this->table = 'system.sharding_state';
	}

	/**
	 * We can have state in non clustered mode
	 * This method allows us to set current cluster
	 * @param Cluster $cluster
	 * @return $this
	 */
	public function setCluster(Cluster $cluster): static {
		$this->cluster = $cluster;
		return $this;
	}


	/**
	 * Set the state key with related value
	 * @param string $key
	 * @param mixed  $value
	 * @return static
	 */
	public function set(string $key, mixed $value): static {
		if (!isset($value)) {
			throw new RuntimeException('Sharding state value cannot be null');
		}
		// If we are in cluster mode or not yet?
		$table = isset($this->cluster)
			? $this->cluster->getSystemTableName($this->table)
			: $this->table
		;
		$now = time();
		$encodedValue = addcslashes(json_encode($value) ?: 'null', "'\\");

		$query = match ($this->fetch($key)) {
			null => "
				INSERT INTO {$table}
					(`key`, `value`, `updated_at`)
						VALUES
					('{$key}', '[{$encodedValue}]', {$now})
			",
			default => "
				UPDATE {$table} SET
					`updated_at` = {$now},
					`value` = '[{$encodedValue}]'
				WHERE `key` = '{$key}'
			",
		};

		// Try to set key and log error in case of failure
		$res = $this->client->sendRequest($query);
		if ($res->hasError()) {
			$error = $res->getError();
			Buddy::debugvv("Sharding: Error while setting state key '{$key}': {$error}");
		}

		return $this;
	}

	/**
	 * Get current state variable or default
	 * @param string $key
	 * @return mixed
	 */
	public function get(string $key): mixed {
		return $this->fetch($key) ?? (static::STATE_DEFAULTS[$key] ?? null);
	}

	/**
	 * Get list of items by provided regex
	 * @param  string $regex
	 * @return Vector<array{key:string,value:mixed}>
	 */
	public function listRegex(string $regex): Vector {
		/** @var array{0:array{data?:array{0?:array{key:string,value:string}}}} $res */
		$res = $this->client
			->sendRequest(
				"SELECT `key`, value[0] AS value FROM {$this->table} WHERE REGEX(`key`, '{$regex}')"
			)
			->getResult();

		/** @var Vector<array{key:string,value:mixed}> */
		$list = new Vector;
		foreach (($res[0]['data'] ?? []) as $row) {
			$list[] = [
				'key' => $row['key'],
				'value' => simdjson_decode($row['value'], true),
			];
		}

		return $list;
	}

	/**
	 * Fetch the value from the database for the key
	 * @param string $key
	 * @return mixed
	 */
	protected function fetch(string $key): mixed {
		$res = $this->client
			->sendRequest(
				"SELECT value[0] as value FROM {$this->table} WHERE key = '$key'"
			)
			->getResult();
		/** @var array{0:array{data:array{0?:array{value:string}}}} $res */
		$value = $res[0]['data'][0]['value'] ?? null;
		if (is_string($value)) {
			$value = trim($value);
			// Manticore returns unquoted string for empty string
			if (!str_starts_with($value, '{') && !str_ends_with($value, '}')) {
				return $value;
			}
		}
		return isset($value) ? simdjson_decode($value, true) : null;
	}

	/**
	 * Setup the initial tables for the system cluster
	 * @return void
	 */
	public function setup(): void {
		$hasTable = $this->client->hasTable($this->table);
		if ($hasTable) {
			throw new RuntimeException(
				'Trying to initialize while already initialized.'
			);
		}
		$query = "CREATE TABLE {$this->table} (
			`key` string,
			`value` json,
			`updated_at` timestamp
		)";
		$this->client->sendRequest($query);
		$this->cluster->attachTables($this->table);
	}

	/**
	 * Check if we activated state so we started to shard
	 * @return bool
	 */
	public function isActive(): bool {
		$hasTable = $this->client->hasTable($this->table);
		$hasRequiredFields = false;
		if ($hasTable) {
			$hasRequiredFields = true;
			foreach (self::STATE_DEFAULTS as $key => $value) {
				$value = $this->fetch($key);
				if (!isset($value)) {
					$hasRequiredFields = false;
					break;
				}
			}
		}

		return $hasTable && $hasRequiredFields;
	}
}
