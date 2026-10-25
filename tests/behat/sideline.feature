@mod @mod_sideline
Feature: Sideline Learning Coach
  In order to get factual, encouraging commentary on my progress
  As a learner
  I need the coach on the course page, under my control, without teachers reading my data

  Background:
    Given the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Tea       | Cher     |
      | student1 | Stu       | Dent     |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | name     | course | idnumber | completion |
      | page     | Unit one | C1     | page1    | 1          |

  Scenario: The plugin is installed
    Given I log in as "admin"
    And I navigate to "Plugins > Plugins overview" in site administration
    Then I should see "Sideline Learning Coach"

  Scenario: Teachers manage questions but never see answers
    Given the following "activities" exist:
      | activity | name  | course | idnumber | dialogenabled |
      | sideline | Coach | C1     | coach1   | 1             |
    When I am on the "Coach" "mod_sideline > Questions" page logged in as "teacher1"
    Then I should see "you will never see the answers"
    And I press "Add question"
    And I set the following fields to these values:
      | Question | How is it going?                     |
      | Options  | Fine \| none                         |
    And I press "Save changes"
    Then I should see "How is it going?"

  Scenario: Invalid custom rules are rejected and valid ones are previewed with sample data
    Given the following "activities" exist:
      | activity | name  | course | idnumber |
      | sideline | Coach | C1     | coach1   |
    When I am on the "Coach" "mod_sideline > Rules" page logged in as "teacher1"
    And I press "Add rule"
    And I set the field "Rule definition (JSON)" to "not json"
    And I press "Save changes"
    Then I should see "The definition is not valid JSON."
    And I set the field "Rule definition (JSON)" to "{\"key\":\"custom_x\",\"category\":\"safety\",\"conditions\":[{\"type\":\"activity\",\"cmid\":0,\"field\":\"completion\",\"is\":\"no\"}],\"templates\":{\"en\":\"Do not forget {label}.\"}}"
    And I press "Preview"
    Then I should see "Do not forget Unit one."
    And I press "Save changes"
    Then I should see "custom_x"

  Scenario: Only teachers get the links to the teacher pages
    Given the following "activities" exist:
      | activity | name  | course | idnumber | display |
      | sideline | Coach | C1     | coach1   | page    |
    When I am on the "Coach" "mod_sideline > View" page logged in as "student1"
    Then "Custom rules" "link" should not exist
    And "Interview questions" "link" should not exist
    And I log out
    And I am on the "Coach" "mod_sideline > View" page logged in as "teacher1"
    And "Custom rules" "link" should exist

  Scenario: Teachers export the configuration without personal data
    Given the following "activities" exist:
      | activity | name  | course | idnumber |
      | sideline | Coach | C1     | coach1   |
    When I am on the "Coach" "mod_sideline > Transfer" page logged in as "teacher1"
    Then I should see "They never contain answers, consents, caches or any other personal data."
    And "Export configuration" "button" should exist

  Scenario: Administrators approve site personas
    Given I log in as "admin"
    And I visit "/mod/sideline/personas.php"
    And I press "Add persona"
    And I set the following fields to these values:
      | Short name                  | chess                                 |
      | Name                        | Chess coach                           |
      | Style instruction (English) | You are a calm chess coach.           |
    And I press "Save changes"
    Then I should see "Draft"
    And I click on "Approve" "link"
    Then I should see "Approved"

  Scenario: The debug export requires a stated purpose
    Given I log in as "admin"
    And I visit "/mod/sideline/debug.php"
    Then I should see "Every export is recorded in the audit log"
    And I set the field "Purpose of this export" to "short"
    And I set the field "Course module id of the Sideline activity" to "1"
    And I press "Create and download export"
    Then I should see "Please describe the purpose in at least 10 characters."

  @javascript @accessibility
  Scenario: The coach appears on the course page and asks for consent first
    Given the following "activities" exist:
      | activity | name  | course | idnumber | consentrequired | display |
      | sideline | Coach | C1     | coach1   | 1               | inline  |
    When I am on the "Course 1" "course" page logged in as "student1"
    Then I should see "May the coach look at your progress?"
    And I press "I agree"
    Then I should see "You have completed 0 of 1 activities so far."
    And "Withdraw my consent" "button" should exist
    And the page should meet accessibility standards with "wcag131, wcag141, wcag412" extra tests
    And I press "Withdraw my consent"
    Then I should see "You decided not to use the coach. You can change your mind at any time."

  @javascript @accessibility
  Scenario: Learners answer a question, see its effect and delete the answer
    Given the following "activities" exist:
      | activity | name  | course | idnumber | dialogenabled | display |
      | sideline | Coach | C1     | coach1   | 1             | inline  |
    And the following "mod_sideline > questions" exist:
      | activity | prompt           | options                             |
      | coach1   | How is it going? | Fine:none;Overwhelmed:small_steps |
    When I am on the "Course 1" "course" page logged in as "student1"
    Then I should see "A quick question: How is it going?"
    And the page should meet accessibility standards with "wcag131, wcag141, wcag412" extra tests
    And I click on "Overwhelmed" "text"
    And I press "Answer"
    Then I should see "Small steps count"
    And I press "Show my answers"
    Then I should see "One small, concrete next step is suggested."
    And I press "Delete answer"
    Then I should see "You have not answered any question yet."

  @javascript
  Scenario: Two inline instances on one page work independently
    Given the following "activities" exist:
      | activity | name    | course | idnumber | display | persona  |
      | sideline | Coach A | C1     | coach1   | inline  | reporter |
      | sideline | Coach B | C1     | coach2   | inline  | mentor   |
    When I am on the "Course 1" "course" page logged in as "student1"
    Then I should see "Here is the latest from your course:"
    And I should see "Good to see you."

  @javascript
  Scenario: A group member opts in with name, another member sees it, and it disappears after withdrawal
    Given the following "users" exist:
      | username | firstname | lastname |
      | student2 | Sam       | Two      |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student2 | C1     | student |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Red     | C1     | G1       |
    And the following "group members" exist:
      | user     | group |
      | student1 | G1    |
      | student2 | G1    |
    And the following "activities" exist:
      | activity | name  | course | idnumber | display | sharingmode | grouppublish |
      | sideline | Coach | C1     | coach1   | inline  | closed      | named        |
    When I am on the "Course 1" "course" page logged in as "student2"
    And I click on "Share my completed activities with my group" "checkbox"
    And I click on "My first name may be shown" "checkbox"
    And I press "Save"
    And I toggle the manual completion state of "Unit one"
    And I log out
    And I am on the "Course 1" "course" page logged in as "student1"
    Then I should see "Sam from your group recently completed \"Unit one\"."
    And I log out
    And I am on the "Course 1" "course" page logged in as "student2"
    And I click on "Share my completed activities with my group" "checkbox"
    And I press "Save"
    And I log out
    And I am on the "Course 1" "course" page logged in as "student1"
    Then I should not see "Sam from your group"
