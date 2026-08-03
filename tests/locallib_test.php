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
 * {@see \insightjournal_contrasting_text_color()}, {@see \insightjournal_entry_visible_to_teacher()},
 * and {@see \insightjournal_visible_char_count()}.
 */
#[CoversFunction('insightjournal_html_to_text')]
#[CoversFunction('insightjournal_prompt_style')]
#[CoversFunction('insightjournal_contrasting_text_color')]
#[CoversFunction('insightjournal_entry_visible_to_teacher')]
#[CoversFunction('insightjournal_visible_char_count')]
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
     * A light background colour gets black text - white text on yellow
     * would fail WCAG contrast.
     */
    public function test_prompt_style_uses_black_text_on_light_background(): void {
        $style = \insightjournal_prompt_style('#ffcc00');
        $this->assertStringContainsString('color: #000000;', $style);
    }

    /**
     * A dark background colour gets white text.
     */
    public function test_prompt_style_uses_white_text_on_dark_background(): void {
        $style = \insightjournal_prompt_style('#000080');
        $this->assertStringContainsString('color: #ffffff;', $style);
    }

    /**
     * White background contrasts better with black text than white.
     */
    public function test_contrasting_text_color_black_on_white(): void {
        $this->assertEquals('#000000', \insightjournal_contrasting_text_color('#ffffff'));
    }

    /**
     * Black background contrasts better with white text than black.
     */
    public function test_contrasting_text_color_white_on_black(): void {
        $this->assertEquals('#ffffff', \insightjournal_contrasting_text_color('#000000'));
    }

    /**
     * A 3-digit shorthand hex colour is expanded correctly before the
     * contrast calculation.
     */
    public function test_contrasting_text_color_expands_shorthand_hex(): void {
        $this->assertEquals(
            \insightjournal_contrasting_text_color('#000000'),
            \insightjournal_contrasting_text_color('#000')
        );
    }

    /**
     * Empty or null input produces no style, meaning "use the default appearance".
     */
    public function test_prompt_style_empty_for_blank_input(): void {
        $this->assertEquals('', \insightjournal_prompt_style(''));
        $this->assertEquals('', \insightjournal_prompt_style(null));
    }

    /**
     * Builds a minimal entry stdClass with a given trainer visibility.
     *
     * @param int $visibility One of the INSIGHTJOURNAL_VISIBILITY_* constants.
     * @return stdClass
     */
    protected function make_entry(int $visibility = INSIGHTJOURNAL_VISIBILITY_VISIBLE): \stdClass {
        return (object) ['visibility' => $visibility];
    }

    /**
     * VISIBLE, the default for new entries, lets trainers read the entry.
     */
    public function test_entry_visible_when_set_to_visible(): void {
        $entry = $this->make_entry(INSIGHTJOURNAL_VISIBILITY_VISIBLE);
        $this->assertTrue(\insightjournal_entry_visible_to_teacher($entry));
    }

    /**
     * PRIVATE keeps the entry visible to the authoring learner only.
     */
    public function test_entry_hidden_when_set_to_private(): void {
        $entry = $this->make_entry(INSIGHTJOURNAL_VISIBILITY_PRIVATE);
        $this->assertFalse(\insightjournal_entry_visible_to_teacher($entry));
    }

    /**
     * An entry record with no visibility property at all (e.g. hand-built in
     * older test/generator code) fails closed: the entry is hidden unless the
     * value is explicitly VISIBLE.
     */
    public function test_entry_hidden_when_property_missing(): void {
        $entry = (object) [];
        $this->assertFalse(\insightjournal_entry_visible_to_teacher($entry));
    }

    /**
     * The retired "use site/activity default" sentinel of 0 predates the
     * per-entry model. The upgrade step resolves it to VISIBLE or PRIVATE
     * depending on the entry's activity's old setting, but a stray 0 reaching
     * this function directly (e.g. an unmigrated row) must fail closed rather
     * than be mistaken for VISIBLE.
     */
    public function test_entry_hidden_when_value_is_legacy_sentinel(): void {
        $entry = $this->make_entry(0);
        $this->assertFalse(\insightjournal_entry_visible_to_teacher($entry));
    }

    /**
     * Any unrecognised visibility value fails closed rather than being
     * treated as visible.
     */
    public function test_entry_hidden_when_value_is_invalid(): void {
        $entry = $this->make_entry(99);
        $this->assertFalse(\insightjournal_entry_visible_to_teacher($entry));
    }

    /**
     * Plain text with no markup at all counts identically either way - the
     * baseline case where insightjournal_visible_char_count() and
     * insightjournal_html_to_text()/strlen() must always agree.
     */
    public function test_visible_char_count_plain_text(): void {
        $this->assertEquals(12, \insightjournal_visible_char_count('twelve chars'));
    }

    /**
     * Two paragraphs count as a plain concatenation (10), not with the
     * blank-line separator insightjournal_html_to_text() inserts for
     * plain-text readability (which would make this 12) - matching a
     * browser DOMParser's textContent, the same thing the client-side
     * counter in amd/src/autosave.js measures.
     */
    public function test_visible_char_count_ignores_paragraph_breaks(): void {
        $this->assertEquals(10, \insightjournal_visible_char_count('<p>Hello</p><p>World</p>'));
    }

    /**
     * List items count as a plain concatenation (6), not with the "* "
     * bullet markers insightjournal_html_to_text() adds (which would make
     * this 12) - a list is a natural way to write a longer reflection, so
     * this was the most likely case to visibly diverge from the client's
     * live counter.
     */
    public function test_visible_char_count_ignores_list_formatting(): void {
        $this->assertEquals(6, \insightjournal_visible_char_count('<ul><li>One</li><li>Two</li></ul>'));
    }

    /**
     * <br> contributes no character of its own, matching a browser's
     * textContent (which never represents <br> as a text character) rather
     * than insightjournal_html_to_text(), which renders it as "\n".
     */
    public function test_visible_char_count_ignores_line_breaks(): void {
        $this->assertEquals(10, \insightjournal_visible_char_count('<p>Line1<br>Line2</p>'));
    }

    /**
     * An empty rich-text editor shell counts as zero, not the length of its
     * own markup - the same emptiness guarantee
     * insightjournal_html_to_text() already provides.
     */
    public function test_visible_char_count_empty_shell_is_zero(): void {
        $this->assertEquals(0, \insightjournal_visible_char_count(''));
        $this->assertEquals(0, \insightjournal_visible_char_count('<p></p>'));
        $this->assertEquals(0, \insightjournal_visible_char_count('<p><br></p>'));
    }

    /**
     * Counted in Unicode code points, not bytes: a multibyte accented
     * character and a 4-byte-UTF-8 emoji (outside the Basic Multilingual
     * Plane) each still count as exactly one character, matching a
     * browser's [...string].length (spread-based, code-point-aware)
     * iteration in amd/src/autosave.js's charCount().
     */
    public function test_visible_char_count_counts_code_points_not_bytes(): void {
        $this->assertEquals(19, \insightjournal_visible_char_count('<p>héllo wörld émoji 😀</p>'));
    }

    /**
     * A non-breaking space and unwrapped bold/emphasis markup already
     * agreed between the two functions before this change - confirms this
     * function didn't regress either.
     */
    public function test_visible_char_count_matches_html_to_text_for_simple_cases(): void {
        $this->assertEquals(11, \insightjournal_visible_char_count('<p>Hello&nbsp;World</p>'));
        $this->assertEquals(11, \insightjournal_visible_char_count('<p>Hello <b>World</b></p>'));
    }
}
