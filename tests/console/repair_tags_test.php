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

class repair_tags_test extends \phpbb_test_case
{
	/** @var \phpbb\language\language */
	protected $language;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\topicprefixes\tags\manager */
	protected $tag_manager;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\topicprefixes\tags\repairer */
	protected $repairer;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\log\log_interface */
	protected $log;

	/** @var \phpbb\config\config */
	protected $config;

	protected function setUp(): void
	{
		parent::setUp();
		global $phpbb_root_path, $phpEx;
		$language_loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
		$language_loader->set_extension_manager(new \phpbb_mock_extension_manager($phpbb_root_path));
		$this->language = new \phpbb\language\language($language_loader);
		$this->language->add_lang('cli_topic_prefixes', 'phpbb/topicprefixes');
		$this->tag_manager = $this->getMockBuilder('\phpbb\topicprefixes\tags\manager')
			->disableOriginalConstructor()
			->getMock();
		$this->repairer = $this->getMockBuilder('\phpbb\topicprefixes\tags\repairer')
			->disableOriginalConstructor()
			->getMock();
		$this->log = $this->createMock('\phpbb\log\log_interface');
	}

	public function test_command_requires_interactive_terminal(): void
	{
		$tester = $this->create_tester(true);
		self::assertSame(1, $tester->execute([], ['interactive' => false]));
		self::assertStringContainsString($this->language->lang('CLI_TOPIC_TAGS_REPAIR_INTERACTIVE_REQUIRED'), $tester->getDisplay());
	}

	public function test_command_uses_generic_repair_name(): void
	{
		self::assertSame('topicprefixes:repair-tags', $this->create_command(true)->getName());
	}

	public function test_command_requires_disabled_board(): void
	{
		$tester = $this->create_tester(false);
		self::assertSame(1, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString(
			$this->language->lang('CLI_TOPIC_TAGS_REPAIR_BOARD_ENABLED'),
			preg_replace('/\s+/', ' ', $tester->getDisplay())
		);
	}

	public function test_declined_backup_confirmation_changes_nothing(): void
	{
		$this->tag_manager->expects(self::never())->method('get_tags');
		$tester = $this->create_tester(true);
		$tester->setInputs(['no']);

		self::assertSame(0, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString($this->language->lang('CLI_TOPIC_TAGS_REPAIR_CANCELLED'), $tester->getDisplay());
	}

	public function test_targeted_repair_previews_confirms_repairs_and_logs(): void
	{
		$tags = [
			1 => [
				'prefix_id' => 1,
				'prefix_tag' => '(A)(B)',
				'prefix_enabled' => 1,
				'prefix_color' => '4A76A8',
				'prefix_order' => 1,
			],
		];
		$preview = [
			'source' => $tags[1],
			'targets' => [
				['prefix_id' => null, 'prefix_tag' => 'A', 'existing' => false],
				['prefix_id' => 2, 'prefix_tag' => 'B', 'existing' => true],
			],
			'forum_count' => 1,
			'topic_count' => 2,
			'cleanup' => [
				'topic_title' => 1,
				'post_subject' => 1,
				'topic_last_post_subject' => 1,
				'forum_last_post_subject' => 1,
			],
		];
		$result = $preview;
		$result['targets'][0]['prefix_id'] = 3;

		$this->tag_manager->expects(self::once())->method('get_tags')->willReturn($tags);
		$this->repairer->expects(self::once())->method('inspect')->with(1)->willReturn([
			'source' => $tags[1],
			'forum_count' => 1,
			'topic_count' => 2,
		]);
		$this->repairer->expects(self::once())->method('preview')->with(1, ['A', 'B'])->willReturn($preview);
		$this->repairer->expects(self::once())->method('repair')->with(1, ['A', 'B'])->willReturn($result);
		$this->log->expects(self::once())->method('add')->with(
			'admin',
			ANONYMOUS,
			'',
			'ACP_LOG_TAG_SPLIT',
			self::isType('int'),
			['(A)(B)', 'A, B']
		);

		$tester = $this->create_tester(true);
		$tester->setInputs(['y', 'split', 'A', 'B', '', 'y']);
		self::assertSame(0, $tester->execute(['--tag-id' => 1], ['interactive' => true]));
		$display = $tester->getDisplay();
		self::assertStringContainsString('2 assigned topics, 1 forum', $display);
		self::assertStringContainsString('Assign every separate tag to 2 topics', $display);
		self::assertStringContainsString('across 1 forum', $display);
		self::assertStringContainsString('1 topic title, 1 first-post subject, 1 last-post subject, and 1 forum last-post subject', $display);
		self::assertStringContainsString('across 2 topics.', $display);
		self::assertStringContainsString('Finished: 1 split, 0 merges, 0 skips.', $display);
	}

	public function test_repair_log_escapes_html_and_preserves_unicode(): void
	{
		$source = [
			'prefix_id' => 1,
			'prefix_tag' => '<Source> 😇',
			'prefix_enabled' => 1,
			'prefix_color' => '4A76A8',
			'prefix_order' => 1,
		];
		$preview = [
			'source' => $source,
			'targets' => [
				['prefix_id' => null, 'prefix_tag' => 'A&B', 'existing' => false],
				['prefix_id' => 2, 'prefix_tag' => '<Target>', 'existing' => true],
			],
			'forum_count' => 1,
			'topic_count' => 2,
			'cleanup' => [
				'topic_title' => 1,
				'post_subject' => 1,
				'topic_last_post_subject' => 1,
				'forum_last_post_subject' => 1,
			],
		];
		$result = $preview;
		$result['targets'][0]['prefix_id'] = 3;

		$this->tag_manager->expects(self::once())->method('get_tags')->willReturn([1 => $source]);
		$this->repairer->expects(self::once())->method('inspect')->with(1)->willReturn([
			'source' => $source,
			'forum_count' => 1,
			'topic_count' => 2,
		]);
		$this->repairer->expects(self::once())->method('preview')->with(1, ['A&B', '<Target>'])->willReturn($preview);
		$this->repairer->expects(self::once())->method('repair')->with(1, ['A&B', '<Target>'])->willReturn($result);
		$this->log->expects(self::once())->method('add')->with(
			'admin',
			ANONYMOUS,
			'',
			'ACP_LOG_TAG_SPLIT',
			self::isType('int'),
			['&lt;Source&gt; &#128519;', 'A&amp;B, &lt;Target&gt;']
		);

		$tester = $this->create_tester(true);
		$tester->setInputs(['y', 'split', 'A&B', '<Target>', '', 'y']);
		self::assertSame(0, $tester->execute(['--tag-id' => 1], ['interactive' => true]));
	}

	public function test_merge_uses_selected_existing_tag_and_logs_operation(): void
	{
		$source = $this->source_tag();
		$target = [
			'prefix_id' => 2,
			'prefix_tag' => 'Target',
			'prefix_enabled' => 0,
			'prefix_color' => '123456',
			'prefix_order' => 2,
		];
		$preview = [
			'source' => $source,
			'targets' => [$target + ['existing' => true]],
			'forum_count' => 1,
			'topic_count' => 1,
			'cleanup' => [
				'topic_title' => 0,
				'post_subject' => 0,
				'topic_last_post_subject' => 0,
				'forum_last_post_subject' => 0,
			],
		];

		$this->tag_manager->expects(self::once())->method('get_tags')->willReturn([1 => $source, 2 => $target]);
		$this->repairer->expects(self::once())->method('inspect')->with(1)->willReturn($this->inspection($source));
		$this->repairer->expects(self::once())->method('preview_merge')->with(1, 2)->willReturn($preview);
		$this->repairer->expects(self::once())->method('merge')->with(1, 2)->willReturn($preview);
		$this->repairer->expects(self::never())->method('repair');
		$this->log->expects(self::once())->method('add')->with(
			'admin',
			ANONYMOUS,
			'',
			'ACP_LOG_TAG_MERGED',
			self::isType('int'),
			['(A)(B)', 'Target']
		);

		$tester = $this->create_tester(true);
		$tester->setInputs(['yes', 'merge', 'Tag #2: Target', 'yes']);
		self::assertSame(0, $tester->execute(['--tag-id' => 1], ['interactive' => true]));
		$display = $tester->getDisplay();
		self::assertStringContainsString($this->language->lang('CLI_TOPIC_TAGS_REPAIR_MERGE_PREVIEW'), $display);
		self::assertStringContainsString($this->language->lang('CLI_TOPIC_TAGS_REPAIR_MERGE_PREVIEW_ENABLE'), $display);
		self::assertStringContainsString('Assign the destination tag to 1 topic', $display);
		self::assertStringContainsString('across 1 topic.', $display);
		self::assertStringContainsString('Finished: 0 splits, 1 merge, 0 skips.', $display);
	}

	public function test_merge_without_destination_skips_source(): void
	{
		$source = $this->source_tag();
		$this->tag_manager->method('get_tags')->willReturn([1 => $source]);
		$this->repairer->method('inspect')->willReturn($this->inspection($source));
		$this->repairer->expects(self::never())->method('preview_merge');
		$this->repairer->expects(self::never())->method('merge');

		$tester = $this->create_tester(true);
		$tester->setInputs(['yes', 'merge']);
		self::assertSame(0, $tester->execute(['--tag-id' => 1], ['interactive' => true]));
		self::assertStringContainsString($this->language->lang('CLI_TOPIC_TAGS_REPAIR_MERGE_NO_TARGETS'), $tester->getDisplay());
	}

	public function test_merge_target_selection_can_be_cancelled(): void
	{
		$source = $this->source_tag();
		$target = ['prefix_id' => 2, 'prefix_tag' => 'Target', 'prefix_enabled' => 1];
		$this->tag_manager->method('get_tags')->willReturn([1 => $source, 2 => $target]);
		$this->repairer->method('inspect')->willReturn($this->inspection($source));
		$this->repairer->expects(self::never())->method('preview_merge');
		$this->repairer->expects(self::never())->method('merge');

		$tester = $this->create_tester(true);
		$tester->setInputs(['yes', 'merge', 'cancel']);
		self::assertSame(0, $tester->execute(['--tag-id' => 1], ['interactive' => true]));
	}

	public function test_skip_and_quit_do_not_repair(): void
	{
		$tags = [
			1 => ['prefix_id' => 1, 'prefix_tag' => 'One'],
			2 => ['prefix_id' => 2, 'prefix_tag' => 'Two'],
		];
		$this->tag_manager->method('get_tags')->willReturn($tags);
		$this->repairer->expects(self::exactly(2))->method('inspect')->willReturn([
			'source' => [],
			'forum_count' => 0,
			'topic_count' => 0,
		]);
		$this->repairer->expects(self::never())->method('repair');

		$tester = $this->create_tester(true);
		$tester->setInputs(['yes', 'skip', 'quit']);
		self::assertSame(0, $tester->execute([], ['interactive' => true]));
	}

	public function test_skip_is_first_and_default_action(): void
	{
		$source = $this->source_tag();
		$this->tag_manager->method('get_tags')->willReturn([1 => $source]);
		$this->repairer->method('inspect')->willReturn($this->inspection($source));
		$this->repairer->expects(self::never())->method('preview');
		$this->repairer->expects(self::never())->method('preview_merge');
		$this->repairer->expects(self::never())->method('repair');
		$this->repairer->expects(self::never())->method('merge');

		$tester = $this->create_tester(true);
		$tester->setInputs(['yes', '']);
		self::assertSame(0, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString('Finished: 0 splits, 0 merges, 1 skip.', $tester->getDisplay());
	}

	public function test_invalid_targeted_tag_is_rejected(): void
	{
		$this->tag_manager->method('get_tags')->willReturn([1 => $this->source_tag()]);
		$this->repairer->expects(self::never())->method('inspect');

		$tester = $this->create_tester(true);
		$tester->setInputs(['yes']);
		self::assertSame(1, $tester->execute(['--tag-id' => 999], ['interactive' => true]));
		self::assertStringContainsString($this->language->lang('CLI_TOPIC_TAGS_REPAIR_TAG_NOT_FOUND'), $tester->getDisplay());
	}

	public function test_empty_catalog_exits_cleanly(): void
	{
		$this->tag_manager->method('get_tags')->willReturn([]);

		$tester = $this->create_tester(true);
		$tester->setInputs(['yes']);
		self::assertSame(0, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString($this->language->lang('CLI_TOPIC_TAGS_REPAIR_NO_TAGS'), $tester->getDisplay());
	}

	public function test_empty_replacement_list_skips_tag(): void
	{
		$source = $this->source_tag();
		$this->tag_manager->method('get_tags')->willReturn([1 => $source]);
		$this->repairer->method('inspect')->willReturn($this->inspection($source));
		$this->repairer->expects(self::never())->method('preview');
		$this->repairer->expects(self::never())->method('repair');

		$tester = $this->create_tester(true);
		$tester->setInputs(['yes', 'split', '']);
		self::assertSame(0, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString($this->language->lang('CLI_TOPIC_TAGS_REPAIR_SKIPPED'), $tester->getDisplay());
	}

	public function test_replacement_prompt_rejects_invalid_source_and_duplicate_then_declines(): void
	{
		$source = $this->source_tag();
		$this->tag_manager->method('get_tags')->willReturn([1 => $source]);
		$this->repairer->method('inspect')->willReturn($this->inspection($source));
		$this->repairer->expects(self::once())->method('preview')->with(1, ['A', 'B'])->willReturn($this->preview($source));
		$this->repairer->expects(self::never())->method('repair');

		$tester = $this->create_tester(true);
		$tester->setInputs(['yes', 'split', str_repeat('x', 51), '(A)(B)', 'A', 'A', 'B', '', 'no']);
		self::assertSame(0, $tester->execute([], ['interactive' => true]));
		$display = $tester->getDisplay();
		self::assertStringContainsString($this->language->lang('CLI_TOPIC_TAGS_REPAIR_INVALID_TAG'), $display);
		self::assertStringContainsString($this->language->lang('CLI_TOPIC_TAGS_REPAIR_SOURCE_TARGET'), $display);
		self::assertStringContainsString($this->language->lang('CLI_TOPIC_TAGS_REPAIR_DUPLICATE_TAG'), $display);
		self::assertStringContainsString($this->language->lang('CLI_TOPIC_TAGS_REPAIR_SKIPPED'), $display);
	}

	public function test_preview_validation_failure_skips_tag(): void
	{
		$source = $this->source_tag();
		$this->tag_manager->method('get_tags')->willReturn([1 => $source]);
		$this->repairer->method('inspect')->willReturn($this->inspection($source));
		$this->repairer->method('preview')->willThrowException(new \InvalidArgumentException('CLI_TOPIC_TAGS_REPAIR_INVALID_TAG'));
		$this->repairer->expects(self::never())->method('repair');

		$tester = $this->create_tester(true);
		$tester->setInputs(['yes', 'split', 'A', '']);
		self::assertSame(0, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString($this->language->lang('CLI_TOPIC_TAGS_REPAIR_INVALID_TAG'), $tester->getDisplay());
	}

	public function test_board_reenabled_before_apply_aborts(): void
	{
		$source = $this->source_tag();
		$this->tag_manager->method('get_tags')->willReturn([1 => $source]);
		$this->repairer->method('inspect')->willReturn($this->inspection($source));
		$this->repairer->method('preview')->willReturnCallback(function () use ($source) {
			$this->config['board_disable'] = 0;
			return $this->preview($source);
		});
		$this->repairer->expects(self::never())->method('repair');

		$tester = $this->create_tester(true);
		$tester->setInputs(['yes', 'split', 'A', 'B', '', 'yes']);
		self::assertSame(1, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString(
			$this->language->lang('CLI_TOPIC_TAGS_REPAIR_BOARD_ENABLED'),
			preg_replace('/\s+/', ' ', $tester->getDisplay())
		);
	}

	public function test_repair_failure_aborts(): void
	{
		$source = $this->source_tag();
		$this->tag_manager->method('get_tags')->willReturn([1 => $source]);
		$this->repairer->method('inspect')->willReturn($this->inspection($source));
		$this->repairer->method('preview')->willReturn($this->preview($source));
		$this->repairer->method('repair')->willThrowException(new \RuntimeException('Database failure.'));

		$tester = $this->create_tester(true);
		$tester->setInputs(['yes', 'split', 'A', 'B', '', 'yes']);
		self::assertSame(1, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString(
			$this->language->lang('CLI_TOPIC_TAGS_REPAIR_SPLIT_FAILED', 'Database failure.'),
			preg_replace('/\s+/', ' ', $tester->getDisplay())
		);
	}

	public function test_merge_failure_aborts(): void
	{
		$source = $this->source_tag();
		$target = ['prefix_id' => 2, 'prefix_tag' => 'Target', 'prefix_enabled' => 1];
		$preview = $this->preview($source);
		$preview['targets'] = [$target + ['existing' => true]];

		$this->tag_manager->method('get_tags')->willReturn([1 => $source, 2 => $target]);
		$this->repairer->method('inspect')->willReturn($this->inspection($source));
		$this->repairer->expects(self::once())->method('preview_merge')->with(1, 2)->willReturn($preview);
		$this->repairer->expects(self::once())->method('merge')->with(1, 2)->willThrowException(new \RuntimeException('Database failure.'));
		$this->repairer->expects(self::never())->method('repair');
		$this->log->expects(self::never())->method('add');

		$tester = $this->create_tester(true);
		$tester->setInputs(['yes', 'merge', 'Tag #2: Target', 'yes']);
		self::assertSame(1, $tester->execute(['--tag-id' => 1], ['interactive' => true]));
		self::assertStringContainsString(
			$this->language->lang('CLI_TOPIC_TAGS_REPAIR_MERGE_FAILED', 'Database failure.'),
			preg_replace('/\s+/', ' ', $tester->getDisplay())
		);
	}

	protected function source_tag(): array
	{
		return [
			'prefix_id' => 1,
			'prefix_tag' => '(A)(B)',
			'prefix_enabled' => 1,
			'prefix_color' => '4A76A8',
			'prefix_order' => 1,
		];
	}

	protected function inspection(array $source): array
	{
		return ['source' => $source, 'forum_count' => 1, 'topic_count' => 2];
	}

	protected function preview(array $source): array
	{
		return [
			'source' => $source,
			'targets' => [
				['prefix_id' => null, 'prefix_tag' => 'A', 'existing' => false],
				['prefix_id' => 2, 'prefix_tag' => 'B', 'existing' => true],
			],
			'forum_count' => 1,
			'topic_count' => 2,
			'cleanup' => [
				'topic_title' => 0,
				'post_subject' => 0,
				'topic_last_post_subject' => 0,
				'forum_last_post_subject' => 0,
			],
		];
	}

	protected function create_tester(bool $board_disabled): CommandTester
	{
		return new CommandTester($this->create_command($board_disabled));
	}

	protected function create_command(bool $board_disabled): \phpbb\topicprefixes\console\command\repair_tags
	{
		$user = $this->getMockBuilder('\phpbb\user')
			->disableOriginalConstructor()
			->getMock();
		$this->config = new \phpbb\config\config(['board_disable' => $board_disabled]);
		$command = new \phpbb\topicprefixes\console\command\repair_tags(
			$user,
			$this->config,
			$this->language,
			$this->tag_manager,
			$this->repairer,
			$this->log
		);

		return $command;
	}
}
