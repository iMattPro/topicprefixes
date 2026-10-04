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

class filter_test extends tags_base
{
	/**
	 * Create filter with configurable forum permissions and visibility.
	 *
	 * @param array $readable_forums Forums with f_read
	 * @param array $listable_forums Forums with f_list_topics
	 * @param bool  $see_hidden       Whether hidden topics are visible
	 * @return \phpbb\topicprefixes\tags\filter
	 */
	protected function create_filter(array $readable_forums = [2, 3], array $listable_forums = [2, 3], bool $see_hidden = true)
	{
		$visibility = $this->getMockBuilder('\phpbb\content_visibility')->disableOriginalConstructor()->getMock();
		$visibility->method('get_visibility_sql')->willReturnCallback(static function ($mode, $forum_id, $alias) use ($see_hidden) {
			return $see_hidden ? '1=1' : $alias . 'topic_visibility = ' . ITEM_APPROVED;
		});
		$visibility->method('get_forums_visibility_sql')->willReturnCallback(static function ($mode, $forum_ids, $alias) use ($see_hidden) {
			return $see_hidden ? '1=1' : $alias . 'topic_visibility = ' . ITEM_APPROVED;
		});
		$auth = $this->getMockBuilder('\phpbb\auth\auth')->disableOriginalConstructor()->getMock();
		$auth->method('acl_getf')->willReturnCallback(static function ($permission) use ($readable_forums, $listable_forums) {
			$forums = $permission === 'f_read' ? $readable_forums : $listable_forums;
			return array_fill_keys($forums, [$permission => true]);
		});

		return new \phpbb\topicprefixes\tags\filter($this->db, $visibility, $auth, 'phpbb_topic_prefixes_topics', 'phpbb_topics');
	}

	/**
	 * Test filtering by one tag.
	 */
	public function test_single_tag_filter()
	{
		$filter = $this->create_filter();
		self::assertStringNotContainsString('GROUP BY', $filter->condition('t', [1]));
		self::assertSame(array(10, 11), $this->query_ids(array(1)));
	}

	/**
	 * Test multiple selected tags use AND semantics.
	 */
	public function test_multiple_tags_use_and_semantics_without_duplicates()
	{
		self::assertStringContainsString('GROUP BY', $this->create_filter()->condition('t', [1, 2]));
		self::assertSame(array(10), $this->query_ids(array(1, 2)));
	}

	/**
	 * Test filtered count matches result rows.
	 */
	public function test_filtered_count_matches_rows()
	{
		self::assertSame(2, $this->create_filter()->count_topics(2, array(1)));
		self::assertSame(1, $this->create_filter()->count_topics(2, array(1, 2)));
	}

	/**
	 * Test empty filters and topic-age count condition.
	 */
	public function test_empty_filter_and_sort_days(): void
	{
		$filter = $this->create_filter();

		self::assertSame('1=1', $filter->condition('t', [0, 0]));
		self::assertSame(2, $filter->count_topics(2, [1], 7));
		self::assertSame([], $filter->get_visible_tag_ids_for_forum(2, [0, 'invalid']));
	}

	/**
	 * Test shadow topics filter using destination-topic assignments.
	 */
	public function test_shadow_topic_filter_uses_destination_tags(): void
	{
		$this->db->sql_query('UPDATE phpbb_topics SET topic_moved_id = 10 WHERE topic_id = 13');

		self::assertSame([10, 11, 13], $this->query_ids([1]));
		self::assertSame(3, $this->create_filter()->count_topics(2, [1]));
	}

	public function test_private_global_announcement_tag_is_not_retained(): void
	{
		$this->db->sql_query('UPDATE phpbb_topics
			SET forum_id = 3, topic_type = ' . POST_GLOBAL . '
			WHERE topic_id = 10');
		$this->db->sql_query('INSERT INTO phpbb_topic_prefixes_topics ' . $this->db->sql_build_array('INSERT', [
			'topic_id' => 10,
			'prefix_id' => 4,
		]));

		self::assertSame([], $this->create_filter([2], [2], false)->get_visible_tag_ids_for_forum(2, [4]));
		self::assertSame([4], $this->create_filter([2, 3], [2, 3], false)->get_visible_tag_ids_for_forum(2, [4]));
	}

	public function test_hidden_local_topic_tag_requires_visibility(): void
	{
		$this->db->sql_query('UPDATE phpbb_topics
			SET topic_visibility = ' . ITEM_UNAPPROVED . '
			WHERE topic_id = 13');
		$this->db->sql_query('INSERT INTO phpbb_topic_prefixes_topics ' . $this->db->sql_build_array('INSERT', [
			'topic_id' => 13,
			'prefix_id' => 4,
		]));

		self::assertSame([], $this->create_filter([2], [2], false)->get_visible_tag_ids_for_forum(2, [4]));
		self::assertSame([4], $this->create_filter([2], [2], true)->get_visible_tag_ids_for_forum(2, [4]));
	}

	public function test_shadow_tag_requires_visible_readable_destination(): void
	{
		$this->db->sql_query('UPDATE phpbb_topics SET forum_id = 3 WHERE topic_id = 12');
		$this->db->sql_query('UPDATE phpbb_topics SET topic_moved_id = 12 WHERE topic_id = 13');
		$this->db->sql_query('INSERT INTO phpbb_topic_prefixes_topics ' . $this->db->sql_build_array('INSERT', [
			'topic_id' => 12,
			'prefix_id' => 4,
		]));

		self::assertSame([], $this->create_filter([2], [2], false)->get_visible_tag_ids_for_forum(2, [4]));
		self::assertSame([], $this->create_filter([2, 3], [2], false)->get_visible_tag_ids_for_forum(2, [4]));
		self::assertSame([4], $this->create_filter([2, 3], [2, 3], false)->get_visible_tag_ids_for_forum(2, [4]));

		$this->db->sql_query('UPDATE phpbb_topics
			SET topic_visibility = ' . ITEM_UNAPPROVED . '
			WHERE topic_id = 12');
		self::assertSame([], $this->create_filter([2, 3], [2, 3], false)->get_visible_tag_ids_for_forum(2, [4]));

		$this->db->sql_query('UPDATE phpbb_topics SET topic_visibility = ' . ITEM_APPROVED . ' WHERE topic_id = 12');
		$this->db->sql_query('UPDATE phpbb_topics SET topic_visibility = ' . ITEM_UNAPPROVED . ' WHERE topic_id = 13');
		self::assertSame([], $this->create_filter([2, 3], [2, 3], false)->get_visible_tag_ids_for_forum(2, [4]));
	}

	/**
	 * Query topic identifiers matching tags.
	 *
	 * @param array $tag_ids Tag identifiers
	 * @return array Topic identifiers
	 */
	protected function query_ids(array $tag_ids)
	{
		$sql = 'SELECT t.topic_id FROM phpbb_topics t WHERE t.forum_id = 2 AND ' . $this->create_filter()->condition('t', $tag_ids) . ' ORDER BY t.topic_id';
		$result = $this->db->sql_query($sql);
		$ids = array_map('intval', array_column($this->db->sql_fetchrowset($result), 'topic_id'));
		$this->db->sql_freeresult($result);
		return $ids;
	}
}
