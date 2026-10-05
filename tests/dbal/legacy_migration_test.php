<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tests\dbal;

require_once __DIR__ . '/tags_base.php';

class legacy_migration_test extends tags_base
{
	/** @var \phpbb\db\tools\tools_interface */
	protected $tools;

	/** @var int */
	protected $moved_topic_id;

	/** @var array */
	protected $post_ids = [];

	protected function setUp(): void
	{
		parent::setUp();
		$factory = new \phpbb\db\tools\factory();
		$this->tools = $factory->get($this->db);
		$this->tools->sql_column_add('phpbb_topics', 'topic_prefix_id', array('UINT', 0));
		$this->tools->sql_column_add('phpbb_topic_prefixes', 'prefix_parent_id', array('UINT', 0));
		$this->tools->sql_column_add('phpbb_topic_prefixes', 'prefix_left_id', array('UINT', 0));
		$this->tools->sql_column_add('phpbb_topic_prefixes', 'prefix_right_id', array('UINT', 0));
		$this->tools->sql_column_add('phpbb_topic_prefixes', 'prefix_parents', array('MTEXT_UNI', ''));
		$this->tools->sql_column_add('phpbb_topic_prefixes', 'forum_id', array('UINT', 0));
		$pdo = $this->getConnection()->getConnection();

		$this->db->sql_query("UPDATE phpbb_topic_prefixes SET prefix_left_id = prefix_id * 2 - 1, prefix_right_id = prefix_id * 2, prefix_order = 0, forum_id = 2");
		$statement = $pdo->prepare('UPDATE phpbb_topic_prefixes SET prefix_tag = ? WHERE prefix_id = 1');
		$statement->execute(array('バグ'));
		$this->db->sql_query('DELETE FROM phpbb_topic_prefixes_forums WHERE prefix_id = 4');
		$statement = $pdo->prepare('UPDATE phpbb_topics SET topic_title = ?, topic_prefix_id = 1 WHERE topic_id = 10');
		$statement->execute(array('バグ 日本語 title'));
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = '[Other] untouched', topic_prefix_id = 1 WHERE topic_id = 11");
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = 'PHP 8.4 PHP only', topic_prefix_id = 2 WHERE topic_id = 12");
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = '[Random] No tags', topic_prefix_id = 0 WHERE topic_id = 13");
		$this->db->sql_query('INSERT INTO phpbb_topics ' . $this->db->sql_build_array('INSERT', array(
			'forum_id' => 2,
			'topic_title' => 'Temporary moved topic',
			'topic_prefix_id' => 1,
			'topic_moved_id' => 10,
			'topic_visibility' => ITEM_APPROVED,
			'topic_type' => POST_NORMAL,
		)));
		$this->moved_topic_id = (int) $this->db->sql_nextid();
		$statement = $pdo->prepare('UPDATE phpbb_topics SET topic_title = ? WHERE topic_id = ?');
		$statement->execute(array('バグ 移動 topic', $this->moved_topic_id));

		foreach (array(
			array('both', 10, 'Temporary first post'),
			array('unrelated', 11, 'Unrelated first post'),
			array('php', 12, 'PHP 8.4 PHP only'),
			array('random', 13, '[Random] No tags'),
			array('reply', 10, 'Temporary reply'),
			array('moved', $this->moved_topic_id, 'Temporary moved post'),
		) as $post)
		{
			$sql = 'INSERT INTO phpbb_posts ' . $this->db->sql_build_array('INSERT', array(
				'topic_id' => $post[1],
				'forum_id' => 2,
				'post_subject' => $post[2],
				'post_text' => '',
			));
			$this->db->sql_query($sql);
			$this->post_ids[$post[0]] = (int) $this->db->sql_nextid();
		}

		$statement = $pdo->prepare('UPDATE phpbb_posts SET post_subject = ? WHERE post_id = ?');
		foreach (array(
			'both' => 'バグ 日本語 title',
			'reply' => 'バグ 返信 subject',
			'moved' => 'バグ 移動 topic',
		) as $post => $subject)
		{
			$statement->execute(array($subject, $this->post_ids[$post]));
		}

		foreach (array(
			10 => $this->post_ids['both'],
			11 => $this->post_ids['unrelated'],
			12 => $this->post_ids['php'],
			13 => $this->post_ids['random'],
			$this->moved_topic_id => $this->post_ids['moved'],
		) as $topic_id => $post_id)
		{
			$this->db->sql_query('UPDATE phpbb_topics
				SET topic_first_post_id = ' . $post_id . '
				WHERE topic_id = ' . $topic_id);
		}

		$statement = $pdo->prepare('UPDATE phpbb_topics
			SET topic_last_post_id = ?, topic_last_post_subject = ?
			WHERE topic_id = ?');
		foreach (array(
			array($this->post_ids['reply'], 'バグ 返信 subject', 10),
			array($this->post_ids['unrelated'], 'Unrelated reply subject', 11),
			array($this->post_ids['php'], 'PHP 8.4 PHP only', 12),
			array($this->post_ids['random'], '[Random] No tags', 13),
			array($this->post_ids['moved'], 'バグ 移動 topic', $this->moved_topic_id),
		) as $last_post)
		{
			$statement->execute($last_post);
		}

		$statement = $pdo->prepare('UPDATE phpbb_forums
			SET forum_last_post_id = ?, forum_last_post_subject = ?
			WHERE forum_id = 2');
		$statement->execute(array($this->post_ids['reply'], 'バグ 返信 subject'));
	}

	protected function tearDown(): void
	{
		$this->tools->sql_column_remove('phpbb_topics', 'topic_prefix_id');
		foreach (array('prefix_parent_id', 'prefix_left_id', 'prefix_right_id', 'prefix_parents', 'forum_id') as $column)
		{
			$this->tools->sql_column_remove('phpbb_topic_prefixes', $column);
		}
		parent::tearDown();
	}

	public function test_legacy_definitions_titles_subjects_and_idempotency()
	{
		$migration = $this->create_migration();
		$migration->migrate_legacy_data();
		$migration->migrate_legacy_data();

		self::assertSame('4A76A8', $this->field('SELECT prefix_color FROM phpbb_topic_prefixes WHERE prefix_id = 1', 'prefix_color'));
		self::assertSame(1, (int) $this->field('SELECT prefix_order FROM phpbb_topic_prefixes WHERE prefix_id = 1', 'prefix_order'));
		self::assertSame('日本語 title', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 10', 'topic_title'));
		self::assertSame('日本語 title', $this->field('SELECT post_subject FROM phpbb_posts WHERE post_id = ' . $this->post_ids['both'], 'post_subject'));
		self::assertSame('返信 subject', $this->field('SELECT topic_last_post_subject FROM phpbb_topics WHERE topic_id = 10', 'topic_last_post_subject'));
		self::assertSame('返信 subject', $this->field('SELECT forum_last_post_subject FROM phpbb_forums WHERE forum_id = 2', 'forum_last_post_subject'));
		self::assertSame('PHP only', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 12', 'topic_title'));
		self::assertSame('PHP only', $this->field('SELECT post_subject FROM phpbb_posts WHERE post_id = ' . $this->post_ids['php'], 'post_subject'));
		self::assertSame('PHP only', $this->field('SELECT topic_last_post_subject FROM phpbb_topics WHERE topic_id = 12', 'topic_last_post_subject'));
		self::assertSame('[Other] untouched', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 11', 'topic_title'));
		self::assertSame('Unrelated first post', $this->field('SELECT post_subject FROM phpbb_posts WHERE post_id = ' . $this->post_ids['unrelated'], 'post_subject'));
		self::assertSame('Unrelated reply subject', $this->field('SELECT topic_last_post_subject FROM phpbb_topics WHERE topic_id = 11', 'topic_last_post_subject'));
		self::assertSame('[Random] No tags', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 13', 'topic_title'));
		self::assertSame('バグ 返信 subject', $this->field('SELECT post_subject FROM phpbb_posts WHERE post_id = ' . $this->post_ids['reply'], 'post_subject'));
		self::assertSame('移動 topic', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = ' . $this->moved_topic_id, 'topic_title'));
		self::assertSame('移動 topic', $this->field('SELECT post_subject FROM phpbb_posts WHERE post_id = ' . $this->post_ids['moved'], 'post_subject'));
		self::assertSame('移動 topic', $this->field('SELECT topic_last_post_subject FROM phpbb_topics WHERE topic_id = ' . $this->moved_topic_id, 'topic_last_post_subject'));
		self::assertSame(0, (int) $this->field('SELECT COUNT(*) AS total FROM phpbb_topic_prefixes_topics WHERE topic_id = ' . $this->moved_topic_id, 'total'));
		self::assertSame(1, (int) $this->field('SELECT COUNT(*) AS total FROM phpbb_topic_prefixes_forums WHERE forum_id = 2 AND prefix_id = 4', 'total'));

		$result = $this->db->sql_query('SELECT COUNT(*) AS total FROM phpbb_topic_prefixes_topics WHERE topic_id = 10 AND prefix_id = 1');
		self::assertSame(1, (int) $this->db->sql_fetchfield('total'));
		$this->db->sql_freeresult($result);
	}

	public function test_shared_last_post_cleans_each_forum_and_queues_assignment_once()
	{
		$this->db->sql_query('DELETE FROM phpbb_topic_prefixes_topics
			WHERE topic_id = 10 AND prefix_id = 1');
		$statement = $this->getConnection()->getConnection()->prepare('UPDATE phpbb_forums
			SET forum_last_post_id = ?, forum_last_post_subject = ?
			WHERE forum_id = 3');
		$statement->execute(array($this->post_ids['reply'], 'バグ 返信 subject'));

		$this->create_migration()->migrate_legacy_data();

		self::assertSame(1, (int) $this->field('SELECT COUNT(*) AS total
			FROM phpbb_topic_prefixes_topics
			WHERE topic_id = 10 AND prefix_id = 1', 'total'));
		self::assertSame('返信 subject', $this->field('SELECT forum_last_post_subject
			FROM phpbb_forums WHERE forum_id = 2', 'forum_last_post_subject'));
		self::assertSame('返信 subject', $this->field('SELECT forum_last_post_subject
			FROM phpbb_forums WHERE forum_id = 3', 'forum_last_post_subject'));
	}

	public function test_split_prefix_does_not_reuse_standalone_tag_with_different_state()
	{
		$this->reset_legacy_data([
			['[DEV]', 0, 2],
			['[3.3][DEV]', 1, 3],
		], true);

		$this->create_migration()->migrate_legacy_data();

		self::assertSame(['[DEV]', '[3.3]', '[DEV]'], $this->tag_names());
		self::assertSame(1, (int) $this->field("SELECT MIN(prefix_id) AS prefix_id
			FROM phpbb_topic_prefixes WHERE prefix_tag = '[DEV]'", 'prefix_id'));
		self::assertSame(2, (int) $this->field("SELECT COUNT(*) AS total FROM phpbb_topic_prefixes WHERE prefix_tag = '[DEV]'", 'total'));
		self::assertSame(1, (int) $this->field("SELECT COUNT(*) AS total FROM phpbb_topic_prefixes
			WHERE prefix_tag = '[DEV]' AND prefix_enabled = 0", 'total'));
		self::assertSame(1, (int) $this->field("SELECT COUNT(*) AS total FROM phpbb_topic_prefixes
			WHERE prefix_tag = '[DEV]' AND prefix_enabled = 1", 'total'));
		self::assertSame(1, (int) $this->field('SELECT COUNT(*) AS total
			FROM phpbb_topic_prefixes_forums WHERE prefix_id = 1', 'total'));
		self::assertSame(['[DEV]'], $this->topic_tag_names(100));
		self::assertSame(['[3.3]', '[DEV]'], $this->topic_tag_names(101));
		self::assertSame(1, (int) $this->field("SELECT COUNT(*) AS total
			FROM phpbb_topic_prefixes_topics pt
			INNER JOIN phpbb_topic_prefixes p ON p.prefix_id = pt.prefix_id
			WHERE pt.topic_id = 101 AND p.prefix_tag = '[DEV]' AND p.prefix_enabled = 1", 'total'));
	}

	public function test_duplicate_standalone_definitions_with_different_states_remain_distinct()
	{
		$this->reset_legacy_data([
			['[CDB]', 1, 2],
			['[CDB]', 0, 3],
		], true);

		$this->create_migration()->migrate_legacy_data();

		self::assertSame(['[CDB]', '[CDB]'], $this->tag_names());
		self::assertSame(1, (int) $this->field('SELECT prefix_enabled FROM phpbb_topic_prefixes WHERE prefix_id = 1', 'prefix_enabled'));
		self::assertSame(0, (int) $this->field('SELECT prefix_enabled FROM phpbb_topic_prefixes WHERE prefix_id = 2', 'prefix_enabled'));
		self::assertSame(1, (int) $this->field('SELECT COUNT(*) AS total
			FROM phpbb_topic_prefixes_forums WHERE forum_id = 2 AND prefix_id = 1', 'total'));
		self::assertSame(1, (int) $this->field('SELECT COUNT(*) AS total
			FROM phpbb_topic_prefixes_forums WHERE forum_id = 3 AND prefix_id = 2', 'total'));
		self::assertSame(['[CDB]'], $this->topic_tag_names(100));
		self::assertSame(['[CDB]'], $this->topic_tag_names(101));
		self::assertSame(1, (int) $this->field('SELECT prefix_id FROM phpbb_topic_prefixes_topics WHERE topic_id = 100', 'prefix_id'));
		self::assertSame(2, (int) $this->field('SELECT prefix_id FROM phpbb_topic_prefixes_topics WHERE topic_id = 101', 'prefix_id'));
	}

	public function test_duplicate_standalone_definitions_with_same_state_are_consolidated()
	{
		$this->reset_legacy_data([
			['[CDB]', 1, 2],
			['[CDB]', 1, 3],
		], true);

		$migration = $this->create_migration();
		$migration->migrate_legacy_data();
		$migration->migrate_legacy_data();

		self::assertSame(['[CDB]'], $this->tag_names());
		self::assertSame(1, (int) $this->field("SELECT prefix_id FROM phpbb_topic_prefixes WHERE prefix_tag = '[CDB]'", 'prefix_id'));
		self::assertSame(0, (int) $this->field('SELECT COUNT(*) AS total
			FROM phpbb_topic_prefixes WHERE prefix_id = 2', 'total'));
		self::assertSame(2, (int) $this->field('SELECT COUNT(*) AS total
			FROM phpbb_topic_prefixes_forums WHERE prefix_id = 1', 'total'));
		self::assertSame(2, (int) $this->field('SELECT COUNT(*) AS total
			FROM phpbb_topic_prefixes_topics WHERE prefix_id = 1 AND topic_id IN (100, 101)', 'total'));
		self::assertSame('Topic 1', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 100', 'topic_title'));
		self::assertSame('Topic 2', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 101', 'topic_title'));
		self::assertSame('Topic 1', $this->field('SELECT post_subject FROM phpbb_posts WHERE post_id = 1000', 'post_subject'));
		self::assertSame('Topic 2', $this->field('SELECT post_subject FROM phpbb_posts WHERE post_id = 1001', 'post_subject'));
	}

	public function test_duplicate_standalone_definitions_in_same_forum_are_consolidated()
	{
		$this->reset_legacy_data([
			['[CDB]', 1, 2],
			['[CDB]', 1, 2],
		], true);

		$this->create_migration()->migrate_legacy_data();

		self::assertSame(['[CDB]'], $this->tag_names());
		self::assertSame(1, (int) $this->field('SELECT COUNT(*) AS total FROM phpbb_topic_prefixes_forums', 'total'));
		self::assertSame(2, (int) $this->field('SELECT COUNT(*) AS total
			FROM phpbb_topic_prefixes_topics WHERE prefix_id = 1', 'total'));
	}

	public function test_deleted_prefix_references_cannot_attach_to_generated_tags()
	{
		$this->reset_legacy_data([['[A][B]', 1, 2]], true);
		$this->insert_explicit_rows('phpbb_topic_prefixes', [[
			'prefix_id' => 5,
			'prefix_tag' => '[A]',
			'prefix_enabled' => 1,
			'prefix_parent_id' => 0,
			'prefix_left_id' => 0,
			'prefix_right_id' => 0,
			'prefix_parents' => '',
			'forum_id' => 0,
			'prefix_color' => '4A76A8',
			'prefix_order' => 1,
		]]);
		$topics = [];
		for ($orphan_id = 2; $orphan_id <= 20; $orphan_id++)
		{
			$topics[] = [
				'topic_id' => 200 + $orphan_id,
				'forum_id' => 2,
				'topic_title' => 'Orphan topic ' . $orphan_id,
				'topic_prefix_id' => $orphan_id,
				'topic_visibility' => ITEM_APPROVED,
				'topic_type' => POST_NORMAL,
			];
		}
		$this->insert_explicit_rows('phpbb_topics', $topics);

		$this->create_migration()->migrate_legacy_data();

		self::assertSame(0, (int) $this->field('SELECT COUNT(*) AS total
			FROM phpbb_topic_prefixes_topics
			WHERE topic_id >= 202 AND topic_id <= 220', 'total'));
		self::assertSame('Orphan topic 2', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 202', 'topic_title'));
		$tag_names = $this->topic_tag_names(100);
		sort($tag_names);
		self::assertSame(['[A]', '[B]'], $tag_names);
	}

	public function test_missing_forums_are_not_copied_to_tag_relationships()
	{
		$this->reset_legacy_data([
			['[CDB]', 1, 998],
			['[A][B]', 1, 999],
		]);

		$this->create_migration()->migrate_legacy_data();

		self::assertSame(['[CDB]', '[A]', '[B]'], $this->tag_names());
		self::assertSame(0, (int) $this->field('SELECT COUNT(*) AS total FROM phpbb_topic_prefixes_forums', 'total'));
	}

	public function test_shared_forum_last_post_is_cleaned_across_topic_batch_boundary()
	{
		$this->reset_legacy_data([['[A]', 1, 2]]);
		$topics = [];
		for ($offset = 0; $offset < 500; $offset++)
		{
			$topics[] = [
				'topic_id' => 1000 + $offset,
				'forum_id' => 2,
				'topic_title' => '[A] Topic ' . $offset,
				'topic_prefix_id' => 1,
				'topic_last_post_id' => $offset === 499 ? 9000 : 0,
				'topic_last_post_subject' => $offset === 499 ? '[A] Boundary subject' : '',
				'topic_visibility' => ITEM_APPROVED,
				'topic_type' => POST_NORMAL,
			];
		}
		$this->insert_explicit_rows('phpbb_topics', $topics);
		$statement = $this->getConnection()->getConnection()->prepare('UPDATE phpbb_forums
			SET forum_last_post_id = ?, forum_last_post_subject = ?
			WHERE forum_id IN (2, 3)');
		$statement->execute(array(9000, '[A] Boundary subject'));

		$this->create_migration()->migrate_legacy_data();

		self::assertSame(500, (int) $this->field('SELECT COUNT(*) AS total FROM phpbb_topic_prefixes_topics', 'total'));
		self::assertSame('Boundary subject', $this->field('SELECT forum_last_post_subject
			FROM phpbb_forums WHERE forum_id = 2', 'forum_last_post_subject'));
		self::assertSame('Boundary subject', $this->field('SELECT forum_last_post_subject
			FROM phpbb_forums WHERE forum_id = 3', 'forum_last_post_subject'));
		self::assertSame('Topic 499', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 1499', 'topic_title'));
	}

	public function test_duplicate_standalone_and_combined_definitions_become_shared_tags()
	{
		$this->reset_legacy_data([
			['[3.3][RC]', 1, 3],
			['[3.3][CDB]', 1, 3],
			['[CDB]', 1, 3],
			['[CDB]', 1, 2],
			['[3.3][RC]', 1, 2],
			['[3.3][CDB]', 1, 2],
		], true);

		$migration = $this->create_migration();
		$migration->migrate_legacy_data();
		$migration->migrate_legacy_data();

		$expected = ['[CDB]', '[3.3]', '[RC]'];
		$actual = $this->tag_names();
		sort($expected);
		sort($actual);
		self::assertSame($expected, $actual);
		self::assertSame(3, (int) $this->field("SELECT prefix_id FROM phpbb_topic_prefixes WHERE prefix_tag = '[CDB]'", 'prefix_id'));
		foreach (['[CDB]', '[3.3]', '[RC]'] as $name)
		{
			self::assertSame(2, (int) $this->field("SELECT COUNT(*) AS total
				FROM phpbb_topic_prefixes_forums pf
				INNER JOIN phpbb_topic_prefixes p ON p.prefix_id = pf.prefix_id
				WHERE p.prefix_tag = '" . $this->db->sql_escape($name) . "'", 'total'));
		}
		self::assertSame(['[3.3]', '[RC]'], $this->topic_tag_names(100));
		self::assertSame(['[3.3]', '[CDB]'], $this->topic_tag_names(101));
		self::assertSame(['[CDB]'], $this->topic_tag_names(102));
		self::assertSame(['[CDB]'], $this->topic_tag_names(103));
		self::assertSame(['[3.3]', '[RC]'], $this->topic_tag_names(104));
		self::assertSame(['[3.3]', '[CDB]'], $this->topic_tag_names(105));
		self::assertSame(10, (int) $this->field('SELECT COUNT(*) AS total FROM phpbb_topic_prefixes_topics', 'total'));
		for ($topic_id = 100; $topic_id <= 105; $topic_id++)
		{
			self::assertSame('Topic ' . ($topic_id - 99), $this->field('SELECT topic_title
				FROM phpbb_topics WHERE topic_id = ' . $topic_id, 'topic_title'));
		}
	}

	public function test_combined_bracket_prefixes_become_shared_tags()
	{
		$definitions = [
			['[3.3][DEV]', 0, 2],
			['[3.3][ALPHA]', 1, 2],
			['[3.3][BETA]', 1, 2],
			['[3.3][RC]', 1, 2],
			['[CDB]', 1, 2],
			['[4.0][DEV]', 1, 3],
			['[4.0][ALPHA]', 1, 3],
			['[4.0][BETA]', 1, 3],
			['[4.0][RC]', 1, 3],
		];
		$this->reset_legacy_data($definitions, true);

		$migration = $this->create_migration();
		$migration->migrate_legacy_data();
		$migration->migrate_legacy_data();

		self::assertSame(['[3.3]', '[DEV]', '[3.3]', '[ALPHA]', '[BETA]', '[RC]', '[CDB]', '[4.0]', '[DEV]'], $this->tag_names());
		self::assertSame(['[3.3]', '[DEV]'], $this->topic_tag_names(100));
		self::assertSame(['[CDB]'], $this->topic_tag_names(104));
		self::assertSame(['[4.0]', '[DEV]'], $this->topic_tag_names(105));
		self::assertSame(17, (int) $this->field('SELECT COUNT(*) AS total FROM phpbb_topic_prefixes_topics', 'total'));
		self::assertSame(2, (int) $this->field("SELECT COUNT(*) AS total FROM phpbb_topic_prefixes WHERE prefix_tag = '[DEV]'", 'total'));
		self::assertSame(1, (int) $this->field("SELECT COUNT(*) AS total FROM phpbb_topic_prefixes
			WHERE prefix_tag = '[DEV]' AND prefix_enabled = 0", 'total'));
		self::assertSame(1, (int) $this->field("SELECT COUNT(*) AS total FROM phpbb_topic_prefixes
			WHERE prefix_tag = '[DEV]' AND prefix_enabled = 1", 'total'));
		self::assertSame(2, (int) $this->field("SELECT COUNT(*) AS total
			FROM phpbb_topic_prefixes_forums pf
			INNER JOIN phpbb_topic_prefixes p ON p.prefix_id = pf.prefix_id
			WHERE p.prefix_tag = '[DEV]'", 'total'));
		self::assertSame(0, (int) $this->field("SELECT COUNT(*) AS total FROM phpbb_topic_prefixes WHERE prefix_tag = '[3.3][DEV]'", 'total'));
		self::assertSame(5, (int) $this->field("SELECT prefix_id FROM phpbb_topic_prefixes WHERE prefix_tag = '[CDB]'", 'prefix_id'));
		self::assertSame('Topic 1', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 100', 'topic_title'));
		self::assertSame('Topic 1', $this->field('SELECT post_subject FROM phpbb_posts WHERE post_id = 1000', 'post_subject'));
	}

	public function test_only_complete_valid_bracket_sequences_are_split()
	{
		$oversized = '[' . str_repeat('x', 51) . '][B]';
		$this->reset_legacy_data([
			['[CDB]', 1, 2],
			['[ A ][B]', 1, 2],
			['PHP 8.4', 1, 2],
			['[A] extra', 1, 2],
			['[A] [B]', 1, 2],
			['[A][ ]', 1, 2],
			[$oversized, 1, 2],
			['[DEV][dev]', 1, 2],
			['[日本語][😇]', 1, 2],
		]);

		$this->create_migration()->migrate_legacy_data();

		$expected = [
			'[CDB]',
			'[ A ]',
			'[B]',
			'PHP 8.4',
			'[A] extra',
			'[A] [B]',
			'[A][ ]',
			$oversized,
			'[DEV]',
			'[dev]',
			'[日本語]',
			'[😇]',
		];
		$actual = $this->tag_names();
		sort($expected);
		sort($actual);
		self::assertSame($expected, $actual);
		self::assertSame(1, (int) $this->field("SELECT prefix_id FROM phpbb_topic_prefixes WHERE prefix_tag = '[CDB]'", 'prefix_id'));
		self::assertSame(3, (int) $this->field("SELECT prefix_id FROM phpbb_topic_prefixes WHERE prefix_tag = 'PHP 8.4'", 'prefix_id'));
		self::assertSame(4, (int) $this->field("SELECT prefix_id FROM phpbb_topic_prefixes WHERE prefix_tag = '[A] extra'", 'prefix_id'));
	}

	public function test_combined_prefix_relationships_cross_batch_boundary()
	{
		$this->reset_legacy_data([['[A][B]', 1, 2]]);
		$topics = [];
		$posts = [];
		for ($offset = 0; $offset < 501; $offset++)
		{
			$topic_id = 1000 + $offset;
			$post_id = 2000 + $offset;
			$subject = '[A][B] Topic ' . $offset;
			$topics[] = [
				'topic_id' => $topic_id,
				'forum_id' => 2,
				'topic_title' => $subject,
				'topic_prefix_id' => 1,
				'topic_first_post_id' => $post_id,
				'topic_visibility' => ITEM_APPROVED,
				'topic_type' => POST_NORMAL,
			];
			$posts[] = [
				'post_id' => $post_id,
				'topic_id' => $topic_id,
				'forum_id' => 2,
				'post_subject' => $subject,
				'post_text' => '',
			];
		}
		$this->insert_explicit_rows('phpbb_topics', $topics);
		$this->insert_explicit_rows('phpbb_posts', $posts);

		$this->create_migration()->migrate_legacy_data();

		self::assertSame(1002, (int) $this->field('SELECT COUNT(*) AS total FROM phpbb_topic_prefixes_topics', 'total'));
		self::assertSame('Topic 0', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 1000', 'topic_title'));
		self::assertSame('Topic 500', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 1500', 'topic_title'));
		self::assertSame(0, (int) $this->field("SELECT COUNT(*) AS total FROM phpbb_topic_prefixes WHERE prefix_tag = '[A][B]'", 'total'));
	}

	public function test_legacy_request_escaping_is_decoded_without_rewriting_storage(): void
	{
		$this->reset_legacy_data([
			['[R&amp;D][X]', 1, 2],
			['Literal &amp;amp;', 1, 2],
		], true);

		$migration = $this->create_migration();
		$migration->migrate_legacy_data();
		self::assertSame('Topic 1', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 100', 'topic_title'));
		self::assertSame('Topic 1', $this->field('SELECT post_subject FROM phpbb_posts WHERE post_id = 1000', 'post_subject'));
		self::assertSame(['[R&D]', '[X]', 'Literal &amp;'], $this->tag_names());
		self::assertSame('[R&amp;D]', $this->field("SELECT prefix_tag FROM phpbb_topic_prefixes WHERE prefix_tag = '[R&amp;D]'", 'prefix_tag'));
	}

	protected function create_migration()
	{
		global $phpbb_root_path, $phpEx;
		return new \phpbb\topicprefixes\migrations\v200_data(
			new \phpbb\config\config(array()),
			$this->db,
			$this->tools,
			$phpbb_root_path,
			$phpEx,
			'phpbb_'
		);
	}

	protected function field($sql, $field)
	{
		$result = $this->db->sql_query($sql);
		$value = $this->db->sql_fetchfield($field);
		$this->db->sql_freeresult($result);
		return $value;
	}

	protected function reset_legacy_data(array $definitions, bool $with_topics = false): void
	{
		$this->db->sql_query('DELETE FROM phpbb_topic_prefixes_topics');
		$this->db->sql_query('DELETE FROM phpbb_topic_prefixes_forums');
		$this->db->sql_query('DELETE FROM phpbb_posts');
		$this->db->sql_query('DELETE FROM phpbb_topics');
		$this->db->sql_query('DELETE FROM phpbb_topic_prefixes');

		foreach ($definitions as $offset => $definition)
		{
			$prefix_id = $offset + 1;
			$this->insert_explicit_rows('phpbb_topic_prefixes', [[
				'prefix_id' => $prefix_id,
				'prefix_tag' => utf8_encode_ucr($definition[0]),
				'prefix_enabled' => $definition[1],
				'prefix_parent_id' => 0,
				'prefix_left_id' => $prefix_id * 2 - 1,
				'prefix_right_id' => $prefix_id * 2,
				'prefix_parents' => '',
				'forum_id' => $definition[2],
				'prefix_color' => '4A76A8',
				'prefix_order' => 0,
			]]);

			if (!$with_topics)
			{
				continue;
			}

			$topic_id = 100 + $offset;
			$post_id = 1000 + $offset;
			$subject = $definition[0] . ' Topic ' . ($offset + 1);
			$this->insert_explicit_rows('phpbb_posts', [[
				'post_id' => $post_id,
				'topic_id' => $topic_id,
				'forum_id' => $definition[2],
				'post_subject' => $subject,
				'post_text' => '',
			]]);
			$this->insert_explicit_rows('phpbb_topics', [[
				'topic_id' => $topic_id,
				'forum_id' => $definition[2],
				'topic_title' => $subject,
				'topic_prefix_id' => $prefix_id,
				'topic_first_post_id' => $post_id,
				'topic_visibility' => ITEM_APPROVED,
				'topic_type' => POST_NORMAL,
			]]);
		}

		if ($this->db->get_sql_layer() === 'postgres')
		{
			$this->db->sql_query("SELECT SETVAL('phpbb_topic_prefixes_seq',
				(SELECT COALESCE(MAX(prefix_id), 0) + 1 FROM phpbb_topic_prefixes), false)");
		}
	}

	protected function tag_names(): array
	{
		$result = $this->db->sql_query('SELECT prefix_tag
			FROM phpbb_topic_prefixes
			ORDER BY prefix_order ASC, prefix_id ASC');
		$names = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$names[] = \phpbb\topicprefixes\tags\manager::decode_name($row['prefix_tag']);
		}
		$this->db->sql_freeresult($result);

		return $names;
	}

	protected function topic_tag_names(int $topic_id): array
	{
		$result = $this->db->sql_query('SELECT p.prefix_tag
			FROM phpbb_topic_prefixes_topics pt
			INNER JOIN phpbb_topic_prefixes p ON p.prefix_id = pt.prefix_id
			WHERE pt.topic_id = ' . (int) $topic_id . '
			ORDER BY p.prefix_order ASC, p.prefix_id ASC');
		$names = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$names[] = \phpbb\topicprefixes\tags\manager::decode_name($row['prefix_tag']);
		}
		$this->db->sql_freeresult($result);

		return $names;
	}
}
