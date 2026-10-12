<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tests\console;

use Symfony\Component\Console\Tester\CommandTester;

/**
 * Test unified topic repair menu and workflow routing.
 */
class repair_topics_test extends \phpbb_test_case
{
	/** @var \PHPUnit\Framework\MockObject\MockObject */
	protected $topic_repairer;

	/** @var \PHPUnit\Framework\MockObject\MockObject */
	protected $titles;

	/** @var \PHPUnit\Framework\MockObject\MockObject */
	protected $replies;

	/** @var \PHPUnit\Framework\MockObject\MockObject */
	protected $log;

	/**
	 * Create a command tester with mocked repair services.
	 *
	 * @param array $recovery Recovery preview plans
	 * @param array $titles   Title preview plans
	 * @param array $replies  Reply preview plans
	 * @return CommandTester Configured command tester
	 */
	protected function create_tester(array $recovery = [], array $titles = [], array $replies = []): CommandTester
	{
		global $phpbb_root_path, $phpEx;
		$loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
		$loader->set_extension_manager(new \phpbb_mock_extension_manager($phpbb_root_path));
		$language = new \phpbb\language\language($loader);
		$user = $this->getMockBuilder('\phpbb\user')->disableOriginalConstructor()->getMock();
		$this->topic_repairer = $this->getMockBuilder('\phpbb\topicprefixes\tags\topic_repairer')
			->disableOriginalConstructor()
			->getMock();
		$this->titles = $this->getMockBuilder('\phpbb\topicprefixes\tags\title_repairer')
			->disableOriginalConstructor()
			->getMock();
		$this->replies = $this->getMockBuilder('\phpbb\topicprefixes\tags\reply_repairer')
			->disableOriginalConstructor()
			->getMock();
		$this->log = $this->createMock('\phpbb\log\log_interface');
		$this->topic_repairer->method('preview_recovery')->willReturnCallback($this->generator($recovery));
		$this->titles->method('preview')->willReturnCallback($this->generator($titles));
		$this->replies->method('preview')->willReturnCallback($this->generator($replies));
		$forums = [2 => ['label' => '  Forum (#2)', 'plain_label' => 'Forum (#2)']];
		$this->titles->method('get_forums')->willReturn($forums);
		$this->replies->method('get_forums')->willReturn($forums);
		$command = new \phpbb\topicprefixes\console\command\repair_topics(
			$user,
			$language,
			$this->topic_repairer,
			$this->titles,
			$this->replies,
			$this->log
		);
		self::assertSame('topicprefixes:repair-topics', $command->getName());

		return new CommandTester($command);
	}

	/**
	 * Create a generator callback for mocked preview methods.
	 *
	 * @param array $plans Preview plans
	 * @return callable Generator callback
	 */
	protected function generator(array $plans): callable
	{
		return static function () use ($plans) {
			foreach ($plans as $plan)
			{
				yield $plan;
			}
		};
	}

	/**
	 * Create one eligible recovery plan.
	 *
	 * @return array Recovery plan
	 */
	protected function recovery_plan(): array
	{
		return [
			'topic_id' => 13,
			'forum_id' => 2,
			'prefix' => 'Bug',
			'tag_ids' => [1],
			'tag_names' => ['Bug'],
			'before' => 'Bug Topic',
			'after' => 'Topic',
			'skipped' => 0,
			'reason' => 'eligible',
			'changes' => [['field' => 'topic_title']],
		];
	}

	/**
	 * Create one eligible title repair plan.
	 *
	 * @return array Title repair plan
	 */
	protected function title_plan(): array
	{
		return [
			'topic_id' => 13,
			'forum_id' => 2,
			'prefix' => '[OLD]',
			'before' => '[OLD] Topic',
			'after' => 'Topic',
			'skipped' => 0,
			'changes' => [['field' => 'topic_title']],
		];
	}

	/**
	 * Create one eligible reply repair plan.
	 *
	 * @return array Reply repair plan
	 */
	protected function reply_repair_plan(): array
	{
		return [
			'topic_id' => 10,
			'post_id' => 101,
			'forum_id' => 2,
			'prefix' => '[OLD]',
			'title' => 'Topic',
			'before' => 'Re: [OLD] Topic',
			'after' => 'Re: Topic',
			'skipped' => 0,
			'reason' => 'eligible',
			'changes' => [['field' => 'post_subject']],
		];
	}

	public function test_noninteractive_rejected(): void
	{
		$tester = $this->create_tester();
		self::assertSame(1, $tester->execute([], ['interactive' => false]));
	}

	public function test_enabled_board_rejected_before_menu(): void
	{
		$tester = $this->create_tester();
		$this->topic_repairer->expects(self::once())
			->method('assert_board_disabled')
			->willThrowException(new \RuntimeException('CLI_TOPIC_TAGS_REPAIR_BOARD_ENABLED'));
		$this->topic_repairer->expects(self::never())->method('preview_recovery');
		$this->titles->expects(self::never())->method('preview');
		$this->replies->expects(self::never())->method('preview');

		self::assertSame(1, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString(
			'Disable the board under General, Board settings',
			$tester->getDisplay()
		);
		self::assertStringNotContainsString('Choose a repair action', $tester->getDisplay());
	}

	public function test_empty_recovery_returns_to_menu(): void
	{
		$tester = $this->create_tester();
		$this->topic_repairer->expects(self::never())->method('apply');
		$tester->setInputs(['0', '3']);
		self::assertSame(0, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString('Found 0 topics', $tester->getDisplay());
	}

	public function test_confirmed_recovery_applies_without_reply_service(): void
	{
		$plan = $this->recovery_plan();
		$tester = $this->create_tester([$plan]);
		$this->topic_repairer->expects(self::once())
			->method('apply_batch')
			->with([$plan], false)
			->willReturn([
				'assignments' => 1,
				'topics' => 1,
				'subjects' => 1,
				'caches' => 0,
				'skipped' => 0,
				'conflicts' => 0,
			]);
		$this->log->expects(self::once())
			->method('add')
			->with(
				'admin',
				ANONYMOUS,
				'',
				'ACP_LOG_TOPIC_PREFIX_RECOVERY_SUMMARY',
				self::isType('int'),
				[1, 1, 1, 0]
			)
			->willReturn(1);
		$this->replies->expects(self::never())->method('apply');
		$tester->setInputs(['0', 'yes', 'yes', 'yes', '3']);
		self::assertSame(0, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString('Topics repaired: 1', $tester->getDisplay());
		self::assertStringNotContainsString('assignments', $tester->getDisplay());
	}

	public function test_title_action_routes_to_title_service(): void
	{
		$plan = $this->title_plan();
		$tester = $this->create_tester([], [$plan]);
		$this->titles->expects(self::once())
			->method('apply_batch')
			->with([$plan], false)
			->willReturn([
				'topics' => 1,
				'subjects' => 0,
				'caches' => 0,
				'skipped' => 0,
				'conflicts' => 0,
			]);
		$this->log->expects(self::once())
			->method('add')
			->with(
				'admin',
				ANONYMOUS,
				'',
				'ACP_LOG_TOPIC_PREFIX_TITLE_SUMMARY',
				self::isType('int'),
				self::isType('array')
			)
			->willReturn(1);
		$this->replies->expects(self::never())->method('apply');
		$tester->setInputs(['1', '[OLD]', '2', 'yes', 'yes', 'yes', '3']);
		self::assertSame(0, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString('titles in Forum (#2)', $tester->getDisplay());
		self::assertStringNotContainsString('titles in   Forum (#2)', $tester->getDisplay());
	}

	public function test_manual_prefix_displays_unicode_emoji_and_slashes(): void
	{
		$tester = $this->create_tester([], [$this->title_plan()]);
		$tester->setInputs(['1', 'バグ/😇', '2', 'no', '3']);

		self::assertSame(0, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString('"バグ/😇"', $tester->getDisplay());
		self::assertStringNotContainsString('\\u30d0', $tester->getDisplay());
		self::assertStringNotContainsString('\\ud83d', $tester->getDisplay());
		self::assertStringNotContainsString('\\/', $tester->getDisplay());
	}

	public function test_manual_prefix_keeps_quotes_and_control_characters_escaped(): void
	{
		$tester = $this->create_tester([], [$this->title_plan()]);
		$encoded_prefix = '"\"quoted\"\\\\path\tline\nnext\u2028end"';
		$tester->setInputs(['1', $encoded_prefix, '2', 'no', '3']);

		self::assertSame(0, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString('\\"quoted\\"', $tester->getDisplay());
		self::assertStringContainsString('\\\\path', $tester->getDisplay());
		self::assertStringContainsString('\\tline\\nnext\\u2028end', $tester->getDisplay());
	}

	public function test_reply_action_is_explicit_and_warns_about_history(): void
	{
		$plan = $this->reply_repair_plan();
		$tester = $this->create_tester([], [], [$plan]);
		$this->replies->expects(self::once())
			->method('apply_batch')
			->with([$plan], false)
			->willReturn([
				'topics' => 0,
				'subjects' => 1,
				'caches' => 0,
				'skipped' => 0,
				'conflicts' => 0,
			]);
		$this->log->expects(self::once())
			->method('add')
			->with(
				'admin',
				ANONYMOUS,
				'',
				'ACP_LOG_TOPIC_PREFIX_REPLY_SUMMARY',
				self::isType('int'),
				self::isType('array')
			)
			->willReturn(1);
		$this->titles->expects(self::never())->method('apply');
		$tester->setInputs(['2', '[OLD]', '2', 'yes', 'yes', 'yes', '3']);
		self::assertSame(0, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString('normally kept as part of the discussion history', $tester->getDisplay());
		self::assertStringContainsString('Reply subjects updated: 1', $tester->getDisplay());
		self::assertStringContainsString('reply subjects in Forum (#2)', $tester->getDisplay());
		self::assertStringNotContainsString('reply subjects in   Forum (#2)', $tester->getDisplay());
	}

	public function test_uncertain_recovery_tells_user_to_choose_tag_manually(): void
	{
		$plan = $this->recovery_plan();
		$plan['reason'] = 'ambiguous';
		$plan['changes'] = [];
		$tester = $this->create_tester([$plan]);
		$this->topic_repairer->expects(self::never())->method('apply');
		$tester->setInputs(['0', '3']);
		self::assertSame(0, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString('Skipped: choose the tag by editing the topic', $tester->getDisplay());
		self::assertStringContainsString('1 need manual review', $tester->getDisplay());
	}

	public function test_recovery_group_preview_can_stop_before_all_pages(): void
	{
		$plans = [];
		for ($id = 1; $id <= 21; $id++)
		{
			$plan = $this->recovery_plan();
			$plan['topic_id'] = $id;
			$plans[] = $plan;
		}
		$tester = $this->create_tester($plans);
		$this->topic_repairer->expects(self::once())
			->method('apply_batch')
			->with($plans, false)
			->willReturn([
				'assignments' => 21,
				'topics' => 21,
				'subjects' => 0,
				'caches' => 0,
				'skipped' => 0,
				'conflicts' => 0,
			]);
		$tester->setInputs(['0', 'no', 'yes', 'yes', 'yes', '3']);
		self::assertSame(0, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString('Total: 21; reviewed: 20; not previewed: 1', $tester->getDisplay());
	}

	public function test_summary_log_failure_reports_completed_repairs(): void
	{
		$plan = $this->title_plan();
		$tester = $this->create_tester([], [$plan]);
		$this->titles->method('apply_batch')->willReturn([
			'topics' => 1,
			'subjects' => 0,
			'caches' => 0,
			'skipped' => 0,
			'conflicts' => 0,
		]);
		$this->log->method('add')->willReturn(false);
		$tester->setInputs(['1', '[OLD]', '2', 'yes', 'yes', 'yes', '3']);
		self::assertSame(1, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString('Repairs were completed', $tester->getDisplay());
	}

	public function test_recovery_groups_can_be_approved_independently(): void
	{
		$first = $this->recovery_plan();
		$second = $this->recovery_plan();
		$second['topic_id'] = 14;
		$second['forum_id'] = 3;
		$tester = $this->create_tester([$first, $second]);
		$this->topic_repairer->expects(self::once())
			->method('apply_batch')
			->with([$second], false)
			->willReturn([
				'assignments' => 1,
				'topics' => 1,
				'subjects' => 0,
				'caches' => 0,
				'skipped' => 0,
				'conflicts' => 0,
			]);
		$tester->setInputs(['0', 'no', 'yes', 'yes', 'yes', '3']);
		self::assertSame(0, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString('Topics repaired: 1', $tester->getDisplay());
	}

	public function test_recovery_prefix_displays_decoded_unicode_emoji_and_slashes(): void
	{
		$plan = $this->recovery_plan();
		$plan['prefix'] = '&#128519;/バグ';
		$tester = $this->create_tester([$plan]);
		$tester->setInputs(['0', 'no', '3']);

		self::assertSame(0, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString('"😇/バグ"', $tester->getDisplay());
		self::assertStringNotContainsString('&#128519;', $tester->getDisplay());
		self::assertStringNotContainsString('\\u30d0', $tester->getDisplay());
		self::assertStringNotContainsString('\\ud83d', $tester->getDisplay());
		self::assertStringNotContainsString('\\/', $tester->getDisplay());
	}
}
