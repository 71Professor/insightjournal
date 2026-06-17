# mod_insightjournal - Insight Journal activity for Moodle

`mod_insightjournal` is a beta Moodle activity module for course-based insight prompts.
Each activity contains one prompt. Learners save their own response, can return to edit it,
and can open a printable course summary of their insight journal entries.

## Installation

1. Copy this folder to `mod/insightjournal` in a Moodle installation.
2. Visit `Site administration > Notifications` to install or upgrade the plugin.
3. Purge caches after changing language strings, templates, or AMD JavaScript.
4. For production JavaScript builds, run Moodle's AMD build process from the Moodle root:

   ```bash
   npx grunt amd
   ```

The plugin targets Moodle 4.5+ and is currently marked `MATURITY_BETA`.

## Trainer Workflow

1. In a course, choose `Add an activity or resource > Insight Journal`.
2. Enter the activity name, optional description, and the insight prompt.
3. Configure autosave and the optional minimum character count.
4. In activity completion settings, keep `Learner must save an insight journal response` enabled
   when saved responses should mark the activity complete.
5. Open the activity report to review entries for one prompt.
6. Open the course insight report for progress across all Insight Journal activities.
7. Download CSV exports where the role has `mod/insightjournal:export`.

## Learner Workflow

Learners open the activity, read the prompt, write a response, and save manually.
If autosave is enabled, the response is saved after a short pause in typing.
Learners can reopen and edit their saved response. The personal summary page lists all visible
insight journal activities in the course and is suitable for browser printing.

## Capabilities

- `mod/insightjournal:addinstance`: add an activity instance.
- `mod/insightjournal:view`: view the activity.
- `mod/insightjournal:submit`: save an own response.
- `mod/insightjournal:viewown`: view own insight journal entries.
- `mod/insightjournal:viewall`: view learner responses and reports.
- `mod/insightjournal:export`: export report data to CSV.

Default archetypes:

- Student: view, submit, view own.
- Teacher/editing teacher: view, view all, export.
- Manager: all configured capabilities.

## Data And Privacy

Insight Journal responses can contain sensitive personal content. The plugin stores:

- activity configuration in `insightjournal`;
- learner responses in `insightjournal_entries`;
- `userid`, response text, response format, creation time, and modification time.

The Privacy API declares stored data, exports user responses, deletes all data in a module
context, deletes data for one approved user, and deletes data for approved user lists.
CSV exports are restricted by capability and prefix spreadsheet-formula values to reduce
CSV injection risk.

## Backup And Restore

Moodle backup includes activity settings. Learner entries are included only when user data is
included in the backup. Restore maps user IDs through Moodle's restore mapping and skips entries
when the mapped user is unavailable.

## Reports

- `report.php`: activity-level report with participant search and CSV export.
- `coursereport.php`: course-level progress report across insight journal activities.
- `summary.php`: personal or trainer-selected learner summary.

## Testing

Recommended local test flow:

1. Install the plugin in a Moodle 4.5+ development site.
2. Create a course with at least one teacher and two students.
3. Add two insight journal activities, one with autosave enabled and one disabled.
4. As a student, save a response, reload the activity, edit it, and confirm completion updates.
5. As a teacher, open the activity report, search by participant, and download CSV.
6. Open the course report and verify progress counts.
7. Open a learner summary as the learner and as a teacher with `viewall`.
8. Run Moodle backup and restore once with user data and once without user data.
9. Run privacy export and deletion for a test user.
10. Run PHP lint, Moodle Code Checker, and Moodle PHPUnit/Behat where available.

Suggested PHPUnit coverage:

- creating and updating an entry through the external API;
- capability failures for save/report/export;
- completion state after saving and after clearing a response;
- Privacy API export and deletion;
- backup/restore with and without user data.

## Development Status

Beta. Remaining review-hardening work:

- run Moodle Code Checker and PHPStan in a full Moodle checkout;
- add automated PHPUnit and Behat tests;
- verify Moodle 4.5 and 5.x compatibility;
- add screenshots;
- review mobile layout and accessibility with real Moodle themes;
- decide whether a dedicated moderation/entry-management capability is needed in a later version.
