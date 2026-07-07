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
