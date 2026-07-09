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
require_once($CFG->dirroot . '/mod/insightjournal/lib.php');

/**
 * Tests for {@see \insightjournal_html_to_text()}, {@see \insightjournal_prompt_style()},
 * and {@see \insightjournal_entries_visible_to_teacher()}.
 */
#[CoversFunction('insightjournal_html_to_text')]
#[CoversFunction('insightjournal_prompt_style')]
#[CoversFunction('insightjournal_entries_visible_to_teacher')]
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

    /**
     * A 6-digit hex colour with a leading hash produces a background-colour style.
     */
    public function test_prompt_style_with_six_digit_hex(): void {
        $style = \insightjournal_prompt_style('#ffcc00');
        $this->assertStringContainsString('background-color: #ffcc00;', $style);
    }

    /**
     * A 3-digit hex colour missing its leading hash is normalised.
     */
    public function test_prompt_style_normalises_missing_hash(): void {
        $style = \insightjournal_prompt_style('abc');
        $this->assertStringContainsString('background-color: #abc;', $style);
    }

    /**
     * Invalid colour values produce no style at all.
     */
    public function test_prompt_style_rejects_invalid_colour(): void {
        $this->assertEquals('', \insightjournal_prompt_style('notacolor'));
    }

    /**
     * Empty or null input produces no style, meaning "use the default appearance".
     */
    public function test_prompt_style_empty_for_blank_input(): void {
        $this->assertEquals('', \insightjournal_prompt_style(''));
        $this->assertEquals('', \insightjournal_prompt_style(null));
    }

    /**
     * Builds a minimal diary stdClass with a given trainer visibility.
     *
     * @param int $entriesvisibility One of the INSIGHTJOURNAL_VISIBILITY_* constants.
     * @return stdClass
     */
    protected function make_diary(int $entriesvisibility = INSIGHTJOURNAL_VISIBILITY_VISIBLE): \stdClass {
        return (object) ['entriesvisibility' => $entriesvisibility];
    }

    /**
     * VISIBLE, the default for new activities, lets trainers read the entries.
     */
    public function test_entries_visible_when_activity_set_to_visible(): void {
        $diary = $this->make_diary(INSIGHTJOURNAL_VISIBILITY_VISIBLE);
        $this->assertTrue(\insightjournal_entries_visible_to_teacher($diary));
    }

    /**
     * PRIVATE keeps the entries visible to the authoring learner only.
     */
    public function test_entries_hidden_when_activity_set_to_private(): void {
        $diary = $this->make_diary(INSIGHTJOURNAL_VISIBILITY_PRIVATE);
        $this->assertFalse(\insightjournal_entries_visible_to_teacher($diary));
    }

    /**
     * A diary record with no entriesvisibility property at all (e.g. hand-built
     * in older test/generator code) is treated as visible.
     */
    public function test_missing_property_treated_as_visible(): void {
        $diary = (object) [];
        $this->assertTrue(\insightjournal_entries_visible_to_teacher($diary));
    }

    /**
     * The retired "use site default" value of 0 predates the per-activity-only
     * model. The upgrade step rewrites it to VISIBLE, but a stray 0 reaching
     * this function must not be mistaken for PRIVATE.
     */
    public function test_legacy_sitedefault_value_treated_as_visible(): void {
        $diary = $this->make_diary(0);
        $this->assertTrue(\insightjournal_entries_visible_to_teacher($diary));
    }
}
