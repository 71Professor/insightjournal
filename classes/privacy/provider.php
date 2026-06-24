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
 * Privacy provider for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_insightjournal\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\approved_userlist;

/**
 * Privacy Subsystem implementation for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Returns metadata describing the personal data stored by this plugin.
     *
     * @param collection $collection The initialised collection to add metadata to.
     * @return collection The updated collection of metadata items.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('insightjournal_entries', [
            'insightjournalid' => 'privacy:metadata:insightjournal_entries:insightjournalid',
            'userid' => 'privacy:metadata:insightjournal_entries:userid',
            'response' => 'privacy:metadata:insightjournal_entries:response',
            'responseformat' => 'privacy:metadata:insightjournal_entries:responseformat',
            'timecreated' => 'privacy:metadata:insightjournal_entries:timecreated',
            'timemodified' => 'privacy:metadata:insightjournal_entries:timemodified',
        ], 'privacy:metadata:insightjournal_entries');
        return $collection;
    }

    /**
     * Returns the list of contexts that contain personal data for the given user.
     *
     * @param int $userid The user to search for.
     * @return contextlist The list of contexts containing the user's data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextmodule
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {insightjournal} rd ON rd.id = cm.instance
                  JOIN {insightjournal_entries} e ON e.insightjournalid = rd.id
                 WHERE e.userid = :userid";
        $contextlist->add_from_sql($sql, ['contextmodule' => CONTEXT_MODULE, 'modname' => 'insightjournal', 'userid' => $userid]);
        return $contextlist;
    }

    /**
     * Exports all personal data stored for the user in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user to export data for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }
            $cm = get_coursemodule_from_id('insightjournal', $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            $diary = $DB->get_record('insightjournal', ['id' => $cm->instance]);
            if (!$diary) {
                continue;
            }
            $entry = $DB->get_record('insightjournal_entries', ['insightjournalid' => $diary->id, 'userid' => $userid]);
            if ($entry) {
                $data = (object)[
                    'activity'       => $diary->name,
                    'response'       => $entry->response,
                    'responseformat' => $entry->responseformat,
                    'timecreated'    => \core_privacy\local\request\transform::datetime($entry->timecreated),
                    'timemodified'   => \core_privacy\local\request\transform::datetime($entry->timemodified),
                ];
                \core_privacy\local\request\writer::with_context($context)->export_data(
                    [get_string('pluginname', 'insightjournal')],
                    $data
                );
            }
        }
    }

    /**
     * Deletes all personal data for all users in the given context.
     *
     * @param \context $context The context to delete data within.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        $cm = get_coursemodule_from_id('insightjournal', $context->instanceid, 0, false, IGNORE_MISSING);
        if ($cm) {
            $DB->delete_records('insightjournal_entries', ['insightjournalid' => $cm->instance]);
        }
    }

    /**
     * Deletes all personal data for the user in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user to delete data for.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }
            $cm = get_coursemodule_from_id('insightjournal', $context->instanceid, 0, false, IGNORE_MISSING);
            if ($cm) {
                $DB->delete_records('insightjournal_entries', ['insightjournalid' => $cm->instance, 'userid' => $userid]);
            }
        }
    }

    /**
     * Returns the list of users who have personal data in the given context.
     *
     * @param userlist $userlist The userlist containing the context to search within.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        $sql = "SELECT e.userid
                  FROM {insightjournal_entries} e
                  JOIN {course_modules} cm ON cm.instance = e.insightjournalid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, ['modname' => 'insightjournal', 'cmid' => $context->instanceid]);
    }

    /**
     * Deletes personal data for the approved list of users in the given context.
     *
     * @param approved_userlist $userlist The approved users and context to delete data for.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        $cm = get_coursemodule_from_id('insightjournal', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['diaryid'] = $cm->instance;
        $DB->delete_records_select('insightjournal_entries', "insightjournalid = :diaryid AND userid $insql", $params);
    }
}
