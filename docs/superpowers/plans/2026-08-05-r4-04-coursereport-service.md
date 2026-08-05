# R4-04 Coursereport Service Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract `coursereport.php`'s authorization/paging/progress-counting/export-selection logic into a `coursereport_provider` service class that the on-screen page and the CSV export both call identically, eliminating the duplicated participant-x-activity loop and folding in two small optimizations deferred from R4-03's final review.

**Architecture:** A new `classes/local/coursereport_provider.php` (same pattern as `classes/local/entry_manager.php` from R2-03) absorbs three existing `insightjournal_coursereport_*` locallib.php functions as private methods, exposes `total_participants()`/`participants()`/`rows_for()` as its public surface, and derives its SQL-level group-id restriction from the same per-grouping-cached data it already resolves for cell masking (no second resolution pass). `coursereport.php` is rewritten to call the provider from both its CSV loop and its screen-page path instead of duplicating the loop.

**Tech Stack:** PHP 8.1+ (Moodle plugin), Moodle DB API, PHPUnit (`advanced_testcase`).

## Global Constraints

- Reference spec: `docs/superpowers/specs/2026-08-05-coursereport-service-design.md` — every task's requirements implicitly include it.
- No behavior change for end users: every existing authorization/display outcome (who sees what, private-entry handling, progress counts, CSV row selection) must be identical before and after.
- A cell with `visible === false` in `rows_for()`'s output MUST NOT have `entry`/`completed`/`private` keys populated — only `['visible' => false]`. No caller may read those keys for an invisible cell.
- No PHPUnit query-count/memory-threshold assertions.
- TDD throughout: write/extend a failing test before touching production code, verify RED, implement, verify GREEN.
- Toolchain per [[moodle-codechecker-toolchain]]/[[moodle-docker-phpunit-env]] memory: sync via `rsync -av --delete <worktree>/ ~/moodle-dev/moodle/mod/insightjournal/` (NOT `~/sync-insightjournal.sh`, whose hardcoded source is the main repo, not a worktree), PHPUnit via `bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite`, phpcs via `"/mnt/c/xampp/php/php.exe" C:/Git/ij-tooling/vendor/squizlabs/php_codesniffer/bin/phpcs --standard=moodle,moodle-extra --extensions=php --warning-severity=1 <files>`, PHPStan via `bin/moodle-docker-compose exec webserver vendor/bin/phpstan analyse -c mod/insightjournal/phpstan.neon --memory-limit=1G --no-progress`, Behat via `bin/moodle-docker-compose exec webserver vendor/bin/behat --config /var/www/behatdata/behatrun/behat/behat.yml --tags @mod_insightjournal`.
- A trailing `rsync error: some files/attrs were not transferred (code 23)` about `chgrp`/timestamps on directories is a known-harmless bind-mount permission quirk in this environment — not a real failure, as long as the file-content lines above it look right.

---

### Task 1: `coursereport_provider` service class + tests

**Files:**
- Create: `classes/local/coursereport_provider.php`
- Create: `tests/local/coursereport_provider_test.php`

**Interfaces:**
- Consumes: nothing new — only existing locallib.php functions (`insightjournal_activity_group_restricted()`, `insightjournal_current_user_allowed_groupids()`, `insightjournal_groupids_members_among()`, `insightjournal_entries_by_diary_and_user()`, `insightjournal_coursereport_cell_state()`, `insightjournal_email_field_visible()`).
- Produces (for Tasks 2 and 3): `\mod_insightjournal\local\coursereport_provider` with public methods:
  - `__construct(\stdClass $course, array $activities)` — `$activities` is `cm_info[]|stdClass[]` keyed by insightjournal instance id, exactly as `coursereport.php` already builds it.
  - `total_participants(): int`
  - `participants(int $offset, int $limit): array` — `stdClass[]` keyed by userid, same shape `get_enrolled_users()` already returns.
  - `rows_for(array $participants): array` — `array<int, array{user: stdClass, cells: array<int, array{visible: bool, entry: ?stdClass, completed: bool, private: bool}>, done: int, visiblecount: int}>` keyed by userid; a cell with `visible === false` has ONLY that key set.
- **This task does not touch `coursereport.php` or `locallib.php`** — purely additive, so nothing existing can break. The three locallib.php functions this class's private methods duplicate (`insightjournal_coursereport_restrict_groupids()`, `insightjournal_coursereport_allowed_groupids_by_diary()`, `insightjournal_coursereport_diary_allowed_users()`) still exist and are still used by `coursereport.php` until Task 3 migrates it and deletes them.

- [ ] **Step 1: Write failing tests for the new class**

Create `tests/local/coursereport_provider_test.php`:

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
 * Unit tests for the course-wide report data provider of mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_insightjournal\local;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');

/**
 * Tests for {@see coursereport_provider}.
 */
#[CoversClass(coursereport_provider::class)]
final class coursereport_provider_test extends advanced_testcase {
    /** @var stdClass The course. */
    protected stdClass $course;

    /**
     * Creates a course.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Returns the plugin's own test data generator, for create_entry().
     *
     * @return \mod_insightjournal_generator
     */
    protected function ij_generator() {
        return $this->getDataGenerator()->get_plugin_generator('mod_insightjournal');
    }

    /**
     * With no group restriction anywhere, every enrolled participant is
     * visible and every activity's cell is visible.
     */
    public function test_unrestricted_course_shows_every_participant_and_cell(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $studenta = $generator->create_and_enrol($this->course, 'student');
        $studentb = $generator->create_and_enrol($this->course, 'student');
        $diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $this->setUser($teacher);

        $provider = new coursereport_provider($this->course, [$diary->id => $cm]);

        $this->assertEquals(3, $provider->total_participants());
        $participants = $provider->participants(0, 20);
        $rows = $provider->rows_for($participants);
        $this->assertTrue($rows[(int) $studenta->id]['cells'][$diary->id]['visible']);
        $this->assertTrue($rows[(int) $studentb->id]['cells'][$diary->id]['visible']);
    }

    /**
     * A cell the viewer isn't authorized for (Separate Groups, target not
     * in the viewer's group) has visible === false and carries no other
     * keys - callers must never read entry/completed/private for it.
     */
    public function test_invisible_cell_carries_only_the_visible_key(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $outsider = $generator->create_and_enrol($this->course, 'student');
        $diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cm->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);
        // $outsider is enrolled but in no group - not covered by the SQL
        // prefilter in a real request, but rows_for() must still be safe
        // if ever called directly against a userid outside the restriction.
        $this->setUser($teacher);

        $provider = new coursereport_provider($this->course, [$diary->id => $cm]);
        $rows = $provider->rows_for([(int) $outsider->id => $outsider]);

        $cell = $rows[(int) $outsider->id]['cells'][$diary->id];
        $this->assertSame(['visible' => false], $cell);
    }

    /**
     * Mixed group modes: one restricted activity, one unrestricted, in the
     * same course. The SQL-level prefilter must stay open (an unrestricted
     * activity alone means every enrolled participant gets a potentially
     * visible cell), but the restricted activity's own cell must still be
     * masked per-participant.
     */
    public function test_mixed_group_modes_prefilter_open_but_cell_masked(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $student = $generator->create_and_enrol($this->course, 'student');

        $open = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $opencm = get_coursemodule_from_id('insightjournal', $open->cmid, 0, false, MUST_EXIST);

        $restricted = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $restrictedcm = get_coursemodule_from_id('insightjournal', $restricted->cmid, 0, false, MUST_EXIST);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $restrictedcm->id]);
        $restrictedcm = get_coursemodule_from_id('insightjournal', $restricted->cmid, 0, false, MUST_EXIST);
        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);

        $this->setUser($teacher);
        $provider = new coursereport_provider($this->course, [
            $open->id => $opencm,
            $restricted->id => $restrictedcm,
        ]);

        // Student is in no group - still shows up (open activity keeps the
        // prefilter unrestricted), but the restricted activity's cell is masked.
        $this->assertEquals(2, $provider->total_participants());
        $rows = $provider->rows_for($provider->participants(0, 20));
        $this->assertTrue($rows[(int) $student->id]['cells'][$open->id]['visible']);
        $this->assertFalse($rows[(int) $student->id]['cells'][$restricted->id]['visible']);
    }

    /**
     * A private entry is visible to the viewer (the activity isn't
     * group-restricted) but its cell reports private === true and
     * completed still reflects the entry's actual content, independent of
     * privacy - matching insightjournal_coursereport_cell_state()'s own
     * documented contract.
     */
    public function test_private_entry_cell_reports_private_and_completed(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $student = $generator->create_and_enrol($this->course, 'student');
        $diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $this->ij_generator()->create_entry(
            $diary,
            (int) $student->id,
            'A private reflection.',
            \INSIGHTJOURNAL_VISIBILITY_PRIVATE
        );
        $this->setUser($teacher);

        $provider = new coursereport_provider($this->course, [$diary->id => $cm]);
        $rows = $provider->rows_for($provider->participants(0, 20));

        $cell = $rows[(int) $student->id]['cells'][$diary->id];
        $this->assertTrue($cell['visible']);
        $this->assertTrue($cell['private']);
        $this->assertTrue($cell['completed']);
    }

    /**
     * done/visiblecount count only diaries the viewer is authorized to see
     * for that learner - an activity masked away by group restriction
     * contributes to neither the numerator nor the denominator.
     */
    public function test_progress_counts_only_visible_diaries(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $student = $generator->create_and_enrol($this->course, 'student');

        $open = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $opencm = get_coursemodule_from_id('insightjournal', $open->cmid, 0, false, MUST_EXIST);
        $this->ij_generator()->create_entry(
            $open,
            (int) $student->id,
            'Completed in the open activity.',
            \INSIGHTJOURNAL_VISIBILITY_VISIBLE
        );

        $restricted = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $restrictedcm = get_coursemodule_from_id('insightjournal', $restricted->cmid, 0, false, MUST_EXIST);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $restrictedcm->id]);
        $restrictedcm = get_coursemodule_from_id('insightjournal', $restricted->cmid, 0, false, MUST_EXIST);
        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);

        $this->setUser($teacher);
        $provider = new coursereport_provider($this->course, [
            $open->id => $opencm,
            $restricted->id => $restrictedcm,
        ]);
        $rows = $provider->rows_for($provider->participants(0, 20));

        $this->assertEquals(1, $rows[(int) $student->id]['done']);
        $this->assertEquals(1, $rows[(int) $student->id]['visiblecount']);
    }

    /**
     * participants() honours offset/limit - two calls with different
     * offsets return disjoint, correctly-sized slices, and total_participants()
     * matches the real enrolled count regardless of paging.
     */
    public function test_participants_pages_correctly(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        for ($i = 0; $i < 5; $i++) {
            $generator->create_and_enrol($this->course, 'student');
        }
        $diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $this->setUser($teacher);

        $provider = new coursereport_provider($this->course, [$diary->id => $cm]);

        $this->assertEquals(6, $provider->total_participants());
        $first = $provider->participants(0, 4);
        $second = $provider->participants(4, 4);
        $this->assertCount(4, $first);
        $this->assertCount(2, $second);
        $this->assertEmpty(array_intersect(array_keys($first), array_keys($second)));
    }

    /**
     * A chunk request landing exactly on the participant count returns
     * every participant with nothing left over on the next call - the CSV
     * export's chunk-boundary termination relies on this.
     */
    public function test_participants_chunk_at_exact_boundary(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $generator->create_and_enrol($this->course, 'student');
        $generator->create_and_enrol($this->course, 'student');
        $diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $this->setUser($teacher);

        $provider = new coursereport_provider($this->course, [$diary->id => $cm]);

        $chunk = $provider->participants(0, 3);
        $this->assertCount(3, $chunk);
        $next = $provider->participants(3, 3);
        $this->assertEmpty($next);
    }

    /**
     * A viewer with zero allowed groups, where every visible activity is
     * restricted, matches nobody - total_participants() is 0 and
     * participants() is empty, not an error.
     */
    public function test_zero_allowed_groups_blocks_all_participants(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $generator->create_and_enrol($this->course, 'student');
        $diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cm->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        // Teacher belongs to no group at all.
        $this->setUser($teacher);

        $provider = new coursereport_provider($this->course, [$diary->id => $cm]);

        $this->assertEquals(0, $provider->total_participants());
        $this->assertSame([], $provider->participants(0, 20));
    }

    /**
     * Two activities sharing a grouping resolve identically for the same
     * viewer - the per-grouping cache must not corrupt either activity's
     * result, and the deduplicated membership lookup must still produce
     * correct per-diary visibility for both.
     */
    public function test_two_activities_sharing_a_grouping_resolve_identically(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $student = $generator->create_and_enrol($this->course, 'student');

        $diarya = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $diaryb = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cma = get_coursemodule_from_id('insightjournal', $diarya->cmid, 0, false, MUST_EXIST);
        $cmb = get_coursemodule_from_id('insightjournal', $diaryb->cmid, 0, false, MUST_EXIST);

        $grouping = $generator->create_grouping(['courseid' => $this->course->id]);
        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_grouping_group(['groupingid' => $grouping->id, 'groupid' => $group->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);

        $DB->set_field('course_modules', 'groupingid', $grouping->id, ['id' => $cma->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cma->id]);
        $DB->set_field('course_modules', 'groupingid', $grouping->id, ['id' => $cmb->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cmb->id]);
        $cma = get_coursemodule_from_id('insightjournal', $diarya->cmid, 0, false, MUST_EXIST);
        $cmb = get_coursemodule_from_id('insightjournal', $diaryb->cmid, 0, false, MUST_EXIST);

        $this->setUser($teacher);
        $provider = new coursereport_provider($this->course, [
            $diarya->id => $cma,
            $diaryb->id => $cmb,
        ]);
        $rows = $provider->rows_for($provider->participants(0, 20));

        $this->assertTrue($rows[(int) $student->id]['cells'][$diarya->id]['visible']);
        $this->assertTrue($rows[(int) $student->id]['cells'][$diaryb->id]['visible']);
    }

    /**
     * rows_for([]) returns an empty array, not an error - the CSV loop's
     * final, empty chunk relies on this.
     */
    public function test_rows_for_empty_participants_returns_empty_array(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $diary = $this->getDataGenerator()->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $this->setUser($teacher);

        $provider = new coursereport_provider($this->course, [$diary->id => $cm]);

        $this->assertSame([], $provider->rows_for([]));
    }
}
```

- [ ] **Step 2: Sync and run to verify RED**

```bash
rsync -av --delete /path/to/worktree/ ~/moodle-dev/moodle/mod/insightjournal/
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter coursereport_provider_test
```
Expected: every test **errors** with `Class "mod_insightjournal\local\coursereport_provider" not found` — not a normal assertion failure, since the class doesn't exist yet.

- [ ] **Step 3: Implement `classes/local/coursereport_provider.php`**

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
 * Course-wide report data provider for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_insightjournal\local;

/**
 * Resolves coursereport.php's authorization, paging, progress-counting, and
 * export-selection logic once, so the on-screen page and the CSV export
 * both call exactly the same core instead of duplicating the same
 * participant x activity loop with two different output shapes.
 */
final class coursereport_provider {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var array Activities (cm_info|stdClass), keyed by insightjournal instance id. */
    private array $activities;

    /** @var array Instance id => allowed group ids (int[]), or null if unrestricted for the current viewer. */
    private array $diaryallowedgroupids;

    /** @var int[]|null Group ids to restrict get_enrolled_users()/count_enrolled_users() to, or null for no restriction. */
    private ?array $restrictgroupids;

    /** @var bool Whether every visible activity is restricted but the viewer's own allowed groups are empty - no participant can ever match. */
    private bool $blockallparticipants;

    /** @var \context_course The course context. */
    private \context_course $coursecontext;

    /** @var string The core_user\fields select fragment for get_enrolled_users(). */
    private string $userfields;

    /**
     * @param \stdClass $course The course.
     * @param array $activities Visible insight journal activities (cm_info|stdClass), keyed by instance id.
     */
    public function __construct(\stdClass $course, array $activities) {
        $this->course = $course;
        $this->activities = $activities;
        $this->coursecontext = \context_course::instance($course->id);
        $this->diaryallowedgroupids = $this->resolve_allowed_groupids_by_diary();

        // Derived from $diaryallowedgroupids instead of a separate
        // resolution pass (R4-03 final-review follow-up): null as soon as
        // any activity is unrestricted (that activity alone means every
        // enrolled participant gets a potentially-visible cell, so no
        // SQL-level filter is safe), otherwise the union of every
        // restricted activity's already-resolved allowed groups.
        $groupids = [];
        $anyunrestricted = false;
        foreach ($this->diaryallowedgroupids as $allowed) {
            if ($allowed === null) {
                $anyunrestricted = true;
                break;
            }
            $groupids = array_merge($groupids, $allowed);
        }
        $this->restrictgroupids = $anyunrestricted ? null : array_values(array_unique($groupids));
        $this->blockallparticipants = $this->restrictgroupids !== null && empty($this->restrictgroupids);

        // Checked at course context, not per-activity like report_table.php -
        // deliberately coarse. A viewer reaching this provider already holds
        // the capability course-wide, so this can only ever be more
        // permissive than a hypothetical per-activity override, never less.
        $showemail = insightjournal_email_field_visible($this->coursecontext);
        $namefields = \core_user\fields::for_name()->including('id');
        if ($showemail) {
            $namefields->including('email');
        }
        // Only ->selects is used: for_name()/including('id'|'email') can
        // never add a custom profile field, so ->joins and ->params are
        // always empty here - revisit this assumption if a
        // with_identity()/custom-field include is ever added.
        $this->userfields = $namefields->get_sql('u', false, '', '', false)->selects;
    }

    /**
     * Total participants matching the SQL-level restriction, or 0 if no
     * participant can ever match.
     *
     * @return int
     */
    public function total_participants(): int {
        if ($this->blockallparticipants) {
            return 0;
        }

        return count_enrolled_users(
            $this->coursecontext,
            'mod/insightjournal:submit',
            $this->restrictgroupids ?? 0
        );
    }

    /**
     * One bounded slice of enrolled participants (a screen page or a CSV
     * chunk), ordered by name.
     *
     * @param int $offset
     * @param int $limit
     * @return \stdClass[] Keyed by userid.
     */
    public function participants(int $offset, int $limit): array {
        if ($this->blockallparticipants) {
            return [];
        }

        return get_enrolled_users(
            $this->coursecontext,
            'mod/insightjournal:submit',
            $this->restrictgroupids ?? 0,
            $this->userfields,
            'u.lastname,u.firstname,u.id',
            $offset,
            $limit
        );
    }

    /**
     * Fully resolved row data for exactly the given participants - never a
     * wider set. Both the CSV export and the on-screen page call this with
     * their own bounded participant slice, so authorization and membership
     * are always resolved per page/chunk, never course-wide (R4-03).
     *
     * @param \stdClass[] $participants From participants(), keyed by userid.
     * @return array<int, array{
     *     user: \stdClass,
     *     cells: array<int, array{visible: bool, entry: ?\stdClass, completed: bool, private: bool}>,
     *     done: int,
     *     visiblecount: int,
     * }> Keyed by userid. A cell with visible === false carries ONLY that key.
     */
    public function rows_for(array $participants): array {
        if (empty($participants)) {
            return [];
        }

        $userids = array_map('intval', array_keys($participants));
        $diaryids = array_keys($this->activities);
        $entries = insightjournal_entries_by_diary_and_user($diaryids, $userids);
        $diaryallowedusers = $this->resolve_diary_allowed_users($userids);

        $rows = [];
        foreach ($participants as $userid => $user) {
            $userid = (int) $userid;
            $cells = [];
            $done = 0;
            $visiblecount = 0;
            foreach ($this->activities as $diaryid => $cm) {
                $allowedusers = $diaryallowedusers[$diaryid];
                if ($allowedusers !== null && !isset($allowedusers[$userid])) {
                    $cells[$diaryid] = ['visible' => false];
                    continue;
                }
                $visiblecount++;
                $entry = $entries[$userid][$diaryid] ?? null;
                $state = insightjournal_coursereport_cell_state($entry);
                if ($state['completed']) {
                    $done++;
                }
                $cells[$diaryid] = [
                    'visible' => true,
                    'entry' => $entry,
                    'completed' => $state['completed'],
                    'private' => $state['private'],
                ];
            }
            $rows[$userid] = [
                'user' => $user,
                'cells' => $cells,
                'done' => $done,
                'visiblecount' => $visiblecount,
            ];
        }

        return $rows;
    }

    /**
     * Allowed group ids for the current viewer, keyed by insightjournal
     * instance id - resolved once per distinct groupingid, not once per
     * activity (two activities sharing a grouping always resolve to the
     * same allowed group ids for a given viewer).
     *
     * @return array Instance id => allowed group ids (int[]), or null for an
     *     activity that is not group-restricted for the current viewer.
     */
    private function resolve_allowed_groupids_by_diary(): array {
        $bygroupingid = [];
        $result = [];
        foreach ($this->activities as $diaryid => $cm) {
            $context = \context_module::instance($cm->id);
            if (!insightjournal_activity_group_restricted($context, $this->course, $cm)) {
                $result[$diaryid] = null;
                continue;
            }
            $groupingid = (int) $cm->groupingid;
            if (!array_key_exists($groupingid, $bygroupingid)) {
                $bygroupingid[$groupingid] = insightjournal_current_user_allowed_groupids($this->course, $cm);
            }
            $result[$diaryid] = $bygroupingid[$groupingid];
        }

        return $result;
    }

    /**
     * Per-diary "is this userid visible under this diary's group
     * restriction" lookup maps, scoped to exactly $userids. Deduplicates
     * the groups_members query across diaries that share the identical
     * (already-cached) allowed-group-ids array, instead of querying once
     * per restricted diary regardless of overlap (R4-03 final-review
     * follow-up) - two diaries sharing a groupingid always hold the exact
     * same cached array from resolve_allowed_groupids_by_diary(), so a
     * value-based key (not the groupingid itself, which isn't available
     * here) is sufficient and always consistent for genuine duplicates.
     *
     * @param int[] $userids The userids actually present in this page/chunk.
     * @return array Instance id => (userid => true) map, or null when the
     *     diary is unrestricted for the current viewer.
     */
    private function resolve_diary_allowed_users(array $userids): array {
        $bygroupidskey = [];
        $result = [];
        foreach ($this->diaryallowedgroupids as $diaryid => $groupids) {
            if ($groupids === null) {
                $result[$diaryid] = null;
                continue;
            }
            $key = implode(',', $groupids);
            if (!array_key_exists($key, $bygroupidskey)) {
                $bygroupidskey[$key] = array_fill_keys(
                    insightjournal_groupids_members_among($groupids, $userids),
                    true
                );
            }
            $result[$diaryid] = $bygroupidskey[$key];
        }

        return $result;
    }
}
```

- [ ] **Step 4: Sync and run to verify GREEN**

```bash
rsync -av --delete /path/to/worktree/ ~/moodle-dev/moodle/mod/insightjournal/
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter coursereport_provider_test
```
Expected: all 10 tests pass.

- [ ] **Step 5: Run the full PHPUnit suite (this task only adds files, but confirm nothing autoload-related broke)**

```bash
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite
```
Expected: no new failures (217 pre-existing + 10 new = 227 passing).

- [ ] **Step 6: phpcs and PHPStan**

```bash
"/mnt/c/xampp/php/php.exe" C:/Git/ij-tooling/vendor/squizlabs/php_codesniffer/bin/phpcs --standard=moodle,moodle-extra --extensions=php --warning-severity=1 C:/Git/insightjournal/.claude/worktrees/<worktree-name>/classes/local/coursereport_provider.php C:/Git/insightjournal/.claude/worktrees/<worktree-name>/tests/local/coursereport_provider_test.php
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpstan analyse -c mod/insightjournal/phpstan.neon --memory-limit=1G --no-progress
```
Expected: both clean.

- [ ] **Step 7: Commit**

```bash
git add classes/local/coursereport_provider.php tests/local/coursereport_provider_test.php
git commit -m "$(cat <<'EOF'
feat: add coursereport_provider service class (R4-04 part 1/3)

coursereport.php duplicates the same participant x activity
authorization loop once for its CSV export and once for its screen
page. Add a provider that resolves participants/rows once, callable
identically by both - not yet wired in, coursereport.php still uses
the old locallib.php functions until part 3 migrates it.

Also derives the SQL-level restrictgroupids from the same per-grouping
-cached data used for cell masking, instead of a separate resolution
pass, and deduplicates the per-chunk membership query across diaries
sharing a grouping - both deferred from R4-03's final review.
EOF
)"
```

---

### Task 2: `insightjournal_coursereport_csv_row()` takes `$private` as a parameter

**Files:**
- Modify: `locallib.php:517-540` (the `insightjournal_coursereport_csv_row()` docblock + function)
- Modify: `tests/coursereport_csv_test.php` (5 call sites)

**Interfaces:**
- Consumes: nothing new.
- Produces (for Task 3): `insightjournal_coursereport_csv_row(stdClass $course, int $cmid, stdClass $diary, stdClass $user, ?stdClass $entry, bool $private, bool $showemail): array` — `$private` inserted as the 6th parameter, between `$entry` and `$showemail`.
- This task is independent of Task 1 (doesn't reference `coursereport_provider`) and can be done in either order relative to it; sequenced second here only to keep the plan linear.

- [ ] **Step 1: Update the 5 call sites in `tests/coursereport_csv_test.php` to pass `$private` explicitly (this IS the failing-test step — the existing tests are the regression guard)**

Change `tests/coursereport_csv_test.php:97-104` (`test_normal_row_matches_legacy_column_order`, entry is `INSIGHTJOURNAL_VISIBILITY_VISIBLE` so `$private = false`) from:
```php
        $row = \insightjournal_coursereport_csv_row(
            $this->course,
            (int) $this->cm->id,
            $this->diary,
            $this->student,
            $entry,
            true
        );
```
to:
```php
        $row = \insightjournal_coursereport_csv_row(
            $this->course,
            (int) $this->cm->id,
            $this->diary,
            $this->student,
            $entry,
            false,
            true
        );
```

Change `tests/coursereport_csv_test.php:130-137` (`test_private_entry_uses_notice_and_blanks_timemodified`, entry is `INSIGHTJOURNAL_VISIBILITY_PRIVATE` so `$private = true`) from:
```php
        $row = \insightjournal_coursereport_csv_row(
            $this->course,
            (int) $this->cm->id,
            $this->diary,
            $this->student,
            $entry,
            true
        );
```
to:
```php
        $row = \insightjournal_coursereport_csv_row(
            $this->course,
            (int) $this->cm->id,
            $this->diary,
            $this->student,
            $entry,
            true,
            true
        );
```

Change `tests/coursereport_csv_test.php:155-162` (`test_email_blanked_when_not_permitted`, entry is `INSIGHTJOURNAL_VISIBILITY_VISIBLE` so `$private = false`, `$showemail = false`) from:
```php
        $row = \insightjournal_coursereport_csv_row(
            $this->course,
            (int) $this->cm->id,
            $this->diary,
            $this->student,
            $entry,
            false
        );
```
to:
```php
        $row = \insightjournal_coursereport_csv_row(
            $this->course,
            (int) $this->cm->id,
            $this->diary,
            $this->student,
            $entry,
            false,
            false
        );
```

Change `tests/coursereport_csv_test.php:172-179` (`test_missing_entry_blanks_response_and_timemodified`, no entry so `$private = false`) from:
```php
        $row = \insightjournal_coursereport_csv_row(
            $this->course,
            (int) $this->cm->id,
            $this->diary,
            $this->student,
            null,
            true
        );
```
to:
```php
        $row = \insightjournal_coursereport_csv_row(
            $this->course,
            (int) $this->cm->id,
            $this->diary,
            $this->student,
            null,
            false,
            true
        );
```

Change `tests/coursereport_csv_test.php:194-201` (`test_formula_prefixed_value_is_returned_unescaped`, no entry so `$private = false`) from:
```php
        $row = \insightjournal_coursereport_csv_row(
            $this->course,
            (int) $this->cm->id,
            $diary,
            $this->student,
            null,
            true
        );
```
to:
```php
        $row = \insightjournal_coursereport_csv_row(
            $this->course,
            (int) $this->cm->id,
            $diary,
            $this->student,
            null,
            false,
            true
        );
```

- [ ] **Step 2: Sync and run to verify RED**

```bash
rsync -av --delete /path/to/worktree/ ~/moodle-dev/moodle/mod/insightjournal/
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter coursereport_csv_test
```
Expected: the 5 updated tests **fail with a `TypeError`** ("too many arguments" / argument count mismatch against the current 6-parameter signature) - the still-unmodified function doesn't accept a 7th argument yet. `test_csv_export_writer_recipe_produces_bom` and `test_csv_export_writer_escapes_formula_after_leading_whitespace` (which don't call this function) and the entries/cell-state tests below them should still pass.

- [ ] **Step 3: Update `insightjournal_coursereport_csv_row()`**

Change `locallib.php:499-540` from:
```php
/**
 * Builds one course-report CSV row: one participant's entry (or lack of
 * one) for one activity. Returned in the plugin's long-standing 9-column
 * legacy order: courseid, coursename, cmid, activityname, userid,
 * fullname, email, response, timemodified.
 *
 * Values are returned raw/unescaped - spreadsheet-formula-prefix escaping
 * is csv_export_writer::add_data()'s job once this row reaches it, not
 * this function's.
 *
 * @param stdClass $course The course the activity belongs to.
 * @param int $cmid The activity's course-module id.
 * @param stdClass $diary The insight journal instance.
 * @param stdClass $user The participant.
 * @param stdClass|null $entry The participant's entry for this activity, or null if they have none.
 * @param bool $showemail Whether the viewer may see participant email addresses.
 * @return array The 9-column row.
 */
function insightjournal_coursereport_csv_row(
    stdClass $course,
    int $cmid,
    stdClass $diary,
    stdClass $user,
    ?stdClass $entry,
    bool $showemail
): array {
    $private = $entry && !insightjournal_entry_visible_to_teacher($entry);

    return [
        $course->id,
        $course->fullname,
        $cmid,
        $diary->name,
        $user->id,
        fullname($user),
        $showemail ? ($user->email ?? '') : '',
        $private
            ? get_string('entriesprivatenotice', 'insightjournal')
            : insightjournal_html_to_text($entry->response ?? ''),
        (!$private && $entry) ? userdate($entry->timemodified) : '',
    ];
}
```
to:
```php
/**
 * Builds one course-report CSV row: one participant's entry (or lack of
 * one) for one activity. Returned in the plugin's long-standing 9-column
 * legacy order: courseid, coursename, cmid, activityname, userid,
 * fullname, email, response, timemodified.
 *
 * Values are returned raw/unescaped - spreadsheet-formula-prefix escaping
 * is csv_export_writer::add_data()'s job once this row reaches it, not
 * this function's.
 *
 * @param stdClass $course The course the activity belongs to.
 * @param int $cmid The activity's course-module id.
 * @param stdClass $diary The insight journal instance.
 * @param stdClass $user The participant.
 * @param stdClass|null $entry The participant's entry for this activity, or null if they have none.
 * @param bool $private Whether the entry's author chose to keep it private
 *     from the trainer - the caller already has this (from
 *     insightjournal_coursereport_cell_state() via coursereport_provider),
 *     so it is passed in rather than recomputed here.
 * @param bool $showemail Whether the viewer may see participant email addresses.
 * @return array The 9-column row.
 */
function insightjournal_coursereport_csv_row(
    stdClass $course,
    int $cmid,
    stdClass $diary,
    stdClass $user,
    ?stdClass $entry,
    bool $private,
    bool $showemail
): array {
    return [
        $course->id,
        $course->fullname,
        $cmid,
        $diary->name,
        $user->id,
        fullname($user),
        $showemail ? ($user->email ?? '') : '',
        $private
            ? get_string('entriesprivatenotice', 'insightjournal')
            : insightjournal_html_to_text($entry->response ?? ''),
        (!$private && $entry) ? userdate($entry->timemodified) : '',
    ];
}
```

- [ ] **Step 4: Sync and run to verify GREEN**

```bash
rsync -av --delete /path/to/worktree/ ~/moodle-dev/moodle/mod/insightjournal/
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite --filter coursereport_csv_test
```
Expected: all tests in the file pass (13 tests).

**Note:** `coursereport.php` itself still calls this function with the OLD 6-argument signature at this point in the plan - Task 3 updates that call site. This means the full suite will NOT be green until Task 3 lands; that's expected and fine, since `coursereport.php`'s own call site has no test coverage that would fail early (it's exercised only via Behat, not PHPUnit) - Step 5 below confirms this precisely so it isn't mistaken for a regression.

- [ ] **Step 5: Confirm the full suite's only failure (if any) is expected**

```bash
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite
```
Expected: all PHPUnit tests pass (`coursereport.php`'s own CSV-export call site has no PHPUnit coverage - only Behat exercises the live page - so this run should already be fully green; if anything fails here, stop and investigate before committing, since it means something unexpected is covering that call site).

- [ ] **Step 6: phpcs and PHPStan**

```bash
"/mnt/c/xampp/php/php.exe" C:/Git/ij-tooling/vendor/squizlabs/php_codesniffer/bin/phpcs --standard=moodle,moodle-extra --extensions=php --warning-severity=1 C:/Git/insightjournal/.claude/worktrees/<worktree-name>/locallib.php C:/Git/insightjournal/.claude/worktrees/<worktree-name>/tests/coursereport_csv_test.php
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpstan analyse -c mod/insightjournal/phpstan.neon --memory-limit=1G --no-progress
```
Expected: phpcs clean. **PHPStan will report an error at `coursereport.php`'s call site** (wrong argument count) until Task 3 fixes that call - this is expected; note it in your report but do not fix `coursereport.php` in this task (out of scope, Task 3's job).

- [ ] **Step 7: Commit**

```bash
git add locallib.php tests/coursereport_csv_test.php
git commit -m "$(cat <<'EOF'
refactor: pass private as a parameter to insightjournal_coursereport_csv_row() (R4-04 part 2/3)

The function recomputed entry-level privacy via
insightjournal_entry_visible_to_teacher() internally, duplicating the
identical computation insightjournal_coursereport_cell_state() already
does for the same entry. Take the caller's already-computed value
instead. coursereport.php's own call site is updated in the next
commit, which is why PHPStan flags it as a mismatch until then.
EOF
)"
```

---

### Task 3: Migrate `coursereport.php`, delete the absorbed locallib.php functions, update their tests

**Files:**
- Modify: `coursereport.php` (full rewrite of the authorization/paging/rendering logic)
- Modify: `locallib.php` (delete `insightjournal_coursereport_restrict_groupids()`, `insightjournal_coursereport_allowed_groupids_by_diary()`, `insightjournal_coursereport_diary_allowed_users()`)
- Modify: `tests/coursereport_authorization_test.php`

**Interfaces:**
- Consumes: `\mod_insightjournal\local\coursereport_provider` (Task 1), `insightjournal_coursereport_csv_row(..., bool $private, bool $showemail)` (Task 2).
- Produces: nothing further consumes this task's output - it's the final wiring task.

- [ ] **Step 1: Confirm the three functions being deleted have no remaining callers outside `coursereport.php` and its own tests**

```bash
grep -rn "insightjournal_coursereport_restrict_groupids(\|insightjournal_coursereport_allowed_groupids_by_diary(\|insightjournal_coursereport_diary_allowed_users(" /path/to/worktree --include="*.php"
```
Expected: only `locallib.php` (the definitions), `coursereport.php` (about to be rewritten), and `tests/coursereport_authorization_test.php` (about to be updated in Step 4). If anything else shows up, stop and investigate before proceeding.

- [ ] **Step 2: Rewrite `coursereport.php`**

Replace the entire file content with:

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
 * Course-wide insight journal report.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/insightjournal/lib.php');
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');

use mod_insightjournal\local\coursereport_provider;

$courseid = required_param('courseid', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
$page = max(0, optional_param('page', 0, PARAM_INT));
$perpage = max(1, min(200, optional_param('perpage', 20, PARAM_INT)));

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);
$coursecontext = context_course::instance($course->id);

$modinfo = get_fast_modinfo($course);
$activities = [];
foreach ($modinfo->get_instances_of('insightjournal') as $cm) {
    if (!$cm->uservisible) {
        continue;
    }
    $context = context_module::instance($cm->id);
    if (has_capability('mod/insightjournal:viewall', $context)) {
        $activities[$cm->instance] = $cm;
    }
}

if (empty($activities)) {
    throw new required_capability_exception($coursecontext, 'mod/insightjournal:viewall', 'nopermissions', '');
}

$diaryids = array_keys($activities);
$diaries = $DB->get_records_list('insightjournal', 'id', $diaryids, 'id ASC');
$provider = new coursereport_provider($course, $activities);

// Checked at course context, not per-activity like report_table.php - deliberately
// coarse. A viewer reaching this branch already holds the capability course-wide,
// so this can only ever be more permissive than a hypothetical per-activity
// override, never less.
$showemail = insightjournal_email_field_visible($coursecontext);

if ($download === 'csv') {
    foreach ($activities as $cm) {
        require_capability('mod/insightjournal:export', context_module::instance($cm->id));
    }
    confirm_sesskey();

    require_once($CFG->libdir . '/csvlib.class.php');
    $writer = new csv_export_writer('comma', '"', 'text/csv', true); // BOM: true - matches report.php's dataformat-writer BOM.
    $writer->filename = clean_filename('insightjournal-course-' . $course->shortname . '.csv');
    $writer->add_data([
        'courseid', 'coursename', 'cmid', 'activityname', 'userid',
        'fullname', 'email', 'response', 'timemodified',
    ]);

    // Fetched and written one bounded chunk of participants at a time, each
    // with only that chunk's own entries, instead of the whole course's
    // participants/entries held in memory at once - keeps memory bounded
    // regardless of course size, the same property report.php already gets
    // for free from table_sql (R2-04).
    $csvchunksize = 500;
    $offset = 0;
    while (true) {
        $chunk = $provider->participants($offset, $csvchunksize);
        if (empty($chunk)) {
            break;
        }
        foreach ($provider->rows_for($chunk) as $row) {
            foreach ($row['cells'] as $diaryid => $cell) {
                if (!$cell['visible']) {
                    continue;
                }
                $writer->add_data(insightjournal_coursereport_csv_row(
                    $course,
                    $activities[$diaryid]->id,
                    $diaries[$diaryid],
                    $row['user'],
                    $cell['entry'],
                    $cell['private'],
                    $showemail
                ));
            }
        }
        $offset += $csvchunksize;
        if (count($chunk) < $csvchunksize) {
            break;
        }
    }
    $writer->download_file(); // Sends headers, streams the file, and exit()s - same contract as the previous fclose()+exit.
}

$totalparticipants = $provider->total_participants();
$participants = $provider->participants($page * $perpage, $perpage);

$PAGE->set_url('/mod/insightjournal/coursereport.php', ['courseid' => $course->id, 'page' => $page, 'perpage' => $perpage]);
$PAGE->set_context($coursecontext);
$PAGE->set_title(get_string('coursereport', 'insightjournal'));
$PAGE->set_heading(format_string($course->fullname));

$activityheaders = [];
foreach ($diaries as $diary) {
    $activityheaders[] = [
        'name' => format_string($diary->name),
    ];
}

$rows = [];
foreach ($provider->rows_for($participants) as $userid => $row) {
    if ($row['visiblecount'] === 0) {
        continue;
    }
    $cells = [];
    foreach ($diaries as $diary) {
        $cell = $row['cells'][$diary->id];
        if (!$cell['visible'] || $cell['private']) {
            $cells[] = ['private' => true];
            continue;
        }
        $cells[] = [
            'private' => false,
            'completed' => $cell['completed'],
            'status' => get_string($cell['completed'] ? 'submitted' : 'notsubmitted', 'insightjournal'),
            'timemodified' => $cell['completed']
                ? userdate($cell['entry']->timemodified, get_string('strftimedatetimeshort', 'langconfig'))
                : '',
        ];
    }
    $rows[] = [
        'fullname' => fullname($row['user']),
        'summaryurl' => (new moodle_url(
            '/mod/insightjournal/summary.php',
            [
                'courseid' => $course->id,
                'userid' => $userid,
                'returnurl' => (new moodle_url(
                    '/mod/insightjournal/coursereport.php',
                    ['courseid' => $course->id]
                ))->out_as_local_url(false),
            ]
        ))->out(false),
        'cells' => $cells,
        'progress' => $row['done'] . ' / ' . $row['visiblecount'],
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursereport', 'insightjournal'));
echo $OUTPUT->render_from_template('mod_insightjournal/coursereport', [
    'backurl' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
    'downloadurl' => (new moodle_url(
        '/mod/insightjournal/coursereport.php',
        ['courseid' => $course->id, 'download' => 'csv', 'sesskey' => sesskey()]
    ))->out(false),
    'activities' => $activityheaders,
    'rows' => $rows,
    'hasactivities' => !empty($activityheaders),
    'pagingbar' => $OUTPUT->paging_bar($totalparticipants, $page, $perpage, $PAGE->url),
]);
echo $OUTPUT->footer();
```

**Behavior-preservation notes for whoever implements this** (do not deviate from these - they are exact, verified-equivalent translations of the pre-refactor logic, not new decisions):
- A cell that fails `visible` OR is entry-level `private` both render as `['private' => true]` in the template context - this collapses two different reasons into the same masked display, exactly matching the pre-refactor code's `if ($allowedusers !== null && !isset(...)) { mask } ... if ($state['private']) { mask }` two-step.
- `visiblecount` counts every `visible` cell regardless of that cell's own `private` flag (a private-but-authorized entry still counts as "the viewer is authorized to see this diary for this learner," just not its content) - matches the pre-refactor `$visiblecount++` placement (before the private check).
- `done` counts every `completed` cell regardless of `private`, matching `insightjournal_coursereport_cell_state()`'s own documented contract that completion is independent of privacy.
- The CSV loop skips a cell only on `!visible`, never on `private` - a private entry still gets a CSV row (with the privacy notice as its content, handled inside `insightjournal_coursereport_csv_row()`), matching `tests/coursereport_csv_test.php::test_private_entry_uses_notice_and_blanks_timemodified`.

- [ ] **Step 3: Delete the three absorbed functions from `locallib.php`**

Delete `locallib.php:390-478` in full (the `insightjournal_coursereport_allowed_groupids_by_diary()` docblock through the closing `}` of `insightjournal_coursereport_restrict_groupids()`) - three complete docblock+function blocks, contiguous. `insightjournal_email_field_visible()` (the next function) is untouched.

- [ ] **Step 4: Update `tests/coursereport_authorization_test.php`**

Add `use mod_insightjournal\local\coursereport_provider;` to the `use` block near the top of the file.

Change the `#[CoversFunction(...)]` attributes above the class from:
```php
#[CoversFunction('insightjournal_coursereport_restrict_groupids')]
#[CoversFunction('insightjournal_activity_visible_to_viewer')]
```
to:
```php
#[CoversClass(coursereport_provider::class)]
#[CoversFunction('insightjournal_activity_visible_to_viewer')]
```
(add `use PHPUnit\Framework\Attributes\CoversClass;` alongside the existing `CoversFunction` import).

Delete these four test methods in full - they call the now-deleted, now-private-inside-the-provider functions directly, and `tests/local/coursereport_provider_test.php` (Task 1) already covers the same scenarios through the provider's public interface:
- `test_allowed_groupids_by_diary_shares_result_across_shared_grouping`
- `test_allowed_groupids_by_diary_null_when_not_restricted`
- `test_diary_allowed_users_scoped_to_given_userids`
- `test_diary_allowed_users_passes_through_null`

Rewrite the remaining `test_two_layer_authorization_across_two_groupings` test - change its body from calling `insightjournal_coursereport_restrict_groupids()` directly and cross-checking via a separate `groups_get_all_groups()` call, to constructing a real `coursereport_provider` and asserting on its actual `participants()`/`rows_for()` output (a more direct verification of production behavior than the old indirect proxy check):

```php
    /**
     * Two activities in different groupings, both Separate Groups: a
     * teacher's SQL-level participant prefilter must include a student
     * authorized under either activity's grouping (layer 1), but the
     * per-cell check must still mask the activity the student isn't
     * authorized under (layer 2) - proving the two layers compose
     * correctly, not just each in isolation.
     */
    public function test_two_layer_authorization_across_two_groupings(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $student = $generator->create_and_enrol($this->course, 'student');
        $outsider = $generator->create_and_enrol($this->course, 'student');

        $diarya = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $diaryb = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cma = get_coursemodule_from_id('insightjournal', $diarya->cmid, 0, false, MUST_EXIST);
        $cmb = get_coursemodule_from_id('insightjournal', $diaryb->cmid, 0, false, MUST_EXIST);

        $groupinga = $generator->create_grouping(['courseid' => $this->course->id]);
        $groupingb = $generator->create_grouping(['courseid' => $this->course->id]);
        $DB->set_field('course_modules', 'groupingid', $groupinga->id, ['id' => $cma->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cma->id]);
        $DB->set_field('course_modules', 'groupingid', $groupingb->id, ['id' => $cmb->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cmb->id]);
        $cma = get_coursemodule_from_id('insightjournal', $diarya->cmid, 0, false, MUST_EXIST);
        $cmb = get_coursemodule_from_id('insightjournal', $diaryb->cmid, 0, false, MUST_EXIST);

        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_grouping_group(['groupingid' => $groupinga->id, 'groupid' => $groupa->id]);
        $generator->create_grouping_group(['groupingid' => $groupingb->id, 'groupid' => $groupb->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $teacher->id]);
        // The student is only in group B (grouping B) - not group A.
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $student->id]);

        $this->ij_generator()->create_entry($diarya, (int) $student->id, 'Entry in activity A.', INSIGHTJOURNAL_VISIBILITY_VISIBLE);
        $this->ij_generator()->create_entry($diaryb, (int) $student->id, 'Entry in activity B.', INSIGHTJOURNAL_VISIBILITY_VISIBLE);

        $this->setUser($teacher);

        $activities = [$cma->instance => $cma, $cmb->instance => $cmb];
        $provider = new coursereport_provider($this->course, $activities);

        // Layer 1: the SQL-level prefilter is the union of the teacher's
        // groups across both restricted activities - group A and group B -
        // so the student (group B) reaches participants(), the outsider
        // (in neither group) does not.
        $participants = $provider->participants(0, 20);
        $this->assertArrayHasKey((int) $student->id, $participants);
        $this->assertArrayNotHasKey((int) $outsider->id, $participants);

        // Layer 2: even though the student passes the SQL prefilter, the
        // per-activity cell check must still mask activity A specifically -
        // the student is not in group A's grouping.
        $rows = $provider->rows_for($participants);
        $this->assertFalse($rows[(int) $student->id]['cells'][$diarya->id]['visible']);
        $this->assertTrue($rows[(int) $student->id]['cells'][$diaryb->id]['visible']);
    }
```

- [ ] **Step 5: Sync and run the full PHPUnit suite**

```bash
rsync -av --delete /path/to/worktree/ ~/moodle-dev/moodle/mod/insightjournal/
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite mod_insightjournal_testsuite
```
Expected: all tests pass, no undefined-function errors (confirms the three deletions have no missed callers), no argument-count errors (confirms `coursereport.php`'s `insightjournal_coursereport_csv_row()` call site is correctly updated).

- [ ] **Step 6: phpcs and PHPStan**

```bash
"/mnt/c/xampp/php/php.exe" C:/Git/ij-tooling/vendor/squizlabs/php_codesniffer/bin/phpcs --standard=moodle,moodle-extra --extensions=php --warning-severity=1 C:/Git/insightjournal/.claude/worktrees/<worktree-name>/coursereport.php C:/Git/insightjournal/.claude/worktrees/<worktree-name>/locallib.php C:/Git/insightjournal/.claude/worktrees/<worktree-name>/tests/coursereport_authorization_test.php
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver vendor/bin/phpstan analyse -c mod/insightjournal/phpstan.neon --memory-limit=1G --no-progress
```
Expected: both clean - the PHPStan mismatch noted as expected in Task 2 Step 6 must now be gone.

- [ ] **Step 7: Full Behat suite — required, not optional**

`coursereport.php`'s rendering has no direct PHPUnit coverage of the full page script (same reasoning as R4-03's Task 3) - the Behat scenarios covering the course-wide report (pagination, Separate Groups masking, CSV download) are the real end-to-end regression net for this rewrite:

```bash
cd ~/moodle-dev/moodle-docker && bin/moodle-docker-compose exec webserver php admin/tool/behat/cli/init.php
bin/moodle-docker-compose exec webserver vendor/bin/behat --config /var/www/behatdata/behatrun/behat/behat.yml --tags @mod_insightjournal
```
Expected: all scenarios pass (20/20 as of the last verified count on `main`).

- [ ] **Step 8: Commit**

```bash
git add coursereport.php locallib.php tests/coursereport_authorization_test.php
git commit -m "$(cat <<'EOF'
refactor: wire coursereport.php through coursereport_provider (R4-04 part 3/3)

coursereport.php's CSV export and screen page each ran their own
near-identical participant x activity authorization loop. Both now
call coursereport_provider::participants()/rows_for() - the same
resolved data, formatted differently by each thin renderer. The three
insightjournal_coursereport_restrict_groupids()/
allowed_groupids_by_diary()/diary_allowed_users() locallib.php
functions have no callers left outside the provider and are removed;
their coverage moved to tests/local/coursereport_provider_test.php,
which exercises them through the provider's real public interface
instead of duplicating assertions against the standalone functions.
EOF
)"
```

## Self-Review Notes

- **Spec coverage:** All four spec sections covered - Task 1 (provider construction with the two R4-03 optimizations folded in, `rows_for()`), Task 2 (`$private` parameter), Task 3 (`coursereport.php` rewiring + deletion + test migration). The spec's explicit ambiguity fix (invisible cells carry only the `visible` key) is enforced in Task 1's implementation and has a dedicated test (`test_invisible_cell_carries_only_the_visible_key`).
- **Placeholder scan:** No TBD/TODO. Task 2's Step 5 explicitly documents why an intermediate state (full suite green except one uncovered call site) is expected rather than glossing over it as "should pass."
- **Type consistency:** `coursereport_provider`'s constructor signature (Task 1) is consumed identically in Task 3's `coursereport.php` rewrite and in the rewritten test in Task 3 Step 4. `insightjournal_coursereport_csv_row()`'s new `bool $private` parameter (Task 2) is consumed with the matching position (6th, before `$showemail`) in Task 3's CSV loop. The `rows_for()` return shape (`cells[$diaryid]['entry'|'completed'|'private']` only present when `visible === true`) is used consistently in both Task 1's tests and Task 3's `coursereport.php` rewrite (`if (!$cell['visible'] || $cell['private'])` never reads `entry`/`completed` before confirming `visible`... actually it short-circuits on `!$cell['visible']` first via `||`, so `$cell['private']` is only evaluated when `visible` is true - safe).
