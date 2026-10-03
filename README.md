# phpBB Topic Tags

This is the repository for development of phpBB Topic Tags, formerly named Topic Prefixes.

[![Build Status](https://github.com/phpbb-extensions/topicprefixes/actions/workflows/tests.yml/badge.svg)](https://github.com/phpbb-extensions/topicprefixes/actions)
[![codecov](https://codecov.io/gh/phpbb-extensions/topicprefixes/branch/master/graph/badge.svg?token=Dc0GWOeQWj)](https://codecov.io/gh/phpbb-extensions/topicprefixes)

The phpBB Topic Tags extension provides administrator-curated topic tags. Tags are structured topic metadata and are displayed separately from topic titles as colored badges. Features include:

- Create, edit, order, enable, disable, and color topic tags.
- Make each tag available in one or more forums.
- Assign multiple tags while creating a topic or editing its first post.
- Display readable colored badges in topic lists, topic pages, and search results.
- Filter forum topic lists by one or more tags using AND semantics.
- Upgrade existing title prefixes safely into relational tag assignments.
- This is the same extension currently in use at phpbb.com in the Extensions and Styles in development forums.

## Repairing combined legacy tags

After upgrading from Topic Prefixes 1.x, use the interactive repair command for
combined prefixes that could not be split automatically, such as `(A)(B)` or
`A|B`:

```shell
php bin/phpbbcli.php topicprefixes:repair-tags
```

Use `--tag-id=ID` to repair one tag. Back up the database and disable the board
before running the command; the command enforces both an interactive backup
confirmation and maintenance mode. Every proposed repair is previewed and must
be confirmed before any data changes.

📦 [Download](https://www.phpbb.com/customise/db/extension/topicprefixes/) the latest release of this extension.

🐞 [Report bugs](https://github.com/phpbb-extensions/topicprefixes/issues) to our Issue Tracker.

💬 [Support](https://www.phpbb.com/customise/db/extension/topicprefixes/support) can be requested and discussed in this extension's support forum at phpBB.com.

## License

[GNU General Public License v2](license.txt)
