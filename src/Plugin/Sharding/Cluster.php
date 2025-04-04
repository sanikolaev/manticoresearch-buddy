<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Sharding;

use Ds\Set;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use RuntimeException;

final class Cluster {
	// Name of the cluster that we use to store meta data
	// TODO: not in use yet
	const SYSTEM_NAME = 'system';
	const GALERA_OPTIONS = 'gmcast.peer_timeout=PT3S;' .
		'evs.install_timeout=PT5S;' .
		'evs.delayed_keep_period=PT10S;' .
		'pc.wait_prim_timeout=PT5S';

	/** @var Set<string> $nodes set of all nodes that belong the the cluster */
	protected Set $nodes;

	/** @var Set<string> $tablesToAttach set of all tables that we need to add to the cluster */
	protected Set $tablesToAttach;

	/** @var Set<string> $tablesToDetach set of all tables that we need to detach from the cluster */
	protected Set $tablesToDetach;

	/**
	 * Initialize with a given client
	 * @param Client $client
	 * @param string $name
	 * @return void
	 */
	public function __construct(
		protected Client $client,
		public readonly string $name,
		protected string $nodeId
	) {
		$this->nodes = new Set;
		$this->tablesToAttach = new Set;
		$this->tablesToDetach = new Set;
	}

	/**
	 * TODO: not in use yet
	 * This method is used to initialize the system cluster
	 * @param  Client $client
	 * @return void
	 */
	public static function init(Client $client): void {
		$cluster = new static(
			$client,
			static::SYSTEM_NAME,
			Node::findId($client)
		);
		$cluster->create();
	}

	/**
	 * Initialize and create the current cluster
	 * This method should be executed on main cluster node
	 * @param ?Queue $queue
	 * @return int Last insert id into the queue or 0
	 */
	public function create(?Queue $queue = null): int {
		// TODO: the pass is the subject to remove
		$galeraOptions = static::GALERA_OPTIONS;
		$query = "CREATE CLUSTER IF NOT EXISTS {$this->name} '{$this->name}' as path, '{$galeraOptions}' as options";
		return $this->runQuery($queue, $query);
	}

	/**
	 * When we have rf=2 and/or cluster with 2 nodes
	 * while one is dead we need to remove it
	 * to make it we need to make it safe first
	 * @param  ?Queue $queue
	 * @return int
	 */
	public function makePrimary(?Queue $queue = null): int {
		$query = "SET CLUSTER {$this->name} GLOBAL 'pc.bootstrap' = 1";
		return $this->runQuery($queue, $query);
	}

	/**
	 * Remove the cluster, we should run it on one
	 * Another will catch up
	 * @param  ?Queue $queue
	 * @return int
	 */
	public function remove(?Queue $queue = null): int {
		$query = "DELETE CLUSTER {$this->name}";
		return $this->runQuery($queue, $query);
	}

	/**
	 * Helper function to run query on the node
	 * @param  ?Queue $queue
	 * @param  string     $query
	 * @return int
	 */
	protected function runQuery(?Queue $queue, string $query): int {
		if ($queue) {
			$queueId = $queue->add($this->nodeId, $query);
		} else {
			$this->client->sendRequest($query, disableAgentHeader: true);
		}

		return $queueId ?? 0;
	}

	/**
	 * Get all nodes that belong to current cluster
	 * @return Set<string>
	 */
	public function getNodes(): Set {
		// If no cluster created, we return single node in set
		if (!$this->name) {
			return new Set([Node::findId($this->client)]);
		}

		$res = $this->client
			->sendRequest("SHOW STATUS LIKE 'cluster_{$this->name}_nodes_set'")
			->getResult();
		/** @var array{0:array{data:array{0?:array{Value:string}}}} $res */
		$replicationSet = $res[0]['data'][0]['Value'] ?? '';
		$set = new Set();
		if ($replicationSet) {
			$set->add(
				...array_map('trim', explode(',', $replicationSet))
			);
		}
		// Merge current nodes and queued to add in runtime to get full list
		return $set->merge($this->nodes);
	}

	/**
	 * Get currently active nodes, so later we can intersect
	 * @return Set<string>
	 */
	public function getActiveNodes(): Set {
		if (!$this->name) {
			return new Set([Node::findId($this->client)]);
		}
		$res = $this->client
			->sendRequest("SHOW STATUS LIKE 'cluster_{$this->name}_nodes_view'")
			->getResult();
		/** @var array{0:array{data:array{0?:array{Value:string}}}} $res */
		$replicationSet = $res[0]['data'][0]['Value'] ?? '';
		$set = new Set();
		if ($replicationSet) {
			// Counter: cluster_c_nodes_view
		// Value: 127.0.0.1:9112,127.0.0.1:9124:replication,127.0.0.1:9212,127.0.0.1:9224:replication
			$set->add(
				...array_filter(
					array_map('trim', explode(',', $replicationSet)),
					fn ($node) => !str_contains($node, ':replication'),
				)
			);
		}

		return $set;
	}

	/**
	 * Helper to get inactive nodes by intersectting all and active ones
	 * Inactive node means node that has outage or just new node
	 * @return Set<string>
	 */
	public function getInactiveNodes(): Set {
		return $this->getNodes()->xor($this->getActiveNodes());
	}

	/**
	 * Validate that the cluster is active and synced
	 * @return bool
	 */
	public function isActive(): bool {
		$res = $this->client
			->sendRequest("SHOW STATUS LIKE 'cluster_{$this->name}_status'")
			->getResult();
		/** @var array{0:array{data:array{0?:array{Value:string}}}} $res */
		$status = $res[0]['data'][0]['Value'] ?? 'primary';
		return $status === 'primary';
	}

	/**
	 * Create a cluster by using distributed queue with list of nodes
	 * This method just add join queries to the queue to all requested nodes
	 * @param  Queue  $queue
	 * @param  string ...$nodeIds
	 * @return static
	 */
	public function addNodeIds(Queue $queue, string ...$nodeIds): static {
		$galeraOptions = static::GALERA_OPTIONS;
		foreach ($nodeIds as $node) {
			$this->nodes->add($node);
			// TODO: the pass is the subject to remove
			$query = "JOIN CLUSTER {$this->name} at '{$this->nodeId}' '{$this->name}' as " .
				"path, '{$galeraOptions}' as options";
			$queue->add($node, $query);
		}
		return $this;
	}

	/**
	 * Get the current hash of all cluster nodes
	 * @param Set<string> $nodes
	 * @return string
	 */
	public static function getNodesHash(Set $nodes): string {
		return md5($nodes->sorted()->join('|'));
	}

	/**
	 * Refresh cluster info due to secure inactive nodes
	 * @return static
	 */
	public function refresh(): static {
		$query = "ALTER CLUSTER {$this->name} UPDATE nodes";
		$this->runQuery(null, $query);
		return $this;
	}

	/**
	 * Enqueue the tables attachments to all nodes of current cluster
	 * @param Queue  $queue
	 * @param string ...$tables
	 * @return int
	 */
	public function addTables(Queue $queue, string ...$tables): int {
		if (!$tables) {
			throw new \Exception('Tables must be passed to add');
		}
		$tables = implode(',', $tables);
		$query = "ALTER CLUSTER {$this->name} ADD {$tables}";
		return $queue->add($this->nodeId, $query);
	}

	/**
	 * Enqueue the tables detachement to all nodes of current cluster
	 * @param Queue  $queue
	 * @param string ...$tables
	 * @return int
	 */
	public function removeTables(Queue $queue, string ...$tables): int {
		if (!$tables) {
			throw new \Exception('Tables must be passed to remove');
		}
		$tables = implode(',', $tables);
		$query = "ALTER CLUSTER {$this->name} DROP {$tables}";
		return $queue->add($this->nodeId, $query);
	}

	/**
	 * Attach table to cluster and make it available on all nodes
	 * @param string ...$tables
	 * @return static
	 */
	public function attachTables(string ...$tables): static {
		if (!$tables) {
			throw new \Exception('Tables must be passed to attach');
		}
		// We can have situation when no cluster required
		if ($this->name) {
			$tables = implode(',', $tables);
			$query = "ALTER CLUSTER {$this->name} ADD {$tables}";
			$this->client->sendRequest($query);
		}
		return $this;
	}

	/**
	 * Detach table from the current cluster
	 * @param string ...$tables
	 * @return static
	 */
	public function detachTables(string ...$tables): static {
		if (!$tables) {
			throw new \Exception('Tables must be passed to detach');
		}
		// We can have situation when no cluster required
		if ($this->name) {
			$tables = implode(',', $tables);
			$query = "ALTER CLUSTER {$this->name} DROP {$tables}";
			$this->client->sendRequest($query);
		}
		return $this;
	}

	/**
	 * Add pending table operation that we will process later in single shot
	 * @param string $table
	 * @param TableOperation $operation
	 * @return static
	 */
	public function addPendingTable(string $table, TableOperation $operation): static {
		if ($operation === TableOperation::Attach) {
			$this->tablesToAttach->add($table);
		} else {
			$this->tablesToDetach->add($table);
		}
		return $this;
	}

	/**
	 * Check if the table is pending to add or drop
	 * @param string $table
	 * @param TableOperation $operation
	 * @return bool
	 */
	public function hasPendingTable(string $table, TableOperation $operation): bool {
		if ($operation === TableOperation::Attach) {
			return $this->tablesToAttach->contains($table);
		}

		return $this->tablesToDetach->contains($table);
	}

	/**
	 * Process pending tables to add and drop in current cluster
	 * @param Queue $queue
	 * @return static
	 * @throws RuntimeException
	 * @throws ManticoreSearchClientError
	 */
	public function processPendingTables(Queue $queue): static {
		if ($this->tablesToDetach->count()) {
			$this->removeTables($queue, ...$this->tablesToDetach);
			$this->tablesToDetach = new Set;
		}

		if ($this->tablesToAttach->count()) {
			$this->addTables($queue, ...$this->tablesToAttach);
			$this->tablesToAttach = new Set;
		}

		return $this;
	}

	/**
	 * Get prefixed table name with current Cluster
	 * @param string $table
	 * @return string
	 */
	public function getTableName(string $table): string {
		return ($this->name ? "{$this->name}:" : '') . $table;
	}

	/**
	 * Same like getTableName but for system table name, now it's the same
	 * @param string $table
	 * @return string
	 */
	public function getSystemTableName(string $table): string {
		return $this->getTableName($table);
	}
}
