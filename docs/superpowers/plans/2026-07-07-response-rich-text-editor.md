# Rich-text response editor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the learner response `<textarea>` in `mod_insightjournal` with Moodle's site-configured rich-text editor, behind a view/edit toggle (saved response renders read-only with an Edit button; Save returns to the read-only view), while keeping min/maxchars and completion measured against visible text, not markup.

**Architecture:** Both the read-only view and the editor markup are rendered on page load; `editors_get_preferred_editor()->use_editor()` attaches once, and a `d-none` CSS class toggles which panel is shown — no fragment-API/lazy-load round trip. `save_entry` accepts and stores HTML (`PARAM_CLEANHTML`, `FORMAT_HTML`) and returns the freshly formatted HTML so the client can swap the view panel without a reload. A new `insightjournal_html_to_text()` helper gives one canonical "visible characters" measurement, used by maxchars validation, the `completionentries` rule, and the "is this response actually empty" check across every display surface (an empty editor serialises to `<p></p>`, not `""`).

**Tech Stack:** Moodle 4.5+/5.0 plugin (PHP 8.x, PHPUnit, Behat/Selenium), vanilla AMD/RequireJS JS, Mustache templates. Reference environment: `~/moodle-dev` (Moodle 5.0.8 core checkout + `moodlehq/moodle-docker`), synced from this repo via the `syncij` shell alias.

## Global Constraints

- Moodle version floor: `$plugin->requires = 2024100700;` (Moodle 4.5+). Do not use APIs unavailable before 4.5.
- No database schema changes. `insightjournal_entries.responseformat` already exists.
- No image/file embedding: editor is attached with `maxfiles => 0`, `trusttext => false`, `subdirs => false` (same restriction as the existing prompt-field editor in `mod_form.php`).
- Stored response format is always `FORMAT_HTML` going forward (no format selector exposed to the learner). Pre-existing `FORMAT_PLAIN` entries are left as-is and continue to render correctly since every read path uses `format_text($response, $responseformat, ...)`, which honours each row's own stored format.
- "Visible characters" (minchars, maxchars, and the emptiness check used by `completionentries` and every "no response" UI state) means HTML tags stripped, via the single shared helper `insightjournal_html_to_text()` in `locallib.php` — never raw string length on stored HTML.
- Manual "Save" click always returns the UI to the read-only view panel. Autosave (the debounced background save while typing) never does — it only updates the "saved at..." status text and leaves the editor open.
- No "Cancel" action, no lazy/fragment-loaded editor — see the spec's Non-goals section (`docs/superpowers/specs/2026-07-07-response-rich-text-editor-design.md`) for the full rationale.
- Every code change in this plan is made in `/mnt/c/Git/insightjournal` (this repo). Before running PHPUnit/Behat in the reference environment, sync with the `syncij` shell alias (copies this repo into `~/moodle-dev/moodle/mod/insightjournal`). This plan calls that out at each test-run step.

---

## Task 1: `insightjournal_html_to_text()` helper

**Files:**
- Modify: `locallib.php`
- Test: `tests/locallib_test.php` (create)

**Interfaces:**
- Produces: `insightjournal_html_to_text(string $html): string` — strips HTML tags and trims whitespace. Used by every later task that needs a "visible characters" measurement or an "is this response actually empty" check.

- [ ] **Step 1: Write the failing test**

Create `tests/locallib_test.php`:

```php
<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Unit tests for locallib.php helpers in mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_insightjournal;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');

/**
 * Tests for {@see \insightjournal_html_to_text()}.
 */
#[CoversFunction('insightjournal_html_to_text')]
final class locallib_test extends advanced_testcase {
    /**
     * Tags are stripped but visible text survives.
     */
    public function test_strips_tags_and_keeps_visible_text(): void {
        $this->assertEquals(
            'Hello world',
            \insightjournal_html_to_text('<p>Hello <strong>world</strong></p>')
        );
    }

    /**
     * An empty rich-text editor serialises to markup, not an empty string.
     */
    public function test_empty_editor_shell_is_empty(): void {
        $this->assertEquals('', \insightjournal_html_to_text('<p></p>'));
        $this->assertEquals('', \insightjournal_html_to_text('<p><br></p>'));
    }

    /**
     * Leading/trailing whitespace is trimmed, including whitespace-only input.
     */
    public function test_trims_whitespace(): void {
        $this->assertEquals('', \insightjournal_html_to_text("   \n\t  "));
    }

    /**
     * Multibyte characters are not mangled by tag stripping.
     */
    public function test_preserves_multibyte_characters(): void {
        $this->assertEquals('äöüéè', \insightjournal_html_to_text('äöüéè'));
    }

    /**
     * List items are not concatenated into one unreadable word.
     */
    public function test_list_items_produce_separated_text(): void {
        $text = \insightjournal_html_to_text('<ul><li>one</li><li>two</li></ul>');
        $this->assertStringContainsString('one', $text);
        $this->assertStringContainsString('two', $text);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
syncij
cd ~/moodle-dev/moodle-docker
bin/moodle-docker-compose exec webserver php admin/tool/phpunit/cli/init.php
bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter locallib_test
```

Expected: FAIL — `Call to undefined function insightjournal_html_to_text()`.

- [ ] **Step 3: Implement the helper**

In `locallib.php`, add this function after `insightjournal_csv_value()` and before `insightjournal_send_csv_headers()`:

```php
/**
 * Convert stored response HTML to its visible plain-text form.
 *
 * Used to measure "visible characters" for minchars/maxchars and to decide
 * whether a response is meaningfully empty. An empty rich-text editor
 * serialises to markup like "<p></p>" or "<p><br></p>", not "", so a raw
 * trim()/strlen() check on stored HTML is unreliable.
 *
 * @param string $html Stored response HTML (or plain text).
 * @return string Trimmed visible text, with all markup stripped.
 */
function insightjournal_html_to_text(string $html): string {
    return trim(html_to_text($html, 0, false));
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
syncij
cd ~/moodle-dev/moodle-docker
bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter locallib_test
```

Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add locallib.php tests/locallib_test.php
git commit -m "Add insightjournal_html_to_text() helper for visible-character checks"
```

---

## Task 2: `save_entry` accepts and stores HTML

**Files:**
- Modify: `classes/external/save_entry.php`
- Modify: `tests/external/save_entry_test.php`

**Interfaces:**
- Consumes: `insightjournal_html_to_text(string $html): string` (Task 1).
- Produces: `save_entry::execute_returns()` gains a `responsehtml` (`PARAM_RAW`) field — the cleaned, `format_text()`-rendered response. Task 5 (JS) consumes this field to update the read-only view panel after a manual save without a page reload.

- [ ] **Step 1: Write the failing tests**

In `tests/external/save_entry_test.php`, replace `test_save_creates_entry()` with a version that also asserts the stored format, and add four new tests after `test_completion_reverts_when_response_shortened()` (before the closing `}` of the class):

```php
    /**
     * A first save persists the entry for the current user, as HTML.
     */
    public function test_save_creates_entry(): void {
        global $DB;

        $result = $this->save('short');

        $this->assertTrue($result['success']);
        $entries = $DB->get_records('insightjournal_entries', [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->student->id,
        ]);
        $this->assertCount(1, $entries);
        $entry = reset($entries);
        $this->assertEquals('short', $entry->response);
        $this->assertEquals(FORMAT_HTML, (int) $entry->responseformat);
    }
```

(This replaces the existing method body; the method name and position stay the same.)

```php
    /**
     * Allowed formatting tags survive HTML cleaning and are stored as-is.
     */
    public function test_html_formatting_is_preserved(): void {
        global $DB;

        $this->save('<p>Hello <strong>world</strong></p>');

        $entry = $DB->get_record('insightjournal_entries', [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->student->id,
        ]);
        $this->assertStringContainsString('<strong>world</strong>', $entry->response);
    }

    /**
     * Disallowed tags (e.g. script) are stripped by the server-side HTML cleaner.
     */
    public function test_script_tags_are_stripped(): void {
        global $DB;

        $this->save('<p>Hello</p><script>alert(1)</script>');

        $entry = $DB->get_record('insightjournal_entries', [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->student->id,
        ]);
        $this->assertStringNotContainsString('<script', $entry->response);
        $this->assertStringContainsString('Hello', $entry->response);
    }

    /**
     * The return value includes the cleaned response, formatted for display.
     */
    public function test_returns_formatted_response_html(): void {
        $result = $this->save('<p>Hello <strong>world</strong></p>');

        $this->assertStringContainsString('<strong>world</strong>', $result['responsehtml']);
    }

    /**
     * maxchars counts visible characters, not HTML markup.
     */
    public function test_maxchars_counts_visible_text_not_markup(): void {
        $generator = $this->getDataGenerator();
        $journal = $generator->create_module('insightjournal', [
            'course' => $this->course->id,
            'maxchars' => 10,
        ]);

        // Heavy markup, but only 5 visible characters: fits within maxchars.
        $result = save_entry::execute((int) $journal->cmid, '<p><strong><em>hello</em></strong></p>');
        $result = external_api::clean_returnvalue(save_entry::execute_returns(), $result);
        $this->assertTrue($result['success']);

        // 12 visible characters, no markup at all: exceeds maxchars of 10.
        $this->expectException(\moodle_exception::class);
        save_entry::execute((int) $journal->cmid, 'twelve chars');
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
syncij
cd ~/moodle-dev/moodle-docker
bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter save_entry_test
```

Expected: FAIL — `test_save_creates_entry` fails on the `FORMAT_HTML` assertion (currently stores `FORMAT_PLAIN`); the four new tests fail (`<strong>` stripped since `PARAM_TEXT` currently strips all HTML; `responsehtml` key undefined; maxchars test fails since the 5-visible-character markup case currently gets rejected by raw-length counting).

- [ ] **Step 3: Implement**

Replace `classes/external/save_entry.php` in full:

```php
<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * External API: save_entry for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_insightjournal\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;

/**
 * External function to save or update a learner's insight journal entry.
 */
class save_entry extends external_api {
    /**
     * Describes the parameters for the save_entry function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'response' => new external_value(PARAM_RAW, 'Learner response (HTML)'),
        ]);
    }

    /**
     * Saves or updates the entry for the current user and updates completion.
     *
     * @param int $cmid Course module id.
     * @param string $response Learner response HTML.
     * @return array Result with success flag, entry id, timestamps, and rendered HTML.
     */
    public static function execute(int $cmid, string $response): array {
        global $DB, $USER, $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'response' => $response]);
        $cm = get_coursemodule_from_id('insightjournal', $params['cmid'], 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $diary = $DB->get_record('insightjournal', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_login($course, false, $cm);
        require_capability('mod/insightjournal:submit', $context);
        $now = time();
        $response = clean_param($params['response'], PARAM_CLEANHTML);
        $visiblelength = \core_text::strlen(insightjournal_html_to_text($response));
        if (!empty($diary->maxchars) && $visiblelength > (int)$diary->maxchars) {
            throw new \moodle_exception('maxcharserror', 'mod_insightjournal', '', (int)$diary->maxchars);
        }
        $entry = $DB->get_record('insightjournal_entries', ['insightjournalid' => $diary->id, 'userid' => $USER->id]);
        if ($entry) {
            $entry->response = $response;
            $entry->responseformat = FORMAT_HTML;
            $entry->timemodified = $now;
            $DB->update_record('insightjournal_entries', $entry);
            $id = $entry->id;
        } else {
            $id = $DB->insert_record('insightjournal_entries', (object)[
                'insightjournalid' => $diary->id,
                'userid' => $USER->id,
                'response' => $response,
                'responseformat' => FORMAT_HTML,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        // Let core recalculate the state via custom_completion::get_state() so the
        // minchars rule is honoured and completion reverts when the response no
        // longer qualifies. Forcing COMPLETION_COMPLETE here would bypass minchars.
        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $USER->id);
        }

        $timestr = userdate($now, get_string('strftimedatetimeshort', 'langconfig'));
        return [
            'success' => true,
            'id' => $id,
            'timemodified' => $now,
            'timestr' => $timestr,
            'responsehtml' => format_text($response, FORMAT_HTML, ['context' => $context]),
        ];
    }

    /**
     * Describes the return value for the save_entry function.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the entry was saved'),
            'id' => new external_value(PARAM_INT, 'Entry id'),
            'timemodified' => new external_value(PARAM_INT, 'Unix timestamp'),
            'timestr' => new external_value(PARAM_TEXT, 'Formatted timestamp'),
            'responsehtml' => new external_value(PARAM_RAW, 'The saved response, cleaned and rendered for display'),
        ]);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
syncij
cd ~/moodle-dev/moodle-docker
bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter save_entry_test
```

Expected: PASS (10 tests). If `test_html_formatting_is_preserved` or `test_script_tags_are_stripped` fail because Moodle's HTML purifier produced different (but still safe) markup than expected, adjust the assertion to match the actual purifier output shown in the failure diff — the important invariant is "safe tags survive, `<script>` does not", not the exact byte-for-byte string.

- [ ] **Step 5: Commit**

```bash
git add classes/external/save_entry.php tests/external/save_entry_test.php
git commit -m "Store and return learner responses as cleaned HTML"
```

---

## Task 3: `completionentries` rule uses visible-text length

**Files:**
- Modify: `classes/completion/custom_completion.php`
- Modify: `tests/custom_completion_test.php`

**Interfaces:**
- Consumes: `insightjournal_html_to_text(string $html): string` (Task 1).

- [ ] **Step 1: Write the failing tests**

In `tests/custom_completion_test.php`, add these two tests after `test_minchars_uses_multibyte_length()`:

```php
    /**
     * An empty rich-text editor shell (no visible text) does not complete.
     */
    public function test_empty_html_shell_is_incomplete(): void {
        $this->resetAfterTest();
        $this->assertEquals(COMPLETION_INCOMPLETE, $this->compute_state(0, '<p></p>'));
        $this->assertEquals(COMPLETION_INCOMPLETE, $this->compute_state(0, '<p><br></p>'));
    }

    /**
     * minchars counts visible characters, not HTML markup.
     */
    public function test_minchars_counts_visible_text_not_markup(): void {
        $this->resetAfterTest();
        // 5 visible characters ("hello") wrapped in markup meets a 5-character minimum.
        $this->assertEquals(
            COMPLETION_COMPLETE,
            $this->compute_state(5, '<p><strong><em>hello</em></strong></p>')
        );
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
syncij
cd ~/moodle-dev/moodle-docker
bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter custom_completion_test
```

Expected: FAIL — `test_empty_html_shell_is_incomplete` fails (raw `trim('<p></p>')` is non-empty today); `test_minchars_counts_visible_text_not_markup` fails (raw markup length is 37 characters, over the 5-character minimum only by coincidence of comparison direction — assert against current behavior by running it and observing the actual failure).

- [ ] **Step 3: Implement**

In `classes/completion/custom_completion.php`, replace the body of `get_state()` from the `$entry = $DB->get_record(...)` line onward:

```php
        $entry = $DB->get_record(
            'insightjournal_entries',
            ['insightjournalid' => $diary->id, 'userid' => $this->userid],
            'response'
        );

        if (!$entry) {
            return COMPLETION_INCOMPLETE;
        }

        $visibletext = \insightjournal_html_to_text($entry->response);
        if ($visibletext === '') {
            return COMPLETION_INCOMPLETE;
        }

        $meetsminchars = \core_text::strlen($visibletext) >= (int)$diary->minchars;
        return $meetsminchars ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
```

Also add the `locallib.php` require alongside the existing `completionlib.php` one at the top of `get_state()`:

```php
    public function get_state(string $rule): int {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
syncij
cd ~/moodle-dev/moodle-docker
bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter custom_completion_test
```

Expected: PASS (all tests in the file, including the pre-existing ones — they use plain-text responses via the generator, which `insightjournal_html_to_text()` passes through unchanged).

- [ ] **Step 5: Commit**

```bash
git add classes/completion/custom_completion.php tests/custom_completion_test.php
git commit -m "Measure completionentries minchars against visible text, not raw HTML"
```

---

## Task 4: View/edit panel markup and editor attachment

**Files:**
- Modify: `templates/view.mustache`
- Modify: `view.php`
- Test: `tests/view_template_test.php` (create)

**Interfaces:**
- Consumes: `insightjournal_html_to_text()` (Task 1).
- Produces: template data attributes `data-insightjournal-view`, `data-insightjournal-response-display`, `data-insightjournal-view-status`, `data-insightjournal-edit`, `data-insightjournal-edit-panel` — Task 5 (JS) queries these by exact name.

- [ ] **Step 1: Write the failing template test**

Create `tests/view_template_test.php`:

```php
<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Unit tests for the mod_insightjournal/view template's view/edit panel markup.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_insightjournal;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the mod_insightjournal/view template.
 */
final class view_template_test extends advanced_testcase {
    /**
     * Builds a minimal, valid template context, with overrides.
     *
     * @param array $overrides Context keys to override.
     * @return array The template context.
     */
    protected function make_context(array $overrides = []): array {
        return array_merge([
            'cmid' => 5,
            'prompt' => '<p>What did you learn?</p>',
            'canwrite' => true,
            'haveentry' => false,
            'responseraw' => '',
            'responseformatted' => '',
            'autosave' => true,
            'minchars' => 0,
            'maxchars' => 0,
            'lastsaved' => '',
            'sesskey' => 'abc',
            'reporturl' => 'https://example.com/report.php',
            'summaryurl' => 'https://example.com/summary.php',
            'sectionurl' => 'https://example.com/course.php',
            'canviewall' => false,
        ], $overrides);
    }

    /**
     * With no saved entry, the edit panel is shown and the view panel is hidden.
     */
    public function test_no_entry_shows_edit_panel_only(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/view', $this->make_context());

        $this->assertMatchesRegularExpression('/data-insightjournal-view[^>]*class="[^"]*d-none/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-insightjournal-edit-panel[^>]*class="[^"]*d-none/', $html);
    }

    /**
     * With a saved entry, the view panel is shown and the edit panel is hidden.
     */
    public function test_existing_entry_shows_view_panel_only(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/view', $this->make_context([
            'haveentry' => true,
            'responseformatted' => '<p>My reflection</p>',
        ]));

        $this->assertDoesNotMatchRegularExpression('/data-insightjournal-view[^>]*class="[^"]*d-none/', $html);
        $this->assertMatchesRegularExpression('/data-insightjournal-edit-panel[^>]*class="[^"]*d-none/', $html);
        $this->assertStringContainsString('My reflection', $html);
    }

    /**
     * A user without submit capability sees neither panel.
     */
    public function test_readonly_user_sees_no_editor(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/view', $this->make_context(['canwrite' => false]));

        $this->assertStringNotContainsString('data-insightjournal-response', $html);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
syncij
cd ~/moodle-dev/moodle-docker
bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter view_template_test
```

Expected: FAIL — the current template has no `data-insightjournal-view`/`data-insightjournal-edit-panel` elements at all, so both regex assertions in the first two tests fail.

- [ ] **Step 3: Implement the template**

Replace `templates/view.mustache` in full:

```mustache
{{!
    This file is part of Moodle - https://moodle.org/

    Moodle is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    Moodle is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with Moodle.  If not, see <https://www.gnu.org/licenses/>.
}}
{{!
    @template mod_insightjournal/view

    Insight journal activity main view with the prompt and the learner response area.

    The response area has two mutually-exclusive panels, toggled by
    amd/src/autosave.js: a read-only view panel (shown when a saved entry
    exists) and an edit panel containing the rich-text editor (shown
    otherwise, or after clicking Edit). Both panels are always rendered;
    visibility is controlled purely via the "d-none" class so the editor
    only needs to be attached once, at page load.

    Classes required for JS:
    * none

    Data attributes required for JS:
    * data-cmid
    * data-insightjournal-view
    * data-insightjournal-response-display
    * data-insightjournal-view-status
    * data-insightjournal-edit
    * data-insightjournal-edit-panel
    * data-insightjournal-response
    * data-insightjournal-save
    * data-insightjournal-status

    Context variables required for this template:
    * cmid - Course module id.
    * prompt - Pre-formatted prompt HTML (rendered via triple braces).
    * canwrite - Whether the current user may write a response.
    * haveentry - Whether a saved, non-empty response already exists.
    * responseraw - The user's current response, as stored HTML (for the editor).
    * responseformatted - The user's current response, pre-formatted for display.
    * lastsaved - Localised "last saved" status text.
    * minchars - Minimum characters required, or 0 if none.
    * maxchars - Maximum characters allowed, or 0 for no limit.
    * summaryurl - URL to the personal summary page.
    * canviewall - Whether the user may view the activity report.
    * reporturl - URL to the activity report.
    * sectionurl - URL to the course section where this activity lives.

    Example context (json):
    {
        "cmid": 5,
        "prompt": "<p>What did you learn today?</p>",
        "canwrite": true,
        "haveentry": true,
        "responseraw": "<p>Today I learned about Mustache templates.</p>",
        "responseformatted": "<p>Today I learned about Mustache templates.</p>",
        "lastsaved": "Saved 2 minutes ago",
        "minchars": 100,
        "maxchars": 500,
        "summaryurl": "https://example.com/mod/insightjournal/summary.php?courseid=2",
        "canviewall": false,
        "reporturl": "https://example.com/mod/insightjournal/report.php?id=5",
        "sectionurl": "https://example.com/course/view.php?id=2&section=3"
    }
}}
<div class="insightjournal-view" data-cmid="{{cmid}}">
    <div class="card mb-3">
        <div class="card-body">
            {{! prompt is pre-sanitised via format_text() in PHP – triple braces intentional, never use for student response }}
            <div class="insightjournal-prompt mb-3">{{{prompt}}}</div>
            {{#canwrite}}
                <div data-insightjournal-view class="{{^haveentry}}d-none{{/haveentry}}">
                    <div class="insightjournal-response-text mb-2" data-insightjournal-response-display>{{{responseformatted}}}</div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-secondary" data-insightjournal-edit>{{#str}}edit{{/str}}</button>
                        <span data-insightjournal-view-status class="small text-muted">{{lastsaved}}</span>
                    </div>
                </div>
                <div data-insightjournal-edit-panel class="{{#haveentry}}d-none{{/haveentry}}">
                    <label for="insightjournal-response-{{cmid}}" class="sr-only">{{#str}}response, mod_insightjournal{{/str}}</label>
                    <textarea id="insightjournal-response-{{cmid}}" name="insightjournalresponse" class="form-control" rows="10" cols="80" spellcheck="true" data-insightjournal-response {{#minchars}}aria-describedby="insightjournal-minchars-{{cmid}}" {{/minchars}}placeholder="{{#str}}responseplaceholder, mod_insightjournal{{/str}}">{{responseraw}}</textarea>
                    <div class="d-flex align-items-center gap-2 mt-2">
                        <button type="button" class="btn btn-primary" data-insightjournal-save>{{#str}}save, mod_insightjournal{{/str}}</button>
                        <span data-insightjournal-status role="status" aria-live="polite">{{lastsaved}}</span>
                        {{#maxchars}}<span data-insightjournal-charcounter class="small text-muted ms-auto" aria-live="polite"></span>{{/maxchars}}
                    </div>
                    {{#minchars}}<p id="insightjournal-minchars-{{cmid}}" class="small text-muted mt-2">{{#str}}mincharsnote, mod_insightjournal, {{minchars}}{{/str}}</p>{{/minchars}}
                </div>
            {{/canwrite}}
            {{^canwrite}}
                <div class="alert alert-info">{{#str}}readonlyteacher, mod_insightjournal{{/str}}</div>
            {{/canwrite}}
        </div>
    </div>
    <div class="mt-3">
        <a href="{{summaryurl}}" class="btn btn-secondary">{{#str}}mysummary, mod_insightjournal{{/str}}</a>
        {{#canviewall}}<a href="{{reporturl}}" class="btn btn-outline-secondary">{{#str}}report, mod_insightjournal{{/str}}</a>{{/canviewall}}
        <a href="{{sectionurl}}" class="btn btn-outline-secondary">{{#str}}backtosection, mod_insightjournal{{/str}}</a>
    </div>
</div>
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
syncij
cd ~/moodle-dev/moodle-docker
bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter view_template_test
```

Expected: PASS (3 tests).

- [ ] **Step 5: Wire the editor and new context variables into `view.php`**

This step has no dedicated automated test (there is no existing test harness for `view.php` itself); it is verified manually now and via Behat in Task 8. Replace `view.php` in full:

```php
<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * View page for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('insightjournal', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$diary = $DB->get_record('insightjournal', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/insightjournal:view', $context);

$PAGE->set_url('/mod/insightjournal/view.php', ['id' => $id]);
$PAGE->set_title(format_string($diary->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->js_call_amd('mod_insightjournal/autosave', 'init', [$cm->id, (int)$diary->autosave, (int)($diary->maxchars ?? 0)]);

$entry = $DB->get_record('insightjournal_entries', ['insightjournalid' => $diary->id, 'userid' => $USER->id]);
$canwrite = has_capability('mod/insightjournal:submit', $context);
$canviewall = has_capability('mod/insightjournal:viewall', $context);

$responseraw = $entry ? $entry->response : '';
$haveentry = insightjournal_html_to_text($responseraw) !== '';

if ($canwrite) {
    // Same restriction options as the prompt field's editor (mod_form.php): no
    // file/image attachments, content is never trusted.
    $editoroptions = [
        'subdirs' => false,
        'maxbytes' => 0,
        'maxfiles' => 0,
        'changeformat' => 0,
        'areamaxbytes' => FILE_AREA_MAX_BYTES_UNLIMITED,
        'context' => $context,
        'noclean' => 0,
        'trusttext' => false,
        'trusted' => false,
        'return_types' => 15,
        'enable_filemanagement' => true,
        'removeorphaneddrafts' => false,
        'autosave' => true,
    ];
    editors_head_setup();
    editors_get_preferred_editor(FORMAT_HTML)->use_editor('insightjournal-response-' . $cm->id, $editoroptions, []);
}

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($diary->name));
if (trim((string)$diary->intro) !== '') {
    echo $OUTPUT->box(format_module_intro('insightjournal', $diary, $cm->id), 'generalbox mod_introbox');
}

$modinfo = get_fast_modinfo($course);
$sectionnum = $modinfo->get_cm($cm->id)->sectionnum;

$templatecontext = [
    'cmid' => $cm->id,
    'prompt' => format_text($diary->prompttext, $diary->promptformat, ['context' => $context]),
    'canwrite' => $canwrite,
    'haveentry' => $haveentry,
    'responseraw' => $responseraw,
    'responseformatted' => $haveentry
        ? format_text($responseraw, $entry->responseformat, ['context' => $context])
        : '',
    'autosave' => (bool)$diary->autosave,
    'minchars' => (int)$diary->minchars,
    'maxchars' => (int)($diary->maxchars ?? 0),
    'lastsaved' => $entry
        ? get_string(
            'lastsaved',
            'insightjournal',
            userdate($entry->timemodified, get_string('strftimedatetimeshort', 'langconfig'))
        )
        : '',
    'sesskey' => sesskey(),
    'reporturl' => (new moodle_url('/mod/insightjournal/coursereport.php', ['courseid' => $course->id]))->out(false),
    'summaryurl' => (new moodle_url('/mod/insightjournal/summary.php', ['courseid' => $course->id]))->out(false),
    'sectionurl' => (new moodle_url('/course/view.php', ['id' => $course->id, 'section' => $sectionnum]))->out(false),
    'canviewall' => $canviewall,
];
echo $OUTPUT->render_from_template('mod_insightjournal/view', $templatecontext);
echo $OUTPUT->footer();
```

- [ ] **Step 6: Manual smoke check**

```bash
syncij
```

Then, with the moodle-docker web container running, log in as a student and open an Insight Journal activity in a browser. Confirm: the response area shows a rich-text toolbar (not a plain textarea), typing and formatting text works, and there are no PHP errors/warnings in the page or `error_log`. Full interactive Save/Edit-round-trip behavior is not testable yet — that lands in Task 5.

- [ ] **Step 7: Commit**

```bash
git add templates/view.mustache view.php tests/view_template_test.php
git commit -m "Render dual view/edit panels and attach the rich-text editor to the response field"
```

---

## Task 5: Client-side view/edit toggle and stripped-text counting

**Files:**
- Modify: `amd/src/autosave.js`
- Modify: `amd/build/autosave.min.js` (regenerated, not hand-edited)

**Interfaces:**
- Consumes: data attributes from Task 4 (`data-insightjournal-view`, `data-insightjournal-edit-panel`, `data-insightjournal-edit`, `data-insightjournal-response-display`, `data-insightjournal-view-status`), and `responsehtml`/`timestr` from Task 2's `save_entry` return value.

There is no PHPUnit/Behat coverage for this task in isolation — the interactive editor cannot be driven by PHPUnit, and Behat coverage for the full round trip is Task 8. Verify this task by hand against the running moodle-docker instance (Step 4 below) before moving on.

Context: Moodle 5's default rich-text editor (`editor_tiny`, TinyMCE-based) only copies its content back into the backing `<textarea>` on blur or removal (confirmed by reading `~/moodle-dev/moodle/lib/editor/tiny/amd/src/editor.js`: `editor.on('blur', () => editor.save())`). It does **not** fire a live `input`/`change` event on the textarea while the user types inside the editor's iframe. So this task cannot reuse the previous `textarea.addEventListener('input', ...)` approach for autosave/character-counting while the editor is focused — it needs to read the live Tiny instance directly via `editor_tiny/editor`'s `getInstanceForElementId(elementid)` (returns `undefined` gracefully if Tiny isn't the active editor, e.g. the site is configured to use the plain-textarea fallback), and poll it, since there's no reliable moment-of-init event to hook synchronously from our module.

- [ ] **Step 1: Implement the new module**

Replace `amd/src/autosave.js` in full:

```js
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Autosave and view/edit toggle handling for the insight journal response field.
 *
 * @module     mod_insightjournal/autosave
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification', 'core/str', 'editor_tiny/editor'], function (Ajax, Notification, Str, TinyEditor) {
    var timer = null;
    var pollTimer = null;
    var maxChars = 0;
    var lastSeenValue = null;

    // TinyMCE only copies its content into the backing textarea on blur, not on
    // every keystroke, so we always ask the live editor instance for its
    // content directly when one exists, falling back to the textarea's own
    // value for the plain-textarea editor (or before Tiny has finished
    // attaching).
    var getCurrentValue = function (textarea) {
        var instance = TinyEditor.getInstanceForElementId(textarea.id);
        return instance ? instance.getContent() : textarea.value;
    };

    var setStatus = function (text, cssclass) {
        var status = document.querySelector('[data-insightjournal-status]');
        if (!status) {
            return;
        }
        status.textContent = text;
        status.className = cssclass || '';
    };

    var setViewStatus = function (text) {
        var status = document.querySelector('[data-insightjournal-view-status]');
        if (status) {
            status.textContent = text;
        }
    };

    var stripHtml = function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        return doc.body.textContent || '';
    };

    var charCount = function (str) {
        return [...str].length;
    };

    var updateCounter = function (value) {
        var counter = document.querySelector('[data-insightjournal-charcounter]');
        var button = document.querySelector('[data-insightjournal-save]');
        if (!counter) {
            return;
        }
        var current = charCount(stripHtml(value));
        var over = current > maxChars;
        counter.textContent = current + ' / ' + maxChars;
        counter.className = 'small ms-auto ' + (over ? 'text-danger fw-bold' : 'text-muted');
        if (button) {
            button.disabled = over;
        }
    };

    var showEditPanel = function () {
        var view = document.querySelector('[data-insightjournal-view]');
        var panel = document.querySelector('[data-insightjournal-edit-panel]');
        var textarea = document.querySelector('[data-insightjournal-response]');
        if (view) {
            view.classList.add('d-none');
        }
        if (panel) {
            panel.classList.remove('d-none');
        }
        if (textarea) {
            var instance = TinyEditor.getInstanceForElementId(textarea.id);
            if (instance) {
                instance.focus();
            } else {
                textarea.focus();
            }
        }
    };

    var showViewPanel = function (responsehtml, timestr) {
        var view = document.querySelector('[data-insightjournal-view]');
        var panel = document.querySelector('[data-insightjournal-edit-panel]');
        var display = document.querySelector('[data-insightjournal-response-display]');
        var editbutton = document.querySelector('[data-insightjournal-edit]');
        if (display) {
            display.innerHTML = responsehtml;
        }
        setViewStatus(timestr);
        if (panel) {
            panel.classList.add('d-none');
        }
        if (view) {
            view.classList.remove('d-none');
        }
        if (editbutton) {
            editbutton.focus();
        }
    };

    var save = function (cmid, manual) {
        var textarea = document.querySelector('[data-insightjournal-response]');
        var button = document.querySelector('[data-insightjournal-save]');
        if (!textarea) {
            return;
        }
        var value = getCurrentValue(textarea);
        if (maxChars > 0 && charCount(stripHtml(value)) > maxChars) {
            return;
        }
        if (button) {
            button.disabled = true;
        }
        Str.get_string('saving', 'mod_insightjournal').then(function (text) {
            setStatus(text, 'text-info');
            return Ajax.call([{
                methodname: 'mod_insightjournal_save_entry',
                args: {cmid: cmid, response: value}
            }])[0];
        }).then(function (result) {
            var current = getCurrentValue(textarea);
            if (button) {
                button.disabled = maxChars > 0 && charCount(stripHtml(current)) > maxChars;
            }
            return Str.get_string('savedat', 'mod_insightjournal', result.timestr).then(function (text) {
                setStatus(text, 'text-success');
                if (manual) {
                    showViewPanel(result.responsehtml, text);
                }
                return text;
            });
        }).catch(function (error) {
            var current = getCurrentValue(textarea);
            if (button) {
                button.disabled = maxChars > 0 && charCount(stripHtml(current)) > maxChars;
            }
            Str.get_string('saveerror', 'mod_insightjournal').then(function (text) {
                setStatus(text, 'text-danger');
                return text;
            }).catch(function () {
                return null;
            });
            Notification.exception(error);
        });
    };

    return {
        init: function (cmid, autosave, maxchars) {
            maxChars = maxchars || 0;
            var textarea = document.querySelector('[data-insightjournal-response]');
            var button = document.querySelector('[data-insightjournal-save]');
            var editbutton = document.querySelector('[data-insightjournal-edit]');
            if (!textarea) {
                return;
            }
            lastSeenValue = getCurrentValue(textarea);
            if (maxChars > 0) {
                updateCounter(lastSeenValue);
            }
            if (button) {
                button.addEventListener('click', function (e) {
                    e.preventDefault();
                    save(cmid, true);
                });
            }
            if (editbutton) {
                editbutton.addEventListener('click', function (e) {
                    e.preventDefault();
                    showEditPanel();
                });
            }
            // Poll rather than bind to a live editor event: Tiny attaches
            // asynchronously (there is no synchronous "ready" signal available
            // to this module), and once attached it only syncs its backing
            // textarea on blur, not per keystroke. One second is frequent
            // enough for a responsive character counter/autosave trigger
            // without meaningfully loading the page.
            pollTimer = setInterval(function () {
                var panel = document.querySelector('[data-insightjournal-edit-panel]');
                if (!panel || panel.classList.contains('d-none')) {
                    return;
                }
                var value = getCurrentValue(textarea);
                if (value === lastSeenValue) {
                    return;
                }
                lastSeenValue = value;
                if (maxChars > 0) {
                    updateCounter(value);
                }
                if (autosave) {
                    clearTimeout(timer);
                    timer = setTimeout(function () {
                        save(cmid, false);
                    }, 3000);
                }
            }, 1000);
        }
    };
});
```

- [ ] **Step 2: Build the AMD module**

The compiled `amd/build/autosave.min.js` is committed to the repo, matching the existing convention for this plugin. Build it via Moodle core's grunt tooling in the reference checkout, then copy the result back:

```bash
syncij
cd ~/moodle-dev/moodle
# First time only in this checkout - installs grunt and friends (this can take several minutes):
npm install
npx grunt amd --root=mod/insightjournal
cp mod/insightjournal/amd/build/autosave.min.js /mnt/c/Git/insightjournal/amd/build/autosave.min.js
cp mod/insightjournal/amd/build/autosave.min.js.map /mnt/c/Git/insightjournal/amd/build/autosave.min.js.map
```

If `npm install` fails or grunt is impractical to set up in this environment, stop and report back rather than hand-writing minified JS or skipping the build — the checked-in `.min.js` must match `amd/src/autosave.js`, and this is the only tooling this project has for producing it.

- [ ] **Step 3: Manual verification against moodle-docker**

With the moodle-docker webserver container running and the plugin synced (`syncij`), in a browser as a logged-in student on an Insight Journal activity with no existing entry:

1. Confirm the editor is shown directly (no saved entry yet → straight into edit mode).
2. Type a response with some formatting (e.g. bold a word). Confirm the character counter (if `maxchars` is set on the test activity) updates within about a second of typing, using the visible-text count (formatting markup should not inflate it).
3. Click **Save**. Confirm the status briefly shows "Saving...", then the view switches to the read-only rendered response with an **Edit** button, and the formatting (e.g. bold) is visibly rendered.
4. Reload the page. Confirm it opens in the read-only view (not the editor), showing the same saved content.
5. Click **Edit**. Confirm the editor reappears, pre-filled with the previously saved content (with formatting intact), and focus moves into the editor.
6. If the activity has autosave enabled, type a change and wait ~3 seconds without clicking Save. Confirm the status updates to "Saved at..." but the editor stays open (does not switch to the read-only view).
7. Open the browser console and confirm there are no JS errors during any of the above.

If step 2, 3, or 6 don't behave as described, the most likely cause is `TinyEditor.getInstanceForElementId()` not resolving for this Moodle version/config — check `window.require(['editor_tiny/editor'], m => console.log(m.getAllInstances()))` in the console to confirm an instance is registered for `insightjournal-response-<cmid>`, and adjust the poll's `getCurrentValue()` lookup accordingly before proceeding.

- [ ] **Step 4: Commit**

```bash
git add amd/src/autosave.js amd/build/autosave.min.js amd/build/autosave.min.js.map
git commit -m "Toggle view/edit panels client-side and count visible characters through Tiny"
```

---

## Task 6: Personal summary renders formatted HTML

**Files:**
- Modify: `summary.php`
- Modify: `templates/entry_card.mustache`

**Interfaces:**
- Consumes: `insightjournal_html_to_text()` (Task 1).

- [ ] **Step 1: Implement**

In `summary.php`, add the require near the top (after the existing `require_once('../../config.php');`):

```php
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');
```

Add `e.responseformat` to the SQL `SELECT` list:

```php
$records = $DB->get_records_sql(
    "SELECT rd.id, rd.name, rd.prompttext, rd.promptformat, e.response, e.responseformat, e.timemodified
       FROM {insightjournal} rd
  LEFT JOIN {insightjournal_entries} e ON e.insightjournalid = rd.id AND e.userid = :userid
      WHERE rd.id $insql
   ORDER BY rd.id ASC",
    $params
);
```

Replace the `foreach ($records as $record)` loop body:

```php
foreach ($records as $record) {
    $modulecontext = context_module::instance($cms[$record->id]->id);
    $rawresponse = $record->response ?? '';
    $hasresponse = insightjournal_html_to_text($rawresponse) !== '';
    $items[] = [
        'activityname' => format_string($record->name),
        'prompt' => format_text($record->prompttext, $record->promptformat, ['context' => $modulecontext]),
        'hasresponse' => $hasresponse,
        'response' => $hasresponse
            ? format_text($rawresponse, $record->responseformat, ['context' => $modulecontext])
            : '',
        'timemodified' => !empty($record->timemodified) ?
            userdate($record->timemodified, get_string('strftimedatetimeshort', 'langconfig')) : '',
    ];
}
```

In `templates/entry_card.mustache`, update the docblock and the response markup. Replace:

```mustache
    Context variables required for this template:
    * activityname - Name of the activity the entry belongs to.
    * prompt - Pre-formatted prompt HTML (rendered via triple braces).
    * response - The user's response text, or empty if none.
    * timemodified - Localised last modified date, or empty if none.

    Example context (json):
    {
        "activityname": "Week 1 reflection",
        "prompt": "<p>What did you learn today?</p>",
        "response": "Today I learned about Mustache templates.",
        "timemodified": "1 January 2026, 10:00 AM"
    }
}}
<div class="card mb-3">
    <div class="card-header"><strong>{{activityname}}</strong></div>
    <div class="card-body">
        {{! prompt is pre-sanitised via format_text() in PHP – triple braces intentional, never use for student response }}
        <div class="mb-2">{{{prompt}}}</div>
        {{#response}}<div class="border rounded p-3 bg-light insightjournal-response-text">{{response}}</div>{{/response}}
        {{^response}}<div class="text-muted font-italic">{{#str}}noresponse, mod_insightjournal{{/str}}</div>{{/response}}
        {{#timemodified}}<div class="small text-muted mt-2">{{timemodified}}</div>{{/timemodified}}
    </div>
</div>
```

with:

```mustache
    Context variables required for this template:
    * activityname - Name of the activity the entry belongs to.
    * prompt - Pre-formatted prompt HTML (rendered via triple braces).
    * hasresponse - Whether the user has entered a response.
    * response - The user's pre-formatted response HTML (rendered via triple braces), or empty if none.
    * timemodified - Localised last modified date, or empty if none.

    Example context (json):
    {
        "activityname": "Week 1 reflection",
        "prompt": "<p>What did you learn today?</p>",
        "hasresponse": true,
        "response": "<p>Today I learned about Mustache templates.</p>",
        "timemodified": "1 January 2026, 10:00 AM"
    }
}}
<div class="card mb-3">
    <div class="card-header"><strong>{{activityname}}</strong></div>
    <div class="card-body">
        {{! prompt and response are pre-sanitised via format_text() in PHP – triple braces intentional }}
        <div class="mb-2">{{{prompt}}}</div>
        {{#hasresponse}}<div class="border rounded p-3 bg-light insightjournal-response-text">{{{response}}}</div>{{/hasresponse}}
        {{^hasresponse}}<div class="text-muted font-italic">{{#str}}noresponse, mod_insightjournal{{/str}}</div>{{/hasresponse}}
        {{#timemodified}}<div class="small text-muted mt-2">{{timemodified}}</div>{{/timemodified}}
    </div>
</div>
```

- [ ] **Step 2: Manual verification**

There is no existing PHPUnit coverage for `summary.php`'s rendering and adding a full page-render test harness is out of scope for this change. Verify by hand: sync (`syncij`), save a formatted response as a student (Task 5's flow), then visit "My Insight Journal" (the summary page) and confirm the formatting renders (not escaped as literal tags) and an activity with no response still shows the "No response entered" message.

- [ ] **Step 3: Commit**

```bash
git add summary.php templates/entry_card.mustache
git commit -m "Render formatted HTML responses on the personal summary page"
```

---

## Task 7: Teacher report and CSV exports

**Files:**
- Modify: `report.php`
- Modify: `templates/report.mustache`
- Modify: `coursereport.php`

**Interfaces:**
- Consumes: `insightjournal_html_to_text()` (Task 1).

- [ ] **Step 1: Implement `report.php` and `report.mustache`**

In `report.php`, update the CSV export line (inside the `if ($download === 'csv')` block):

```php
        fputcsv($out, [
            $course->id,
            insightjournal_csv_value($course->fullname),
            $cm->id,
            insightjournal_csv_value($diary->name),
            $entry->userid,
            insightjournal_csv_value(fullname($user)),
            insightjournal_csv_value($entry->email),
            insightjournal_csv_value(insightjournal_html_to_text($entry->response)),
            userdate($entry->timemodified),
        ]);
```

(Only the `response` line changes — wraps it in `insightjournal_html_to_text()`.)

Update the web-rows `'response'` value inside the `foreach ($entries as $entry)` loop below the CSV block:

```php
    $rows[] = [
        'fullname' => fullname($user),
        'email' => $entry->email,
        'summaryurl' => (new moodle_url(
            '/mod/insightjournal/summary.php',
            [
                'courseid' => $course->id,
                'userid' => $entry->userid,
                'returnurl' => (new moodle_url('/mod/insightjournal/report.php', ['id' => $cm->id]))->out_as_local_url(false),
            ]
        ))->out(false),
        'response' => format_text($entry->response, $entry->responseformat, ['context' => $context]),
        'timemodified' => userdate($entry->timemodified, get_string('strftimedatetimeshort', 'langconfig')),
    ];
```

(Only the `response` line changes — from raw `$entry->response` to `format_text(...)`. `$entry->responseformat` is already available since the row query uses `SELECT e.*, ...`.)

In `templates/report.mustache`, change the response cell from escaped to raw HTML output:

```mustache
                        <td><div class="insightjournal-response-text">{{response}}</div></td>
```

to:

```mustache
                        <td><div class="insightjournal-response-text">{{{response}}}</div></td>
```

Also update the docblock. Replace:

```mustache
        * response - Response text.
```

with:

```mustache
        * response - Pre-formatted response HTML (rendered via triple braces).
```

And in the example context JSON block below it, replace:

```mustache
                "response": "Today I learned about Mustache templates.",
```

with:

```mustache
                "response": "<p>Today I learned about Mustache templates.</p>",
```

- [ ] **Step 2: Implement `coursereport.php`**

Change the CSV export line:

```php
                insightjournal_csv_value($entry->response ?? ''),
```

to:

```php
                insightjournal_csv_value(insightjournal_html_to_text($entry->response ?? '')),
```

Change the completion check:

```php
        $completed = $entry && trim((string)$entry->response) !== '';
```

to:

```php
        $completed = $entry && insightjournal_html_to_text($entry->response) !== '';
```

(`locallib.php` is already required at the top of this file — no new require needed.)

- [ ] **Step 3: Manual verification**

There is no existing PHPUnit coverage for these report pages' HTML/CSV rendering. Verify by hand: sync (`syncij`), as a teacher open the activity report and course report for an activity with at least one HTML-formatted response and one participant with no response. Confirm: the report table shows rendered formatting (not escaped tags), the CSV downloads show plain text (no HTML tags) in the response column, and the course report's submitted/not-submitted status is correct for a response consisting only of an empty editor shell (if you can produce one via Task 5's UI, e.g. by saving with no typed text — note `save_entry` doesn't currently block saving an empty response, so this is reachable).

- [ ] **Step 4: Commit**

```bash
git add report.php templates/report.mustache coursereport.php
git commit -m "Render formatted HTML in reports and export plain text in CSV downloads"
```

---

## Task 8: Behat coverage, version bump, and full suite run

**Files:**
- Modify: `tests/behat/insight_journal.feature`
- Modify: `version.php`
- Modify: `CHANGELOG.md`

**Interfaces:** None (final integration task).

- [ ] **Step 1: Update the Behat feature file**

Replace `tests/behat/insight_journal.feature` in full:

```gherkin
@mod @mod_insightjournal
Feature: Insight journal activity
  In order to let learners record reflections
  As a teacher
  I need students to be able to write, save, and complete insight journal activities

  Background:
    Given the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | 1        |
      | student1 | Student   | 1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |

  @javascript
  Scenario: A learner writes and saves a response, then sees it again after reload
    Given the following "activities" exist:
      | activity       | course | name       | prompttext           | minchars |
      | insightjournal  | C1     | My Journal | What did you learn?  | 0        |
    When I am on the "My Journal" "insightjournal activity" page logged in as student1
    And I set the field "Response" to "Today I learned about Behat testing."
    And I press "Save"
    Then I should see "Today I learned about Behat testing." in the "[data-insightjournal-view]" "css_element"
    And "[data-insightjournal-edit-panel]" "css_element" should not be visible
    When I reload the page
    Then the field "Response" matches value "Today I learned about Behat testing."
    And "[data-insightjournal-edit-panel]" "css_element" should not be visible

  @javascript
  Scenario: Saving a response shorter than the minimum does not complete the activity, a longer one does
    Given the following "activities" exist:
      | activity       | course | name       | prompttext           | minchars | completion | completionentries |
      | insightjournal  | C1     | My Journal | What did you learn?  | 10       | 2          | 1                 |
    When I am on the "My Journal" "insightjournal activity" page logged in as student1
    And I set the field "Response" to "short"
    And I press "Save"
    And I log out
    And I am on the "Course 1" course page logged in as teacher1
    Then "Student 1" user has not completed "My Journal" activity
    When I am on the "My Journal" "insightjournal activity" page logged in as student1
    And I press "Edit"
    And I set the field "Response" to "This is a long enough reflection."
    And I press "Save"
    And I log out
    And I am on the "Course 1" course page logged in as teacher1
    Then "Student 1" user has completed "My Journal" activity

  @javascript
  Scenario: A learner edits a previously saved response
    Given the following "activities" exist:
      | activity       | course | name       | prompttext           | minchars |
      | insightjournal  | C1     | My Journal | What did you learn?  | 0        |
    When I am on the "My Journal" "insightjournal activity" page logged in as student1
    And I set the field "Response" to "Original response."
    And I press "Save"
    Then I should see "Original response." in the "[data-insightjournal-view]" "css_element"
    When I press "Edit"
    Then the field "Response" matches value "Original response."
    When I set the field "Response" to "Updated response."
    And I press "Save"
    Then I should see "Updated response." in the "[data-insightjournal-view]" "css_element"
    And "[data-insightjournal-edit-panel]" "css_element" should not be visible
```

- [ ] **Step 2: Bump the version and update the changelog**

In `version.php`, change:

```php
$plugin->version   = 2026061703;
```

to:

```php
$plugin->version   = 2026070700;
```

In `CHANGELOG.md`, add a bullet at the end of the existing `## [Unreleased]` / `### Added` list (after the "Behat acceptance tests..." bullet — entries in this file are ordered oldest-first, with newest work appended at the bottom):

```markdown
- Learner responses now use Moodle's site-configured rich-text editor
  (matching the existing prompt field) instead of a plain textarea, with a
  view/edit toggle: a saved response renders read-only with an "Edit"
  button, and "Save" returns to the read-only view. Responses are stored as
  HTML (`FORMAT_HTML`) going forward; `minchars`/`maxchars` and the
  `completionentries` completion rule are measured against visible
  characters (HTML tags stripped), not raw markup length. No image/file
  embedding is supported.
```

- [ ] **Step 3: Run the full PHPUnit suite**

```bash
syncij
cd ~/moodle-dev/moodle-docker
bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite
```

Expected: PASS, all tests across every file touched in Tasks 1-7.

- [ ] **Step 4: Run the Behat suite**

```bash
syncij
cd ~/moodle-dev/moodle-docker
bin/moodle-docker-compose exec webserver php admin/tool/behat/cli/init.php
bin/moodle-docker-compose exec webserver php admin/tool/behat/cli/run.php --tags="@mod_insightjournal"
```

Expected: PASS, all three scenarios in `insight_journal.feature`. If a step fails because Behat's field manager doesn't detect the response field as an "editor" type (it does this by checking for a `<div id="{elementid}editable">` sibling that Tiny creates at runtime — see `~/moodle-dev/moodle/lib/behat/behat_field_manager.php`), confirm in a manual browser session that the textarea's `id` attribute exactly matches the id passed to `use_editor()` in `view.php` (`insightjournal-response-<cmid>`); a mismatch there is the most likely cause.

- [ ] **Step 5: Commit**

```bash
git add tests/behat/insight_journal.feature version.php CHANGELOG.md
git commit -m "Add Behat coverage for the edit round trip and bump the plugin version"
```
