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
 * Integration tests wiring locallib.php's per-activity visibility
 * helpers into coursereport.php's actual query sequence.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_insightjournal;

use advanced_testcase;
use context_module;
use mod_insightjournal\local\coursereport_provider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');
require_once($CFG->dirroot . '/mod/insightjournal/lib.php');

/**
 * Reproduces coursereport.php's two-layer authorization sequence:
 * the SQL-level participant prefilter and the per-cell visibility
 * check, proving the R3-02 cross-activity disclosure scenario is
 * closed at both layers.
 */
#[CoversClass(coursereport_provider::class)]
#[CoversFunction('insightjournal_activity_visible_to_viewer')]
final class coursereport_authorization_test extends advanced_testcase {
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
}
