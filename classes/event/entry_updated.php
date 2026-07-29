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
 * The mod_insightjournal entry updated event.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_insightjournal\event;

/**
 * The mod_insightjournal entry updated event class.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - int insightjournalid: id of the insight journal instance the entry belongs to.
 * }
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class entry_updated extends \core\event\base {
    /**
     * Creates an instance of the event from a saved entry record.
     *
     * @param \stdClass $entry The insightjournal_entries record (id and userid must be set).
     * @param \stdClass $diary The insightjournal instance the entry belongs to.
     * @param \stdClass $cm The course module.
     * @param \stdClass $course The course.
     * @return self
     */
    public static function create_from_entry(\stdClass $entry, \stdClass $diary, \stdClass $cm, \stdClass $course): self {
        /** @var self $event */
        $event = self::create([
            'objectid' => $entry->id,
            'context' => \context_module::instance($cm->id),
            'userid' => $entry->userid,
            'other' => ['insightjournalid' => $diary->id],
        ]);
        $event->add_record_snapshot('course', $course);
        $event->add_record_snapshot('course_modules', $cm);
        $event->add_record_snapshot('insightjournal', $diary);
        $event->add_record_snapshot('insightjournal_entries', $entry);
        return $event;
    }

    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['objecttable'] = 'insightjournal_entries';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Returns description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' updated the insight journal entry with id '$this->objectid' " .
            "in the activity with course module id '$this->contextinstanceid'.";
    }

    /**
     * Returns localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('evententryupdated', 'mod_insightjournal');
    }

    /**
     * Get URL related to the action.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/insightjournal/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->other['insightjournalid'])) {
            throw new \coding_exception('The \'insightjournalid\' value must be set in other.');
        }
    }

    /**
     * This is used when restoring course logs where it is required that we
     * map the objectid to its new value in the new course.
     *
     * @return array The restore mapping the objectid links to.
     */
    public static function get_objectid_mapping() {
        return ['db' => 'insightjournal_entries', 'restore' => 'insightjournal_entry'];
    }

    /**
     * This is used when restoring course logs where it is required that we
     * map the information in 'other' to its new value in the new course.
     *
     * @return array An array of other values and their corresponding mapping.
     */
    public static function get_other_mapping() {
        return ['insightjournalid' => ['db' => 'insightjournal', 'restore' => 'insightjournal']];
    }
}
