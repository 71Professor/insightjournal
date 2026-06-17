<?php
namespace mod_insightjournal\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\approved_userlist;

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

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
            $entry = $DB->get_record('insightjournal_entries', ['insightjournalid' => $diary->id, 'userid' => $userid]);
            if ($entry) {
                $data = (object)[
                    'activity' => $diary->name,
                    'response' => $entry->response,
                    'timecreated' => \core_privacy\local\request\transform::datetime($entry->timecreated),
                    'timemodified' => \core_privacy\local\request\transform::datetime($entry->timemodified),
                ];
                \core_privacy\local\request\writer::with_context($context)->export_data([get_string('pluginname', 'insightjournal')], $data);
            }
        }
    }

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
        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['diaryid'] = $cm->instance;
        $DB->delete_records_select('insightjournal_entries', "insightjournalid = :diaryid AND userid $insql", $params);
    }
}
