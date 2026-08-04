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
use PHPUnit\Framework\Attributes\DataProvider;

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
     * Data provider backed by the fixture table shared with the JavaScript
     * side (amd/src/autosave.js) and its Behat parity proof - see
     * tests/fixtures/visible_char_fixtures.json and
     * tests/behat/insight_journal.feature.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function visible_char_count_fixture_provider(): iterable {
        $fixtures = json_decode(
            file_get_contents(__DIR__ . '/fixtures/visible_char_fixtures.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        foreach ($fixtures as $fixture) {
            yield $fixture['id'] => [$fixture['html'], $fixture['expected']];
        }
    }

    /**
     * insightjournal_visible_char_count() matches the shared PHP/JS fixture
     * table exactly, row for row.
     */
    #[DataProvider('visible_char_count_fixture_provider')]
    public function test_visible_char_count_matches_fixture(string $html, int $expected): void {
        $this->assertEquals($expected, \insightjournal_visible_char_count($html));
    }
}
