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
 * Restore activity task for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/mod/insightjournal/backup/moodle2/restore_insightjournal_stepslib.php');

/**
 * Restore task that defines the settings and steps for restoring an insightjournal activity.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class restore_insightjournal_activity_task extends restore_activity_task {
    /**
     * Defines the activity specific restore settings.
     *
     * @return void
     */
    protected function define_my_settings() {}

    /**
     * Defines the activity specific restore steps.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new restore_insightjournal_activity_structure_step('insightjournal_structure', 'insightjournal.xml'));
    }

    /**
     * Defines the contents in the activity that must be processed by the link decoder.
     *
     * @return array Array of restore_decode_content objects.
     */
    public static function define_decode_contents() { return []; }

    /**
     * Defines the decoding rules for links belonging to the activity.
     *
     * @return array Array of restore_decode_rule objects.
     */
    public static function define_decode_rules() { return []; }
}
