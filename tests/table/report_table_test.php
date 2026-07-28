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
 * Unit tests for the mod_insightjournal/report_table class.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_insightjournal\table;

use advanced_testcase;
use context_module;
use moodle_url;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use stdClass;

/**
 * Tests for {@see report_table}.
 */
#[CoversClass(report_table::class)]
final class report_table_test extends advanced_testcase {
    /** @var stdClass The course. */
    protected stdClass $course;

    /** @var stdClass The insight journal instance. */
    protected stdClass $diary;

    /** @var stdClass The activity's course-module record. */
    protected stdClass $cm;

    /** @var context_module The activity's module context. */
    protected context_module $context;

    /**
     * Creates a course and an insight journal activity to report on.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_id('insightjournal', $this->diary->cmid, 0, false, MUST_EXIST);
        $this->context = context_module::instance($this->cm->id);

        global $PAGE;
        $PAGE->set_url('/mod/insightjournal/report.php', ['id' => $this->cm->id]);
    }

    /**
     * @return \mod_insightjournal_generator
     */
    protected function ij_generator() {
        return $this->getDataGenerator()->get_plugin_generator('mod_insightjournal');
    }

    /**
     * Builds a report_table wired up the same way report.php wires it for
     * on-screen rendering, and renders it to a string.
     *
     * @param string $search Optional search term.
     * @param int $perpage Page size.
     * @return string The rendered HTML.
     */
    protected function render_table(string $search = '', int $perpage = 20): string {
        $table = new report_table('report_table_test', $this->course, $this->cm, $this->diary, $this->context, $search);
        $table->setup_columns();
        $table->define_baseurl(new moodle_url('/mod/insightjournal/report.php', ['id' => $this->cm->id]));

        ob_start();
        $table->out($perpage, false);
        return ob_get_clean();
    }

    /**
     * A visible row renders its response and no privacy notice.
     */
    public function test_visible_row_shows_response(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->ij_generator()->create_entry(
            $this->diary,
            (int) $student->id,
            'Today I learned about table_sql.',
            INSIGHTJOURNAL_VISIBILITY_VISIBLE
        );

        $html = $this->render_table();

        $this->assertStringContainsString(fullname($student), $html);
        $this->assertStringContainsString('Today I learned about table_sql.', $html);
        $this->assertStringNotContainsString(get_string('entriesprivatenotice', 'mod_insightjournal'), $html);
    }

    /**
     * A private row (the participant's own choice) still shows their name,
     * but the notice replaces their response.
     */
    public function test_private_row_shows_notice_and_hides_response(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->ij_generator()->create_entry(
            $this->diary,
            (int) $student->id,
            'Secret reflection.',
            INSIGHTJOURNAL_VISIBILITY_PRIVATE
        );

        $html = $this->render_table();

        $this->assertStringContainsString(fullname($student), $html);
        $this->assertStringContainsString(get_string('entriesprivatenotice', 'mod_insightjournal'), $html);
        $this->assertStringNotContainsString('Secret reflection.', $html);
    }

    /**
     * A mix of a visible and a private row in the same report renders both
     * correctly: both participants' names show, but only the visible row's
     * response content appears.
     */
    public function test_mixed_rows_only_hides_the_private_response(): void {
        $visible = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $private = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->ij_generator()->create_entry($this->diary, (int) $visible->id, 'Public reflection.', INSIGHTJOURNAL_VISIBILITY_VISIBLE);
        $this->ij_generator()->create_entry($this->diary, (int) $private->id, 'Secret reflection.', INSIGHTJOURNAL_VISIBILITY_PRIVATE);

        $html = $this->render_table();

        $this->assertStringContainsString('Public reflection.', $html);
        $this->assertStringContainsString(fullname($private), $html);
        $this->assertStringContainsString(get_string('entriesprivatenotice', 'mod_insightjournal'), $html);
        $this->assertStringNotContainsString('Secret reflection.', $html);
    }

    /**
     * The search box filters by first name, last name, or email.
     */
    public function test_search_filters_by_name(): void {
        $jane = $this->getDataGenerator()->create_and_enrol(
            $this->course,
            'student',
            ['firstname' => 'Jane', 'lastname' => 'Doe']
        );
        $john = $this->getDataGenerator()->create_and_enrol(
            $this->course,
            'student',
            ['firstname' => 'John', 'lastname' => 'Roe']
        );
        $this->ij_generator()->create_entry($this->diary, (int) $jane->id, 'Jane entry.', INSIGHTJOURNAL_VISIBILITY_VISIBLE);
        $this->ij_generator()->create_entry($this->diary, (int) $john->id, 'John entry.', INSIGHTJOURNAL_VISIBILITY_VISIBLE);

        $html = $this->render_table('Jane');

        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringNotContainsString('John Roe', $html);
    }

    /**
     * Row order is fixed (lastname, then firstname), regardless of insertion order.
     */
    public function test_rows_are_ordered_by_lastname_then_firstname(): void {
        $zed = $this->getDataGenerator()->create_and_enrol(
            $this->course,
            'student',
            ['firstname' => 'Zed', 'lastname' => 'Zabriskie']
        );
        $amy = $this->getDataGenerator()->create_and_enrol(
            $this->course,
            'student',
            ['firstname' => 'Amy', 'lastname' => 'Abbot']
        );
        $this->ij_generator()->create_entry($this->diary, (int) $zed->id, 'Zed entry.', INSIGHTJOURNAL_VISIBILITY_VISIBLE);
        $this->ij_generator()->create_entry($this->diary, (int) $amy->id, 'Amy entry.', INSIGHTJOURNAL_VISIBILITY_VISIBLE);

        $html = $this->render_table();

        $this->assertLessThan(strpos($html, 'Zed Zabriskie'), strpos($html, 'Amy Abbot'));
    }

    /**
     * Only the current page's rows are rendered when there are more
     * participants than fit on one page.
     */
    public function test_pagination_shows_only_current_page(): void {
        for ($i = 0; $i < 3; $i++) {
            $student = $this->getDataGenerator()->create_and_enrol(
                $this->course,
                'student',
                ['firstname' => 'Student', 'lastname' => sprintf('%02d', $i)]
            );
            $this->ij_generator()->create_entry($this->diary, (int) $student->id, "Entry $i.", INSIGHTJOURNAL_VISIBILITY_VISIBLE);
        }

        $html = $this->render_table('', 2);

        $this->assertStringContainsString('Student 00', $html);
        $this->assertStringContainsString('Student 01', $html);
        $this->assertStringNotContainsString('Student 02', $html);
    }

    /**
     * The CSV download preserves the exact legacy 9-column format. This runs
     * in a separate process because finish_document() calls exit() once the
     * CSV body has been streamed — that would otherwise kill the whole
     * PHPUnit run, not just this test.
     */
    #[RunInSeparateProcess]
    public function test_csv_export_matches_legacy_column_format(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->ij_generator()->create_entry($this->diary, (int) $student->id, 'CSV entry.', INSIGHTJOURNAL_VISIBILITY_VISIBLE);

        $table = new report_table('report_table_test_csv', $this->course, $this->cm, $this->diary, $this->context);
        $table->is_downloading('csv', 'insightjournal-test');
        $table->setup_columns();
        $table->define_baseurl(new moodle_url('/mod/insightjournal/report.php', ['id' => $this->cm->id]));

        ob_start();
        $table->out(20, false);
        $csv = ob_get_clean();

        $this->assertStringContainsString('courseid', $csv);
        $this->assertStringContainsString('coursename', $csv);
        $this->assertStringContainsString('activityname', $csv);
        $this->assertStringContainsString('fullname', $csv);
        $this->assertStringContainsString((string) $this->course->id, $csv);
        $this->assertStringContainsString($this->diary->name, $csv);
        $this->assertStringContainsString(fullname($student), $csv);
        $this->assertStringContainsString('CSV entry.', $csv);
    }

    /**
     * A private entry's CSV row uses the privacy notice text, not the response.
     */
    #[RunInSeparateProcess]
    public function test_csv_export_hides_private_response(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->ij_generator()->create_entry($this->diary, (int) $student->id, 'Secret reflection.', INSIGHTJOURNAL_VISIBILITY_PRIVATE);

        $table = new report_table('report_table_test_csv_private', $this->course, $this->cm, $this->diary, $this->context);
        $table->is_downloading('csv', 'insightjournal-test');
        $table->setup_columns();
        $table->define_baseurl(new moodle_url('/mod/insightjournal/report.php', ['id' => $this->cm->id]));

        ob_start();
        $table->out(20, false);
        $csv = ob_get_clean();

        $this->assertStringContainsString(get_string('entriesprivatenotice', 'mod_insightjournal'), $csv);
        $this->assertStringNotContainsString('Secret reflection.', $csv);
    }
}
