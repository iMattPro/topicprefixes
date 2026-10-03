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
	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\language\language */
	protected $language;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\topicprefixes\tags\manager */
	protected $tag_manager;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\topicprefixes\tags\repairer */
	protected $repairer;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\log\log_interface */
	protected $log;

	protected function setUp(): void
	{
		parent::setUp();
		$this->language = $this->getMockBuilder('\phpbb\language\language')
			->disableOriginalConstructor()
			->getMock();
		$this->language->method('lang')->willReturnCallback(function ($key) {
			$arguments = func_get_args();
			array_shift($arguments);
			$messages = [
				'CLI_TOPIC_PREFIXES_REPAIR_ACTION_REPAIR' => 'repair',
				'CLI_TOPIC_PREFIXES_REPAIR_ACTION_SKIP' => 'skip',
				'CLI_TOPIC_PREFIXES_REPAIR_ACTION_QUIT' => 'quit',
			];
			$message = $messages[$key] ?? $key;
			return $arguments ? vsprintf($message, $arguments) : $message;
		});
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
		self::assertStringContainsString('CLI_TOPIC_PREFIXES_REPAIR_INTERACTIVE_REQUIRED', $tester->getDisplay());
	}

	public function test_command_requires_disabled_board(): void
	{
		$tester = $this->create_tester(false);
		self::assertSame(1, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString('CLI_TOPIC_PREFIXES_REPAIR_BOARD_ENABLED', $tester->getDisplay());
	}

	public function test_declined_backup_confirmation_changes_nothing(): void
	{
		$this->tag_manager->expects(self::never())->method('get_tags');
		$tester = $this->create_tester(true);
		$tester->setInputs(['no']);

		self::assertSame(0, $tester->execute([], ['interactive' => true]));
		self::assertStringContainsString('CLI_TOPIC_PREFIXES_REPAIR_CANCELLED', $tester->getDisplay());
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
			'ACP_LOG_TAG_REPAIRED',
			self::isType('int'),
			['(A)(B)', 'A, B']
		);

		$tester = $this->create_tester(true);
		$tester->setInputs(['y', '0', 'A', 'B', '', 'y']);
		self::assertSame(0, $tester->execute(['--tag-id' => 1], ['interactive' => true]));
		self::assertStringContainsString('CLI_TOPIC_PREFIXES_REPAIR_SUCCESS', $tester->getDisplay());
		self::assertStringContainsString('CLI_TOPIC_PREFIXES_REPAIR_SUMMARY', $tester->getDisplay());
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

	protected function create_tester(bool $board_disabled): CommandTester
	{
		$user = $this->getMockBuilder('\phpbb\user')
			->disableOriginalConstructor()
			->getMock();
		$command = new \phpbb\topicprefixes\console\command\repair_tags(
			$user,
			new \phpbb\config\config(['board_disable' => $board_disabled]),
			$this->language,
			$this->tag_manager,
			$this->repairer,
			$this->log
		);

		return new CommandTester($command);
	}
}
