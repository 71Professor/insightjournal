# mod_insightjournal – Insight Journal for Moodle

**Version 0.2.0-beta · June 2026 · Moodle 4.5+**

`mod_insightjournal` is a Moodle activity module for focused reflection prompts.
Each activity holds one prompt. Learners write and save their own response, can return
to edit it, and can open a printable personal summary of all their journal entries
across the course. Trainers see all entries, track course-wide progress, and can
export responses to CSV.

> **This is a beta release** distributed to a small group of educators and Moodle
> developers for feedback. See the [Feedback](#feedback) section below.

---

## Installation

1. Copy the `insightjournal` folder into the `mod/` directory of your Moodle
   installation, so the path is `mod/insightjournal/`.
2. Visit **Site administration → Notifications** — Moodle will detect the plugin
   and run the database installation automatically.
3. Purge caches after changing language strings, templates, or AMD JavaScript
   (**Site administration → Development → Purge caches**).
4. For production JavaScript builds, run Moodle's AMD build from the Moodle root:

   ```bash
   npx grunt amd
   ```

   In development environments with `$CFG->cachejs = false` this step is not required.

**Requirements:** Moodle 4.5+ · PHP 7.4+ · No Composer or Node.js runtime dependencies.

---

## Trainer Workflow

1. In a course, choose **Add an activity or resource → Insight Journal**.
2. Enter the activity **name** (shown in the course navigation).
3. Enter the **Insight prompt** — the reflection question or task for learners.
4. Optionally enable **autosave** (response is saved after a pause in typing).
5. Optionally set a **minimum character count** as an activity completion condition.
6. In the **Activity completion** settings, keep *Learner must save an Insight Journal
   response* enabled when saved responses should mark the activity complete.
7. After the course runs, open the **activity report** to review entries for one prompt,
   or the **course report** for progress across all Insight Journal activities.

---

## Learner Workflow

Learners open the activity, read the prompt, write a response, and save manually.
If autosave is enabled, the response is saved after a short pause in typing.
Learners can reopen and edit their saved response at any time. The personal summary
page lists all their Insight Journal responses in the course and is suitable for
browser printing (including save-as-PDF).

---

## Capabilities

| Capability | Default roles |
|---|---|
| `mod/insightjournal:addinstance` | Editing teacher, Manager |
| `mod/insightjournal:view` | Student, Teacher, Editing teacher |
| `mod/insightjournal:submit` | Student |
| `mod/insightjournal:viewown` | Student |
| `mod/insightjournal:viewall` | Teacher, Editing teacher, Manager |
| `mod/insightjournal:export` | Teacher, Editing teacher, Manager |

---

## Reports

- **`report.php`** — activity-level report with participant search and CSV export
  (requires `mod/insightjournal:export`).
- **`coursereport.php`** — course-level progress report across all Insight Journal
  activities.
- **`summary.php`** — personal or trainer-selected learner summary; suitable for
  browser printing.

---

## Data and Privacy

Insight Journal responses can contain sensitive personal content. The plugin stores:

- activity configuration in `insightjournal`;
- learner responses in `insightjournal_entries`:
  `userid`, response text, response format, creation time, and modification time.

The Privacy API declares stored data, exports user responses, and deletes all data
for a module context, a single approved user, or approved user lists.
CSV exports are restricted by capability; spreadsheet-formula values are prefixed
to reduce CSV injection risk.

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
7. Open a learner summary as the learner and as a teacher with `viewall`.
8. Run Moodle backup and restore — once with user data, once without.
9. Run privacy export and deletion for a test user.
10. Run PHP lint, Moodle Code Checker, and PHPUnit where available.

PHPUnit tests are in `tests/` and cover the custom completion rule, lib callbacks,
the `save_entry` external function, and the Privacy API provider.

---

## Known Limitations (Beta)

- **No native Moodle Mobile App addon** (`db/mobile.php` is not provided). The
  activity is usable in the app via its responsive web view; native in-app editing
  is planned for a later version.
- **No server-side PDF export.** The summary page uses the browser print dialog.
  A direct PDF download is planned for a later version.
- **PHPStan** has not yet been run in a full Moodle checkout.
- **Behat tests** are not yet provided (PHPUnit tests are included).

---

## Development Status

Beta (`MATURITY_BETA`). The plugin is feature-complete for the core workflow.
Outstanding work before a stable release:

- [ ] Run PHPStan in a full Moodle checkout
- [ ] Add Behat tests
- [ ] Verify on Moodle 4.5 and 5.x (tested on 5.0.2)
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
