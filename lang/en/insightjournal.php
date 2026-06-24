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

$string['pluginname'] = 'Insight Journal';
$string['pluginadministration'] = 'Insight Journal administration';
$string['modulename'] = 'Insight Journal';
$string['modulenameplural'] = 'Insight Journals';
$string['modulename_help'] = 'The Insight Journal activity lets learners write responses to insight prompts. Teachers can view and export entries.';
$string['insightjournal:addinstance'] = 'Add a new insight journal activity';
$string['insightjournal:view'] = 'View Insight Journal';
$string['insightjournal:submit'] = 'Submit own insight journal entry';
$string['insightjournal:viewown'] = 'View own insight journal entries';
$string['insightjournal:viewall'] = 'View all insight journal entries';
$string['insightjournal:export'] = 'Export insight journal entries';
$string['deleteallentries'] = 'Delete all insight journal entries';
$string['intro'] = 'Description';
$string['prompttext'] = 'Insight prompt';
$string['prompttext_help'] = 'The prompt or question shown to learners. Each Insight Journal activity contains exactly one prompt that learners respond to.';
$string['autosave'] = 'Enable autosave';
$string['autosave_help'] = 'When enabled, a learner\'s response is saved automatically a short time after they stop typing, in addition to the manual Save button.';
$string['minchars'] = 'Minimum characters for completion';
$string['minchars_help'] = 'The minimum number of characters a response must contain before the activity is marked complete. Set to 0 to require no minimum length.';
$string['mincharsnote'] = 'Minimum length for completion: {$a} characters.';
$string['maxchars'] = 'Maximum characters allowed';
$string['maxchars_help'] = 'The maximum number of characters a learner may enter. A live counter is shown while typing. Set to 0 for no limit.';
$string['maxcharsnote'] = '{$a->current} / {$a->max} characters';
$string['maxcharserror'] = 'Response exceeds the maximum allowed length of {$a} characters.';
$string['completionentries'] = 'Learner must save an insight journal response';
$string['completionentriesgroup'] = 'Require saved response';
$string['response'] = 'Response';
$string['responseplaceholder'] = 'Write your insight journal response here...';
$string['save'] = 'Save';
$string['saving'] = 'Saving...';
$string['savedat'] = 'Saved at {$a}';
$string['lastsaved'] = 'Last saved: {$a}';
$string['saveerror'] = 'Could not save the response.';
$string['readonlyteacher'] = 'You can view this activity, but only learners with submit permission can write here.';
$string['report'] = 'Insight report';
$string['reportfor'] = 'Insight report: {$a}';
$string['downloadcsv'] = 'Download CSV';
$string['searchparticipants'] = 'Search participants';
$string['participant'] = 'Participant';
$string['timemodified'] = 'Last modified';
$string['noentries'] = 'No entries yet.';
$string['noresponse'] = 'No response entered.';
$string['mysummary'] = 'My Insight Journal';
$string['mysummaryfor'] = 'Insight Journal: {$a}';
$string['noreflectionsincourse'] = 'There are no insight journal activities in this course yet.';
$string['backtocourse'] = 'Back to course';
$string['backtolist'] = 'Back to list';
$string['backtoactivity'] = 'Back to activity';
$string['backtosection'] = 'Back';
$string['print'] = 'Print / save as PDF';
$string['privacy:metadata:insightjournal_entries'] = 'Stores users\' insight journal responses.';
$string['privacy:metadata:insightjournal_entries:insightjournalid'] = 'The activity instance the response belongs to.';
$string['privacy:metadata:insightjournal_entries:userid'] = 'The user who wrote the response.';
$string['privacy:metadata:insightjournal_entries:response'] = 'The response text.';
$string['privacy:metadata:insightjournal_entries:responseformat'] = 'The response format.';
$string['privacy:metadata:insightjournal_entries:timecreated'] = 'The time when the response was created.';
$string['privacy:metadata:insightjournal_entries:timemodified'] = 'The time when the response was last modified.';
$string['err_mingtmax'] = 'Minimum characters cannot exceed maximum characters.';
$string['submitted'] = 'Submitted';
$string['notsubmitted'] = 'Not submitted';
$string['coursereport'] = 'Course insight report';
$string['progress'] = 'Progress';
