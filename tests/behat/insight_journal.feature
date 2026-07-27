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
