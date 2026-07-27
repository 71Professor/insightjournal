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
 * Unit tests for the entry_form of mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_insightjournal\form;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for {@see \mod_insightjournal\form\entry_form}.
 */
#[CoversClass(entry_form::class)]
final class entry_form_test extends advanced_testcase {
    /** @var \stdClass The course. */
    protected $course;

    /** @var \context_module The module context. */
    protected $context;

    /**
     * Creates a course and module context to build forms against.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $journal = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $this->context = \context_module::instance($journal->cmid);
    }

    /**
     * Builds a form with the given maxchars, without rendering it.
     *
     * @param int $maxchars Maximum visible characters allowed, or 0 for none.
     * @return entry_form
     */
    protected function make_form(int $maxchars = 0): entry_form {
        return new entry_form(null, [
            'context' => $this->context,
            'maxchars' => $maxchars,
        ]);
    }

    /**
     * A response within the maxchars limit passes validation.
     */
    public function test_validation_passes_within_maxchars(): void {
        $form = $this->make_form(10);

        $errors = $form->validation([
            'response' => ['text' => 'hello', 'format' => FORMAT_HTML],
            'expectedrevision' => 0,
            'private' => 0,
        ], []);

        $this->assertArrayNotHasKey('response', $errors);
    }

    /**
     * A response exceeding maxchars (counting visible text, not markup) fails validation.
     */
    public function test_validation_fails_over_maxchars(): void {
        $form = $this->make_form(10);

        $errors = $form->validation([
            'response' => ['text' => '<p><strong>twelve chars</strong></p>', 'format' => FORMAT_HTML],
            'expectedrevision' => 0,
            'private' => 0,
        ], []);

        $this->assertArrayHasKey('response', $errors);
    }

    /**
     * With no maxchars configured, any length passes.
     */
    public function test_validation_passes_when_maxchars_is_zero(): void {
        $form = $this->make_form(0);

        $errors = $form->validation([
            'response' => ['text' => str_repeat('a', 5000), 'format' => FORMAT_HTML],
            'expectedrevision' => 0,
            'private' => 0,
        ], []);

        $this->assertArrayNotHasKey('response', $errors);
    }

    /**
     * The private checkbox is unchecked by default (visible to trainer),
     * matching set_data() with private => 0.
     */
    public function test_private_checkbox_unchecked_by_default(): void {
        $form = $this->make_form();
        $form->set_data([
            'response' => ['text' => '', 'format' => FORMAT_HTML],
            'expectedrevision' => 0,
            'private' => 0,
        ]);
        $html = $form->render();

        $this->assertStringContainsString('data-insightjournal-private', $html);
        $this->assertDoesNotMatchRegularExpression('/id="id_private"[^>]*checked/', $html);
    }

    /**
     * With private => 1 in set_data(), the checkbox is pre-checked.
     */
    public function test_private_checkbox_checked_when_entry_is_private(): void {
        $form = $this->make_form();
        $form->set_data([
            'response' => ['text' => '', 'format' => FORMAT_HTML],
            'expectedrevision' => 0,
            'private' => 1,
        ]);
        $html = $form->render();

        $this->assertStringContainsString('data-insightjournal-private', $html);
        $this->assertMatchesRegularExpression('/id="id_private"[^>]*checked/', $html);
    }

    /**
     * The save button carries the data attribute autosave.js hooks its click
     * handler onto.
     */
    public function test_render_includes_save_button_data_attribute(): void {
        $form = $this->make_form();

        $this->assertStringContainsString('data-insightjournal-save', $form->render());
    }
}
