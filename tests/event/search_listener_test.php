<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tests\event;

class search_listener_test extends \phpbb_test_case
{
	public function test_subscribed_events(): void
	{
		self::assertSame([
			'core.search_native_by_keyword_modify_search_key',
			'core.search_native_by_author_modify_search_key',
			'core.search_mysql_by_keyword_modify_search_key',
			'core.search_mysql_by_author_modify_search_key',
			'core.search_postgres_by_keyword_modify_search_key',
			'core.search_postgres_by_author_modify_search_key',
			'core.search_modify_param_after',
			'core.get_unread_topics_modify_sql',
			'core.search_modify_url_parameters',
			'core.search_modify_rowset',
			'core.search_modify_tpl_ary',
		], array_keys(\phpbb\topicprefixes\event\search_listener::getSubscribedEvents()));
	}

	/**
	 * @dataProvider predefined_search_data
	 */
	public function test_predefined_search_adds_tag_condition_before_sorting(string $search_id, string $show_results, string $topic_id): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request) = $this->listener();
		$request->method('variable')->with('tags', '')->willReturn('1');
		$manager->expects(self::once())->method('get_tags_by_ids')->with([1])->willReturn([1 => ['prefix_id' => 1]]);
		$filter->expects(self::once())->method('topic_id_condition')->with($topic_id, [1])->willReturn('TAG_CONDITION');

		$event = new \phpbb\event\data([
			'search_id' => $search_id,
			'show_results' => $show_results,
			'sql' => 'SELECT result_id FROM result_table WHERE VISIBLE ORDER BY result_id DESC',
		]);
		$listener->filter_predefined_search($event);

		self::assertSame(
			'SELECT result_id FROM result_table WHERE VISIBLE AND TAG_CONDITION ORDER BY result_id DESC',
			$event['sql']
		);
	}

	public function predefined_search_data(): array
	{
		return [
			'active topics' => ['active_topics', 'topics', 't.topic_id'],
			'new topics' => ['newposts', 'topics', 't.topic_id'],
			'new posts' => ['newposts', 'posts', 'p.topic_id'],
			'unanswered topics' => ['unanswered', 'topics', 't.topic_id'],
			'unanswered posts' => ['unanswered', 'posts', 'p.topic_id'],
		];
	}

	public function test_unread_search_filters_get_unread_topics_query(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request) = $this->listener();
		$request->method('variable')->with('tags', '')->willReturn('1');
		$manager->expects(self::once())->method('get_tags_by_ids')->with([1])->willReturn([1 => ['prefix_id' => 1]]);
		$filter->expects(self::once())->method('topic_id_condition')->with('t.topic_id', [1])->willReturn('TAG_CONDITION');

		$listener->filter_predefined_search(new \phpbb\event\data([
			'search_id' => 'unreadposts',
			'show_results' => 'topics',
			'sql' => '',
		]));
		$event = new \phpbb\event\data([
			'sql_array' => ['WHERE' => 'UNREAD AND VISIBLE ORDER BY t.topic_last_post_time DESC'],
		]);
		$listener->filter_unread_topics($event);

		self::assertSame(
			'UNREAD AND VISIBLE AND TAG_CONDITION ORDER BY t.topic_last_post_time DESC',
			$event['sql_array']['WHERE']
		);
	}

	public function test_topic_backend_intersects_visibility_and_changes_cache_key(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request) = $this->listener();
		$request->method('variable')->with('tags', '')->willReturn('2,1,2');
		$manager->expects(self::once())->method('get_tags_by_ids')->with([2, 1])->willReturn([
			1 => ['prefix_id' => 1],
			2 => ['prefix_id' => 2],
		]);
		$filter->expects(self::once())->method('topic_id_condition')->with('p.topic_id', [1, 2])->willReturn('TAG_CONDITION');

		$event = new \phpbb\event\data([
			'type' => 'topics',
			'post_visibility' => 'CORE_VISIBILITY',
			'search_key_array' => ['original'],
		]);
		$listener->filter_backend($event);

		self::assertSame('(CORE_VISIBILITY) AND TAG_CONDITION', $event['post_visibility']);
		self::assertSame(['original', 'topicprefixes:1,2'], $event['search_key_array']);
	}

	public function test_post_backend_is_filtered_and_invalid_tags_do_not_change_search(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request) = $this->listener();
		$request->method('variable')->with('tags', '')->willReturn('1');
		$manager->expects(self::once())->method('get_tags_by_ids')->with([1])->willReturn([1 => ['prefix_id' => 1]]);
		$filter->expects(self::once())->method('topic_id_condition')->with('p.topic_id', [1])->willReturn('TAG_CONDITION');

		$post = new \phpbb\event\data(['type' => 'posts', 'post_visibility' => 'VISIBLE', 'search_key_array' => []]);
		$listener->filter_backend($post);
		self::assertSame('(VISIBLE) AND TAG_CONDITION', $post['post_visibility']);
		self::assertSame(['topicprefixes:1'], $post['search_key_array']);
	}

	public function test_invalid_tags_do_not_change_search(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request) = $this->listener();
		$request->method('variable')->with('tags', '')->willReturn('1,invalid');
		$manager->expects(self::never())->method('get_tags_by_ids');
		$filter->expects(self::never())->method('topic_id_condition');

		$topics = new \phpbb\event\data(['type' => 'topics', 'post_visibility' => 'VISIBLE', 'search_key_array' => []]);
		$listener->filter_backend($topics);
		self::assertSame('VISIBLE', $topics['post_visibility']);
		self::assertSame([], $topics['search_key_array']);
	}

	public function test_visible_search_badges_get_links_and_selected_panel(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request, $template, $language) = $this->listener();
		$tag = ['prefix_id' => 1, 'prefix_tag' => 'Bug', 'prefix_color' => 'D4351C'];
		$request->method('variable')->with('tags', '')->willReturn('1');
		$manager->method('get_tags_by_ids')->willReturn([1 => $tag]);
		$filter->method('topic_id_condition')->willReturn('TAG_CONDITION');
		$renderer->method('url_with_tags')->willReturn('./search.php?sr=topics&amp;tags=1');
		$renderer->expects(self::exactly(2))->method('render_for_url')->with([1 => $tag], './search.php?sr=topics', [1])->willReturn([
			['TAG_ID' => 1, 'TAG_NAME' => 'Bug', 'U_FILTER' => './search.php?sr=topics'],
		]);
		$assignments->expects(self::once())->method('get_tags_for_topics')->with([42])->willReturn([42 => [1 => $tag]]);
		$template->expects(self::once())->method('assign_vars')->with(self::callback(static function ($vars) {
			return $vars['S_SEARCH_TOPIC_TAG_FILTERED'] === true
				&& $vars['U_CLEAR_SEARCH_TOPIC_TAG_FILTERS'] === './search.php?sr=topics';
		}));
		$language->expects(self::once())->method('add_lang')->with('topic_prefixes', 'phpbb/topicprefixes');

		$listener->filter_backend(new \phpbb\event\data([
			'type' => 'topics', 'post_visibility' => 'VISIBLE', 'search_key_array' => [],
		]));
		$url = new \phpbb\event\data([
			'u_search' => './search.php?sr=topics', 'show_results' => 'topics',
		]);
		$listener->preserve_filter_url($url);
		self::assertSame('./search.php?sr=topics&amp;tags=1', $url['u_search']);

		$listener->load_search_tags(new \phpbb\event\data([
			'rowset' => [['topic_id' => 42]], 'show_results' => 'topics',
		]));
		$row = new \phpbb\event\data([
			'row' => ['topic_id' => 42], 'tpl_ary' => [], 'show_results' => 'topics',
		]);
		$listener->add_search_tags($row);
		self::assertSame('Bug', $row['tpl_ary']['TOPIC_TAGS'][0]['TAG_NAME']);
	}

	public function test_search_without_supported_backend_loads_tags_without_filter_links(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request, $template, $language) = $this->listener();
		$tag = ['prefix_id' => 1, 'prefix_tag' => 'Bug', 'prefix_color' => 'D4351C'];
		$assignments->method('get_tags_for_topics')->willReturn([42 => [1 => $tag]]);
		$renderer->expects(self::once())->method('render')->with([1 => $tag])->willReturn([['TAG_ID' => 1, 'U_FILTER' => '']]);
		$renderer->expects(self::never())->method('render_for_url');
		$language->expects(self::once())->method('add_lang');

		$listener->load_search_tags(new \phpbb\event\data([
			'rowset' => [['topic_id' => 42]], 'show_results' => 'posts',
		]));
		$row = new \phpbb\event\data([
			'row' => ['topic_id' => 42], 'tpl_ary' => [], 'show_results' => 'posts',
		]);
		$listener->add_search_tags($row);
		self::assertSame('', $row['tpl_ary']['TOPIC_TAGS'][0]['U_FILTER']);
	}

	public function test_filterable_search_without_selection_keeps_panel_inactive(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request, $template) = $this->listener();
		$request->method('variable')->with('tags', '')->willReturn('');
		$manager->expects(self::never())->method('get_tags_by_ids');
		$renderer->method('url_with_tags')->willReturn('./search.php?sr=topics');
		$renderer->expects(self::once())->method('render_for_url')->with([], './search.php?sr=topics', [])->willReturn([]);
		$assignments->method('get_tags_for_topics')->willReturn([42 => [1 => ['prefix_id' => 1]]]);
		$template->expects(self::once())->method('assign_vars')->with(self::callback(static function ($vars) {
			return $vars['S_SEARCH_TOPIC_TAG_FILTERED'] === false;
		}));

		$listener->filter_backend(new \phpbb\event\data([
			'type' => 'topics', 'post_visibility' => 'VISIBLE', 'search_key_array' => [],
		]));
		$listener->preserve_filter_url(new \phpbb\event\data([
			'u_search' => './search.php?sr=topics', 'show_results' => 'topics',
		]));
		$listener->load_search_tags(new \phpbb\event\data([
			'rowset' => [['topic_id' => 42]], 'show_results' => 'topics',
		]));
	}

	public function test_filtered_empty_search_keeps_clear_action(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request, $template) = $this->listener();
		$request->method('variable')->with('tags', '')->willReturn('1');
		$manager->method('get_tags_by_ids')->willReturn([1 => ['prefix_id' => 1]]);
		$filter->method('topic_id_condition')->willReturn('TAG_CONDITION');
		$renderer->method('url_with_tags')->willReturn('./search.php?sr=topics&amp;tags=1');
		$renderer->expects(self::once())->method('render_for_url')->with([], './search.php?sr=topics', [1])->willReturn([]);
		$assignments->method('get_tags_for_topics')->with([])->willReturn([]);
		$template->expects(self::once())->method('assign_vars')->with(self::callback(static function ($vars) {
			return $vars['S_SEARCH_TOPIC_TAG_FILTERED'] === true
				&& $vars['SEARCH_TOPIC_TAG_FILTERS'] === []
				&& $vars['U_CLEAR_SEARCH_TOPIC_TAG_FILTERS'] === './search.php?sr=topics';
		}));

		$listener->filter_backend(new \phpbb\event\data([
			'type' => 'topics', 'post_visibility' => 'VISIBLE', 'search_key_array' => [],
		]));
		$listener->preserve_filter_url(new \phpbb\event\data([
			'u_search' => './search.php?sr=topics', 'show_results' => 'topics',
		]));
		$listener->load_search_tags(new \phpbb\event\data([
			'rowset' => [], 'show_results' => 'topics',
		]));
	}

	protected function listener(): array
	{
		$manager = $this->getMockBuilder('\phpbb\topicprefixes\tags\manager')->disableOriginalConstructor()->getMock();
		$assignments = $this->getMockBuilder('\phpbb\topicprefixes\tags\assignment_manager')->disableOriginalConstructor()->getMock();
		$filter = $this->getMockBuilder('\phpbb\topicprefixes\tags\filter')->disableOriginalConstructor()->getMock();
		$renderer = $this->getMockBuilder('\phpbb\topicprefixes\tags\renderer')->disableOriginalConstructor()->getMock();
		$request = $this->getMockBuilder('\phpbb\request\request')->disableOriginalConstructor()->getMock();
		$template = $this->getMockBuilder('\phpbb\template\template')->disableOriginalConstructor()->getMock();
		$language = $this->getMockBuilder('\phpbb\language\language')->disableOriginalConstructor()->getMock();
		$listener = new \phpbb\topicprefixes\event\search_listener(
			$manager, $assignments, $filter, $renderer, $request, $template, $language
		);
		return [$listener, $manager, $assignments, $filter, $renderer, $request, $template, $language];
	}
}
