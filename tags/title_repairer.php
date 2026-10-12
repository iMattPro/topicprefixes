<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tags;

use phpbb\cache\driver\driver_interface as cache;
use phpbb\db\driver\driver_interface;

/**
 * Repair old literal prefixes using confirmed title and subject snapshots.
 */
class title_repairer
{
	protected const BATCH_SIZE = 100;

	/** @var driver_interface */
	protected $db;

	/** @var cache */
	protected $cache;

	/** @var string Topics table */
	protected $topics;

	/** @var string Posts table */
	protected $posts;

	/** @var string Forums table */
	protected $forums;

	/** @var string Configuration table */
	protected $config_table;

	/**
	 * Constructor.
	 *
	 * @param driver_interface $db           Database connection
	 * @param cache            $cache        Cache driver
	 * @param string           $topics       Topics table
	 * @param string           $posts        Posts table
	 * @param string           $forums       Forums table
	 * @param string           $config_table Configuration table
	 */
	public function __construct(
		driver_interface $db,
		cache $cache,
		string $topics,
		string $posts,
		string $forums,
		string $config_table
	)
	{
		$this->db = $db;
		$this->cache = $cache;
		$this->topics = $topics;
		$this->posts = $posts;
		$this->forums = $forums;
		$this->config_table = $config_table;
	}

	/**
	 * Validate a nonempty UTF-8 literal prefix without normalizing its whitespace.
	 *
	 * @param string $prefix Display-form literal prefix
	 * @return void
	 * @throws \InvalidArgumentException When the prefix is invalid or whitespace-only
	 */
	public static function validate_prefix(string $prefix): void
	{
		if (!preg_match('//u', $prefix) || preg_match('/\A[\s\p{Z}]*\z/u', $prefix))
		{
			throw new \InvalidArgumentException('CLI_TOPIC_TITLES_INVALID_PREFIX');
		}
	}

	/**
	 * Remove one leading literal prefix and its following whitespace.
	 *
	 * Matching decodes phpBB text references while preserving the stored suffix.
	 * Callers must reject blank results before writing them.
	 *
	 * @param string $text   Stored title or subject
	 * @param string $prefix Display-form literal prefix
	 * @return string Cleaned stored text, or the original text when unmatched
	 * @throws \InvalidArgumentException When the prefix is invalid
	 */
	public static function clean(string $text, string $prefix): string
	{
		self::validate_prefix($prefix);
		preg_match_all('/&(?:#[0-9]+|#x[0-9a-f]+|amp|lt|gt|quot);|./uis', $text, $tokens);

		$decoded = '';
		$offset = 0;
		$matched = false;
		foreach ($tokens[0] as $token)
		{
			$character = manager::decode_name($token);
			if (!$matched)
			{
				$decoded .= $character;
				if (strpos($prefix, $decoded) !== 0)
				{
					return $text;
				}
				$offset += strlen($token);
				$matched = $decoded === $prefix;
			}
			else if (preg_match('/\A[\s\p{Z}]+\z/u', $character))
			{
				$offset += strlen($token);
			}
			else
			{
				break;
			}
		}
		if (!$matched)
		{
			return $text;
		}

		return substr($text, $offset);
	}

	/**
	 * Load existing forums with indented selection labels in nested-set order.
	 *
	 * @return array Forum rows keyed by forum identifier
	 */
	public function get_forums(): array
	{
		$sql = 'SELECT forum_id, forum_name, left_id, right_id
			FROM ' . $this->forums . '
			ORDER BY left_id, forum_id';
		$result = $this->db->sql_query($sql);
		$forums = [];
		$ancestors = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			while ($ancestors && end($ancestors)['right_id'] < $row['left_id'])
			{
				array_pop($ancestors);
			}

			$row['plain_label'] = manager::decode_name($row['forum_name']) . ' (#' . $row['forum_id'] . ')';
			$row['label'] = str_repeat('  ', count($ancestors)) . $row['plain_label'];
			$forums[(int) $row['forum_id']] = $row;
			$ancestors[] = $row;
		}
		$this->db->sql_freeresult($result);

		return $forums;
	}

	/**
	 * Preview matching topics using bounded cursor pagination.
	 *
	 * Literal matching runs in PHP to avoid database collation differences.
	 *
	 * @param int    $forum_id Selected forum identifier, excluding descendants
	 * @param string $prefix   Display-form literal prefix
	 * @return \Generator Repair snapshots, including skipped matches
	 * @throws \InvalidArgumentException When the prefix is invalid
	 */
	public function preview(int $forum_id, string $prefix): \Generator
	{
		self::validate_prefix($prefix);
		$cursor = 0;
		do
		{
			$sql = 'SELECT t.topic_id, t.forum_id, t.topic_title, t.topic_moved_id,
					t.topic_first_post_id, t.topic_last_post_id, t.topic_last_post_subject
				FROM ' . $this->topics . ' t
				INNER JOIN ' . $this->forums . ' f ON f.forum_id = t.forum_id
				WHERE t.forum_id = ' . $forum_id . '
					AND t.topic_id > ' . $cursor . '
				ORDER BY t.topic_id';
			$result = $this->db->sql_query_limit($sql, self::BATCH_SIZE);
			$topics = [];
			while ($row = $this->db->sql_fetchrow($result))
			{
				$topics[] = $row;
			}
			$this->db->sql_freeresult($result);
			if (!$topics)
			{
				break;
			}

			$posts = $this->load_posts($topics);
			$forum_caches = $this->load_forum_caches($topics);
			foreach ($topics as $topic)
			{
				$cursor = (int) $topic['topic_id'];
				$plan = $this->plan_from_row($topic, $prefix, $posts, $forum_caches);
				if ($plan !== null)
				{
					yield $plan;
				}
			}
		}
		while (count($topics) === self::BATCH_SIZE);
	}

	/**
	 * Load first and last posts needed by one topic batch.
	 *
	 * @param array $topics Topic rows
	 * @return array Post rows keyed by post identifier
	 */
	protected function load_posts(array $topics): array
	{
		$ids = [];
		foreach ($topics as $topic)
		{
			$ids[(int) $topic['topic_first_post_id']] = true;
			$ids[(int) $topic['topic_last_post_id']] = true;
		}
		unset($ids[0]);
		if (!$ids)
		{
			return [];
		}

		$result = $this->db->sql_query('SELECT post_id, topic_id, forum_id, post_subject
			FROM ' . $this->posts . '
			WHERE ' . $this->db->sql_in_set('post_id', array_keys($ids)));
		$posts = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$posts[(int) $row['post_id']] = $row;
		}
		$this->db->sql_freeresult($result);

		return $posts;
	}

	/**
	 * Load forum last-post caches referencing one topic batch.
	 *
	 * @param array $topics Topic rows
	 * @return array Forum rows grouped by last post identifier
	 */
	protected function load_forum_caches(array $topics): array
	{
		$ids = [];
		foreach ($topics as $topic)
		{
			$ids[(int) $topic['topic_last_post_id']] = true;
		}
		unset($ids[0]);
		if (!$ids)
		{
			return [];
		}

		$result = $this->db->sql_query('SELECT forum_id, forum_last_post_id, forum_last_post_subject
			FROM ' . $this->forums . '
			WHERE ' . $this->db->sql_in_set('forum_last_post_id', array_keys($ids)) . '
			ORDER BY forum_id');
		$forums = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$forums[(int) $row['forum_last_post_id']][] = $row;
		}
		$this->db->sql_freeresult($result);

		return $forums;
	}

	/**
	 * Build one repair snapshot from batch-loaded rows.
	 *
	 * @param array  $row          Topic row
	 * @param string $prefix       Display-form literal prefix
	 * @param array  $posts        Post rows keyed by post identifier
	 * @param array  $forum_caches Forum rows grouped by last post identifier
	 * @return array|null Repair snapshot, or null when the topic is unmatched
	 * @throws \InvalidArgumentException When the prefix is invalid
	 */
	protected function plan_from_row(array $row, string $prefix, array $posts, array $forum_caches): ?array
	{
		$topic_id = (int) $row['topic_id'];
		$forum_id = (int) $row['forum_id'];
		$after = self::clean($row['topic_title'], $prefix);
		if ($after === $row['topic_title'])
		{
			return null;
		}

		$plan = [
			'topic_id' => $topic_id,
			'forum_id' => $forum_id,
			'prefix' => $prefix,
			'before' => $row['topic_title'],
			'after' => $after,
			'snapshot' => $row,
			'changes' => [],
			'skipped' => 0,
		];
		if ($this->blank($after))
		{
			$plan['skipped'] = 1;
			return $plan;
		}

		$this->add_change($plan, $this->topics, 'topic_id', $row, 'topic_title');

		// A moved shadow can point into a different topic; never modify its posts.
		$first_id = (int) $row['topic_first_post_id'];
		$first_post = $posts[$first_id] ?? null;
		$first_changed = false;
		if (empty($row['topic_moved_id']) && $first_post
			&& (int) $first_post['topic_id'] === $topic_id
			&& (int) $first_post['forum_id'] === $forum_id)
		{
			$before = count($plan['changes']);
			$this->add_change($plan, $this->posts, 'post_id', $first_post, 'post_subject');
			$first_changed = count($plan['changes']) > $before;
		}

		// Keep denormalized last-subject caches equal to their source post.
		if ($first_changed && (int) $row['topic_last_post_id'] === $first_id
			&& $row['topic_last_post_subject'] === $first_post['post_subject'])
		{
			$this->add_change($plan, $this->topics, 'topic_id', $row, 'topic_last_post_subject');
			foreach ($forum_caches[$first_id] ?? [] as $forum)
			{
				if ($forum['forum_last_post_subject'] === $first_post['post_subject'])
				{
					$this->add_change($plan, $this->forums, 'forum_id', $forum, 'forum_last_post_subject');
				}
			}
		}

		return $plan;
	}

	/**
	 * Append a matching nonblank field change or count a skipped blank result.
	 *
	 * @param array  $plan  Repair snapshot updated by reference
	 * @param string $table Table containing the field
	 * @param string $key   Row identifier column
	 * @param array  $row   Original row values used to guard the update
	 * @param string $field Text column to clean
	 * @return void
	 * @throws \InvalidArgumentException When the snapshot prefix is invalid
	 */
	protected function add_change(array &$plan, string $table, string $key, array $row, string $field): void
	{
		$after = self::clean($row[$field], $plan['prefix']);
		if ($after === $row[$field])
		{
			return;
		}
		if ($this->blank($after))
		{
			$plan['skipped']++;
			return;
		}

		$plan['changes'][] = [
			'table' => $table,
			'key' => $key,
			'row' => $row,
			'field' => $field,
			'after' => $after,
		];
	}

	/**
	 * Check whether decoded stored text is empty or contains only whitespace.
	 *
	 * @param string $text Stored text
	 * @return bool Whether the text is blank
	 */
	protected function blank(string $text): bool
	{
		return (bool) preg_match('/\A[\s\p{Z}]*\z/u', manager::decode_name($text));
	}

	/**
	 * Check maintenance mode against the live configuration table.
	 *
	 * @return void
	 * @throws \RuntimeException When the board is enabled or its setting is absent
	 */
	public function assert_board_disabled(): void
	{
		$sql = 'SELECT config_value
			FROM ' . $this->config_table . "
			WHERE config_name = 'board_disable'";
		$row = $this->fetch($sql);
		if (!$row || empty($row['config_value']))
		{
			throw new \RuntimeException('CLI_TOPIC_TAGS_REPAIR_BOARD_ENABLED');
		}
	}

	/**
	 * Apply one confirmed snapshot using guarded transactional updates.
	 *
	 * Conflicting snapshots or guarded updates are rolled back and counted.
	 * Previously committed repair units are retained if a later unit fails.
	 *
	 * @param array $plan Confirmed repair snapshot
	 * @return array Counts of changed topics, subjects, caches, skipped fields and conflicts
	 * @throws \RuntimeException When maintenance mode fails or data conflicts
	 * @throws \Throwable When a transaction fails
	 */
	public function apply(array $plan): array
	{
		return $this->apply_batch([$plan]);
	}

	/**
	 * Apply one bounded batch of confirmed repair snapshots.
	 *
	 * Conflicting batches fall back to one transaction per snapshot so valid
	 * repairs can still be committed.
	 *
	 * @param array $plans      Confirmed repair snapshots
	 * @param bool  $invalidate Whether to invalidate SQL caches after the batch
	 * @return array Counts of changed topics, subjects, caches, skipped fields and conflicts
	 * @throws \RuntimeException When maintenance mode fails
	 * @throws \Throwable When a transaction fails
	 */
	public function apply_batch(array $plans, bool $invalidate = true): array
	{
		$this->assert_board_disabled();
		try
		{
			$counts = $this->apply_transaction($plans);
		}
		catch (\Throwable $e)
		{
			if ($e->getMessage() !== 'CLI_TOPIC_REPAIR_CONFLICT')
			{
				throw $e;
			}
			$counts = $this->empty_counts();
			foreach ($plans as $plan)
			{
				try
				{
					$result = $this->apply_transaction([$plan]);
				}
				catch (\RuntimeException $conflict)
				{
					if ($conflict->getMessage() !== 'CLI_TOPIC_REPAIR_CONFLICT')
					{
						throw $conflict;
					}
					$result = $this->empty_counts();
					$result['conflicts'] = 1;
				}
				$this->merge_counts($counts, $result);
			}
		}
		if ($invalidate)
		{
			$this->invalidate_caches();
		}

		return $counts;
	}

	/**
	 * Apply repair snapshots in one transaction.
	 *
	 * @param array $plans Confirmed repair snapshots
	 * @return array Applied change counts
	 * @throws \Throwable When a transaction fails
	 */
	protected function apply_transaction(array $plans): array
	{
		$counts = $this->empty_counts();
		$this->db->sql_transaction('begin');
		try
		{
			$changes = [];
			foreach ($plans as $plan)
			{
				$counts['skipped'] += (int) ($plan['skipped'] ?? 0);
				foreach ($plan['changes'] as $change)
				{
					$changes[] = $change;
				}
			}
			$this->apply_batch_relations($plans, $counts);
			$this->apply_changes($changes, $counts);
			$this->db->sql_transaction('commit');

			return $counts;
		}
		catch (\Throwable $e)
		{
			$this->db->sql_transaction('rollback');
			throw $e;
		}
	}

	/**
	 * Apply relationships associated with one repair batch.
	 *
	 * Subclasses may extend text repair with relational changes.
	 *
	 * @param array $plans  Confirmed repair snapshots
	 * @param array $counts Applied change counts, updated by reference
	 * @return void
	 */
	protected function apply_batch_relations(array $plans, array &$counts): void
	{
	}

	/**
	 * Apply guarded text changes in portable ten-row conditional updates.
	 *
	 * @param array $changes Field changes from confirmed repair snapshots
	 * @param array $counts  Applied change counts, updated by reference
	 * @return void
	 * @throws \RuntimeException When stored data no longer matches a snapshot
	 */
	protected function apply_changes(array $changes, array &$counts): void
	{
		$rows = [];
		foreach ($changes as $change)
		{
			$id = $change['table'] . ':' . $change['row'][$change['key']];
			$rows[$id]['table'] = $change['table'];
			$rows[$id]['key'] = $change['key'];
			$rows[$id]['row'] = $change['row'];
			$rows[$id]['values'][$change['field']] = $change['after'];
		}
		$groups = [];
		foreach ($rows as $row)
		{
			$fields = array_keys($row['values']);
			sort($fields);
			$group_key = $row['table'] . ':' . $row['key'] . ':' . implode(',', $fields);
			$groups[$group_key][] = $row;
		}
		foreach ($groups as $group)
		{
			foreach (array_chunk($group, 10) as $batch)
			{
				$sets = [];
				$guards = [];
				foreach ($batch as $item)
				{
					$where = [];
					foreach ($item['row'] as $column => $value)
					{
						$where[] = $column . ' = ' . $this->literal((string) $value);
					}
					$guards[] = '(' . implode(' AND ', $where) . ')';
				}
				$fields = array_keys($batch[0]['values']);
				sort($fields);
				foreach ($fields as $field)
				{
					$value_sql = $field;
					foreach (array_reverse($batch) as $item)
					{
						$where = [];
						foreach ($item['row'] as $column => $value)
						{
							$where[] = $column . ' = ' . $this->literal((string) $value);
						}
						$value_sql = $this->db->sql_case(
							implode(' AND ', $where),
							$this->literal($item['values'][$field]),
							$value_sql
						);
					}
					$sets[] = $field . ' = ' . $value_sql;
				}
				$this->db->sql_query('UPDATE ' . $batch[0]['table'] . '
					SET ' . implode(', ', $sets) . '
					WHERE ' . implode(' OR ', $guards));
				if ($this->db->sql_affectedrows() !== count($batch))
				{
					throw new \RuntimeException('CLI_TOPIC_REPAIR_CONFLICT');
				}
			}
		}
		foreach ($changes as $change)
		{
			if ($change['field'] === 'topic_title')
			{
				$key = 'topics';
			}
			else if ($change['field'] === 'post_subject')
			{
				$key = 'subjects';
			}
			else
			{
				$key = 'caches';
			}

			$counts[$key]++;
		}
	}

	/**
	 * Create an empty applied change count set.
	 *
	 * @return array Empty applied change counts
	 */
	protected function empty_counts(): array
	{
		return [
			'topics' => 0,
			'subjects' => 0,
			'caches' => 0,
			'skipped' => 0,
			'conflicts' => 0,
		];
	}

	/**
	 * Merge applied change counts into a running total.
	 *
	 * @param array $counts Running totals, updated by reference
	 * @param array $result Applied change counts to add
	 * @return void
	 */
	protected function merge_counts(array &$counts, array $result): void
	{
		foreach ($counts as $key => $value)
		{
			$counts[$key] += $result[$key];
		}
	}

	/**
	 * Invalidate SQL caches for tables changed by repair operations.
	 *
	 * @return void
	 */
	public function invalidate_caches(): void
	{
		foreach ([$this->topics, $this->posts, $this->forums] as $table)
		{
			$this->cache->destroy('sql', $table);
		}
	}

	/**
	 * Quote a portable SQL text literal, preserving Unicode on SQL Server.
	 *
	 * @param string $text Stored text
	 * @return string Quoted SQL literal
	 */
	protected function literal(string $text): string
	{
		$prefix = strpos($this->db->get_sql_layer(), 'mssql') === 0 ? 'N' : '';

		return $prefix . "'" . $this->db->sql_escape($text) . "'";
	}

	/**
	 * Fetch one row and release the query result.
	 *
	 * @param string $sql SQL query
	 * @return array|null First row, or null when no row exists
	 */
	protected function fetch(string $sql): ?array
	{
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ?: null;
	}
}
