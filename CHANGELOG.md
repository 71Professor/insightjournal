# Changelog

All notable changes to `mod_insightjournal` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Versions map to the `$plugin->release` value in `version.php`.

## [Unreleased]

### Added

- **New per-activity setting: Trainer visibility for this activity** (`entriesvisibility`: Visible to trainer / Private), set by the course
  teacher who creates or edits an Insight Journal activity. Defaults to
  "Visible to trainer", so existing activities keep today's behaviour. With
  "Private", learner entries are visible to the learner who wrote them only:
  the activity report, course report, and personal summary pages remain
  reachable to trainers with `mod/insightjournal:viewall`, but show a notice
  instead of entry content, and CSV export is blocked. Applies uniformly to
  every role, including managers and site admins — there is no bypass. The
  course report and personal summary reflect this per activity (e.g. one
  activity's entries can be private while another's stay visible in the same
  course), instead of a single page-wide notice. There is deliberately no
  site-wide setting; visibility is a per-activity decision.
- Help buttons (contextual `_help` strings) for the activity settings
  `Task / Question`, `Enable autosave`, and `Minimum characters for completion`,
  in English and German.
- PHPUnit test suite (`tests/`): custom completion rule, lib callbacks, the
  `save_entry` external function, and the privacy provider, plus a test data
  generator. Includes regression tests for both completion fixes below.
- PHPStan static analysis (`phpstan.neon`, `phpstan-bootstrap.php`, level 5),
  using the `micaherne/phpstan-moodle` extension for Moodle-aware class/global
  resolution. One pre-existing Moodle core PHPDoc inaccuracy
  (`moodleform_mod::standard_intro_elements()` documents its `$customlabel`
  parameter as `null`-only even though passing a string is the documented,
  intended use) is baselined in `phpstan-baseline.neon` rather than worked
  around in our code.
- Behat acceptance tests (`tests/behat/insight_journal.feature`): the
  save/reload roundtrip and the minchars completion regression, run against
  Firefox via Selenium.
- Learner responses now use Moodle's site-configured rich-text editor
  (matching the existing prompt field) instead of a plain textarea, with a
  view/edit toggle: a saved response renders read-only with an "Edit"
  button, and "Save" returns to the read-only view. Responses are stored as
  HTML (`FORMAT_HTML`) going forward; `minchars`/`maxchars` and the
  `completionentries` completion rule are measured against visible
  characters (HTML tags stripped), not raw markup length. No image/file
  embedding is supported.
- Optional `promptcolor` activity setting: a hex colour code (e.g. `#ffcc00`)
  used as the background of the task/question box, on both the activity
  view and the personal summary page. Never affects the learner's response.
  Blank (the default) keeps today's appearance unchanged.
- ### Changed

- Renamed the activity setting label from **Insight prompt** to **Task /
  Question** (German: **Aufgabe / Frage**) for clarity, including its help
  text and the related **Task / Question background colour** setting
  (formerly **Prompt background colour**). The underlying `prompttext` and
  `promptcolor` field names are unchanged, so no database upgrade is needed.
- Accessibility: the autosave status now lives in an ARIA live region
  (`role="status"` / `aria-live="polite"`) so screen readers announce
  save progress, and the response field is associated with the minimum-character
  hint via `aria-describedby`. Bootstrap 4 utility classes (`sr-only`,
  `input-group-append`) are kept intentionally because the plugin supports
  Moodle 4.5 (Bootstrap 4); they remain valid on Moodle 5.0 via its compatibility layer.
- Code style aligned with the Moodle `phpcs` coding standard across all PHP files.

### Notes

- No dedicated Moodle Mobile App addon (`db/mobile.php`) in this release; the
  activity is usable via its responsive web view in the app.

### Fixed

- `insightjournal_get_coursemodule_info()` now exposes the `completionentries`
  custom completion rule to core completion via
  `customdata['customcompletionrules']`. Previously the rule was never reported,
  so automatic completion was never evaluated and the rule description never
  appeared for learners. Found during live testing on Moodle 5.0.2.
- `save_entry` now passes `COMPLETION_UNKNOWN` to `update_state()` so core
  recalculates completion via `custom_completion::get_state()`. Previously it
  forced `COMPLETION_COMPLETE`, which bypassed the `minchars` rule (any save,
  even an empty or too-short response, marked the activity complete and it never
  reverted). Found during browser UI testing on Moodle 5.0.2.
- `view.php`, `save_entry`, `custom_completion::get_state()`, and
  `insightjournal_get_coursemodule_info()` now explicitly `require_once` Moodle's
  `completionlib.php` before using `completion_info` or any `COMPLETION_*`
  constant, matching the convention used throughout Moodle core (e.g.
  `mod/page/view.php`). That library is never autoloaded or included by Moodle's
  bootstrap; production code was only working by incidentally relying on some
  other part of the same request already having loaded it. Found by actually
  executing the PHPUnit suite for the first time (moodle-docker, Moodle 5.0.8),
  which has no such incidental load and failed with `Undefined constant` errors.

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

[Unreleased]: https://github.com/71Professor/moodle-mod_insightjournal/compare/v0.2.0-beta...HEAD
[0.2.0-beta]: https://github.com/71Professor/moodle-mod_insightjournal/releases/tag/v0.2.0-beta
