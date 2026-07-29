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
 * Unit tests for the email-identity-field helper in locallib.php.
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
use PHPUnit\Framework\Attributes\CoversFunction;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');
require_once($CFG->dirroot . '/mod/insightjournal/lib.php');

/**
 * Tests for {@see \insightjournal_email_field_visible()}.
 */
#[CoversFunction('insightjournal_email_field_visible')]
final class locallib_email_test extends advanced_testcase {
    /** @var stdClass The course. */
    protected stdClass $course;

    /** @var context_module The activity's module context. */
    protected context_module $context;

    /**
     * Creates a course and an insight journal activity.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $this->context = context_module::instance($cm->id);
    }

    /**
     * A teacher (moodle/site:viewuseridentity is CAP_ALLOW for this
     * archetype by default) sees email under the site's default
     * $CFG->showuseridentity ('email' only).
     */
    public function test_visible_for_capable_role(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->setUser($teacher);

        $this->assertTrue(insightjournal_email_field_visible($this->context));
    }

    /**
     * Explicitly revoking the capability for an otherwise-capable role
     * hides email, even though $CFG->showuseridentity still lists it.
     */
    public function test_hidden_when_capability_revoked(): void {
        global $DB;

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability('moodle/site:viewuseridentity', CAP_PREVENT, $teacherroleid, $this->context, true);
        $this->setUser($teacher);

        $this->assertFalse(insightjournal_email_field_visible($this->context));
    }

    /**
     * A capable role still gets no email if the site admin has removed
     * 'email' from $CFG->showuseridentity entirely - the capability alone
     * is not sufficient.
     */
    public function test_hidden_when_email_removed_from_showuseridentity(): void {
        global $CFG;

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->setUser($teacher);
        $CFG->showuseridentity = '';

        $this->assertFalse(insightjournal_email_field_visible($this->context));
    }
}
