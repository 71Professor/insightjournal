# mod_insightjournal Review Checklist

## Code Quality

- [x] Frankenstyle component name is `mod_insightjournal`.
- [x] Plugin folder is intended for `mod/insightjournal`.
- [x] PHP lint run on Moodle 5.0.2 / PHP 8.2 (no syntax errors).
- [ ] Moodle Code Checker run in a full Moodle development environment.
- [ ] PHPStan or equivalent static analysis run in a full Moodle development environment.
- [x] Visible UI strings are routed through language files.
- [x] Large page output moved to Mustache templates.
- [x] JavaScript moved to AMD modules.

## Installation And Compatibility

- [x] `db/install.xml` defines activity and entry tables.
- [x] Unique index exists for `(insightjournalid, userid)`.
- [x] Upgrade step exists for the new completion field.
- [ ] Fresh install tested on Moodle 4.5+.
- [ ] Upgrade from the alpha MVP tested.
- [x] Moodle 5.0.2 installation tested (clean install via CLI on MariaDB).

## Roles And Security

- [x] Capabilities declared for add, view, submit, view own, view all, and export.
- [x] Activity view requires login and `mod/insightjournal:view`.
- [x] Save service validates context and requires `mod/insightjournal:submit`.
- [x] Reports require `mod/insightjournal:viewall`.
- [x] CSV export requires `mod/insightjournal:export`.
- [x] CSV values are guarded against spreadsheet formula injection.
- [ ] Role matrix tested with student, teacher, editing teacher, and manager accounts.

## Activity Features

- [x] Manual save implemented.
- [x] Autosave implemented as AMD JavaScript.
- [x] Activity completion can require a saved response.
- [x] Minimum character count is considered by completion state.
- [x] Completion behavior tested in Moodle UI (save -> complete, sub-minchars/empty -> reverts). Cron/task context still untested.
- [ ] Mobile behavior tested.
- [ ] Accessibility tested with keyboard and screen reader tooling.

## Reports

- [x] Activity report lists entries and supports participant search.
- [x] Activity report CSV export implemented.
- [x] Course report lists participants and progress across activities.
- [x] Course report CSV export implemented.
- [x] Personal summary uses templates and print-friendly controls.
- [ ] Report performance tested with larger courses.

## Privacy And Backup

- [x] Privacy metadata declared.
- [x] User data export implemented.
- [x] Context deletion implemented.
- [x] Single-user and multi-user deletion implemented.
- [x] Backup includes settings and optional user entries.
- [x] Restore maps user IDs.
- [ ] Privacy export/delete tested in Moodle.
- [ ] Backup/restore tested with and without user data.

## Documentation

- [x] README covers purpose, installation, roles, privacy, backup/restore, reports, tests, and status.
- [x] Known limitations documented.
- [ ] Screenshots added.
- [ ] Plugin database metadata prepared.
