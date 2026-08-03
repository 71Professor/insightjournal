@mod @mod_insightjournal
Feature: Insight journal activity
  In order to let learners record reflections
  As a teacher
  I need students to be able to write, save, and complete insight journal activities

  Background:
    Given the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | 1        |
      | student1 | Student   | 1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |

  Scenario: A learner saves a response via a normal form submit, no JavaScript required
    Given the following "activities" exist:
      | activity       | course | name       | prompttext           | minchars |
      | insightjournal  | C1     | My Journal | What did you learn?  | 0        |
    When I am on the "My Journal" "insightjournal activity" page logged in as student1
    And I set the field "Response" to "Saved without JavaScript."
    And I press "Save"
    Then I should see "Saved without JavaScript."
    When I reload the page
    Then I should see "Saved without JavaScript."

  Scenario: A no-JS save conflict re-shows the learner's draft instead of discarding it
    Given the following "activities" exist:
      | activity       | course | name       | prompttext           | minchars |
      | insightjournal  | C1     | My Journal | What did you learn?  | 0        |
    And I am on the "My Journal" "insightjournal activity" page logged in as student1
    And I set the field "Response" to "My no-JS draft, about to conflict."
    And insight journal entry for "student1" in "My Journal" was saved elsewhere as "Saved from another tab, no JS."
    When I press "Save"
    Then I should see "a newer version was saved elsewhere"
    And I should see "Saved from another tab, no JS." in the "[data-insightjournal-conflict-content]" "css_element"
    And the field "Response" matches value "My no-JS draft, about to conflict."
    When I press "Save"
    Then I should see "My no-JS draft, about to conflict."

  @javascript
  Scenario: A learner writes and saves a response, then sees it again after reload
    Given the following "activities" exist:
      | activity       | course | name       | prompttext           | minchars |
      | insightjournal  | C1     | My Journal | What did you learn?  | 0        |
    When I am on the "My Journal" "insightjournal activity" page logged in as student1
    And I set the field "Response" to "Today I learned about Behat testing."
    And I press "Save"
    Then I should see "Today I learned about Behat testing." in the "[data-insightjournal-view]" "css_element"
    And "[data-insightjournal-edit-panel]" "css_element" should not be visible
    When I reload the page
    Then the field "Response" matches value "Today I learned about Behat testing."
    And "[data-insightjournal-edit-panel]" "css_element" should not be visible

  @javascript
  Scenario: Saving a response shorter than the minimum does not complete the activity, a longer one does
    Given the following "activities" exist:
      | activity       | course | name       | prompttext           | minchars | completion | completionentries |
      | insightjournal  | C1     | My Journal | What did you learn?  | 10       | 2          | 1                 |
    When I am on the "My Journal" "insightjournal activity" page logged in as student1
    And I set the field "Response" to "short"
    And I press "Save"
    And I log out
    And I am on the "Course 1" course page logged in as teacher1
    Then "Student 1" user has not completed "My Journal" activity
    When I am on the "My Journal" "insightjournal activity" page logged in as student1
    And I press "Edit"
    And I set the field "Response" to "This is a long enough reflection."
    And I press "Save"
    And I log out
    And I am on the "Course 1" course page logged in as teacher1
    Then "Student 1" user has completed "My Journal" activity

  @javascript
  Scenario: A learner edits a previously saved response
    Given the following "activities" exist:
      | activity       | course | name       | prompttext           | minchars |
      | insightjournal  | C1     | My Journal | What did you learn?  | 0        |
    When I am on the "My Journal" "insightjournal activity" page logged in as student1
    And I set the field "Response" to "Original response."
    And I press "Save"
    Then I should see "Original response." in the "[data-insightjournal-view]" "css_element"
    When I press "Edit"
    Then the field "Response" matches value "Original response."
    When I set the field "Response" to "Updated response."
    And I press "Save"
    Then I should see "Updated response." in the "[data-insightjournal-view]" "css_element"
    And "[data-insightjournal-edit-panel]" "css_element" should not be visible

  @javascript
  Scenario: Autosave persists a change without leaving edit mode
    Given the following "activities" exist:
      | activity       | course | name       | prompttext           | minchars | autosave |
      | insightjournal  | C1     | My Journal | What did you learn?  | 0        | 1        |
    When I am on the "My Journal" "insightjournal activity" page logged in as student1
    And I set the field "Response" to "Drafting my reflection."
    And I wait "6" seconds
    Then "[data-insightjournal-edit-panel]" "css_element" should be visible
    And "[data-insightjournal-view]" "css_element" should not be visible
    And I should see "Saved at" in the "[data-insightjournal-status]" "css_element"
    And "[data-insightjournal-status].text-danger" "css_element" should not exist

  @javascript
  Scenario: A successful manual save shows the success status, never the error class
    Given the following "activities" exist:
      | activity       | course | name       | prompttext           | minchars |
      | insightjournal  | C1     | My Journal | What did you learn?  | 0        |
    When I am on the "My Journal" "insightjournal activity" page logged in as student1
    And I set the field "Response" to "Today I learned about Behat testing."
    And I press "Save"
    Then "[data-insightjournal-status].text-danger" "css_element" should not exist

  @javascript
  Scenario: A trainer sees a privacy notice instead of an entry the learner marked private
    Given the following "activities" exist:
      | activity       | course | name       | prompttext           | minchars |
      | insightjournal  | C1     | My Journal | What did you learn?  | 0        |
    And I am on the "My Journal" "insightjournal activity" page logged in as student1
    And I set the field "Response" to "This is my private reflection."
    And I click on "Keep this entry private (only visible to you)" "checkbox"
    And I press "Save"
    And I log out
    When I am on the "My Journal" "insightjournal activity" page logged in as teacher1
    And I follow "Insight report"
    Then I should see "Insight journal entries are currently private. Only the learner who wrote an entry can view it."
    And I should not see "This is my private reflection."

  @javascript
  Scenario: Saving, the character counter, and autosave all work with Atto instead of Tiny
    Given the following config values are set as admin:
      | texteditors | atto,textarea |
    And the following "activities" exist:
      | activity       | course | name       | prompttext           | minchars | maxchars | autosave |
      | insightjournal  | C1     | My Journal | What did you learn?  | 0        | 200      | 1        |
    When I am on the "My Journal" "insightjournal activity" page logged in as student1
    And I set the field "Response" to "Written with the Atto editor."
    And I press "Save"
    Then I should see "Written with the Atto editor." in the "[data-insightjournal-view]" "css_element"
    When I press "Edit"
    And I set the field "Response" to "Drafting with Atto."
    And I wait "6" seconds
    Then I should see "Saved at" in the "[data-insightjournal-status]" "css_element"
    And "[data-insightjournal-status].text-danger" "css_element" should not exist
    And I should see "19 / 200" in the "[data-insightjournal-charcounter]" "css_element"

  @javascript
  Scenario: A learner decides per activity whether their own entry is visible to the trainer
    Given the following "activities" exist:
      | activity       | course | name            | prompttext            | minchars |
      | insightjournal  | C1     | Open Journal    | What did you learn?   | 0        |
      | insightjournal  | C1     | Private Journal | What surprised you?   | 0        |
    And I am on the "Open Journal" "insightjournal activity" page logged in as student1
    And I set the field "Response" to "Visible reflection."
    And I press "Save"
    And I am on the "Private Journal" "insightjournal activity" page logged in as student1
    And I set the field "Response" to "Secret reflection."
    And I click on "Keep this entry private (only visible to you)" "checkbox"
    And I press "Save"
    And I log out
    When I am on the "Open Journal" "insightjournal activity" page logged in as teacher1
    And I follow "Insight report"
    And I follow "Student 1"
    Then I should see "Visible reflection."
    And I should not see "Secret reflection."
    And I should see "Insight journal entries are currently private. Only the learner who wrote an entry can view it."

  @javascript
  Scenario: A stale save is rejected as a conflict and locks further saves until reload
    Given the following "activities" exist:
      | activity       | course | name       | prompttext           | minchars |
      | insightjournal  | C1     | My Journal | What did you learn?  | 0        |
    And I am on the "My Journal" "insightjournal activity" page logged in as student1
    And I set the field "Response" to "My original draft."
    And I press "Save"
    And I press "Edit"
    And I set the field "Response" to "My edited draft, about to conflict."
    And insight journal entry for "student1" in "My Journal" was saved elsewhere as "Saved from another tab."
    When I press "Save"
    Then I should see "Not saved: a newer version was saved elsewhere" in the "[data-insightjournal-status]" "css_element"
    And "[data-insightjournal-conflict-banner]" "css_element" should be visible
    And I should see "Saved from another tab." in the "[data-insightjournal-conflict-content]" "css_element"
    And the field "Response" matches value "My edited draft, about to conflict."
    And the "[data-insightjournal-save]" "css_element" should be disabled
    And I set the field "Response" to "Still editing after the conflict."
    And I wait "4" seconds
    And "[data-insightjournal-status].text-success" "css_element" should not exist
    When I reload the page
    Then the field "Response" matches value "Saved from another tab."

  Scenario: The activity report paginates through many participants
    Given the following "activities" exist:
      | activity       | course | name       | prompttext           | minchars |
      | insightjournal  | C1     | My Journal | What did you learn?  | 0        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student2 | Student   | 2        |
      | student3 | Student   | 3        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student2 | C1     | student |
      | student3 | C1     | student |
    And I am on the "My Journal" "insightjournal activity" page logged in as student1
    And I set the field "Response" to "Response from student 1."
    And I press "Save"
    And I log out
    And I am on the "My Journal" "insightjournal activity" page logged in as student2
    And I set the field "Response" to "Response from student 2."
    And I press "Save"
    And I log out
    And I am on the "My Journal" "insightjournal activity" page logged in as student3
    And I set the field "Response" to "Response from student 3."
    And I press "Save"
    And I log out
    And I log in as "teacher1"
    And I am on the report page for "My Journal" with "2" per page
    Then I should see "Student 1"
    And I should see "Student 2"
    And I should not see "Student 3"
    When I click on "2" "link"
    Then I should see "Student 3"
    And I should not see "Student 1"

  Scenario: The course-wide report paginates through many participants
    Given the following "activities" exist:
      | activity       | course | name       | prompttext           | minchars |
      | insightjournal  | C1     | My Journal | What did you learn?  | 0        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student2 | Student   | 2        |
      | student3 | Student   | 3        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student2 | C1     | student |
      | student3 | C1     | student |
    And I log in as "teacher1"
    And I am on the course insight report for "Course 1" with "2" per page
    Then I should see "Student 1"
    And I should see "Student 2"
    And I should not see "Student 3"
    When I click on "2" "link" in the "nav[aria-label='Page']" "css_element"
    Then I should see "Student 3"
    And I should not see "Student 1"

  @javascript
  Scenario: A teacher restricted to Separate Groups only sees their own group's entries in the activity report
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher2 | Teacher   | 2        |
      | student2 | Student   | 2        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | teacher2 | C1     | teacher |
      | student2 | C1     | student |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | GA       |
      | Group B | C1     | GB       |
    And the following "group members" exist:
      | user     | group |
      | teacher2 | GA    |
      | student1 | GA    |
      | student2 | GB    |
    And the following "activities" exist:
      | activity       | course | name       | prompttext           | minchars | groupmode |
      | insightjournal  | C1     | My Journal | What did you learn?  | 0        | 1         |
    And I am on the "My Journal" "insightjournal activity" page logged in as student1
    And I set the field "Response" to "From group A."
    And I press "Save"
    And I log out
    And I am on the "My Journal" "insightjournal activity" page logged in as student2
    And I set the field "Response" to "From group B."
    And I press "Save"
    And I log out
    And I am on the "My Journal" "insightjournal activity" page logged in as teacher2
    And I follow "Insight report"
    Then I should see "Student 1"
    And I should see "From group A."
    And I should not see "Student 2"
    And I should not see "From group B."

  @javascript
  Scenario: A teacher restricted to Separate Groups only sees their own group's participants in the course-wide report
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher2 | Teacher   | 2        |
      | student2 | Student   | 2        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | teacher2 | C1     | teacher |
      | student2 | C1     | student |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | GA       |
      | Group B | C1     | GB       |
    And the following "group members" exist:
      | user     | group |
      | teacher2 | GA    |
      | student1 | GA    |
      | student2 | GB    |
    And the following "activities" exist:
      | activity       | course | name       | prompttext           | minchars | groupmode |
      | insightjournal  | C1     | My Journal | What did you learn?  | 0        | 1         |
    And I am on the "My Journal" "insightjournal activity" page logged in as student1
    And I set the field "Response" to "From group A."
    And I press "Save"
    And I log out
    And I am on the "My Journal" "insightjournal activity" page logged in as student2
    And I set the field "Response" to "From group B."
    And I press "Save"
    And I log out
    And I log in as "teacher2"
    And I am on the course insight report for "Course 1" with "20" per page
    Then I should see "Student 1"
    And I should not see "Student 2"

  @javascript
  Scenario: A teacher restricted to Separate Groups never sees a different activity's grouping data in the course report
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher2 | Teacher   | 2        |
      | student2 | Student   | 2        |
      | student3 | Student   | 3        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | teacher2 | C1     | teacher |
      | student2 | C1     | student |
      | student3 | C1     | student |
    And the following "groupings" exist:
      | name       | course | idnumber |
      | Grouping A | C1     | GNA      |
      | Grouping B | C1     | GNB      |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | GA       |
      | Group B | C1     | GB       |
    And the following "grouping groups" exist:
      | grouping | group |
      | GNA      | GA    |
      | GNB      | GB    |
    And the following "group members" exist:
      | user     | group |
      | teacher2 | GA    |
      | teacher2 | GB    |
      | student2 | GB    |
    And the following "activities" exist:
      | activity       | course | name      | prompttext           | minchars | groupmode | grouping |
      | insightjournal  | C1     | Journal A | What did you learn?  | 0        | 1         | GNA      |
      | insightjournal  | C1     | Journal B | What did you learn?  | 0        | 1         | GNB      |
    And I am on the "Journal A" "insightjournal activity" page logged in as student2
    And I set the field "Response" to "Student 2 in Journal A."
    And I press "Save"
    And I log out
    And I am on the "Journal B" "insightjournal activity" page logged in as student2
    And I set the field "Response" to "Student 2 in Journal B."
    And I press "Save"
    And I log out
    And I am on the "Journal A" "insightjournal activity" page logged in as student3
    And I set the field "Response" to "Student 3 in Journal A."
    And I press "Save"
    And I log out
    And I log in as "teacher2"
    And I am on the course insight report for "Course 1" with "20" per page
    Then I should see "Student 2"
    And "Student 2" row "Journal B" column of "generaltable" table should contain "Submitted"
    And "Student 2" row "Journal A" column of "generaltable" table should not contain "Submitted"
    And I should not see "Student 3"

  # No negative/denial half to this scenario - only the "can view my own
  # group's summary while restricted" case. Moodle's Behat harness runs
  # look_for_exceptions() (behat_session_trait.php) as an automatic
  # post-step hook on every step; it scans the resulting page for a
  # data-rel="fatalerror" marker and throws if found, failing that step
  # immediately - before any subsequent "Then" step in the same scenario
  # ever runs. summary.php's denial path throws required_capability_exception,
  # which renders exactly that marker, so a scenario cannot navigate there
  # and then assert on the resulting error text: the navigation step itself
  # is auto-failed by the harness first. Confirmed empirically (reproduced
  # the failure) and by grepping the entire Moodle core test suite for any
  # precedent of asserting text on an expected-exception page via Behat -
  # there is none. The denial path itself is still covered: Task 1's
  # PHPUnit tests exercise insightjournal_current_user_group_userids()'s
  # exact "is this userid in the set" semantics directly, summary.php's own
  # three-line wiring was verified line-by-line in Task 4's code review,
  # and the equivalent "restricted teacher cannot see the other group's
  # participant" property is proven end-to-end for coursereport.php in the
  # scenario immediately above this one.
  @javascript
  Scenario: A teacher restricted to Separate Groups can still view their own group's learner summary
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher2 | Teacher   | 2        |
      | student2 | Student   | 2        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | teacher2 | C1     | teacher |
      | student2 | C1     | student |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | GA       |
      | Group B | C1     | GB       |
    And the following "group members" exist:
      | user     | group |
      | teacher2 | GA    |
      | student1 | GA    |
      | student2 | GB    |
    And the following "activities" exist:
      | activity       | course | name       | prompttext           | minchars | groupmode |
      | insightjournal  | C1     | My Journal | What did you learn?  | 0        | 1         |
    And I am on the "My Journal" "insightjournal activity" page logged in as student1
    And I set the field "Response" to "From group A."
    And I press "Save"
    And I log out
    And I log in as "teacher2"
    And I am on the insight journal summary for "student1" in "Course 1"
    Then I should see "From group A."

  @javascript
  Scenario: A teacher restricted to Separate Groups never sees a different activity's grouping data in a learner's summary
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher2 | Teacher   | 2        |
      | student2 | Student   | 2        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | teacher2 | C1     | teacher |
      | student2 | C1     | student |
    And the following "groupings" exist:
      | name       | course | idnumber |
      | Grouping A | C1     | GNA      |
      | Grouping B | C1     | GNB      |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | GA       |
      | Group B | C1     | GB       |
    And the following "grouping groups" exist:
      | grouping | group |
      | GNA      | GA    |
      | GNB      | GB    |
    And the following "group members" exist:
      | user     | group |
      | teacher2 | GA    |
      | teacher2 | GB    |
      | student2 | GB    |
    And the following "activities" exist:
      | activity       | course | name      | prompttext           | minchars | groupmode | grouping |
      | insightjournal  | C1     | Journal A | What did you learn?  | 0        | 1         | GNA      |
      | insightjournal  | C1     | Journal B | What did you learn?  | 0        | 1         | GNB      |
    And I am on the "Journal A" "insightjournal activity" page logged in as student2
    And I set the field "Response" to "Student 2 in Journal A."
    And I press "Save"
    And I log out
    And I am on the "Journal B" "insightjournal activity" page logged in as student2
    And I set the field "Response" to "Student 2 in Journal B."
    And I press "Save"
    And I log out
    And I log in as "teacher2"
    And I am on the insight journal summary for "student2" in "Course 1"
    Then I should see "Student 2 in Journal B."
    And I should not see "Student 2 in Journal A."

  Scenario: A teacher without permission to view user identity sees no participant email
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student2 | Student   | 2        | student2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student2 | C1     | student |
    And the following "activities" exist:
      | activity       | course | name       | prompttext           | minchars |
      | insightjournal  | C1     | My Journal | What did you learn?  | 0        |
    And I am on the "My Journal" "insightjournal activity" page logged in as student2
    And I set the field "Response" to "Response from student 2."
    And I press "Save"
    And I log out
    And the following "permission overrides" exist:
      | capability                   | permission | role           | contextlevel | reference |
      | moodle/site:viewuseridentity | Prevent    | editingteacher | Course       | C1        |
    And I log in as "teacher1"
    And I am on the report page for "My Journal" with "20" per page
    Then I should see "Student 2"
    And I should not see "student2@example.com"
    When I am on the course insight report for "Course 1" with "20" per page
    Then I should see "Student 2"
