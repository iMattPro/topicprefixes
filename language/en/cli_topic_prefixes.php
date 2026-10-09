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
	'CLI_TOPIC_TAGS_REPAIR_NO_TEXT_CHANGES' => 'Titles and post subjects are not changed.',
	'CLI_TOPIC_TAGS_REPAIR_DESCRIPTION' => 'Interactively split or merge topic tags.',
	'CLI_TOPIC_TAGS_REPAIR_TAG_ID' => 'Repair only this source tag identifier.',
	'CLI_TOPIC_TAGS_REPAIR_INTERACTIVE_REQUIRED' => 'This tag tool requires an interactive terminal.',
	'CLI_TOPIC_TAGS_REPAIR_BOARD_ENABLED' => 'Disable the board under General, Board settings before repairing topic tags.',
	'CLI_TOPIC_TAGS_REPAIR_WARNING' => 'This tool changes topic tags and topic assignments. Titles and post subjects are left unchanged.',
	'CLI_TOPIC_TAGS_REPAIR_BACKUP_CONFIRM' => 'Do you have a current database backup?',
	'CLI_TOPIC_TAGS_REPAIR_CANCELLED' => 'No changes made.',
	'CLI_TOPIC_TAGS_REPAIR_TAG_NOT_FOUND' => 'Requested topic tag does not exist.',
	'CLI_TOPIC_TAGS_REPAIR_NO_TAGS' => 'No topic tags are available to repair.',
	'CLI_TOPIC_TAGS_REPAIR_TITLE' => 'Topic tag repair',
	'CLI_TOPIC_TAGS_REPAIR_SOURCE' => 'Tag #%1$d: %2$s — %3$s, %4$s, %5$s',
	'CLI_TOPIC_TAGS_REPAIR_SOURCE_TOPICS' => [
		1 => '%d assigned topic',
		2 => '%d assigned topics',
	],
	'CLI_TOPIC_TAGS_REPAIR_SOURCE_FORUMS' => [
		1 => '%d forum',
		2 => '%d forums',
	],
	'CLI_TOPIC_TAGS_REPAIR_ENABLED' => 'enabled',
	'CLI_TOPIC_TAGS_REPAIR_DISABLED' => 'disabled',
	'CLI_TOPIC_TAGS_REPAIR_ACTION' => 'Choose an action',
	'CLI_TOPIC_TAGS_REPAIR_ACTION_SPLIT' => 'split',
	'CLI_TOPIC_TAGS_REPAIR_ACTION_MERGE' => 'merge',
	'CLI_TOPIC_TAGS_REPAIR_ACTION_SKIP' => 'skip',
	'CLI_TOPIC_TAGS_REPAIR_ACTION_QUIT' => 'quit',
	'CLI_TOPIC_TAGS_REPAIR_FIRST_TAG' => 'Enter first tag, or leave blank to cancel',
	'CLI_TOPIC_TAGS_REPAIR_ANOTHER_TAG' => 'Enter another tag, or leave blank to finish',
	'CLI_TOPIC_TAGS_REPAIR_NEW_TAG_REQUIRED' => 'Enter at least one separate tag.',
	'CLI_TOPIC_TAGS_REPAIR_INVALID_TAG' => 'Tag is empty or too long.',
	'CLI_TOPIC_TAGS_REPAIR_SOURCE_TARGET' => 'Destination tag cannot equal source tag.',
	'CLI_TOPIC_TAGS_REPAIR_DUPLICATE_TAG' => 'Separate tag was already entered.',
	'CLI_TOPIC_TAGS_REPAIR_SKIPPED' => 'Tag skipped. No changes made.',
	'CLI_TOPIC_TAGS_REPAIR_MERGE_TARGET' => 'Choose the existing tag to merge into',
	'CLI_TOPIC_TAGS_REPAIR_MERGE_TARGET_OPTION' => 'Tag #%1$d: %2$s',
	'CLI_TOPIC_TAGS_REPAIR_MERGE_CANCEL' => 'cancel',
	'CLI_TOPIC_TAGS_REPAIR_MERGE_NO_TARGETS' => 'No other topic tags are available as a merge destination.',
	'CLI_TOPIC_TAGS_REPAIR_MERGE_TARGET_NOT_FOUND' => 'Merge destination tag does not exist.',
	'CLI_TOPIC_TAGS_REPAIR_SPLIT_PREVIEW' => 'Tag split preview',
	'CLI_TOPIC_TAGS_REPAIR_MERGE_PREVIEW' => 'Tag merge preview',
	'CLI_TOPIC_TAGS_REPAIR_REPLACEMENT' => 'Tag',
	'CLI_TOPIC_TAGS_REPAIR_STATUS' => 'Status',
	'CLI_TOPIC_TAGS_REPAIR_EXISTING_TAG' => 'reuse existing tag #%d',
	'CLI_TOPIC_TAGS_REPAIR_NEW_TAG' => 'create new tag',
	'CLI_TOPIC_TAGS_REPAIR_SPLIT_PREVIEW_TOPICS' => [
		1 => 'Assign every separate tag to %d topic currently using the source tag.',
		2 => 'Assign every separate tag to %d topics currently using the source tag.',
	],
	'CLI_TOPIC_TAGS_REPAIR_SPLIT_PREVIEW_FORUMS' => [
		1 => 'Copy source tag availability to every separate tag across %d forum.',
		2 => 'Copy source tag availability to every separate tag across %d forums.',
	],
	'CLI_TOPIC_TAGS_REPAIR_MERGE_PREVIEW_TOPICS' => [
		1 => 'Assign the destination tag to %d topic currently using the source tag.',
		2 => 'Assign the destination tag to %d topics currently using the source tag.',
	],
	'CLI_TOPIC_TAGS_REPAIR_MERGE_PREVIEW_FORUMS' => [
		1 => 'Add source tag availability to the destination tag across %d forum.',
		2 => 'Add source tag availability to the destination tag across %d forums.',
	],
	'CLI_TOPIC_TAGS_REPAIR_MERGE_PREVIEW_ENABLE' => 'Enable the destination tag because the source tag is enabled.',
	'CLI_TOPIC_TAGS_REPAIR_PREVIEW_DELETE' => 'Delete source tag only after every transfer succeeds.',
	'CLI_TOPIC_TAGS_REPAIR_SPLIT_APPLY_CONFIRM' => 'Apply this tag split?',
	'CLI_TOPIC_TAGS_REPAIR_MERGE_APPLY_CONFIRM' => 'Apply this tag merge?',
	'CLI_TOPIC_TAGS_REPAIR_SPLIT_FAILED' => 'Tag split failed; source tag was retained where possible. Error: %s',
	'CLI_TOPIC_TAGS_REPAIR_MERGE_FAILED' => 'Tag merge failed; source tag was retained where possible. Error: %s',
	'CLI_TOPIC_TAGS_REPAIR_SPLIT_SUCCESS' => [
		1 => 'Split “%1$s” into “%2$s” across %3$d topic.',
		2 => 'Split “%1$s” into “%2$s” across %3$d topics.',
	],
	'CLI_TOPIC_TAGS_REPAIR_MERGE_SUCCESS' => [
		1 => 'Merged “%1$s” into “%2$s” across %3$d topic.',
		2 => 'Merged “%1$s” into “%2$s” across %3$d topics.',
	],
	'CLI_TOPIC_TAGS_REPAIR_SUMMARY' => 'Finished: %1$s, %2$s, %3$s.',
	'CLI_TOPIC_TAGS_REPAIR_SUMMARY_SPLITS' => [
		1 => '%d split',
		2 => '%d splits',
	],
	'CLI_TOPIC_TAGS_REPAIR_SUMMARY_MERGES' => [
		1 => '%d merge',
		2 => '%d merges',
	],
	'CLI_TOPIC_TAGS_REPAIR_SUMMARY_SKIPS' => [
		1 => '%d skip',
		2 => '%d skips',
	],
]);
