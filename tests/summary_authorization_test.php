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
 * helpers into summary.php's actual query sequence.
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
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');
require_once($CFG->dirroot . '/mod/insightjournal/lib.php');

/**
 * Reproduces summary.php's actual production sequence for computing
 * $querycms/$diaryids when viewing another user's summary, proving the
 * R3-02 cross-activity disclosure scenario is closed end-to-end.
 */
#[CoversFunction('insightjournal_visible_activities_for_user')]
#[CoversFunction('insightjournal_activity_visible_to_viewer')]
final class summary_authorization_test extends advanced_testcase {
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
     * Reproduces summary.php's own SQL query for a given set of visible
     * activities and a target user, returning the diary ids that
     * actually come back with a non-null response.
     *
     * @param array<int, cm_info|stdClass> $querycms
     * @param int $viewuserid
     * @return int[]
     */
    protected function diaryids_with_response(array $querycms, int $viewuserid): array {
        global $DB;

        $diaryids = array_keys($querycms);
        [$insql, $params] = $DB->get_in_or_equal($diaryids, SQL_PARAMS_NAMED);
        $params['userid'] = $viewuserid;
        $records = $DB->get_records_sql(
            "SELECT rd.id, e.response
               FROM {insightjournal} rd
          LEFT JOIN {insightjournal_entries} e ON e.insightjournalid = rd.id AND e.userid = :userid
              WHERE rd.id $insql
           ORDER BY rd.id ASC",
            $params
        );

        $withresponse = [];
        foreach ($records as $record) {
            if ($record->response !== null) {
                $withresponse[] = (int) $record->id;
            }
        }

        return $withresponse;
    }

    /**
     * Two activities in different groupings, both Separate Groups: a
     * teacher authorized under only one of them must not see the
     * target user's entry in the other, even though the target user
     * has an entry there too.
     */
    public function test_summary_query_excludes_activity_from_different_grouping(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $student = $generator->create_and_enrol($this->course, 'student');

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
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $student->id]);

        $this->ij_generator()->create_entry($diarya, (int) $student->id, 'Entry in activity A.', INSIGHTJOURNAL_VISIBILITY_VISIBLE);
        $this->ij_generator()->create_entry($diaryb, (int) $student->id, 'Entry in activity B.', INSIGHTJOURNAL_VISIBILITY_VISIBLE);

        $this->setUser($teacher);

        $viewallcms = [$cma->instance => $cma, $cmb->instance => $cmb];
        $querycms = insightjournal_visible_activities_for_user($viewallcms, $this->course, (int) $student->id);

        $this->assertArrayNotHasKey($cma->instance, $querycms);
        $this->assertArrayHasKey($cmb->instance, $querycms);

        $diaryidswithresponse = $this->diaryids_with_response($querycms, (int) $student->id);
        $this->assertSame([(int) $cmb->instance], $diaryidswithresponse);
    }
}
