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
 * Unit tests for the mod_insightjournal custom completion rule.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_insightjournal;

use advanced_testcase;
use cm_info;
use mod_insightjournal\completion\custom_completion;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for {@see \mod_insightjournal\completion\custom_completion}.
 */
#[CoversClass(custom_completion::class)]
final class custom_completion_test extends advanced_testcase {
    /**
     * Builds a course module with an entry and returns the computed completion state.
     *
     * The state is fetched through a real cm_info object, which only carries the
     * custom completion rule in its customdata when
     * insightjournal_get_coursemodule_info() registers it. This exercises both the
     * rule registration and the minchars evaluation in a single, realistic path.
     *
     * @param int $minchars The minimum characters required by the rule.
     * @param string|null $response The learner response, or null for no entry.
     * @return int The resulting COMPLETION_* constant.
     */
    protected function compute_state(int $minchars, ?string $response): int {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $journal = $generator->create_module('insightjournal', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionentries' => 1,
            'minchars' => $minchars,
        ]);
        $user = $generator->create_and_enrol($course, 'student');

        if ($response !== null) {
            /** @var \mod_insightjournal_generator $plugingenerator */
            $plugingenerator = $generator->get_plugin_generator('mod_insightjournal');
            $plugingenerator->create_entry($journal, (int) $user->id, $response);
        }

        $cm = get_fast_modinfo($course)->get_cm($journal->cmid);
        $completion = new custom_completion($cm, (int) $user->id);

        return $completion->get_state('completionentries');
    }

    /**
     * A user with no entry never meets the rule.
     */
    public function test_no_entry_is_incomplete(): void {
        $this->resetAfterTest();
        $this->assertEquals(COMPLETION_INCOMPLETE, $this->compute_state(10, null));
    }

    /**
     * A response shorter than minchars must not complete the activity.
     *
     * Regression test: save_entry previously forced COMPLETION_COMPLETE, bypassing
     * the minchars threshold entirely.
     */
    public function test_response_below_minchars_is_incomplete(): void {
        $this->resetAfterTest();
        $this->assertEquals(COMPLETION_INCOMPLETE, $this->compute_state(10, 'short'));
    }

    /**
     * A response meeting minchars completes the activity.
     */
    public function test_response_meeting_minchars_is_complete(): void {
        $this->resetAfterTest();
        $this->assertEquals(COMPLETION_COMPLETE, $this->compute_state(10, str_repeat('a', 10)));
        $this->assertEquals(COMPLETION_COMPLETE, $this->compute_state(10, str_repeat('a', 25)));
    }

    /**
     * A whitespace-only response counts as empty and does not complete.
     */
    public function test_whitespace_only_response_is_incomplete(): void {
        $this->resetAfterTest();
        $this->assertEquals(COMPLETION_INCOMPLETE, $this->compute_state(0, "   \n\t  "));
    }

    /**
     * With minchars = 0 any non-empty response completes the activity.
     */
    public function test_any_response_completes_when_no_minimum(): void {
        $this->resetAfterTest();
        $this->assertEquals(COMPLETION_COMPLETE, $this->compute_state(0, 'x'));
    }

    /**
     * Length is measured in characters, not bytes (multibyte safety).
     */
    public function test_minchars_uses_multibyte_length(): void {
        $this->resetAfterTest();
        // Five multibyte characters meet a five-character minimum.
        $this->assertEquals(COMPLETION_COMPLETE, $this->compute_state(5, 'äöüéè'));
        // Four multibyte characters fall short of a five-character minimum.
        $this->assertEquals(COMPLETION_INCOMPLETE, $this->compute_state(5, 'äöüé'));
    }

    /**
     * The plugin defines exactly the completionentries rule.
     */
    public function test_get_defined_custom_rules(): void {
        $rules = custom_completion::get_defined_custom_rules();
        $this->assertEquals(['completionentries'], $rules);
    }

    /**
     * Every defined rule has a human-readable description.
     */
    public function test_get_custom_rule_descriptions(): void {
        $mockcm = $this->getMockBuilder(cm_info::class)
            ->disableOriginalConstructor()
            ->getMock();
        $completion = new custom_completion($mockcm, 1);

        $descriptions = $completion->get_custom_rule_descriptions();
        foreach (custom_completion::get_defined_custom_rules() as $rule) {
            $this->assertArrayHasKey($rule, $descriptions);
            $this->assertNotEmpty($descriptions[$rule]);
        }
    }

    /**
     * The sort order matches the set of defined rules.
     */
    public function test_get_sort_order(): void {
        $mockcm = $this->getMockBuilder(cm_info::class)
            ->disableOriginalConstructor()
            ->getMock();
        $completion = new custom_completion($mockcm, 1);

        $this->assertEquals(['completionentries'], $completion->get_sort_order());
    }
}
