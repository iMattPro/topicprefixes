# phpBB Topic Tags

This is the repository for development of phpBB Topic Tags, formerly named Topic Prefixes.

[![Build Status](https://github.com/phpbb-extensions/topicprefixes/actions/workflows/tests.yml/badge.svg)](https://github.com/phpbb-extensions/topicprefixes/actions)
[![codecov](https://codecov.io/gh/phpbb-extensions/topicprefixes/branch/master/graph/badge.svg?token=Dc0GWOeQWj)](https://codecov.io/gh/phpbb-extensions/topicprefixes)

The phpBB Topic Tags extension provides administrator-curated topic tags. Tags are structured topic metadata and are displayed separately from topic titles as colored badges. Features include:

- Create, edit, order, enable, disable, and color topic tags.
- Make each tag available in one or more forums.
- Assign multiple tags while creating a topic or editing its first post.
- Display readable colored badges in topic lists, topic pages, and search results.
- Filter forum and category Active Topics lists by one or more tags using AND semantics.
- Filter keyword and author search results shown as topics or posts when using phpBB native, MySQL fulltext, or PostgreSQL search; Sphinx search remains display-only.
- Upgrade existing title prefixes safely into relational tag assignments.
- This is the same extension currently in use at phpbb.com in the Extensions and Styles in development forums.

## Upgrading from 1.x

Before enabling version 2, make a full database backup and disable the board in
General > Board settings. The extension blocks a legacy upgrade while the board
is enabled.

Migration splits complete adjacent bracket sequences such as `[3.3][DEV]` into
separate tags. Legacy prefixes duplicated within or across multiple forums are 
consolidated into one tag available in each applicable forum when their decoded 
names and enabled states match. Other combined formats and same-name definitions with
different enabled states remain separate. This upgrade is one-way; restore the
pre-upgrade database backup to undo it.

## Repairing topic tags

Use the interactive command to split one tag into multiple separate tags or
merge one tag into another existing tag. Splitting can repair combined formats
that could not be handled automatically, such as `(A)(B)` or `A|B`:

```shell
php bin/phpbbcli.php topicprefixes:repair-tags
```

Use `--tag-id=ID` to repair one source tag. Back up the database and disable the
board before running the command; the command enforces both an interactive
backup confirmation and maintenance mode. Every proposed change is previewed
and must be confirmed before any data changes.

📦 [Download](https://www.phpbb.com/customise/db/extension/topicprefixes/) the latest release of this extension.

🐞 [Report bugs](https://github.com/phpbb-extensions/topicprefixes/issues) to our Issue Tracker.

💬 [Support](https://www.phpbb.com/customise/db/extension/topicprefixes/support) can be requested and discussed in this extension's support forum at phpBB.com.

## License

[GNU General Public License v2](license.txt)
