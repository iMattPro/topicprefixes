<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\migrations;

use phpbb\topicprefixes\tags\manager as tag_manager;

/**
 * Convert legacy title prefixes to relational topic tags.
 */
class v200_data extends \phpbb\db\migration\migration
{
	protected const DEFAULT_COLOR = '4A76A8';
	protected const BATCH_SIZE = 500;

	// sql_case() produces nested CASE expressions; SQL Server supports at most 10 levels.
	protected const UPDATE_CASE_BATCH_SIZE = 10;

	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return isset($this->config['topicprefixes_tags_migrated']);
	}

	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return ['\phpbb\topicprefixes\migrations\v200_schema'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return [
			['custom', [[$this, 'migrate_legacy_data']]],
			['config.add', ['topicprefixes_tags_migrated', 1]],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_data()
	{
		return [
			['config.remove', ['topicprefixes_tags_migrated']],
		];
	}

	/**
	 * Migrate legacy definitions, forum availability, and topic assignments.
	 *
	 * Topic rows are processed in bounded transactions. An interrupted run can
	 * safely restart because existing relationships and already-clean titles are
	 * detected before writes.
	 *
	 * @return void
	 */
	public function migrate_legacy_data(): void
	{
		$tables = [
			'topic_prefixes' => $this->table_prefix . 'topic_prefixes',
			'topic_prefixes_forums' => $this->table_prefix . 'topic_prefixes_forums',
			'topic_prefixes_topics' => $this->table_prefix . 'topic_prefixes_topics',
			'forums' => $this->table_prefix . 'forums',
			'topics' => $this->table_prefix . 'topics',
			'posts' => $this->table_prefix . 'posts',
		];

		$this->migrate_tag_definitions($tables['topic_prefixes']);
		$this->migrate_forum_availability($tables['topic_prefixes'], $tables['topic_prefixes_forums']);
		list($tag_map, $split_source_ids) = $this->prepare_legacy_tags($tables);

		$last_topic_id = 0;
		do
		{
			$topics = $this->get_legacy_topics($tables, $last_topic_id);
			if (!$topics)
			{
				break;
			}

			$last_topic_id = (int) end($topics)['topic_id'];
			$this->migrate_topic_batch($tables, $topics, $tag_map);
		}
		while (count($topics) === self::BATCH_SIZE);

		$this->remove_split_sources($tables, $split_source_ids);
	}

	/**
	 * Add default presentation fields to legacy tag definitions.
	 *
	 * @param string $tags_table Tag definition table
	 * @return void
	 */
	protected function migrate_tag_definitions(string $tags_table): void
	{
		$sql = 'UPDATE ' . $tags_table . "
			SET prefix_color = '" . self::DEFAULT_COLOR . "',
				prefix_order = prefix_left_id
			WHERE prefix_order = 0";
		$this->db->sql_query($sql);
	}

	/**
	 * Prepare all legacy prefixes as tags, splitting complete multi-bracket
	 * sequences when detected. Other prefix formats remain unchanged.
	 *
	 * @param array $tables Migration table names
	 * @return array Source-to-target map and split source identifiers
	 */
	protected function prepare_legacy_tags(array $tables): array
	{
		$sql = 'SELECT prefix_id, prefix_tag, prefix_enabled, prefix_order, forum_id
			FROM ' . $tables['topic_prefixes'] . '
			ORDER BY prefix_order ASC, prefix_id ASC';
		$result = $this->db->sql_query($sql);
		$sources = [];
		$known_tags = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$sources[] = $row;
			$key = base64_encode(tag_manager::decode_name($row['prefix_tag']));
			if (!isset($known_tags[$key]))
			{
				$known_tags[$key] = [
					'prefix_id' => (int) $row['prefix_id'],
					'prefix_enabled' => (int) $row['prefix_enabled'],
				];
			}
		}
		$this->db->sql_freeresult($result);

		$sql = 'SELECT forum_id, prefix_id FROM ' . $tables['topic_prefixes_forums'];
		$result = $this->db->sql_query($sql);
		$forum_keys = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$forum_keys[(int) $row['forum_id'] . ':' . (int) $row['prefix_id']] = true;
		}
		$this->db->sql_freeresult($result);

		$tag_map = [];
		$split_source_ids = [];
		$forum_rows = [];
		foreach ($sources as $source)
		{
			$source_id = (int) $source['prefix_id'];
			$names = $this->split_legacy_tag($source['prefix_tag']);
			if (!$names)
			{
				$tag_map[$source_id] = [$source_id];
				continue;
			}

			$split_source_ids[] = $source_id;
			foreach (array_unique($names) as $name)
			{
				$key = base64_encode(tag_manager::decode_name($name));
				if (isset($known_tags[$key]))
				{
					$target_id = $known_tags[$key]['prefix_id'];
					if (!empty($source['prefix_enabled']) && empty($known_tags[$key]['prefix_enabled']))
					{
						$this->db->sql_query('UPDATE ' . $tables['topic_prefixes'] . '
							SET prefix_enabled = 1
							WHERE prefix_id = ' . $target_id);
						$known_tags[$key]['prefix_enabled'] = 1;
					}
				}
				else
				{
					$sql = 'INSERT INTO ' . $tables['topic_prefixes'] . '
						(prefix_tag, prefix_color, prefix_enabled, prefix_order, prefix_parent_id,
							prefix_left_id, prefix_right_id, prefix_parents, forum_id)
						VALUES (' . $this->sql_text_literal($name) . ", '" . self::DEFAULT_COLOR . "', " .
						(int) !empty($source['prefix_enabled']) . ', ' . (int) $source['prefix_order'] . ", 0, 0, 0, '', 0)";
					$this->db->sql_query($sql);
					$target_id = (int) $this->db->sql_nextid();
					$known_tags[$key] = [
						'prefix_id' => $target_id,
						'prefix_enabled' => (int) !empty($source['prefix_enabled']),
					];
				}

				$tag_map[$source_id][] = $target_id;
				$forum_key = (int) $source['forum_id'] . ':' . $target_id;
				if (!empty($source['forum_id']) && !isset($forum_keys[$forum_key]))
				{
					$forum_rows[] = [
						'forum_id' => (int) $source['forum_id'],
						'prefix_id' => $target_id,
					];
					$forum_keys[$forum_key] = true;
				}
			}
		}

		if ($forum_rows)
		{
			$this->db->sql_multi_insert($tables['topic_prefixes_forums'], $forum_rows);
		}

		return [$tag_map, $split_source_ids];
	}

	/**
	 * Split a complete, uninterrupted sequence containing at least two
	 * bracketed labels. Brackets remain part of each resulting tag name.
	 *
	 * Mixed, empty, or invalid values remain unchanged.
	 *
	 * @param string $stored_name Database-safe legacy tag name
	 * @return array Split tag names, or an empty array when unchanged
	 */
	protected function split_legacy_tag(string $stored_name): array
	{
		$name = tag_manager::decode_name($stored_name);
		if (!preg_match('/\A(?:\[[^\[\]]+\])+\z/u', $name))
		{
			return [];
		}

		preg_match_all('/\[([^\[\]]+)\]/u', $name, $matches, PREG_SET_ORDER);
		if (count($matches) < 2)
		{
			return [];
		}

		$names = [];
		foreach ($matches as $match)
		{
			$normalized = tag_manager::normalize_name($match[0]);
			if (trim($match[1]) === '' || $normalized === '')
			{
				return [];
			}
			$names[] = $normalized;
		}

		return $names;
	}

	/**
	 * Remove obsolete multi-bracket source definitions after all topics are mapped.
	 *
	 * @param array $tables     Migration table names
	 * @param array $source_ids Split legacy tag identifiers
	 * @return void
	 */
	protected function remove_split_sources(array $tables, array $source_ids): void
	{
		if (!$source_ids)
		{
			return;
		}

		$this->db->sql_transaction('begin');
		foreach (['topic_prefixes_forums', 'topic_prefixes_topics', 'topic_prefixes'] as $table)
		{
			$this->db->sql_query('DELETE FROM ' . $tables[$table] . '
				WHERE ' . $this->db->sql_in_set('prefix_id', $source_ids));
		}
		$this->db->sql_transaction('commit');
	}

	/**
	 * Copy legacy single-forum values into forum/tag relationships.
	 *
	 * @param string $tags_table   Tag definition table
	 * @param string $forums_table Forum/tag map table
	 * @return void
	 */
	protected function migrate_forum_availability(string $tags_table, string $forums_table): void
	{
		$sql = 'SELECT p.forum_id, p.prefix_id
			FROM ' . $tags_table . ' p
			LEFT JOIN ' . $forums_table . ' pf
				ON pf.forum_id = p.forum_id
				AND pf.prefix_id = p.prefix_id
			WHERE p.forum_id <> 0
				AND pf.prefix_id IS NULL';
		$result = $this->db->sql_query($sql);
		$rows = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = [
				'forum_id' => (int) $row['forum_id'],
				'prefix_id' => (int) $row['prefix_id'],
			];
		}
		$this->db->sql_freeresult($result);

		if ($rows)
		{
			$this->db->sql_multi_insert($forums_table, $rows);
		}
	}

	/**
	 * Load one bounded set of legacy topics and first-post subjects.
	 *
	 * @param array $tables        Migration table names
	 * @param int   $last_topic_id Last processed topic identifier
	 * @return array Legacy topic rows
	 */
	protected function get_legacy_topics(array $tables, int $last_topic_id): array
	{
		$sql = 'SELECT t.topic_id, t.topic_title, t.topic_first_post_id, t.topic_last_post_id,
				t.topic_last_post_subject, t.topic_moved_id, t.topic_prefix_id, p.prefix_tag,
				fp.post_subject, f.forum_id AS last_post_forum_id, f.forum_last_post_subject
			FROM ' . $tables['topics'] . ' t
			INNER JOIN ' . $tables['topic_prefixes'] . ' p
				ON p.prefix_id = t.topic_prefix_id
			LEFT JOIN ' . $tables['posts'] . ' fp
				ON fp.post_id = t.topic_first_post_id
			LEFT JOIN ' . $tables['forums'] . ' f
				ON t.topic_last_post_id <> 0
				AND f.forum_last_post_id = t.topic_last_post_id
			WHERE t.topic_prefix_id <> 0
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
	 * Migrate one topic batch in a transaction.
	 *
	 * @param array $tables  Migration table names
	 * @param array $topics  Legacy topic rows
	 * @param array $tag_map Legacy-to-relational tag map
	 * @return void
	 */
	protected function migrate_topic_batch(array $tables, array $topics, array $tag_map): void
	{
		$topic_ids = array_map('intval', array_column($topics, 'topic_id'));
		$existing = $this->get_existing_assignments($tables['topic_prefixes_topics'], $topic_ids);
		$assignments = [];
		$topic_titles = [];
		$topic_last_post_subjects = [];
		$post_subjects = [];
		$forum_last_post_subjects = [];

		foreach ($topics as $topic)
		{
			$topic_id = (int) $topic['topic_id'];
			$source_id = (int) $topic['topic_prefix_id'];
			foreach ($tag_map[$source_id] ?? [$source_id] as $tag_id)
			{
				if (empty($topic['topic_moved_id']) && empty($existing[$topic_id][$tag_id]))
				{
					$assignments[] = ['topic_id' => $topic_id, 'prefix_id' => $tag_id];
				}
			}

			$legacy_text = $topic['prefix_tag'] . ' ';
			if ($legacy_text === ' ')
			{
				continue;
			}

			// These byte-string functions are intentional. strpos() confirms the
			// exact prefix bytes, so its byte length is a safe UTF-8 boundary.
			$legacy_length = strlen($legacy_text);
			if (strpos($topic['topic_title'], $legacy_text) === 0)
			{
				$topic_titles[$topic_id] = substr($topic['topic_title'], $legacy_length);
			}
			if ($topic['topic_last_post_subject'] !== null && strpos($topic['topic_last_post_subject'], $legacy_text) === 0)
			{
				$topic_last_post_subjects[$topic_id] = substr($topic['topic_last_post_subject'], $legacy_length);
			}

			$post_id = (int) $topic['topic_first_post_id'];
			if ($post_id && $topic['post_subject'] !== null && strpos($topic['post_subject'], $legacy_text) === 0)
			{
				$post_subjects[$post_id] = substr($topic['post_subject'], $legacy_length);
			}

			$forum_id = (int) $topic['last_post_forum_id'];
			if ($forum_id && $topic['forum_last_post_subject'] !== null && strpos($topic['forum_last_post_subject'], $legacy_text) === 0)
			{
				$forum_last_post_subjects[$forum_id] = substr($topic['forum_last_post_subject'], $legacy_length);
			}
		}

		$this->db->sql_transaction('begin');
		if ($assignments)
		{
			$this->db->sql_multi_insert($tables['topic_prefixes_topics'], $assignments);
		}
		$this->bulk_update_text($tables['topics'], 'topic_id', 'topic_title', $topic_titles);
		$this->bulk_update_text($tables['topics'], 'topic_id', 'topic_last_post_subject', $topic_last_post_subjects);
		$this->bulk_update_text($tables['posts'], 'post_id', 'post_subject', $post_subjects);
		$this->bulk_update_text($tables['forums'], 'forum_id', 'forum_last_post_subject', $forum_last_post_subjects);
		$this->db->sql_transaction('commit');
	}

	/**
	 * Load existing topic/tag assignments for one migration batch.
	 *
	 * @param string $topic_tags_table Topic/tag map table
	 * @param array  $topic_ids        Topic identifiers
	 * @return array Existing relationship lookup
	 */
	protected function get_existing_assignments(string $topic_tags_table, array $topic_ids): array
	{
		$sql = 'SELECT topic_id, prefix_id
			FROM ' . $topic_tags_table . '
			WHERE ' . $this->db->sql_in_set('topic_id', $topic_ids);
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
	 * Update distinct text values with portable, bounded conditional queries.
	 *
	 * @param string $table        Table name
	 * @param string $id_column    Integer primary key column
	 * @param string $value_column Text column
	 * @param array  $changes      New values keyed by identifier
	 * @return void
	 */
	protected function bulk_update_text(string $table, string $id_column, string $value_column, array $changes): void
	{
		if (!$changes)
		{
			return;
		}

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

			$sql = 'UPDATE ' . $table . '
				SET ' . $value_column . ' = ' . $value_sql . '
				WHERE ' . $this->db->sql_in_set($id_column, array_keys($batch));
			$this->db->sql_query($sql);
		}
	}

	/**
	 * Quote text for use as an SQL literal.
	 *
	 * SQL Server requires the N prefix to preserve Unicode text when a literal
	 * is assigned to a nvarchar column. Other supported DBMS use the standard
	 * quoted form generated throughout phpBB's DBAL.
	 *
	 * @param string $value Text value
	 * @return string Quoted SQL literal
	 */
	protected function sql_text_literal(string $value): string
	{
		$unicode_prefix = strpos($this->db->get_sql_layer(), 'mssql') === 0 ? 'N' : '';

		return $unicode_prefix . "'" . $this->db->sql_escape($value) . "'";
	}
}
