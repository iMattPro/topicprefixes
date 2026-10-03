<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

$lang = array_merge($lang, [
	'CLI_TOPIC_PREFIXES_REPAIR_DESCRIPTION' => 'Interactively split or replace migrated legacy topic tags.',
	'CLI_TOPIC_PREFIXES_REPAIR_TAG_ID' => 'Repair only this tag identifier.',
	'CLI_TOPIC_PREFIXES_REPAIR_INTERACTIVE_REQUIRED' => 'This repair tool requires an interactive terminal.',
	'CLI_TOPIC_PREFIXES_REPAIR_BOARD_ENABLED' => 'Disable the board under General, Board settings before repairing topic tags.',
	'CLI_TOPIC_PREFIXES_REPAIR_WARNING' => 'This tool changes topic tags, topic assignments, and exact legacy text at the start of associated titles and subjects.',
	'CLI_TOPIC_PREFIXES_REPAIR_BACKUP_CONFIRM' => 'Do you have a current database backup?',
	'CLI_TOPIC_PREFIXES_REPAIR_CANCELLED' => 'No changes made.',
	'CLI_TOPIC_PREFIXES_REPAIR_TAG_NOT_FOUND' => 'Requested topic tag does not exist.',
	'CLI_TOPIC_PREFIXES_REPAIR_NO_TAGS' => 'No topic tags are available to repair.',
	'CLI_TOPIC_PREFIXES_REPAIR_TITLE' => 'Topic tag repair',
	'CLI_TOPIC_PREFIXES_REPAIR_SOURCE' => 'Tag #%1$d: %2$s — %3$s, %4$d assigned topics, %5$d forums',
	'CLI_TOPIC_PREFIXES_REPAIR_ENABLED' => 'enabled',
	'CLI_TOPIC_PREFIXES_REPAIR_DISABLED' => 'disabled',
	'CLI_TOPIC_PREFIXES_REPAIR_ACTION' => 'Choose an action',
	'CLI_TOPIC_PREFIXES_REPAIR_ACTION_REPAIR' => 'repair',
	'CLI_TOPIC_PREFIXES_REPAIR_ACTION_SKIP' => 'skip',
	'CLI_TOPIC_PREFIXES_REPAIR_ACTION_QUIT' => 'quit',
	'CLI_TOPIC_PREFIXES_REPAIR_FIRST_TAG' => 'Enter first replacement tag, or leave blank to cancel',
	'CLI_TOPIC_PREFIXES_REPAIR_ANOTHER_TAG' => 'Enter another replacement tag, or leave blank to finish',
	'CLI_TOPIC_PREFIXES_REPAIR_INVALID_TAG' => 'Tag is empty or too long.',
	'CLI_TOPIC_PREFIXES_REPAIR_SOURCE_TARGET' => 'Replacement cannot equal source tag.',
	'CLI_TOPIC_PREFIXES_REPAIR_DUPLICATE_TAG' => 'Replacement was already entered.',
	'CLI_TOPIC_PREFIXES_REPAIR_SKIPPED' => 'Tag skipped. No changes made.',
	'CLI_TOPIC_PREFIXES_REPAIR_PREVIEW' => 'Repair preview',
	'CLI_TOPIC_PREFIXES_REPAIR_REPLACEMENT' => 'Replacement',
	'CLI_TOPIC_PREFIXES_REPAIR_STATUS' => 'Status',
	'CLI_TOPIC_PREFIXES_REPAIR_EXISTING_TAG' => 'reuse existing tag #%d',
	'CLI_TOPIC_PREFIXES_REPAIR_NEW_TAG' => 'create new tag',
	'CLI_TOPIC_PREFIXES_REPAIR_PREVIEW_TOPICS' => 'Transfer assignments for %d topics.',
	'CLI_TOPIC_PREFIXES_REPAIR_PREVIEW_FORUMS' => 'Merge availability from %d forums.',
	'CLI_TOPIC_PREFIXES_REPAIR_PREVIEW_TEXT' => 'Clean exact legacy text from %1$d topic titles, %2$d first-post subjects, %3$d last-post subjects, and %4$d forum last-post subjects.',
	'CLI_TOPIC_PREFIXES_REPAIR_PREVIEW_DELETE' => 'Delete source tag only after every transfer succeeds.',
	'CLI_TOPIC_PREFIXES_REPAIR_APPLY_CONFIRM' => 'Apply this repair?',
	'CLI_TOPIC_PREFIXES_REPAIR_FAILED' => 'Repair failed; source tag was retained where possible. Error: %s',
	'CLI_TOPIC_PREFIXES_REPAIR_SUCCESS' => 'Repaired “%1$s” into “%2$s” across %3$d topics.',
	'CLI_TOPIC_PREFIXES_REPAIR_SUMMARY' => 'Finished: %1$d repaired, %2$d skipped.',
]);
