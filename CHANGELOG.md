# Changelog

All notable changes to `mod_insightjournal` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Versions map to the `$plugin->release` value in `version.php`.

## [Unreleased]

### Added

- Help buttons (contextual `_help` strings) for the activity settings
  `Insight prompt`, `Enable autosave`, and `Minimum characters for completion`,
  in English and German.

### Fixed

- `insightjournal_get_coursemodule_info()` now exposes the `completionentries`
  custom completion rule to core completion via
  `customdata['customcompletionrules']`. Previously the rule was never reported,
  so automatic completion was never evaluated and the rule description never
  appeared for learners. Found during live testing on Moodle 5.0.2.

## [0.2.0-beta] - 2026-06-17

First beta release. Targets Moodle 4.5+ (`$plugin->requires = 2024100700`),
maturity `MATURITY_BETA`.

### Added

- Insight Journal activity module: one insight prompt per activity instance.
- Learner workflow: write, manually save, and later edit a personal response,
  with optional autosave after a pause in typing.
- Optional minimum character count as an activity completion condition.
- Activity report (`report.php`) with participant search and capability-gated
  CSV export; spreadsheet-formula values are prefixed to reduce CSV injection risk.
- Course-level progress report (`coursereport.php`) across all Insight Journal
  activities in a course.
- Personal/trainer learner summary (`summary.php`), suitable for browser printing.
- Capabilities: `addinstance`, `view`, `submit`, `viewown`, `viewall`, `export`.
- Privacy API provider: metadata declaration, user-data export, and deletion for
  module context, a single approved user, and approved user lists.
- Moodle backup/restore support, including learner entries when user data is
  included; restore maps user IDs and skips entries for unavailable users.
- English and German language packs.

[Unreleased]: https://github.com/71Professor/insightjournal/compare/v0.2.0-beta...HEAD
[0.2.0-beta]: https://github.com/71Professor/insightjournal/releases/tag/v0.2.0-beta
