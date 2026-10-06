<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\event;

use phpbb\language\language;
use phpbb\request\request;
use phpbb\template\template;
use phpbb\topicprefixes\tags\assignment_manager;
use phpbb\topicprefixes\tags\filter;
use phpbb\topicprefixes\tags\manager;
use phpbb\topicprefixes\tags\renderer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Filter SQL-backed topic searches and display filterable search badges.
 */
class search_listener implements EventSubscriberInterface
{
	/** @var manager */
	protected $manager;

	/** @var assignment_manager */
	protected $assignments;

	/** @var filter */
	protected $filter;

	/** @var renderer */
	protected $renderer;

	/** @var request */
	protected $request;

	/** @var template */
	protected $template;

	/** @var language */
	protected $language;

	/** @var array */
	protected $selected_ids;

	/** @var array */
	protected $search_tags = [];

	/** @var bool */
	protected $filterable = false;

	/** @var string */
	protected $filter_url = '';

	/** @var bool */
	protected $language_loaded = false;

	public function __construct(manager $manager, assignment_manager $assignments, filter $filter, renderer $renderer, request $request, template $template, language $language)
	{
		$this->manager = $manager;
		$this->assignments = $assignments;
		$this->filter = $filter;
		$this->renderer = $renderer;
		$this->request = $request;
		$this->template = $template;
		$this->language = $language;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'core.search_native_by_keyword_modify_search_key' => 'filter_backend',
			'core.search_native_by_author_modify_search_key' => 'filter_backend',
			'core.search_mysql_by_keyword_modify_search_key' => 'filter_backend',
			'core.search_mysql_by_author_modify_search_key' => 'filter_backend',
			'core.search_postgres_by_keyword_modify_search_key' => 'filter_backend',
			'core.search_postgres_by_author_modify_search_key' => 'filter_backend',
			'core.search_modify_url_parameters' => 'preserve_filter_url',
			'core.search_modify_rowset' => 'load_search_tags',
			'core.search_modify_tpl_ary' => 'add_search_tags',
		];
	}

	/**
	 * Intersect phpBB's post visibility SQL with selected topic tags.
	 *
	 * The modified visibility expression participates in both result counting
	 * and ID selection. A separate cache-key component prevents collisions.
	 */
	public function filter_backend($event): void
	{
		if (!in_array($event['type'], ['posts', 'topics'], true))
		{
			return;
		}

		$this->filterable = true;
		$selected_ids = $this->get_selected_ids();
		if (!$selected_ids)
		{
			return;
		}

		$post_visibility = (string) $event['post_visibility'];
		$event['post_visibility'] = '(' . $post_visibility . ') AND ' . $this->filter->topic_id_condition('p.topic_id', $selected_ids);
		$search_key = $event['search_key_array'];
		$search_key[] = 'topicprefixes:' . implode(',', $selected_ids);
		$event['search_key_array'] = $search_key;
	}

	/**
	 * Preserve selected tags in search sorting and pagination URLs.
	 */
	public function preserve_filter_url($event): void
	{
		if (!$this->filterable)
		{
			return;
		}

		$this->filter_url = (string) $event['u_search'];
		$event['u_search'] = $this->renderer->url_with_tags($this->filter_url, $this->get_selected_ids());
	}

	/**
	 * Batch-load tags only for rows phpBB already authorized and selected.
	 */
	public function load_search_tags($event): void
	{
		$topic_ids = [];
		foreach ($event['rowset'] as $row)
		{
			$topic_ids[] = (int) $row['topic_id'];
		}
		$this->search_tags = $this->assignments->get_tags_for_topics($topic_ids);

		if (!$this->filterable || !$this->filter_url)
		{
			return;
		}

		$visible_tags = [];
		foreach ($this->search_tags as $tags)
		{
			$visible_tags += $tags;
		}
		$selected_ids = $this->get_selected_ids();
		$selected_tags = array_intersect_key($visible_tags, array_fill_keys($selected_ids, true));
		$this->template->assign_vars([
			'S_SEARCH_TOPIC_TAG_FILTERS' => !empty($selected_tags),
			'S_SEARCH_TOPIC_TAG_FILTERED' => !empty($selected_ids),
			'SEARCH_TOPIC_TAG_FILTERS' => $this->renderer->render_for_url($selected_tags, $this->filter_url, $selected_ids),
			'U_CLEAR_SEARCH_TOPIC_TAG_FILTERS' => $this->filter_url,
		]);
	}

	/**
	 * Add plain or filterable tag badges to one search result row.
	 */
	public function add_search_tags($event): void
	{
		$this->load_language();
		$topic_id = (int) $event['row']['topic_id'];
		$tags = $this->search_tags[$topic_id] ?? [];
		$tpl = $event['tpl_ary'];
		$tpl['TOPIC_TAGS'] = $this->filterable && $this->filter_url
			? $this->renderer->render_for_url($tags, $this->filter_url, $this->get_selected_ids())
			: $this->renderer->render($tags);
		$event['tpl_ary'] = $tpl;
	}

	/**
	 * Parse and validate selected tags once without loading topic assignments.
	 */
	protected function get_selected_ids(): array
	{
		if ($this->selected_ids !== null)
		{
			return $this->selected_ids;
		}

		$value = trim($this->request->variable('tags', ''));
		if ($value === '' || !preg_match('/^\d+(?:,\d+)*$/D', $value))
		{
			return $this->selected_ids = [];
		}

		$requested_ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $value)))));
		$tags = $requested_ids ? $this->manager->get_tags_by_ids($requested_ids) : [];
		$this->selected_ids = array_values(array_intersect($requested_ids, array_keys($tags)));
		sort($this->selected_ids, SORT_NUMERIC);
		return $this->selected_ids;
	}

	protected function load_language(): void
	{
		if (!$this->language_loaded)
		{
			$this->language->add_lang('topic_prefixes', 'phpbb/topicprefixes');
			$this->language_loaded = true;
		}
	}
}
