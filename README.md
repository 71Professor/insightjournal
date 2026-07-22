# mod_insightjournal – Insight Journal for Moodle

[![Moodle Plugin CI](https://github.com/71Professor/insightjournal/actions/workflows/ci.yml/badge.svg)](https://github.com/71Professor/insightjournal/actions/workflows/ci.yml)

**Version 0.5.0-beta · July 2026 · Moodle 4.5+**

`mod_insightjournal` is a Moodle activity module for focused reflection tasks and
questions. Each activity holds one task or question. Learners write and save their
own response, can return to edit it, and can open a printable personal summary of
all their journal entries across the course. Each learner decides for themselves,
per entry, whether trainers may see it. Trainers track course-wide progress and
can export to CSV, but only ever see the entries their authors chose to share.

> **This is a beta release** distributed to a small group of educators and Moodle
> developers for feedback. See the [Feedback](#feedback) section below.

A detailed German user guide is available at
[`docs/Reflexionstagebuch_Plugin_Dokumentation.md`](docs/Reflexionstagebuch_Plugin_Dokumentation.md).

---

## Installation

### Recommended: install the release ZIP

1. Download the `mod_insightjournal-v…zip` file from the latest
   [GitHub release](https://github.com/71Professor/insightjournal/releases).
   Its root folder is already named `insightjournal`, as the Moodle installer requires.
2. In Moodle, go to **Site administration → Plugins → Install plugins**, upload the
   ZIP and follow the installer — the database installation runs automatically.

Alternatively, unzip the release ZIP into the `mod/` directory of your Moodle
installation (so the path is `mod/insightjournal/`) and visit
**Site administration → Notifications**.

### From a Git checkout

Clone the repository into `mod/insightjournal/` and visit
**Site administration → Notifications**.

> **Note:** GitHub's *Code → Download ZIP* button packs the plugin into a folder
> named `insightjournal-main`. If you install from that ZIP instead of a
> release ZIP, rename the unpacked folder to `insightjournal` first — otherwise the
> Moodle installer rejects it.

### After installing

- Purge caches after changing language strings, templates, or AMD JavaScript
  (**Site administration → Development → Purge caches**).
- For production JavaScript builds, run Moodle's AMD build from the Moodle root:

  ```bash
  npx grunt amd
  ```

  In development environments with `$CFG->cachejs = false` this step is not required.

**Requirements:** Moodle 4.5+ · PHP 7.4+ · No Composer or Node.js runtime dependencies.

---

## Trainer Workflow

1. In a course, choose **Add an activity or resource → Insight Journal**.
2. Enter the activity **name** (shown in the course navigation).
3. Enter the **Task / Question** — the reflection question or task for learners.
4. Optionally set a **Task / Question background colour** (a hex code, e.g. `#ffcc00`) to
   set it visually apart from the learner's response, wherever it is shown.
5. Optionally enable **autosave** (response is saved after a pause in typing).
6. Optionally set a **minimum character count** as an activity completion condition,
   and/or a **maximum character count**, enforced with a live counter as learners type.
7. In the **Activity completion** settings, keep *Learner must save an Insight Journal
   response* enabled when saved responses should mark the activity complete.
8. After the course runs, open the **activity report** to review entries for one task/question,
   or the **course report** for progress across all Insight Journal activities. Each
   learner decides for themselves whether their own entry is visible to trainers —
   see [Data and Privacy](#data-and-privacy).

---

## Learner Workflow

Learners open the activity, read the task/question, write a response using Moodle's
rich-text editor, and save manually. If autosave is enabled, the response is
saved after a short pause in typing.
Learners can reopen and edit their saved response at any time. Next to the
response is a **"Keep this entry private (only visible to you)"** checkbox,
unticked by default, so trainers can read the entry unless the learner opts
out. Toggling it saves immediately, and it can be changed again at any time —
see [Data and Privacy](#data-and-privacy). Each save checks
that no newer version was saved elsewhere in the meantime (e.g. from another
tab); if one was, the save is rejected with a notice instead of silently
overwriting it. The personal summary page lists all their Insight Journal
responses in the course and is suitable for browser printing (including
save-as-PDF).

---

## Capabilities

| Capability                       | Default roles                     |
| -------------------------------- | --------------------------------- |
| `mod/insightjournal:addinstance` | Editing teacher, Manager          |
| `mod/insightjournal:view`        | Student, Teacher, Editing teacher |
| `mod/insightjournal:submit`      | Student                           |
| `mod/insightjournal:viewown`     | Student                           |
| `mod/insightjournal:viewall`     | Teacher, Editing teacher, Manager |
| `mod/insightjournal:export`      | Teacher, Editing teacher, Manager |

---

## Reports

- **`report.php`** — activity-level report with participant search and CSV export
  (requires `mod/insightjournal:export`).
- **`coursereport.php`** — course-level progress report across all Insight Journal
  activities.
- **`summary.php`** — personal or trainer-selected learner summary; suitable for
  browser printing. Each of the viewer's own, still-writable entries shows an
  Edit link straight back to that activity.

---

## Data and Privacy

Insight Journal responses can contain sensitive personal content. The plugin stores:

- activity configuration in `insightjournal`;
- learner responses in `insightjournal_entries`:
  `userid`, response text, response format, visibility, creation time, and modification time.

The Privacy API declares stored data, exports user responses, and deletes all data
for a module context, a single approved user, or approved user lists.
CSV exports are restricted by capability; spreadsheet-formula values are prefixed
to reduce CSV injection risk.

Each entry has its own visibility choice, made **only by the learner who wrote
it** — a **"Keep this entry private (only visible to you)"** checkbox on the
response form, unticked by default (visible to trainer). The learner can change
it at any time, and trainers have no setting anywhere that can override it. With
an entry marked private, the activity report, course report, and personal
summary pages stay reachable to anyone with `mod/insightjournal:viewall`, but
show a notice instead of that entry's content; CSV export replaces only that
row with the notice rather than blocking the whole export. This applies to
every role, including managers and site admins — there is no bypass. Different
learners in the same activity can choose differently; the reports and summary
reflect this per entry rather than hiding a whole page or column.

---

## Backup and Restore

Moodle backup includes activity settings. Learner entries are included only when
user data is included in the backup. Restore maps user IDs through Moodle's restore
mapping and skips entries when the mapped user is unavailable.

---

## Testing

Recommended local test flow:

1. Install the plugin in a Moodle 4.5+ development site.
2. Create a course with at least one teacher and two students.
3. Add two Insight Journal activities: one with autosave enabled, one disabled.
4. As a student: save a response, reload the activity, edit it, confirm completion
   updates (check the completion condition with minimum characters, if set).
5. As a teacher: open the activity report, search by participant, download CSV.
6. Open the course report and verify progress counts.
7. Open a learner summary as the learner and as a teacher with `viewall`;
   confirm the learner sees an Edit link on their own entries and the teacher
   sees none.
8. Run Moodle backup and restore — once with user data, once without.
9. Run privacy export and deletion for a test user.
10. Run PHP lint, Moodle Code Checker, PHPUnit, PHPStan, and Behat where available.

PHPUnit tests are in `tests/` and cover the custom completion rule, lib callbacks,
the `save_entry` external function, and the Privacy API provider.

PHPStan (level 5) is configured via `phpstan.neon` and requires the
[`micaherne/phpstan-moodle`](https://packagist.org/packages/micaherne/phpstan-moodle)
extension installed in the Moodle checkout being analysed
(`composer require --dev micaherne/phpstan-moodle`), plus `phpstan-bootstrap.php`
to load a real site. Run from the Moodle root:
`vendor/bin/phpstan analyse -c mod/insightjournal/phpstan.neon`.

Behat scenarios are in `tests/behat/insight_journal.feature` and cover the
save/reload roundtrip, editing a previously saved response, autosave
persisting a change without leaving edit mode, the minchars completion
regression, a successful save never showing the error status, a learner
marking their own entry private, saving/the character counter/autosave
with the Atto editor, and a learner choosing differently across two
activities in the same course. Run via
`php admin/tool/behat/cli/run.php --tags=@mod_insightjournal`
after `php admin/tool/behat/cli/init.php`.

---

## Known Limitations (Beta)

- **No native Moodle Mobile App addon** (`db/mobile.php` is not provided). The
  activity is usable in the app via its responsive web view; native in-app editing
  is planned for a later version.
- **No server-side PDF export.** The summary page uses the browser print dialog.
  A direct PDF download is planned for a later version.
- **Behat coverage is limited**: eight scenarios cover the save/reload
  roundtrip, editing a saved response, autosave, the minchars completion
  regression, a learner marking their own entry private, choosing
  differently across two activities in the same course, the Atto editor,
  and the save-status classes. Broader coverage (CSV export) is not yet
  automated.
- **Two navigation links share the label "Insight report"**: the activity
  settings navigation link to the per-activity report (`report.php`) and the
  on-page button to the course-wide report (`coursereport.php`) use the same
  text, which can be confusing when both are visible on the same page.
  Cosmetic only — found while adding Behat coverage for the per-activity
  visibility override (2026-07-09); no functional impact. Planned: give the
  course-wide link a distinct label (e.g. "Course insight report", matching
  its own page heading).

---

## Development Status

Beta (`MATURITY_BETA`). The plugin is feature-complete for the core workflow.
Outstanding work before a stable release:

- [x] Run PHPStan in a full Moodle checkout (level 5, clean) — 2026-07-07
- [x] Add Behat tests (8 scenarios: save/reload roundtrip, editing a saved
      response, autosave, completion regression, save-status classes, a
      learner marking their own entry private, Atto editor, choosing
      differently across activities) — 2026-07-09, extended 2026-07-21,
      2026-07-22
- [x] Execute the PHPUnit suite (moodle-docker, Moodle 5.0.8) — 2026-07-07
- [x] Verify on Moodle 4.5 and 5.x (tested on 4.5 and 5.0.2)
- [ ] Add screenshots for the Plugin Directory
- [ ] Decide whether a dedicated moderation/entry-management capability is needed

---

## Feedback

This beta is distributed to a small group of educators and Moodle developers.
All feedback is welcome — whether you are evaluating it as a developer or as a trainer.

**Particularly interested in:**

- Is the trainer workflow intuitive enough inside Moodle?
- Are there features missing for real-world use?
- Does autosave behave as expected? Does the completion condition work correctly?
- Any issues with specific Moodle versions, themes, or role configurations?
- Code review: anything that violates Moodle coding standards or best practices?

**Contact:** Michael Kohl — michaelkohl71@gmail.com

**GitHub:** https://github.com/71Professor/insightjournal/issues
