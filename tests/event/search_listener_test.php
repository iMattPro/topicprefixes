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
			'core.search_modify_submit_parameters',
			'core.search_modify_param_before',
			'core.search_native_by_keyword_modify_search_key',
			'core.search_native_by_author_modify_search_key',
			'core.search_mysql_by_keyword_modify_search_key',
			'core.search_mysql_by_author_modify_search_key',
			'core.search_postgres_by_keyword_modify_search_key',
			'core.search_postgres_by_author_modify_search_key',
			'core.search_backend_search_after',
			'core.search_modify_param_after',
			'core.get_unread_topics_modify_sql',
			'core.search_modify_url_parameters',
			'core.search_modify_rowset',
			'core.search_modify_tpl_ary',
			'core.search_modify_forum_select_list',
		], array_keys(\phpbb\topicprefixes\event\search_listener::getSubscribedEvents()));
	}

	public function test_disabled_search_entry_points_are_noops(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request, $template, $language, $auth, $config) = $this->listener();
		$config['topicprefixes_search_enabled'] = 0;
		$manager->expects(self::never())->method('resolve_search_tags');
		$manager->expects(self::never())->method('get_search_catalog');
		$filter->expects(self::never())->method('search_topic_ids');
		$request->expects(self::never())->method('variable');
		$template->expects(self::never())->method('assign_vars');
		$auth->expects(self::never())->method('acl_getf');

		$submit = new \phpbb\event\data([
			'keywords' => '', 'author' => '', 'author_id' => 0, 'search_id' => '', 'submit' => false,
		]);
		$listener->validate_submission($submit);
		self::assertFalse($submit['submit']);

		$params = new \phpbb\event\data([
			'keywords' => '', 'author_id_ary' => [], 'search_id' => '', 'show_results' => 'posts', 'sort_by_sql' => [],
		]);
		$listener->configure_tag_only_search($params);
		self::assertSame('posts', $params['show_results']);

		$tag_only = new \ReflectionProperty($listener, 'tag_only');
		$tag_only->setAccessible(true);
		$tag_only->setValue($listener, true);
		$search = new \phpbb\event\data(['id_ary' => [], 'total_match_count' => 0, 'start' => 0]);
		$listener->search_by_tags($search);
		self::assertSame([], $search['id_ary']);

		$listener->configure_search_form(new \phpbb\event\data([]));
	}

	/**
	 * @dataProvider predefined_search_data
	 */
	public function test_predefined_search_adds_tag_condition_before_sorting(string $search_id, string $show_results, string $topic_id): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request) = $this->listener();
		$request->method('variable')->willReturn('1');
		$tags = $this->search_tags([1]);
		$manager->expects(self::once())->method('resolve_search_tags')->with([1])->willReturn($tags);
		$filter->expects(self::once())->method('topic_id_tags_condition')->with($topic_id, [1], 'all')->willReturn('TAG_CONDITION');

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
		$request->method('variable')->willReturn('1');
		$tags = $this->search_tags([1]);
		$manager->expects(self::once())->method('resolve_search_tags')->with([1])->willReturn($tags);
		$filter->expects(self::once())->method('topic_id_tags_condition')->with('t.topic_id', [1], 'all')->willReturn('TAG_CONDITION');

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

	public function test_unsupported_search_paths_are_noops(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request) = $this->listener();
		$request->expects(self::never())->method('variable');
		$filter->expects(self::never())->method('topic_id_tags_condition');
		$renderer->expects(self::never())->method('url_with_tags');

		$predefined = new \phpbb\event\data([
			'search_id' => 'egosearch',
			'show_results' => 'topics',
			'sql' => 'ORIGINAL_SEARCH_SQL',
		]);
		$listener->filter_predefined_search($predefined);
		self::assertSame('ORIGINAL_SEARCH_SQL', $predefined['sql']);

		$unread = new \phpbb\event\data(['sql_array' => ['WHERE' => 'ORIGINAL_UNREAD_SQL']]);
		$listener->filter_unread_topics($unread);
		self::assertSame('ORIGINAL_UNREAD_SQL', $unread['sql_array']['WHERE']);

		$backend = new \phpbb\event\data([
			'type' => 'unsupported',
			'post_visibility' => 'VISIBLE',
			'search_key_array' => [],
		]);
		$listener->filter_backend($backend);
		self::assertSame('VISIBLE', $backend['post_visibility']);

		$url = new \phpbb\event\data(['u_search' => './search.php']);
		$listener->preserve_filter_url($url);
		self::assertSame('./search.php', $url['u_search']);
	}

	public function test_predefined_search_without_selection_leaves_sql_unchanged(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request) = $this->listener();
		$request->method('variable')->willReturn('');
		$manager->expects(self::never())->method('resolve_search_tags');
		$filter->expects(self::never())->method('topic_id_tags_condition');

		$event = new \phpbb\event\data([
			'search_id' => 'active_topics',
			'show_results' => 'topics',
			'sql' => 'ORIGINAL_SQL',
		]);
		$listener->filter_predefined_search($event);

		self::assertSame('ORIGINAL_SQL', $event['sql']);
	}

	public function test_unread_search_without_selection_leaves_sql_unchanged(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request) = $this->listener();
		$request->method('variable')->willReturn('');
		$manager->expects(self::never())->method('resolve_search_tags');
		$filter->expects(self::never())->method('topic_id_tags_condition');

		$listener->filter_predefined_search(new \phpbb\event\data([
			'search_id' => 'unreadposts',
			'show_results' => 'topics',
			'sql' => '',
		]));
		$event = new \phpbb\event\data(['sql_array' => ['WHERE' => 'ORIGINAL_SQL']]);
		$listener->filter_unread_topics($event);

		self::assertSame('ORIGINAL_SQL', $event['sql_array']['WHERE']);
	}

	public function test_predefined_search_without_order_by_appends_tag_condition(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request) = $this->listener();
		$request->method('variable')->willReturn('1');
		$tags = $this->search_tags([1]);
		$manager->method('resolve_search_tags')->with([1])->willReturn($tags);
		$filter->method('topic_id_tags_condition')->with('t.topic_id', [1], 'all')->willReturn('TAG_CONDITION');

		$event = new \phpbb\event\data([
			'search_id' => 'active_topics',
			'show_results' => 'topics',
			'sql' => 'SELECT topic_id FROM topics WHERE VISIBLE',
		]);
		$listener->filter_predefined_search($event);

		self::assertSame('SELECT topic_id FROM topics WHERE VISIBLE AND TAG_CONDITION', $event['sql']);
	}

	public function test_topic_backend_intersects_visibility_and_changes_cache_key(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request) = $this->listener();
		$request->method('variable')->willReturnCallback(static function ($name, $default) {
			return $name === 'tags' ? '2,1,2' : $default;
		});
		$tags = $this->search_tags([1, 2]);
		$manager->expects(self::once())->method('resolve_search_tags')->with([2, 1])->willReturn($tags);
		$filter->expects(self::once())->method('topic_id_tags_condition')->with('p.topic_id', [1, 2], 'all')->willReturn('TAG_CONDITION');

		$event = new \phpbb\event\data([
			'type' => 'topics',
			'post_visibility' => 'CORE_VISIBILITY',
			'search_key_array' => ['original'],
		]);
		$listener->filter_backend($event);

		self::assertSame('(CORE_VISIBILITY) AND TAG_CONDITION', $event['post_visibility']);
		self::assertSame(['original', 'topicprefixes:all:1,2'], $event['search_key_array']);
	}

	public function test_post_backend_is_filtered_and_invalid_tags_do_not_change_search(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request) = $this->listener();
		$request->method('variable')->willReturn('1');
		$tags = $this->search_tags([1]);
		$manager->expects(self::once())->method('resolve_search_tags')->with([1])->willReturn($tags);
		$filter->expects(self::once())->method('topic_id_tags_condition')->with('p.topic_id', [1], 'all')->willReturn('TAG_CONDITION');

		$post = new \phpbb\event\data(['type' => 'posts', 'post_visibility' => 'VISIBLE', 'search_key_array' => []]);
		$listener->filter_backend($post);
		self::assertSame('(VISIBLE) AND TAG_CONDITION', $post['post_visibility']);
		self::assertSame(['topicprefixes:all:1'], $post['search_key_array']);
	}

	public function test_invalid_tags_do_not_change_search(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request) = $this->listener();
		$request->method('variable')->willReturn('1,invalid');
		$manager->expects(self::never())->method('resolve_search_tags');
		$filter->expects(self::never())->method('topic_id_tags_condition');

		$topics = new \phpbb\event\data(['type' => 'topics', 'post_visibility' => 'VISIBLE', 'search_key_array' => []]);
		$listener->filter_backend($topics);
		self::assertSame('VISIBLE', $topics['post_visibility']);
		self::assertSame([], $topics['search_key_array']);
	}

	public function test_visible_search_badges_get_links_and_selected_panel(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request, $template, $language) = $this->listener();
		$tag = ['prefix_id' => 1, 'prefix_tag' => 'Bug', 'prefix_color' => '1D70B8'];
		$tags = $this->search_tags([1]);
		$request->method('variable')->willReturn('1');
		$manager->method('resolve_search_tags')->willReturn($tags);
		$filter->method('topic_id_tags_condition')->willReturn('TAG_CONDITION');
		$renderer->method('url_with_tags')->willReturn('./search.php?sr=topics&amp;tags=1');
		$renderer->expects(self::exactly(2))->method('render_for_url')->withConsecutive(
			[$tags, './search.php?sr=topics', [1]],
			[[1 => $tag], './search.php?sr=topics', [1]]
		)->willReturnOnConsecutiveCalls(
			[['TAG_ID' => 1, 'TAG_NAME' => 'Tag 1', 'U_FILTER' => './search.php?sr=topics']],
			[['TAG_ID' => 1, 'TAG_NAME' => 'Bug', 'TAG_COLOR' => '1D70B8', 'U_FILTER' => './search.php?sr=topics']]
		);
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
		self::assertSame('1D70B8', $row['tpl_ary']['TOPIC_TAGS'][0]['TAG_COLOR']);
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
		$request->method('variable')->willReturn('');
		$manager->method('resolve_search_tags')->willReturn($this->search_tags([1]));
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
		$request->method('variable')->willReturn('1');
		$manager->method('resolve_search_tags')->willReturn($this->search_tags([1]));
		$filter->method('topic_id_tags_condition')->willReturn('TAG_CONDITION');
		$renderer->method('url_with_tags')->willReturn('./search.php?sr=topics&amp;tags=1');
		$renderer->expects(self::once())->method('render_for_url')->willReturn([]);
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

	public function test_tag_only_search_forces_topics_and_supplies_ids(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request) = $this->listener();
		$tags = $this->search_tags([1]);
		$request->method('variable')->willReturnCallback(static function ($name, $default) {
			return $name === 'tags' ? '1' : $default;
		});
		$manager->method('resolve_search_tags')->with([1])->willReturn($tags);

		$submit = new \phpbb\event\data([
			'keywords' => '', 'author' => '', 'author_id' => 0, 'search_id' => '', 'submit' => false,
		]);
		$listener->validate_submission($submit);
		self::assertTrue($submit['submit']);

		$params = new \phpbb\event\data([
			'keywords' => '', 'author_id_ary' => [], 'search_id' => '', 'show_results' => 'posts',
			'sort_by_sql' => ['a' => 'u.username_clean', 't' => 'p.post_time', 'f' => 'f.forum_id', 'i' => 't.topic_title', 's' => 'p.post_subject'],
		]);
		$listener->configure_tag_only_search($params);
		self::assertSame('topics', $params['show_results']);
		self::assertSame('t.topic_last_post_time', $params['sort_by_sql']['t']);
		self::assertSame('t.topic_title', $params['sort_by_sql']['s']);

		$filter->expects(self::once())->method('search_topic_ids')
			->with([1], 'all', [3], 't', 'd', 0, 0, 25)
			->willReturn([[42], 1, 0]);
		$search = new \phpbb\event\data([
			'ex_fid_ary' => [3], 'sort_key' => 't', 'sort_dir' => 'd', 'sort_days' => 0,
			'start' => 0, 'per_page' => 25, 'id_ary' => [], 'total_match_count' => 0,
		]);
		$listener->search_by_tags($search);
		self::assertSame([42], $search['id_ary']);
		self::assertSame(1, $search['total_match_count']);
	}

	public function test_any_match_changes_condition_cache_key_and_url(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request) = $this->listener();
		$tags = $this->search_tags([1, 2]);
		$request->method('variable')->willReturnCallback(static function ($name, $default) {
			if ($name === 'tags')
			{
				return '1,2';
			}
			return $name === 'tag_match' ? 'any' : $default;
		});
		$manager->method('resolve_search_tags')->willReturn($tags);
		$filter->expects(self::once())->method('topic_id_tags_condition')
			->with('p.topic_id', [1, 2], 'any')->willReturn('ANY_TAG');
		$renderer->expects(self::once())->method('url_with_tags')
			->with('./search.php?sr=topics&amp;tag_match=any', [1, 2])
			->willReturn('./search.php?sr=topics&amp;tag_match=any&amp;tags=1%2C2');

		$backend = new \phpbb\event\data([
			'type' => 'topics', 'post_visibility' => 'VISIBLE', 'search_key_array' => [],
		]);
		$listener->filter_backend($backend);
		self::assertSame('(VISIBLE) AND ANY_TAG', $backend['post_visibility']);
		self::assertSame(['topicprefixes:any:1,2'], $backend['search_key_array']);

		$url = new \phpbb\event\data(['u_search' => './search.php?sr=topics']);
		$listener->preserve_filter_url($url);
		self::assertSame('./search.php?sr=topics&amp;tag_match=any&amp;tags=1%2C2', $url['u_search']);
	}

	public function test_advanced_search_preloads_permission_filtered_catalog(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request, $template, $language, $auth) = $this->listener();
		$request->method('variable')->willReturnCallback(static function ($name, $default) {
			return $default;
		});
		$auth->method('acl_getf')->willReturnCallback(static function ($permission) {
			$ids = $permission === 'f_search' ? [2] : [2, 3];
			return array_fill_keys($ids, [$permission => true]);
		});
		$tags = $this->search_tags([1]);
		$manager->expects(self::once())->method('get_search_catalog')->with([2])->willReturn($tags);
		$renderer->method('contrast_color')->willReturn('#FFFFFF');
		$template->expects(self::once())->method('assign_vars')->with(self::callback(static function ($vars) {
			$catalog = json_decode($vars['TOPIC_TAG_SEARCH_CATALOG'], true);
			return $vars['S_TOPIC_TAG_SEARCH'] === true
				&& $catalog[0][0] === 1
				&& $catalog[0][4] === '#FFFFFF';
		}));

		$listener->configure_search_form(new \phpbb\event\data(['rowset' => []]));
	}

	public function test_sphinx_rejects_combined_tag_search(): void
	{
		list($listener, $manager, $assignments, $filter, $renderer, $request, $template, $language, $auth, $config) = $this->listener();
		$config['search_type'] = '\\phpbb\\search\\fulltext_sphinx';
		$request->method('variable')->willReturnCallback(static function ($name, $default) {
			return $name === 'tags' ? '1' : $default;
		});
		$manager->method('resolve_search_tags')->willReturn($this->search_tags([1]));
		$language->method('lang')->with('TOPIC_TAG_SEARCH_SPHINX_UNSUPPORTED')->willReturn('Unsupported');

		$this->expectException(\PHPUnit\Framework\Error\Notice::class);
		$this->expectExceptionMessage('Unsupported');
		$listener->validate_submission(new \phpbb\event\data([
			'keywords' => 'security', 'author' => '', 'author_id' => 0, 'search_id' => '', 'submit' => true,
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
		$auth = $this->getMockBuilder('\phpbb\auth\auth')->disableOriginalConstructor()->getMock();
		$config = new \phpbb\config\config([
			'search_type' => '\\phpbb\\search\\fulltext_native',
			'topicprefixes_search_enabled' => 1,
		]);
		$listener = new \phpbb\topicprefixes\event\search_listener(
			$manager, $assignments, $filter, $renderer, $request, $template, $language, $auth, $config
		);
		return [$listener, $manager, $assignments, $filter, $renderer, $request, $template, $language, $auth, $config];
	}

	protected function search_tags(array $ids): array
	{
		$tags = [];
		foreach ($ids as $id)
		{
			$tags[$id] = [
				'prefix_id' => $id,
				'prefix_tag' => 'Tag ' . $id,
				'prefix_color' => 'D4351C',
				'prefix_enabled' => 1,
				'prefix_order' => $id,
				'search_name' => 'tag ' . $id,
			];
		}
		return $tags;
	}
}
