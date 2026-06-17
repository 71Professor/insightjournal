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
 * Backup activity task for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/mod/insightjournal/backup/moodle2/backup_insightjournal_stepslib.php');

/**
 * Backup task that defines the settings and steps for backing up an insightjournal activity.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class backup_insightjournal_activity_task extends backup_activity_task {
    /**
     * Defines the activity specific backup settings.
     *
     * @return void
     */
    protected function define_my_settings() {}

    /**
     * Defines the activity specific backup steps.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new backup_insightjournal_activity_structure_step('insightjournal_structure', 'insightjournal.xml'));
    }

    /**
     * Encodes URLs to the activity instance into a transportable form for backup.
     *
     * @param string $content The content to encode links within.
     * @return string The content with encoded links.
     */
    public static function encode_content_links($content) {
        return $content;
    }
}
