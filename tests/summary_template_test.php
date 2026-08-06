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
 * Unit tests for the mod_insightjournal/summary and entry_card templates' per-activity privacy rendering.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_insightjournal;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Tests for the mod_insightjournal/summary template (and the entry_card partial it renders).
 */
#[CoversNothing]
final class summary_template_test extends advanced_testcase {
    /**
     * Builds a minimal, valid template context, with overrides.
     *
     * @param array $overrides Context keys to override.
     * @return array The template context.
     */
    protected function make_context(array $overrides = []): array {
        return array_merge([
            'backurl' => 'https://example.com/course/view.php?id=2',
            'listurl' => '',
            'hasitems' => false,
            'items' => [],
        ], $overrides);
    }

    /**
     * A visible entry card renders its response and no privacy notice.
     */
    public function test_visible_item_shows_response(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/summary', $this->make_context([
            'hasitems' => true,
            'items' => [
                [
                    'activityname' => 'Week 1 reflection',
                    'prompt' => '<p>What did you learn today?</p>',
                    'promptstyle' => '',
                    'private' => false,
                    'hasresponse' => true,
                    'response' => '<p>Today I learned about Mustache templates.</p>',
                    'timemodified' => '1 January 2026, 10:00 AM',
                ],
            ],
        ]));

        $this->assertStringContainsString('Today I learned about Mustache templates.', $html);
        $this->assertStringNotContainsString(get_string('entriesprivatenotice', 'mod_insightjournal'), $html);
    }

    /**
     * A private entry card still shows its prompt (trainer-authored content) but
     * replaces the response with the privacy notice, and hides timemodified.
     */
    public function test_private_item_shows_prompt_but_hides_response(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/summary', $this->make_context([
            'hasitems' => true,
            'items' => [
                [
                    'activityname' => 'Week 2 reflection',
                    'prompt' => '<p>What surprised you this week?</p>',
                    'promptstyle' => '',
                    'private' => true,
                    'hasresponse' => false,
                    'response' => '',
                    'timemodified' => '',
                ],
            ],
        ]));

        $this->assertStringContainsString('What surprised you this week?', $html);
        $this->assertStringContainsString(get_string('entriesprivatenotice', 'mod_insightjournal'), $html);
        $this->assertStringNotContainsString('Secret reflection', $html);
    }

    /**
     * A mix of a visible and a private activity in the same summary renders both
     * correctly: the visible item's response shows, the private item's does not,
     * and no response content leaks for the private one even if it were (mistakenly) supplied.
     */
    public function test_mixed_visibility_only_hides_the_private_item(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/summary', $this->make_context([
            'hasitems' => true,
            'items' => [
                [
                    'activityname' => 'Week 1 reflection',
                    'prompt' => '<p>What did you learn today?</p>',
                    'promptstyle' => '',
                    'private' => false,
                    'hasresponse' => true,
                    'response' => '<p>Public reflection.</p>',
                    'timemodified' => '1 January 2026, 10:00 AM',
                ],
                [
                    'activityname' => 'Week 2 reflection',
                    'prompt' => '<p>What surprised you this week?</p>',
                    'promptstyle' => '',
                    'private' => true,
                    'hasresponse' => true,
                    'response' => '<p>Secret reflection.</p>',
                    'timemodified' => '8 January 2026, 10:00 AM',
                ],
            ],
        ]));

        $this->assertStringContainsString('Public reflection.', $html);
        $this->assertStringNotContainsString('Secret reflection.', $html);
        $this->assertStringContainsString(get_string('entriesprivatenotice', 'mod_insightjournal'), $html);
    }

    /**
     * An entry the viewer owns and may still submit to shows an Edit link
     * back to the activity.
     */
    public function test_own_entry_shows_edit_link(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/summary', $this->make_context([
            'hasitems' => true,
            'items' => [
                [
                    'activityname' => 'Week 1 reflection',
                    'prompt' => '<p>What did you learn today?</p>',
                    'promptstyle' => '',
                    'private' => false,
                    'hasresponse' => true,
                    'response' => '<p>Today I learned about Mustache templates.</p>',
                    'timemodified' => '1 January 2026, 10:00 AM',
                    'canedit' => true,
                    'editurl' => 'https://example.com/mod/insightjournal/view.php?id=5',
                ],
            ],
        ]));

        $this->assertStringContainsString('https://example.com/mod/insightjournal/view.php?id=5', $html);
        $this->assertStringContainsString(get_string('gotoentry', 'mod_insightjournal'), $html);
    }

    /**
     * Someone else's entry (viewed via a trainer or another learner's
     * summary) never shows an Edit link, even if it has a response.
     */
    public function test_other_users_entry_has_no_edit_link(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/summary', $this->make_context([
            'hasitems' => true,
            'items' => [
                [
                    'activityname' => 'Week 1 reflection',
                    'prompt' => '<p>What did you learn today?</p>',
                    'promptstyle' => '',
                    'private' => false,
                    'hasresponse' => true,
                    'response' => '<p>Today I learned about Mustache templates.</p>',
                    'timemodified' => '1 January 2026, 10:00 AM',
                    'canedit' => false,
                    'editurl' => 'https://example.com/mod/insightjournal/view.php?id=5',
                ],
            ],
        ]));

        $this->assertStringNotContainsString(get_string('gotoentry', 'mod_insightjournal'), $html);
    }

    /**
     * The print button and back-to-course link are always shown now (privacy is
     * per-card, not a page-wide gate).
     */
    public function test_print_and_back_links_always_present(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/summary', $this->make_context([
            'hasitems' => true,
            'items' => [
                [
                    'activityname' => 'Week 2 reflection',
                    'prompt' => '<p>What surprised you this week?</p>',
                    'promptstyle' => '',
                    'private' => true,
                    'hasresponse' => false,
                    'response' => '',
                    'timemodified' => '',
                ],
            ],
        ]));

        $this->assertStringContainsString(get_string('print', 'mod_insightjournal'), $html);
        $this->assertStringContainsString(get_string('backtocourse', 'mod_insightjournal'), $html);
    }
}
