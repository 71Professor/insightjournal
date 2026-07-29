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
 * Unit tests for mod_insightjournal's Moodle event classes.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_insightjournal;

use advanced_testcase;
use completion_info;
use context_module;
use mod_insightjournal\event\course_module_viewed;
use PHPUnit\Framework\Attributes\CoversFunction;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/insightjournal/lib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Tests for {@see \insightjournal_view()} and the mod_insightjournal event classes.
 */
#[CoversFunction('insightjournal_view')]
final class event_test extends advanced_testcase {
    /** @var stdClass The course. */
    protected stdClass $course;

    /** @var stdClass The insight journal instance. */
    protected stdClass $diary;

    /** @var stdClass The activity's course-module record. */
    protected stdClass $cm;

    /** @var context_module The activity's module context. */
    protected context_module $context;

    /** @var stdClass The enrolled student. */
    protected stdClass $student;

    /**
     * Creates a basic course with an insight journal activity and an
     * enrolled student. Note that completion tracking is NOT enabled here
     * (matching mod_choice's convention) to avoid the course_module_completion_updated
     * event that would otherwise be fired by set_module_viewed().
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_id('insightjournal', $this->diary->cmid, 0, false, MUST_EXIST);
        $this->context = context_module::instance($this->cm->id);
        $this->student = $generator->create_and_enrol($this->course, 'student');
    }

    /**
     * insightjournal_view() fires exactly one course_module_viewed event
     * with the activity instance as its object. Completion tracking is not
     * configured on this activity (see setUp()) so set_module_viewed()
     * returns early without touching completion state - matching Moodle
     * core's own mod_choice::test_choice_view() convention exactly, which
     * avoids entangling this assertion with core's own
     * course_module_completion_updated event that a completion-tracked
     * activity would additionally fire.
     */
    public function test_view_fires_course_module_viewed(): void {
        $this->setUser($this->student);

        $sink = $this->redirectEvents();
        insightjournal_view($this->diary, $this->course, $this->cm, $this->context);
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(course_module_viewed::class, $event);
        $this->assertEquals($this->diary->id, $event->objectid);
        $this->assertEquals($this->context->id, $event->contextid);
        $this->assertEquals((int) $this->student->id, $event->userid);
    }

    /**
     * insightjournal_view() still marks the activity as viewed for
     * completion tracking, on a separately-configured completion-enabled
     * activity (kept separate from the event-count test above precisely
     * because enabling completion here also fires an unrelated core
     * course_module_completion_updated event, which would make a shared
     * assertCount(1, $events) fail for a reason unrelated to what this
     * test is actually checking).
     */
    public function test_view_marks_activity_as_viewed_for_completion(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $diary = $generator->create_module('insightjournal', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => COMPLETION_VIEW_REQUIRED,
        ]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        $student = $generator->create_and_enrol($course, 'student');
        $this->setUser($student);

        insightjournal_view($diary, $course, $cm, $context);

        $completion = new completion_info($course);
        $data = $completion->get_data($cm, false, (int) $student->id);
        $this->assertEquals(COMPLETION_VIEWED, $data->viewed);
    }
}
