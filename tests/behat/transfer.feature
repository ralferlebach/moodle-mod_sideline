@mod @mod_sideline
Feature: Configuration export, import, backup and restore of the Sideline Learning Coach
  In order to reuse a coach in another course or on another site
  As a teacher
  I need downloads and imports that carry configuration only and respect the rights of the target

  Background:
    Given the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
      | Course 2 | C2        | 1                |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Tea       | Cher     |
      | student1 | Stu       | Dent     |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C2     | editingteacher |
      | student1 | C1     | student        |
      | student1 | C2     | student        |
    And the following "activities" exist:
      | activity | name     | course | idnumber | completion | consentrequired | dialogenabled | display |
      | page     | Unit one | C1     | page1    | 1          |                 |               |         |
      | page     | Unit one | C2     | page2    | 1          |                 |               |         |
      | sideline | Coach    | C1     | coach1   |            | 1               | 1             | inline  |
      | sideline | Coach 2  | C2     | coach2   |            | 0               | 0             | inline  |
    And the following "mod_sideline > rules" exist:
      | activity | key      | template                   |
      | coach1   | custom_x | Do not forget {label} now. |
    And the following "mod_sideline > questions" exist:
      | activity | prompt           | options                    |
      | coach1   | How is it going? | Fine:none;Busy:small_steps |

  Scenario: The configuration export is a JSON download for teachers and holds no personal data
    Given "student1" gives the consent for "coach1" elsewhere
    When I am on the "Coach" "mod_sideline > Transfer" page logged in as "teacher1"
    And I press "Export configuration"
    Then the response should be a "application/json" download named "sideline-config-*.json"
    And the downloaded content should contain "\"format\": \"mod_sideline_config\""
    And the downloaded content should contain "custom_x"
    And the downloaded content should contain "How is it going?"
    And the downloaded content should not contain "Stu"
    And the downloaded content should not contain "student1"
    And the downloaded content should not contain "accepted"

  Scenario: Learners get neither the transfer page nor the export, not even with a valid session key
    Given I am on the "Coach" "mod_sideline > View" page logged in as "student1"
    When I download the configuration export of "coach1" over HTTP
    Then the HTTP download should be refused
    And the HTTP download should contain "Sorry, but you do not currently have permissions to do that"
    And the HTTP download should not contain "mod_sideline_config"
    And the HTTP download should not contain "custom_x"

  Scenario: The export needs the session key of the teacher
    Given I am on the "Coach" "mod_sideline > Transfer" page logged in as "teacher1"
    When I download the configuration export of "coach1" without a session key over HTTP
    Then the HTTP download should be refused
    And the HTTP download should not contain "custom_x"

  Scenario: The debug export is a JSON download for administrators only, with a stated purpose
    Given "student1" gives the consent for "coach1" elsewhere
    And I log in as "admin"
    And I visit "/mod/sideline/debug.php"
    When I download the debug export of "coach1" for "student1" with the purpose "Support ticket 4711: wrong text" over HTTP
    Then the HTTP download should be a "application/json" file named "sideline-debug-*.json"
    And the HTTP download should contain "Support ticket 4711: wrong text"
    And the HTTP download should not contain "Stu Dent"
    And the HTTP download should not contain "student1"
    And I log out
    And I log in as "teacher1"
    And I download the debug export of "coach1" for "student1" with the purpose "Support ticket 4711: wrong text" over HTTP
    And the HTTP download should be refused
    And the HTTP download should not contain "Support ticket 4711"

  @javascript @_file_upload
  Scenario: A configuration travels to another course and learners decide again there
    Given I am on the "Coach" "mod_sideline > Transfer" page logged in as "teacher1"
    And I download the configuration export of "coach1" over HTTP
    And the HTTP download should be a "application/json" file named "sideline-config-*.json"
    And I keep the HTTP download as "coach1.json"
    And I am on the "Coach 2" "mod_sideline > Transfer" page
    And I upload the kept file "coach1.json" to "Configuration file (JSON)" filemanager
    And I set the field "Apply the import now" to "1"
    And I press "Import"
    Then I should see "The configuration was imported."
    And I am on the "Coach 2" "mod_sideline > Rules" page
    And I should see "custom_x"
    And I log out
    # Consent is never transferred: the imported setting asks the learner first.
    And I am on the "Course 2" "course" page logged in as "student1"
    And I should see "May the coach look at your progress?"
    And I press "I agree"
    And I should see "Do not forget Unit one now."
    And I should see "A quick question: How is it going?"

  @javascript @_file_upload
  Scenario: A file from another site is mapped by name and nothing of it is taken over unseen
    Given I am on the "Coach 2" "mod_sideline > Transfer" page logged in as "teacher1"
    And I upload "mod/sideline/tests/fixtures/foreign_site_export.json" file to "Configuration file (JSON)" filemanager
    And I press "Import"
    Then I should see "Preview of the import"
    And I should see "NOWHERE"
    And I am on the "Coach 2" "mod_sideline > Rules" page
    And I should not see "custom_foreign"

  @javascript
  Scenario: Backup and restore keep the configuration, but no consent or answer of a learner
    Given I am on the "Course 1" "course" page logged in as "student1"
    And I press "I agree"
    And I click on "Busy" "text"
    And I press "Answer"
    And I log out
    And the following config values are set as admin:
      | enableasyncbackup | 0 |
    And I log in as "admin"
    When I backup "Course 1" course using this options:
      | Confirmation | Filename | test_backup.mbz |
    And I restore "test_backup.mbz" backup into a new course using this options:
      | Schema | Course name       | Course 1 copy |
      | Schema | Course short name | C1copy        |
    Then I should see "Course 1 copy"
    And I log out
    And I am on the "Course 1 copy" "course" page logged in as "student1"
    And I should see "May the coach look at your progress?"
    And I press "I agree"
    And I should see "A quick question: How is it going?"
    And I should see "Do not forget Unit one now."
