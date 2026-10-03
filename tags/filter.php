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

use phpbb\auth\auth;
use phpbb\content_visibility;
use phpbb\db\driver\driver_interface;

/**
 * Viewforum topic tag query filter.
 */
class filter
{
	/** @var driver_interface */
	protected $db;
	/** @var content_visibility */
	protected $visibility;
	/** @var auth */
	protected $auth;

	/** @var string Topic/tag map table */
	protected $topic_map_table;

	/** @var string Topics table */
	protected $topics_table;

	/**
	 * Constructor.
	 *
	 * @param driver_interface  $db              Database connection
	 * @param content_visibility $visibility      Content visibility service
	 * @param auth               $auth            Permission service
	 * @param string             $topic_map_table Topic/tag map table
	 * @param string             $topics_table    Topics table
	 */
	public function __construct(driver_interface $db, content_visibility $visibility, auth $auth, $topic_map_table, $topics_table)
	{
		$this->db = $db;
		$this->visibility = $visibility;
		$this->auth = $auth;
		$this->topic_map_table = $topic_map_table;
		$this->topics_table = $topics_table;
	}

	/**
	 * Get requested tag IDs assigned to topics visible in one forum.
	 *
	 * Global announcements must come from readable forums. Move shadows must
	 * point to visible destinations the user can read and list.
	 *
	 * @param int   $forum_id     Forum identifier
	 * @param array $candidate_ids Tag identifiers to examine
	 * @return array Visible tag identifiers
	 */
	public function get_visible_tag_ids_for_forum(int $forum_id, array $candidate_ids): array
	{
		$candidate_ids = array_values(array_unique(array_filter(array_map('intval', $candidate_ids))));
		if (!$candidate_ids)
		{
			return [];
		}

		$readable_forums = array_keys($this->auth->acl_getf('f_read', true));
		$listable_forums = array_keys($this->auth->acl_getf('f_list_topics', true));
		$shadow_forums = array_values(array_intersect($readable_forums, $listable_forums));
		$local_visibility = $this->visibility->get_visibility_sql('topic', $forum_id, 't.');

		$global_condition = '1=0';
		if ($readable_forums)
		{
			$global_condition = $this->db->sql_in_set('t.forum_id', $readable_forums) . '
				AND ' . $this->visibility->get_forums_visibility_sql('topic', $readable_forums, 't.');
		}

		$shadow_condition = '1=0';
		if ($shadow_forums)
		{
			$shadow_condition = $this->db->sql_in_set('d.forum_id', $shadow_forums) . '
				AND ' . $this->visibility->get_forums_visibility_sql('topic', $shadow_forums, 'd.');
		}

		$effective_topic_id = $this->db->sql_case(
			't.topic_moved_id <> 0',
			't.topic_moved_id',
			't.topic_id'
		);
		$sql = 'SELECT DISTINCT pt.prefix_id
			FROM ' . $this->topics_table . ' t
			LEFT JOIN ' . $this->topics_table . ' d
				ON d.topic_id = t.topic_moved_id
			INNER JOIN ' . $this->topic_map_table . ' pt
				ON pt.topic_id = ' . $effective_topic_id . '
			WHERE ' . $this->db->sql_in_set('pt.prefix_id', $candidate_ids) . '
				AND (
					(t.forum_id = ' . $forum_id . '
						AND t.topic_moved_id = 0
						AND ' . $local_visibility . ')
					OR (t.topic_type = ' . POST_GLOBAL . '
						AND ' . $global_condition . ')
					OR (t.forum_id = ' . $forum_id . '
						AND t.topic_moved_id <> 0
						AND ' . $local_visibility . '
						AND ' . $shadow_condition . ')
				)
			ORDER BY pt.prefix_id ASC';
		$result = $this->db->sql_query($sql);
		$tag_ids = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$tag_ids[] = (int) $row['prefix_id'];
		}
		$this->db->sql_freeresult($result);

		return $tag_ids;
	}

	/**
	 * Build SQL condition requiring every selected tag.
	 *
	 * @param string $topic_alias Topics table alias
	 * @param array  $tag_ids     Selected tag identifiers
	 * @return string SQL condition
	 */
	public function condition(string $topic_alias, array $tag_ids): string
	{
		$tag_ids = array_values(array_unique(array_filter(array_map('intval', $tag_ids))));
		if (!$tag_ids)
		{
			return '1=1';
		}

		$topic_id = $this->db->sql_case(
			$topic_alias . '.topic_moved_id <> 0',
			$topic_alias . '.topic_moved_id',
			$topic_alias . '.topic_id'
		);

		$subquery = 'SELECT tpf.topic_id
			FROM ' . $this->topic_map_table . ' tpf
			WHERE ' . $this->db->sql_in_set('tpf.prefix_id', $tag_ids);
		if (count($tag_ids) > 1)
		{
			$subquery .= '
			GROUP BY tpf.topic_id
			HAVING COUNT(tpf.prefix_id) = ' . count($tag_ids);
		}

		return $topic_id . ' IN (
			' . $subquery . '
		)';
	}

	/**
	 * Count visible forum topics matching selected tags.
	 *
	 * @param int   $forum_id Forum identifier
	 * @param array $tag_ids  Selected tag identifiers
	 * @param int   $sort_days Age filter in days
	 * @return int Matching topic count
	 */
	public function count_topics(int $forum_id, array $tag_ids, int $sort_days = 0): int
	{
		$where = 't.forum_id = ' . (int) $forum_id . '
			AND ' . $this->condition('t', $tag_ids) . '
			AND ' . $this->visibility->get_visibility_sql('topic', (int) $forum_id, 't.');
		if ($sort_days)
		{
			$min_time = time() - ((int) $sort_days * 86400);
			$where .= ' AND (t.topic_last_post_time >= ' . $min_time . '
				OR t.topic_type = ' . POST_ANNOUNCE . '
				OR t.topic_type = ' . POST_GLOBAL . ')';
		}

		$sql = 'SELECT COUNT(t.topic_id) AS num_topics
			FROM ' . $this->topics_table . ' t
			WHERE ' . $where;
		$result = $this->db->sql_query($sql);
		$count = (int) $this->db->sql_fetchfield('num_topics');
		$this->db->sql_freeresult($result);

		return $count;
	}
}
