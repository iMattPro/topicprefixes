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
 * Safely replace one combined tag with one or more administrator-supplied tags.
 */
class repairer
{
	protected const BATCH_SIZE = 500;
	protected const UPDATE_CASE_BATCH_SIZE = 10;

	/** @var driver_interface */
	protected $db;

	/** @var cache|null */
	protected $cache;

	/** @var string */
	protected $tags_table;

	/** @var string */
	protected $forums_map_table;

	/** @var string */
	protected $topic_map_table;

	/** @var string */
	protected $topics_table;

	/** @var string */
	protected $posts_table;

	/** @var string */
	protected $forums_table;

	public function __construct(
		driver_interface $db,
		cache $cache,
		$tags_table,
		$forums_map_table,
		$topic_map_table,
		$topics_table,
		$posts_table,
		$forums_table
	)
	{
		$this->db = $db;
		$this->cache = $cache;
		$this->tags_table = $tags_table;
		$this->forums_map_table = $forums_map_table;
		$this->topic_map_table = $topic_map_table;
		$this->topics_table = $topics_table;
		$this->posts_table = $posts_table;
		$this->forums_table = $forums_table;
	}

	/**
	 * Describe one source tag without changing data.
	 *
	 * @param int $source_id Source tag identifier
	 * @return array
	 */
	public function inspect(int $source_id): array
	{
		$source = $this->get_source($source_id);

		return [
			'source' => $source,
			'forum_count' => $this->count_relationships($this->forums_map_table, $source_id),
			'topic_count' => $this->count_relationships($this->topic_map_table, $source_id),
		];
	}

	/**
	 * Validate replacement names and preview exact changes.
	 *
	 * @param int   $source_id   Source tag identifier
	 * @param array $replacements Display-form replacement names
	 * @return array
	 */
	public function preview(int $source_id, array $replacements): array
	{
		$source = $this->get_source($source_id);
		$targets = $this->resolve_targets($source, $replacements);
		$scan = $this->scan_topics($source);

		return [
			'source' => $source,
			'targets' => $targets,
			'forum_count' => count($this->get_source_forums($source_id)),
			'topic_count' => $scan['topic_count'],
			'cleanup' => $scan['cleanup'],
		];
	}

	/**
	 * Apply one restart-safe repair.
	 *
	 * Target definitions are committed before topic batches. The source remains
	 * until every batch succeeds, so rerunning the same repair after interruption
	 * safely reuses targets, skips existing relationships, and ignores clean text.
	 *
	 * @param int $source_id Source tag identifier
	 * @param array $replacements Display-form replacement names
	 * @return array Applied change counts and targets
	 * @throws \Exception
	 */
	public function repair(int $source_id, array $replacements): array
	{
		$preview = $this->preview($source_id, $replacements);
		$source = $preview['source'];
		$targets = $this->create_or_update_targets($source, $preview['targets']);
		$target_ids = array_column($targets, 'prefix_id');
		$totals = [
			'topic_count' => 0,
			'cleanup' => $this->empty_cleanup_counts(),
		];

		$last_topic_id = 0;
		do
		{
			$topics = $this->get_topic_batch($source_id, $last_topic_id);
			if (!$topics)
			{
				break;
			}

			$last_topic_id = (int) end($topics)['topic_id'];
			$batch = $this->repair_topic_batch($source, $target_ids, $topics);
			$totals['topic_count'] += $batch['topic_count'];
			foreach ($batch['cleanup'] as $field => $count)
			{
				$totals['cleanup'][$field] += $count;
			}
		}
		while (count($topics) === self::BATCH_SIZE);

		$this->db->sql_transaction('begin');
		try
		{
			$this->db->sql_query('DELETE FROM ' . $this->topic_map_table . ' WHERE prefix_id = ' . $source_id);
			$this->db->sql_query('DELETE FROM ' . $this->forums_map_table . ' WHERE prefix_id = ' . $source_id);
			$this->db->sql_query('DELETE FROM ' . $this->tags_table . ' WHERE prefix_id = ' . $source_id);
			$this->db->sql_transaction('commit');
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			$this->invalidate_cache();
			throw $e;
		}
		$this->invalidate_cache();

		return [
			'source' => $source,
			'targets' => $targets,
			'topic_count' => $totals['topic_count'],
			'cleanup' => $totals['cleanup'],
		];
	}

	/**
	 * Load and normalize one source definition.
	 *
	 * @throws \InvalidArgumentException When source does not exist
	 */
	protected function get_source(int $source_id): array
	{
		$sql = 'SELECT prefix_id, prefix_tag, prefix_color, prefix_enabled, prefix_order
			FROM ' . $this->tags_table . '
			WHERE prefix_id = ' . (int) $source_id;
		$result = $this->db->sql_query($sql);
		$source = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$source)
		{
			throw new \InvalidArgumentException('Source tag does not exist.');
		}

		$source['prefix_id'] = (int) $source['prefix_id'];
		$source['prefix_enabled'] = (int) $source['prefix_enabled'];
		$source['prefix_order'] = (int) $source['prefix_order'];
		$source['stored_name'] = $source['prefix_tag'];
		$source['prefix_tag'] = manager::decode_name($source['prefix_tag']);

		return $source;
	}

	/**
	 * Resolve replacement names against current definitions.
	 *
	 * @throws \InvalidArgumentException For invalid or unsafe names
	 */
	protected function resolve_targets(array $source, array $replacements): array
	{
		if (!$replacements)
		{
			throw new \InvalidArgumentException('Enter at least one replacement tag.');
		}

		$result = $this->db->sql_query('SELECT prefix_id, prefix_tag, prefix_enabled FROM ' . $this->tags_table);
		$known = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$key = base64_encode(manager::decode_name($row['prefix_tag']));
			if (!isset($known[$key]))
			{
				$known[$key] = [
					'prefix_id' => (int) $row['prefix_id'],
					'prefix_enabled' => (int) $row['prefix_enabled'],
				];
			}
		}
		$this->db->sql_freeresult($result);

		$targets = [];
		$seen = [];
		foreach ($replacements as $replacement)
		{
			$stored_name = manager::normalize_name((string) $replacement);
			if ($stored_name === '')
			{
				throw new \InvalidArgumentException('Replacement tag is empty or too long.');
			}

			$name = manager::decode_name($stored_name);
			$key = base64_encode($name);
			if ($name === $source['prefix_tag'])
			{
				throw new \InvalidArgumentException('Replacement tag cannot equal the source tag.');
			}
			if (isset($seen[$key]))
			{
				throw new \InvalidArgumentException('Replacement tags must be unique.');
			}
			$seen[$key] = true;

			$targets[] = [
				'prefix_id' => $known[$key]['prefix_id'] ?? null,
				'prefix_enabled' => $known[$key]['prefix_enabled'] ?? null,
				'prefix_tag' => $name,
				'stored_name' => $stored_name,
				'existing' => isset($known[$key]),
			];
		}

		return $targets;
	}

	/**
	 * Create missing targets and merge source metadata into existing targets.
	 */
	protected function create_or_update_targets(array $source, array $targets): array
	{
		$source_forums = $this->get_source_forums($source['prefix_id']);
		$this->db->sql_transaction('begin');
		try
		{
			foreach ($targets as &$target)
			{
				if ($target['existing'])
				{
					if ($source['prefix_enabled'] && !$target['prefix_enabled'])
					{
						$this->db->sql_query('UPDATE ' . $this->tags_table . '
							SET prefix_enabled = 1
							WHERE prefix_id = ' . (int) $target['prefix_id']);
						$target['prefix_enabled'] = 1;
					}
				}
				else
				{
					$sql = 'INSERT INTO ' . $this->tags_table . '
						(prefix_tag, prefix_color, prefix_enabled, prefix_order)
						VALUES (' . $this->sql_text_literal($target['stored_name']) . ', ' .
						$this->sql_text_literal($source['prefix_color']) . ', ' .
						(int) $source['prefix_enabled'] . ', ' . (int) $source['prefix_order'] . ')';
					$this->db->sql_query($sql);
					$target['prefix_id'] = (int) $this->db->sql_nextid();
					$target['prefix_enabled'] = $source['prefix_enabled'];
				}

				$this->merge_forums((int) $target['prefix_id'], $source_forums);
			}
			unset($target);
			$this->db->sql_transaction('commit');
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			$this->invalidate_cache();
			throw $e;
		}
		$this->invalidate_cache();

		return $targets;
	}

	/**
	 * Add missing source forums to one target.
	 */
	protected function merge_forums(int $target_id, array $source_forums): void
	{
		if (!$source_forums)
		{
			return;
		}

		$sql = 'SELECT forum_id FROM ' . $this->forums_map_table . '
			WHERE prefix_id = ' . $target_id . '
				AND ' . $this->db->sql_in_set('forum_id', $source_forums);
		$result = $this->db->sql_query($sql);
		$existing = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$existing[(int) $row['forum_id']] = true;
		}
		$this->db->sql_freeresult($result);

		$rows = [];
		foreach ($source_forums as $forum_id)
		{
			if (!isset($existing[$forum_id]))
			{
				$rows[] = ['forum_id' => $forum_id, 'prefix_id' => $target_id];
			}
		}
		if ($rows)
		{
			$this->db->sql_multi_insert($this->forums_map_table, $rows);
		}
	}

	/**
	 * Inspect or apply title cleanup for every source assignment.
	 */
	protected function scan_topics(array $source): array
	{
		$totals = ['topic_count' => 0, 'cleanup' => $this->empty_cleanup_counts()];
		$last_topic_id = 0;
		do
		{
			$topics = $this->get_topic_batch($source['prefix_id'], $last_topic_id);
			if (!$topics)
			{
				break;
			}
			$last_topic_id = (int) end($topics)['topic_id'];
			$changes = $this->collect_text_changes($source['stored_name'] . ' ', $topics);
			$totals['topic_count'] += count($topics);
			foreach ($changes['counts'] as $field => $count)
			{
				$totals['cleanup'][$field] += $count;
			}
		}
		while (count($topics) === self::BATCH_SIZE);

		return $totals;
	}

	/**
	 * Repair one bounded topic batch.
	 */
	protected function repair_topic_batch(array $source, array $target_ids, array $topics): array
	{
		$topic_ids = array_map('intval', array_column($topics, 'topic_id'));
		$existing = $this->get_existing_assignments($topic_ids, $target_ids);
		$rows = [];
		foreach ($topic_ids as $topic_id)
		{
			foreach ($target_ids as $target_id)
			{
				if (!isset($existing[$topic_id][$target_id]))
				{
					$rows[] = ['topic_id' => $topic_id, 'prefix_id' => $target_id];
				}
			}
		}
		$changes = $this->collect_text_changes($source['stored_name'] . ' ', $topics);

		$this->db->sql_transaction('begin');
		try
		{
			foreach (array_chunk($rows, self::BATCH_SIZE) as $row_batch)
			{
				$this->db->sql_multi_insert($this->topic_map_table, $row_batch);
			}
			$this->apply_text_changes($changes);
			$this->db->sql_transaction('commit');
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			throw $e;
		}

		return ['topic_count' => count($topics), 'cleanup' => $changes['counts']];
	}

	/**
	 * Load one bounded topic set assigned to source.
	 */
	protected function get_topic_batch(int $source_id, int $last_topic_id): array
	{
		$sql = 'SELECT t.topic_id, t.topic_title, t.topic_first_post_id, t.topic_last_post_id,
				t.topic_last_post_subject, fp.post_subject, f.forum_id AS last_post_forum_id,
				f.forum_last_post_subject
			FROM ' . $this->topic_map_table . ' pt
			INNER JOIN ' . $this->topics_table . ' t
				ON t.topic_id = pt.topic_id
			LEFT JOIN ' . $this->posts_table . ' fp
				ON fp.post_id = t.topic_first_post_id
			LEFT JOIN ' . $this->forums_table . ' f
				ON t.topic_last_post_id <> 0
					AND f.forum_last_post_id = t.topic_last_post_id
			WHERE pt.prefix_id = ' . $source_id . '
				AND t.topic_id > ' . $last_topic_id . '
			ORDER BY t.topic_id ASC';
		$result = $this->db->sql_query_limit($sql, self::BATCH_SIZE);
		$topics = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$topics[] = $row;
		}
		$this->db->sql_freeresult($result);

		return $topics;
	}

	/**
	 * Find target relationships already present in one topic batch.
	 */
	protected function get_existing_assignments(array $topic_ids, array $target_ids): array
	{
		$sql = 'SELECT topic_id, prefix_id FROM ' . $this->topic_map_table . '
			WHERE ' . $this->db->sql_in_set('topic_id', $topic_ids) . '
				AND ' . $this->db->sql_in_set('prefix_id', $target_ids);
		$result = $this->db->sql_query($sql);
		$existing = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$existing[(int) $row['topic_id']][(int) $row['prefix_id']] = true;
		}
		$this->db->sql_freeresult($result);

		return $existing;
	}

	/**
	 * Collect exact leading-prefix removals from migration-owned subject fields.
	 */
	protected function collect_text_changes(string $legacy_text, array $topics): array
	{
		$changes = [
			'topic_titles' => [],
			'topic_last_post_subjects' => [],
			'post_subjects' => [],
			'forum_last_post_subjects' => [],
			'counts' => $this->empty_cleanup_counts(),
		];
		if ($legacy_text === ' ')
		{
			return $changes;
		}

		$legacy_length = strlen($legacy_text);
		foreach ($topics as $topic)
		{
			$topic_id = (int) $topic['topic_id'];
			if (strpos($topic['topic_title'], $legacy_text) === 0)
			{
				$changes['topic_titles'][$topic_id] = substr($topic['topic_title'], $legacy_length);
				$changes['counts']['topic_title']++;
			}
			if ($topic['topic_last_post_subject'] !== null && strpos($topic['topic_last_post_subject'], $legacy_text) === 0)
			{
				$changes['topic_last_post_subjects'][$topic_id] = substr($topic['topic_last_post_subject'], $legacy_length);
				$changes['counts']['topic_last_post_subject']++;
			}

			$post_id = (int) $topic['topic_first_post_id'];
			if ($post_id && $topic['post_subject'] !== null && strpos($topic['post_subject'], $legacy_text) === 0)
			{
				$changes['post_subjects'][$post_id] = substr($topic['post_subject'], $legacy_length);
				$changes['counts']['post_subject']++;
			}

			$forum_id = (int) $topic['last_post_forum_id'];
			if ($forum_id && $topic['forum_last_post_subject'] !== null && strpos($topic['forum_last_post_subject'], $legacy_text) === 0)
			{
				$changes['forum_last_post_subjects'][$forum_id] = substr($topic['forum_last_post_subject'], $legacy_length);
				$changes['counts']['forum_last_post_subject']++;
			}
		}

		return $changes;
	}

	/**
	 * Write collected title changes.
	 */
	protected function apply_text_changes(array $changes): void
	{
		$this->bulk_update_text($this->topics_table, 'topic_id', 'topic_title', $changes['topic_titles']);
		$this->bulk_update_text($this->topics_table, 'topic_id', 'topic_last_post_subject', $changes['topic_last_post_subjects']);
		$this->bulk_update_text($this->posts_table, 'post_id', 'post_subject', $changes['post_subjects']);
		$this->bulk_update_text($this->forums_table, 'forum_id', 'forum_last_post_subject', $changes['forum_last_post_subjects']);
	}

	/**
	 * Update distinct text values with portable bounded CASE expressions.
	 */
	protected function bulk_update_text(string $table, string $id_column, string $value_column, array $changes): void
	{
		foreach (array_chunk($changes, self::UPDATE_CASE_BATCH_SIZE, true) as $batch)
		{
			$value_sql = $value_column;
			foreach (array_reverse($batch, true) as $id => $value)
			{
				$value_sql = $this->db->sql_case(
					$id_column . ' = ' . (int) $id,
					$this->sql_text_literal($value),
					$value_sql
				);
			}
			$this->db->sql_query('UPDATE ' . $table . '
				SET ' . $value_column . ' = ' . $value_sql . '
				WHERE ' . $this->db->sql_in_set($id_column, array_keys($batch)));
		}
	}

	/**
	 * Get source forum availability identifiers.
	 */
	protected function get_source_forums(int $source_id): array
	{
		$result = $this->db->sql_query('SELECT forum_id FROM ' . $this->forums_map_table . '
			WHERE prefix_id = ' . $source_id . '
			ORDER BY forum_id ASC');
		$forum_ids = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$forum_ids[] = (int) $row['forum_id'];
		}
		$this->db->sql_freeresult($result);

		return $forum_ids;
	}

	/**
	 * Count source relationships without loading topic content.
	 */
	protected function count_relationships(string $table, int $source_id): int
	{
		$result = $this->db->sql_query('SELECT COUNT(*) AS total FROM ' . $table . '
			WHERE prefix_id = ' . $source_id);
		$count = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		return $count;
	}

	protected function empty_cleanup_counts(): array
	{
		return [
			'topic_title' => 0,
			'post_subject' => 0,
			'topic_last_post_subject' => 0,
			'forum_last_post_subject' => 0,
		];
	}

	protected function sql_text_literal(string $value): string
	{
		$unicode_prefix = strpos($this->db->get_sql_layer(), 'mssql') === 0 ? 'N' : '';
		return $unicode_prefix . "'" . $this->db->sql_escape($value) . "'";
	}

	protected function invalidate_cache(): void
	{
		if ($this->cache)
		{
			$this->cache->destroy(manager::CACHE_KEY);
		}
	}
}
