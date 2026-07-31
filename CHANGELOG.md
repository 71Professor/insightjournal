# Changelog

All notable changes to `mod_insightjournal` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Versions map to the `$plugin->release` value in `version.php`.

## [Unreleased]

### Added

- **The activity now fires Moodle's standard events**, addressing the
  R2-09 review finding: `course_module_viewed` on every activity view,
  and new `entry_created`/`entry_updated` events on every successful
  entry save (never on a save that's rejected as a conflict). This makes
  activity show up in Moodle's standard activity log (**Reports → Logs**)
  and become available to any log-consuming plugin (analytics,
  notifications, etc.), which previously showed nothing for this
  activity at all. Purely additive and server-side; no visible change to
  any page. Note that `entry_updated` fires once per autosave as well as
  per manual save, so an actively-typing learner can generate multiple
  log rows per session.

### Fixed

- **The activity report's Separate Groups restriction is now scoped to
  the activity's own grouping and only counts participation-eligible
  groups**, closing the R3-01 review finding. Previously, a teacher
  without `moodle/site:accessallgroups` was restricted to their group's
  members course-wide - including members of groups tied to a
  *different* grouping than the one this activity actually uses, and
  including groups flagged as not participation-eligible. Both could
  make the report show (or hide) participants outside the activity's
  own group configuration. On an upgrading site, this is a *narrowing*
  change: a viewer with no participation-eligible group in this
  activity's own grouping now correctly sees no participants at all in
  that activity's report, where they may previously have seen their
  course-wide group's members. `coursereport.php`'s and `summary.php`'s
  equivalent checks still use the old, course-wide behaviour - not yet
  fixed here; that's the immediate next review item (R3-02).

- **The release workflow no longer trusts a `v`-prefixed branch/PR name
  alone**, addressing the R3-03 review finding. `ci.yml` runs on both
  `push` and `pull_request`, so a branch or PR merely *named* like a
  release tag (e.g. `v9.9.9-evil`) previously satisfied the release job's
  entire gate once its CI run completed. The job now also requires the
  triggering run to be a `push` from this repository (not a fork), and a
  new pre-checkout step queries the remote directly to confirm a real git
  tag exists and resolves to exactly the commit CI validated, failing
  closed otherwise; checkout then uses that verified tag ref instead of a
  bare SHA. No effect on a normal tagged release - only closes a spoofing
  path nothing in this project's history has actually exploited.

### Changed

- **Reports no longer show a participant's email address to a viewer who
  isn't allowed to see it.** `report.php` used to select and expose
  `u.email` unconditionally - on screen, in participant search, and in CSV
  export; `coursereport.php` only in its CSV export (it never showed email
  on screen or offered search). Email now only appears when the viewer holds
  Moodle's `moodle/site:viewuseridentity` capability *and* the site admin
  has kept `email` in **Site administration → Users → Permissions → User
  policies → Show user identity**, addressing the R2-06 review finding. On
  a default-configured site with the standard teacher/editing
  teacher/manager roles, this is invisible - nothing changes. It only
  matters for a restricted role or a site that has customised its
  identity-field configuration. Table and CSV column layout are unchanged
  either way; only the email value goes blank when not permitted.
  `summary.php`'s user fetch also drops a similarly-unconditional `email`
  selection that turned out to be entirely unused.

- **`coursereport.php`'s CSV export now uses Moodle's `csv_export_writer`
  instead of a hand-written `fputcsv()` loop**, addressing the R2-12 review
  finding. Two visible effects: it now begins with a UTF-8 byte-order mark
  (BOM), matching `report.php`'s CSV export (previously the two reports'
  CSV exports differed in this one respect — see the 0.7.1-beta entry
  below); and its formula-injection escaping (a leading `=`/`+`/`-`/`@`
  gets a defensive `'` prefix, per OWASP's CSV-injection guidance) now also
  catches a value with leading whitespace (spaces, tabs, line breaks) before
  that character, which the plugin's own previous hand-rolled check did not
  catch. If you parse this export programmatically, read it as
  UTF-8-with-BOM (e.g. Python's `csv` module needs `encoding='utf-8-sig'`,
  not plain `'utf-8'`) - otherwise a check like `row[0] == 'courseid'` will
  now fail against the BOM-prefixed first cell. The export's `Content-Type`
  also changes from `text/csv; charset=utf-8` to plain `text/csv`, matching
  `report.php`'s CSV export. Column layout and content are otherwise
  unchanged. The activity report's own CSV export (`report_table.php`)
  already went through Moodle's core writer since 0.7.1-beta and needed no
  equivalent fix — its now-redundant manual escaping calls were simply
  removed.

### Testing

- **Closed every test-coverage gap R2-11 identifies except one
  sub-item**, per the 2026-07-27 follow-up review: a save attempt
  without the `mod/insightjournal:submit` capability is now
  regression-tested end to end (rejected, writes nothing, fires no
  event); resubmitting an already-rejected stale save a second time is
  proven to fail again server-side; a non-editing teacher without
  `accessallgroups` in Separate Groups mode is now covered by an
  integration-level test that exercises the real `report.php` wiring,
  not just its underlying helpers in isolation; and course
  backup/restore is now verified to preserve an entry's `response`,
  `revision`, and `visibility` values and to exclude entries entirely
  when "Include user data" is off. No behaviour changed — this is
  coverage only. The one remaining sub-item (a direct regression test
  proving the R2-09 restore-mapping fix registers a queryable mapping)
  was investigated and found technically infeasible against Moodle's
  public API — its temp bookkeeping table is dropped inside
  `execute_plan()` itself — and is logged as an accepted gap, consistent
  with the same call already made for this exact mapping when R2-09
  shipped it.

## [0.7.1-beta] - 2026-07-28

### Changed

- **The activity report (`report.php`) and course-wide report
  (`coursereport.php`) now paginate** instead of loading every matching row
  in one request (20 per page by default, adjustable via a `perpage` URL
  parameter), addressing the R2-04 review finding. The activity report is
  now built on Moodle's `table_sql` API
  (`classes/table/report_table.php`), which also brings its CSV export
  in-house (previously a hand-written loop) — the exported CSV's 9-column
  format is unchanged. Both reports' CSV exports are unaffected by
  pagination and continue to export every matching row.

- **The activity report's CSV export now begins with a UTF-8 byte-order
  mark (BOM)**, a side effect of moving its export onto Moodle's own CSV
  writer (see above) for better default Excel compatibility. The
  course-wide report's CSV, still written by hand, is unaffected and has
  no BOM — the two reports' CSV exports are not currently byte-identical
  in this one respect. Unifying both onto the same writer is left to a
  future pass (R2-12).

- **The activity report's empty state** (no entries, or a search matching
  nothing) now shows Moodle's standard "Nothing to display" notification
  instead of the plugin's own "No entries yet." message, as a side effect
  of the `table_sql` migration above. The now-unused `noentries` string is
  deprecated (`lang/en/deprecated.txt`) rather than removed outright, per
  Moodle's convention of keeping a deprecated string's definition in place
  for at least one more release before deleting it; it is slated for
  actual removal in a later release.

- **The activity report, course-wide report, and learner summary page now
  respect Moodle's Separate Groups mode** (addressing the R2-05 review
  finding). A teacher without the `moodle/site:accessallgroups` capability
  in a Separate-Groups activity now sees only their own group's
  participants — previously every report showed every participant
  regardless of group mode. The activity's own edit form also gains the
  standard "Group mode" setting (`FEATURE_GROUPS` is now declared), so
  trainers can set it per-activity, not only via the course default. Only
  Separate Groups restricts; Visible Groups and No Groups are unaffected,
  matching every other Moodle activity's behaviour.

## [0.7.0-beta] - 2026-07-27

### Fixed

- **Closed a lost-update race in autosave that the previous optimistic-concurrency
  check alone did not prevent.** Server: `save_entry`'s read-compare-write is now
  serialised per entry (activity + user) via the Moodle Lock API, so two genuinely
  concurrent saves can no longer both read the same revision and both write; the
  loser is now reliably told about the conflict instead of occasionally winning a
  race. Client: on a rejected save, `autosave.js` now enters an explicit conflicted
  state instead of quietly adopting the server's revision as its new write base —
  it discards any queued save, disables further auto/manual saves, and shows the
  server's actual current content next to the still-editable, still-copyable local
  draft, with a "Reload page" action as the only way to resume saving. Previously,
  the very next autosave tick or manual click after a conflict could silently
  overwrite the other writer's newer text with the same stale local content that
  had just been rejected.

- **Declared the `revision` column in the Privacy API metadata**, with an
  English/German description, and included it in the user's data export
  alongside the other stored fields. It was added to `insightjournal_entries`
  for optimistic-concurrency saves but never documented as personal data.

### Changed

- **The response field is now a standard Moodle form (`classes/form/entry_form.php`)
  instead of a hand-wired rich-text editor.** `view.php` no longer calls
  `editors_head_setup()`/`use_editor()` directly, no longer requires
  `repository/lib.php` itself, and no longer needs the `return_types` option
  (dead in practice, since file/image attachments were already disabled via
  `maxfiles => 0`) — the standard `editor` form element handles all of that
  internally. A plain form submit now works with JavaScript disabled entirely,
  saving via the same code path as autosave: the actual save logic (the
  per-entry lock, the revision check, the completion update) moved out of
  `save_entry` into a shared `entry_manager` service that both the AJAX
  external function and the new form submission call. No change for
  JavaScript-enabled sessions: autosave, the character counter, the conflict
  banner, and the view/edit toggle all work exactly as before. The response
  field itself is now rendered by Moodle's standard form markup (label above
  the field, in the usual Moodle form styling) rather than the previous
  compact custom layout.

## [0.6.0-beta] - 2026-07-22

### Added

- **The personal summary page (`summary.php`) now shows a "Go to entry" link
  on each entry the viewer owns and can still submit to**, jumping straight
  to that activity's page to make changes, instead of requiring a trip back
  through the course.

### Changed

- **Entry visibility is now decided per entry by the entry's author, not by
  the trainer.** The former per-activity "Trainer visibility for this
  activity" setting is removed from the activity settings form entirely —
  trainers can no longer see or change it. Instead, each learner's response
  form has a "Keep this entry private (only visible to you)" checkbox,
  unticked by default (visible to trainer), which they can change at any
  time; toggling it saves immediately. Adds a `visibility` column to
  `insightjournal_entries` and a required `private` parameter on the
  `save_entry` external function; removes the `entriesvisibility` column
  from `insightjournal` (upgrade steps included). Existing activities that
  were set to "Private" have their existing entries migrated to private so
  that guarantee is preserved; everything else defaults to visible, matching
  a freshly written entry.

## [0.5.0-beta] - 2026-07-21

### Added

- **Optimistic-concurrency protection when saving an entry.** Every save now
  carries the revision it was based on; if the entry has since been saved
  elsewhere (another tab, another device) the save is rejected with a "Not
  saved: a newer version was saved elsewhere" notice instead of silently
  overwriting the newer text. Adds a `revision` column to
  `insightjournal_entries` (upgrade step, existing rows backfilled to `1`)
  and a new required `expectedrevision` parameter on the `save_entry`
  external function.
- Two new Behat scenarios: a successful manual save never shows the error
  status class, and saving/the character counter/autosave all work with the
  Atto editor instead of Tiny. Suite is now 8 scenarios (was 6).

### Changed

- `insightjournal_entries_visible_to_teacher()` now **fails closed**: an
  unexpected, legacy, or missing `entriesvisibility` value is treated as
  **not** visible to the trainer. Previously such a value was treated as
  visible. This only affects rows left with an unresolved value.
- The upgrade step that migrated the retired site-wide "Visible to trainer"
  setting now resolves each activity from what that old setting actually
  was, instead of unconditionally marking every legacy row visible; a
  missing or unrecognised old value now resolves to private.

### Fixed

- Learner responses no longer hard-depend on the Tiny editor: `autosave.js`
  requests `editor_tiny/editor` lazily and falls back to the plain textarea
  value when Tiny isn't the active editor, so autosave and the character
  counter keep working on sites using Atto or the plain text editor.
- The response editor no longer contradicts itself by enabling file
  management (`enable_filemanagement`) while `maxfiles` is `0`, which showed
  a non-functional "manage files" control in Atto.

## [0.4.1-beta] - 2026-07-17

First release published as a tagged GitHub Release with an installable ZIP.
Collects everything since 0.2.0-beta.

### Added

- **GitHub release workflow** (`.github/workflows/release.yml`): pushing a
  version tag (`v*`) builds an installable plugin ZIP whose root folder is
  named `insightjournal` — as the Moodle installer requires — and publishes it
  as a GitHub Release. The workflow fails if the tag does not match
  `$plugin->release` in `version.php`. This removes the need to manually
  rename the folder from GitHub's *Code → Download ZIP* archive
  (`moodle-mod_insightjournal-main`).
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
- Continuous integration via GitHub Actions using
  [moodle-plugin-ci](https://github.com/moodlehq/moodle-plugin-ci)
  (`.github/workflows/ci.yml`): every push and pull request runs phplint,
  phpmd, the Moodle Code Checker (phpcs), PHPDoc checks, plugin validation,
  upgrade-savepoint checks, Mustache lint, Grunt, PHPUnit, and Behat across a
  matrix of PHP 8.1–8.4, Moodle 4.5 LTS through `main`, and
  PostgreSQL/MariaDB. Contributed by Jonathan Champ (@jrchamp) — thanks!
  (PR #9)

### Changed

- **Repository layout cleaned up for maintainability**: development-only files
  (`docs/`, `phpstan.neon`, `phpstan-baseline.neon`, `phpstan-bootstrap.php`,
  `.github/`, Git metadata) are excluded from release ZIPs and GitHub source
  archives via `export-ignore`, so an installed plugin folder contains only
  runtime files; internal working documents were removed from the repository;
  the German user guide moved from the repository root to
  `docs/Reflexionstagebuch_Plugin_Dokumentation.md`.
- Installation instructions (README and German guide) now recommend the
  release ZIP from the GitHub Releases page and document that a folder
  unpacked from GitHub's *Code → Download ZIP* must be renamed to
  `insightjournal` before installing.
- Renamed the activity setting label from **Insight prompt** to **Task /
  Question** (German: **Aufgabe / Frage**) for clarity, including its help
  text and the related **Task / Question background colour** setting
  (formerly **Prompt background colour**). The underlying `prompttext` and
  `promptcolor` field names are unchanged, so no database upgrade is needed.
- Accessibility: the autosave status now lives in an ARIA live region
  (`role="status"` / `aria-live="polite"`) so screen readers announce
  save progress, and the response field is associated with the minimum-character
  hint via `aria-describedby`. The deprecated Bootstrap 4 class `sr-only` has
  been replaced with `visually-hidden`, which Moodle 4.5 already supports via
  its Bootstrap 5 bridge; `input-group-append` is kept intentionally for
  Moodle 4.5 (Bootstrap 4) and remains valid on Moodle 5.0 via its
  compatibility layer. (PR #9)
- Code style aligned with the Moodle `phpcs` coding standard across all PHP
  files, now enforced by CI: consistent spacing after type casts, multi-line
  array formatting, and alphabetically sorted language strings (English and
  German, no wording changes). JavaScript in `amd/src/` now passes Moodle's
  ESLint rules and the `amd/build/` bundles were rebuilt via Grunt, including
  a proper source map for `summary.min.js`. (PR #9)

### Notes

- No dedicated Moodle Mobile App addon (`db/mobile.php`) in this release; the
  activity is usable via its responsive web view in the app.

### Fixed

- Two Behat scenarios still configured the site-wide `entriesvisibletoteacher`
  setting that was removed when visibility became a per-activity decision,
  breaking the acceptance test run. They now set `entriesvisibility` on the
  activity instead. Found by the new CI. (PR #9)
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

[Unreleased]: https://github.com/71Professor/insightjournal/compare/v0.5.0-beta...HEAD
[0.2.0-beta]: https://github.com/71Professor/insightjournal/releases/tag/v0.2.0-beta
