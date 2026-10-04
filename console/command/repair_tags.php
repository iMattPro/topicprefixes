<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\console\command;

use phpbb\config\config;
use phpbb\console\command\command;
use phpbb\language\language;
use phpbb\log\log_interface;
use phpbb\topicprefixes\tags\manager;
use phpbb\topicprefixes\tags\repairer;
use phpbb\user;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Interactive repair tool for combined legacy topic tags.
 */
class repair_tags extends command
{
	/** @var config */
	protected $config;

	/** @var language */
	protected $language;

	/** @var manager */
	protected $tag_manager;

	/** @var repairer */
	protected $repairer;

	/** @var log_interface */
	protected $log;

	public function __construct(user $user, config $config, language $language, manager $tag_manager, repairer $repairer, log_interface $log)
	{
		$this->config = $config;
		$this->language = $language;
		$this->tag_manager = $tag_manager;
		$this->repairer = $repairer;
		$this->log = $log;
		$this->language->add_lang('cli_topic_prefixes', 'phpbb/topicprefixes');

		parent::__construct($user);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function configure()
	{
		$this
			->setName('topicprefixes:repair-tags')
			->setDescription($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_DESCRIPTION'))
			->addOption(
				'tag-id',
				null,
				InputOption::VALUE_REQUIRED,
				$this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_TAG_ID')
			);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$io = new SymfonyStyle($input, $output);
		if (!$input->isInteractive())
		{
			$io->error($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_INTERACTIVE_REQUIRED'));
			return 1;
		}
		if (empty($this->config['board_disable']))
		{
			$io->error($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_BOARD_ENABLED'));
			return 1;
		}

		$io->warning($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_WARNING'));
		if (!$io->confirm($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_BACKUP_CONFIRM'), false))
		{
			$io->note($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_CANCELLED'));
			return 0;
		}

		$tags = $this->tag_manager->get_tags();
		$tag_id = $input->getOption('tag-id');
		if ($tag_id !== null)
		{
			$tag_id = (int) $tag_id;
			if ($tag_id <= 0 || !isset($tags[$tag_id]))
			{
				$io->error($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_TAG_NOT_FOUND'));
				return 1;
			}
			$tags = [$tag_id => $tags[$tag_id]];
		}
		if (!$tags)
		{
			$io->note($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_NO_TAGS'));
			return 0;
		}

		$io->title($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_TITLE'));
		$repaired = 0;
		$skipped = 0;
		foreach ($tags as $current_id => $tag)
		{
			$details = $this->repairer->inspect((int) $current_id);
			$io->section($this->language->lang(
				'CLI_TOPIC_PREFIXES_REPAIR_SOURCE',
				$current_id,
				OutputFormatter::escape($tag['prefix_tag']),
				!empty($tag['prefix_enabled'])
					? $this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_ENABLED')
					: $this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_DISABLED'),
				$details['topic_count'],
				$details['forum_count']
			));

			$choices = [
				$this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_ACTION_REPAIR'),
				$this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_ACTION_SKIP'),
				$this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_ACTION_QUIT'),
			];
			$action = $io->choice($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_ACTION'), $choices, $choices[1]);
			if ($action === $choices[2])
			{
				break;
			}
			if ($action !== $choices[0])
			{
				$skipped++;
				continue;
			}

			$replacements = $this->ask_replacements($io, $tag['prefix_tag']);
			if (!$replacements)
			{
				$skipped++;
				continue;
			}

			try
			{
				$preview = $this->repairer->preview((int) $current_id, $replacements);
			}
			catch (\InvalidArgumentException $e)
			{
				$io->error($e->getMessage());
				$skipped++;
				continue;
			}
			$this->display_preview($io, $preview);
			if (!$io->confirm($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_APPLY_CONFIRM'), false))
			{
				$io->note($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_SKIPPED'));
				$skipped++;
				continue;
			}
			if (empty($this->config['board_disable']))
			{
				$io->error($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_BOARD_ENABLED'));
				return 1;
			}

			try
			{
				$result = $this->repairer->repair((int) $current_id, $replacements);
			}
			catch (\Exception $e)
			{
				$io->error($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_FAILED', OutputFormatter::escape($e->getMessage())));
				return 1;
			}

			$target_names = array_column($result['targets'], 'prefix_tag');
			$this->log->add(
				'admin',
				ANONYMOUS,
				'',
				'ACP_LOG_TAG_REPAIRED',
				time(),
				[
					utf8_encode_ucr(utf8_htmlspecialchars($result['source']['prefix_tag'])),
					utf8_encode_ucr(utf8_htmlspecialchars(implode(', ', $target_names))),
				]
			);
			$io->success($this->language->lang(
				'CLI_TOPIC_PREFIXES_REPAIR_SUCCESS',
				OutputFormatter::escape($result['source']['prefix_tag']),
				OutputFormatter::escape(implode(', ', $target_names)),
				$result['topic_count']
			));
			$repaired++;
		}

		$io->success($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_SUMMARY', $repaired, $skipped));
		return 0;
	}

	/**
	 * Collect normalized replacement names one at a time.
	 */
	protected function ask_replacements(SymfonyStyle $io, string $source_name): array
	{
		$replacements = [];
		$seen = [];
		while (true)
		{
			$prompt = $replacements
				? $this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_ANOTHER_TAG')
				: $this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_FIRST_TAG');
			$value = $io->ask($prompt, false);
			if ($value === false || $value === null || trim($value) === '')
			{
				if (!$replacements)
				{
					$io->note($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_SKIPPED'));
				}
				break;
			}

			$stored_name = manager::normalize_name($value);
			if ($stored_name === '')
			{
				$io->error($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_INVALID_TAG'));
				continue;
			}
			$name = manager::decode_name($stored_name);
			$key = base64_encode($name);
			if ($name === $source_name)
			{
				$io->error($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_SOURCE_TARGET'));
				continue;
			}
			if (isset($seen[$key]))
			{
				$io->error($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_DUPLICATE_TAG'));
				continue;
			}

			$seen[$key] = true;
			$replacements[] = $name;
		}

		return $replacements;
	}

	/**
	 * Display all effects known before mutation.
	 */
	protected function display_preview(SymfonyStyle $io, array $preview): void
	{
		$rows = [];
		foreach ($preview['targets'] as $target)
		{
			$rows[] = [
				OutputFormatter::escape($target['prefix_tag']),
				$target['existing']
					? $this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_EXISTING_TAG', $target['prefix_id'])
					: $this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_NEW_TAG'),
			];
		}

		$io->section($this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_PREVIEW'));
		$io->table([
			$this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_REPLACEMENT'),
			$this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_STATUS'),
		], $rows);
		$io->listing([
			$this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_PREVIEW_TOPICS', $preview['topic_count']),
			$this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_PREVIEW_FORUMS', $preview['forum_count']),
			$this->language->lang(
				'CLI_TOPIC_PREFIXES_REPAIR_PREVIEW_TEXT',
				$preview['cleanup']['topic_title'],
				$preview['cleanup']['post_subject'],
				$preview['cleanup']['topic_last_post_subject'],
				$preview['cleanup']['forum_last_post_subject']
			),
			$this->language->lang('CLI_TOPIC_PREFIXES_REPAIR_PREVIEW_DELETE'),
		]);
	}
}
