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

class repairer_test extends tags_base
{
	public function test_split_reuses_tags_deduplicates_relationships_and_cleans_exact_titles(): void
	{
		$this->db->sql_query("UPDATE phpbb_topic_prefixes SET prefix_tag = '(A)(B)' WHERE prefix_id = 1");
		$this->db->sql_query("UPDATE phpbb_topic_prefixes SET prefix_tag = 'B', prefix_enabled = 0 WHERE prefix_id = 2");
		$this->db->sql_query("UPDATE phpbb_topics
			SET topic_title = '(A)(B) Topic', topic_first_post_id = 100, topic_last_post_id = 100,
				topic_last_post_subject = '(A)(B) Topic'
			WHERE topic_id = 10");
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = '(A)(B)Different' WHERE topic_id = 11");
		$this->db->sql_query("INSERT INTO phpbb_posts (post_id, topic_id, forum_id, post_subject, post_text)
			VALUES (100, 10, 2, '(A)(B) Topic', '')");
		$this->db->sql_query("UPDATE phpbb_forums
			SET forum_last_post_id = 100, forum_last_post_subject = '(A)(B) Topic'
			WHERE forum_id = 2");
		$this->create_tag_manager()->get_tags();

		$preview = $this->create_repairer()->preview(1, ['A', 'B']);
		self::assertSame(2, $preview['topic_count']);
		self::assertSame([
			'topic_title' => 1,
			'post_subject' => 1,
			'topic_last_post_subject' => 1,
			'forum_last_post_subject' => 1,
		], $preview['cleanup']);
		self::assertFalse($preview['targets'][0]['existing']);
		self::assertTrue($preview['targets'][1]['existing']);
		self::assertSame(2, $preview['targets'][1]['prefix_id']);

		$result = $this->create_repairer()->repair(1, ['A', 'B']);
		$a_id = (int) $result['targets'][0]['prefix_id'];
		self::assertGreaterThan(4, $a_id);
		self::assertSame(2, $result['targets'][1]['prefix_id']);
		self::assertSame(0, $this->count_rows('phpbb_topic_prefixes', 'prefix_id = 1'));
		self::assertSame(1, $this->count_rows('phpbb_topic_prefixes', "prefix_id = $a_id AND prefix_color = 'D4351C'"));
		self::assertSame(1, $this->count_rows('phpbb_topic_prefixes', 'prefix_id = 2 AND prefix_enabled = 1'));
		self::assertSame(1, $this->count_rows('phpbb_topic_prefixes_topics', "topic_id = 10 AND prefix_id = $a_id"));
		self::assertSame(1, $this->count_rows('phpbb_topic_prefixes_topics', 'topic_id = 10 AND prefix_id = 2'));
		self::assertSame(1, $this->count_rows('phpbb_topic_prefixes_topics', "topic_id = 11 AND prefix_id = $a_id"));
		self::assertSame(1, $this->count_rows('phpbb_topic_prefixes_topics', 'topic_id = 11 AND prefix_id = 2'));
		self::assertSame(2, $this->count_rows('phpbb_topic_prefixes_forums', "prefix_id = $a_id"));
		self::assertSame(2, $this->count_rows('phpbb_topic_prefixes_forums', 'prefix_id = 2'));
		self::assertSame('Topic', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 10', 'topic_title'));
		self::assertSame('(A)(B)Different', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 11', 'topic_title'));
		self::assertSame('Topic', $this->field('SELECT post_subject FROM phpbb_posts WHERE post_id = 100', 'post_subject'));
		self::assertSame('Topic', $this->field('SELECT topic_last_post_subject FROM phpbb_topics WHERE topic_id = 10', 'topic_last_post_subject'));
		self::assertSame('Topic', $this->field('SELECT forum_last_post_subject FROM phpbb_forums WHERE forum_id = 2', 'forum_last_post_subject'));
		self::assertArrayNotHasKey(1, $this->create_tag_manager()->get_tags());
		self::assertArrayHasKey($a_id, $this->create_tag_manager()->get_tags());
	}

	public function test_one_target_merges_without_disturbing_other_topic_tags(): void
	{
		$result = $this->create_repairer()->repair(1, ['Other']);

		self::assertSame(4, $result['targets'][0]['prefix_id']);
		self::assertSame(0, $this->count_rows('phpbb_topic_prefixes', 'prefix_id = 1'));
		self::assertSame(1, $this->count_rows('phpbb_topic_prefixes_topics', 'topic_id = 10 AND prefix_id = 2'));
		self::assertSame(1, $this->count_rows('phpbb_topic_prefixes_topics', 'topic_id = 10 AND prefix_id = 4'));
		self::assertSame(1, $this->count_rows('phpbb_topic_prefixes_topics', 'topic_id = 11 AND prefix_id = 4'));
		self::assertSame(2, $this->count_rows('phpbb_topic_prefixes_forums', 'prefix_id = 4'));
	}

	public function test_unicode_and_case_sensitive_targets_remain_distinct(): void
	{
		$this->db->sql_query("UPDATE phpbb_topic_prefixes SET prefix_tag = 'Combined' WHERE prefix_id = 3");

		$result = $this->create_repairer()->repair(3, ['DEV', 'dev', '😇']);
		self::assertSame(['DEV', 'dev', '😇'], array_column($result['targets'], 'prefix_tag'));
		self::assertSame(1, $this->count_rows('phpbb_topic_prefixes', "prefix_tag = 'DEV'"));
		self::assertSame(1, $this->count_rows('phpbb_topic_prefixes', "prefix_tag = 'dev'"));
		self::assertSame(1, $this->count_rows('phpbb_topic_prefixes', "prefix_tag = '&#128519;'"));
	}

	public function test_unicode_source_cannot_target_equivalent_display_name(): void
	{
		$this->db->sql_query("UPDATE phpbb_topic_prefixes SET prefix_tag = '&#128519;' WHERE prefix_id = 3");
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Replacement tag cannot equal the source tag.');

		$this->create_repairer()->preview(3, ['😇']);
	}

	public function test_repairs_topics_across_batch_boundary(): void
	{
		$this->db->sql_query('DELETE FROM phpbb_topic_prefixes_topics');
		$this->db->sql_query('DELETE FROM phpbb_topics');
		$topics = [];
		$assignments = [];
		for ($offset = 0; $offset < 501; $offset++)
		{
			$topic_id = 1000 + $offset;
			$topics[] = [
				'topic_id' => $topic_id,
				'forum_id' => 2,
				'topic_title' => 'Bug Topic ' . $offset,
				'topic_visibility' => ITEM_APPROVED,
				'topic_type' => POST_NORMAL,
			];
			$assignments[] = ['topic_id' => $topic_id, 'prefix_id' => 1];
		}
		$this->db->sql_multi_insert('phpbb_topics', $topics);
		$this->db->sql_multi_insert('phpbb_topic_prefixes_topics', $assignments);

		$result = $this->create_repairer()->repair(1, ['Defect']);
		$target_id = (int) $result['targets'][0]['prefix_id'];
		self::assertSame(501, $result['topic_count']);
		self::assertSame(501, $result['cleanup']['topic_title']);
		self::assertSame(501, $this->count_rows('phpbb_topic_prefixes_topics', 'prefix_id = ' . $target_id));
		self::assertSame('Topic 0', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 1000', 'topic_title'));
		self::assertSame('Topic 500', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 1500', 'topic_title'));
	}

	public function test_empty_source_name_never_strips_leading_spaces(): void
	{
		$this->db->sql_query("UPDATE phpbb_topic_prefixes SET prefix_tag = '' WHERE prefix_id = 1");
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = ' Leading space' WHERE topic_id = 10");

		$result = $this->create_repairer()->repair(1, ['Repaired']);
		self::assertSame(0, $result['cleanup']['topic_title']);
		self::assertSame(' Leading space', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 10', 'topic_title'));
	}

	/**
	 * @dataProvider invalid_replacement_provider
	 */
	public function test_invalid_replacements_are_rejected(array $replacements, string $message): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		$this->create_repairer()->preview(1, $replacements);
	}

	public function invalid_replacement_provider(): array
	{
		return [
			'none' => [[], 'Enter at least one replacement tag.'],
			'empty' => [[''], 'Replacement tag is empty or too long.'],
			'too long' => [[str_repeat('x', 51)], 'Replacement tag is empty or too long.'],
			'source' => [['Bug'], 'Replacement tag cannot equal the source tag.'],
			'duplicate' => [['A', 'A'], 'Replacement tags must be unique.'],
		];
	}

	protected function create_repairer(): \phpbb\topicprefixes\tags\repairer
	{
		return new \phpbb\topicprefixes\tags\repairer(
			$this->db,
			$this->cache,
			'phpbb_topic_prefixes',
			'phpbb_topic_prefixes_forums',
			'phpbb_topic_prefixes_topics',
			'phpbb_topics',
			'phpbb_posts',
			'phpbb_forums'
		);
	}

	protected function count_rows(string $table, string $where): int
	{
		return (int) $this->field("SELECT COUNT(*) AS total FROM $table WHERE $where", 'total');
	}

	protected function field(string $sql, string $field)
	{
		$result = $this->db->sql_query($sql);
		$value = $this->db->sql_fetchfield($field);
		$this->db->sql_freeresult($result);
		return $value;
	}
}
