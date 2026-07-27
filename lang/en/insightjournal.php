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
 * English language strings for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['autosave'] = 'Enable autosave';
$string['autosave_help'] = 'When enabled, a learner\'s response is saved automatically a short time after they stop typing, in addition to the manual Save button.';
$string['backtoactivity'] = 'Back to activity';
$string['backtocourse'] = 'Back to course';
$string['backtolist'] = 'Back to list';
$string['backtosection'] = 'Back';
$string['completionentries'] = 'Learner must save an insight journal response';
$string['completionentriesgroup'] = 'Require saved response';
$string['conflictreload'] = 'Reload page';
$string['coursereport'] = 'Course insight report';
$string['deleteallentries'] = 'Delete all insight journal entries';
$string['downloadcsv'] = 'Download CSV';
$string['entriesprivatenotice'] = 'Insight journal entries are currently private. Only the learner who wrote an entry can view it.';
$string['entryprivate'] = 'Keep this entry private (only visible to you)';
$string['entryprivate_help'] = 'By default your entry is visible to trainers/teachers with the "View all entries" capability. Tick this box to keep it visible to you only — trainers will see a notice instead of your response. You can change this at any time.';
$string['err_invalidcolor'] = 'Enter a valid hex colour code (e.g. #ffcc00), or leave the field blank.';
$string['err_mingtmax'] = 'Minimum characters cannot exceed maximum characters.';
$string['gotoentry'] = 'Go to entry';
$string['insightjournal:addinstance'] = 'Add a new insight journal activity';
$string['insightjournal:export'] = 'Export insight journal entries';
$string['insightjournal:submit'] = 'Submit own insight journal entry';
$string['insightjournal:view'] = 'View Insight Journal';
$string['insightjournal:viewall'] = 'View all insight journal entries';
$string['insightjournal:viewown'] = 'View own insight journal entries';
$string['intro'] = 'Description';
$string['lastsaved'] = 'Last saved: {$a}';
$string['maxchars'] = 'Maximum characters allowed';
$string['maxchars_help'] = 'The maximum number of characters a learner may enter. A live counter is shown while typing. Set to 0 for no limit.';
$string['maxcharserror'] = 'Response exceeds the maximum allowed length of {$a} characters.';
$string['maxcharsnote'] = '{$a->current} / {$a->max} characters';
$string['minchars'] = 'Minimum characters for completion';
$string['minchars_help'] = 'The minimum number of characters a response must contain before the activity is marked complete. Set to 0 to require no minimum length.';
$string['mincharsnote'] = 'Minimum length for completion: {$a} characters.';
$string['modulename'] = 'Insight Journal';
$string['modulename_help'] = 'The Insight Journal activity lets learners write responses to a task or question. Teachers can view and export entries.';
$string['modulenameplural'] = 'Insight Journals';
$string['mysummary'] = 'My Insight Journal';
$string['mysummaryfor'] = 'Insight Journal: {$a}';
$string['noentries'] = 'No entries yet.';
$string['noreflectionsincourse'] = 'There are no insight journal activities in this course yet.';
$string['noresponse'] = 'No response entered.';
$string['notsubmitted'] = 'Not submitted';
$string['participant'] = 'Participant';
$string['pluginadministration'] = 'Insight Journal administration';
$string['pluginname'] = 'Insight Journal';
$string['print'] = 'Print / save as PDF';
$string['privacy:metadata:insightjournal_entries'] = 'Stores users\' insight journal responses.';
$string['privacy:metadata:insightjournal_entries:insightjournalid'] = 'The activity instance the response belongs to.';
$string['privacy:metadata:insightjournal_entries:response'] = 'The response text.';
$string['privacy:metadata:insightjournal_entries:responseformat'] = 'The response format.';
$string['privacy:metadata:insightjournal_entries:revision'] = 'A counter incremented on every save, used to detect conflicting simultaneous edits.';
$string['privacy:metadata:insightjournal_entries:timecreated'] = 'The time when the response was created.';
$string['privacy:metadata:insightjournal_entries:timemodified'] = 'The time when the response was last modified.';
$string['privacy:metadata:insightjournal_entries:userid'] = 'The user who wrote the response.';
$string['privacy:metadata:insightjournal_entries:visibility'] = 'Whether the entry is private (visible only to its author) or visible to trainers.';
$string['private'] = 'Private';
$string['progress'] = 'Progress';
$string['promptcolor'] = 'Task / Question background colour';
$string['promptcolor_help'] = 'An optional hex colour code (e.g. #ffcc00) used as the background of the task/question box, wherever it is shown. This only affects the task or question, never a learner\'s response. Leave blank to use the default appearance.';
$string['prompttext'] = 'Task / Question';
$string['prompttext_help'] = 'The task or question shown to learners. Each Insight Journal activity contains exactly one task or question that learners respond to.';
$string['readonlyteacher'] = 'You can view this activity, but only learners with submit permission can write here.';
$string['report'] = 'Insight report';
$string['reportfor'] = 'Insight report: {$a}';
$string['response'] = 'Response';
$string['responseplaceholder'] = 'Write your insight journal response here...';
$string['save'] = 'Save';
$string['saveconflict'] = 'Not saved: a newer version was saved elsewhere (e.g. another tab). Reload the page to see it.';
$string['savedat'] = 'Saved at {$a}';
$string['saveerror'] = 'Could not save the response.';
$string['savelockerror'] = 'Could not save the response: the server is busy. Please try again in a moment.';
$string['saving'] = 'Saving...';
$string['searchparticipants'] = 'Search participants';
$string['submitted'] = 'Submitted';
$string['timemodified'] = 'Last modified';
