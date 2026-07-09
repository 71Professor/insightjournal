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
     * With the config never set (e.g. a fresh install before the admin visits
     * the settings page), entries default to visible to preserve prior behaviour.
     */
    public function test_entries_visible_by_default_when_unset(): void {
        $this->resetAfterTest();
        unset_config('entriesvisibletoteacher', 'insightjournal');

        $this->assertTrue(\insightjournal_entries_visible_to_teacher());
    }

    /**
     * Explicitly enabling the setting keeps entries visible to the teacher.
     */
    public function test_entries_visible_when_explicitly_enabled(): void {
        $this->resetAfterTest();
        set_config('entriesvisibletoteacher', 1, 'insightjournal');

        $this->assertTrue(\insightjournal_entries_visible_to_teacher());
    }

    /**
     * Explicitly disabling the setting makes entries private.
     */
    public function test_entries_hidden_when_explicitly_disabled(): void {
        $this->resetAfterTest();
        set_config('entriesvisibletoteacher', 0, 'insightjournal');

        $this->assertFalse(\insightjournal_entries_visible_to_teacher());
    }
}
