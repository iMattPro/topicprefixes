<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

/**
 * DO NOT CHANGE
 */
if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = array();
}

$lang = array_merge($lang, array(
	'TOPIC_PREFIXES'			=> 'Topic prefixes',
	'TOPIC_PREFIXES_EXPLAIN'	=> 'From this page you can manage topic prefixes for forums.',

	'TOPIC_PREFIX_TAG'			=> 'Prefix Tag',
	'TOPIC_PREFIX_ENABLED'		=> 'Prefix Enabled',
	'TOPIC_PREFIXES_EMPTY'		=> 'There are no prefixes for this forum.',

	'CREATE_TOPIC_PREFIX'			=> 'Create a new topic prefix',
	'DELETE_TOPIC_PREFIX_CONFIRM'	=> 'Are you sure you want to delete the topic prefix?',
	'TOPIC_PREFIX_DELETED'			=> 'The topic prefix has been deleted.',

	'TOPIC_PREVIEW_TOGGLE_STATE'	=> array(
		0	=> 'Click to enable',
		1	=> 'Click to disable',
	),

	// Nested set exception messages
	'TOPIC_PREFIXES_LOCK_FAILED_ACQUIRE'	=> 'Topic prefixes extension failed to acquire a lock on the table.',
	'TOPIC_PREFIXES_INVALID_ITEM'			=> 'The requested topic prefix is invalid.',
	'TOPIC_PREFIXES_INVALID_PARENT'			=> 'The requested topic prefix has no valid parent.',

));
