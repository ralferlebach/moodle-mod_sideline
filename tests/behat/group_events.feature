@mod @mod_sideline
Feature: Group statements about use of an activity and about improved results
  In order to see that my group is working on the course without learning how one person did
  As a learner
  I need statements about who worked on an activity when they allow it, and only anonymous ones about improvements

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Tea       | Cher     |
      | student1 | Stu       | Dent     |
      | student2 | Sam       | Two      |
      | student3 | Kim       | Three    |
      | student4 | Lou       | Four     |
      | student5 | Ada       | Five     |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | student4 | C1     | student        |
      | student5 | C1     | student        |
    And the following "groups" exist:
      | name | course | idnumber |
      | Red  | C1     | G1       |
    And the following "group members" exist:
      | user     | group |
      | student1 | G1    |
      | student2 | G1    |
      | student3 | G1    |
      | student4 | G1    |
      | student5 | G1    |
    And the following "activities" exist:
      | activity | name | course | idnumber | option  |
      | choice   | Pick | C1     | choice1  | Yes, No |
    And the following "activities" exist:
      | activity | name  | course | idnumber | grade |
      | assign   | Essay | C1     | assign1  | 100   |
    And the following "activities" exist:
      | activity | name  | course | idnumber | display | sharingmode | grouppublish | groupminsize | groupevents     |
      | sideline | Coach | C1     | coach1   | inline  | closed      | named        | 3            | active,improved |
    And "student1" gives the consent for "Coach" elsewhere
    And "student2" shares by name completions in "Coach" elsewhere
    And "student3" shares by name completions in "Coach" elsewhere
    And "student4" shares by name completions in "Coach" elsewhere

  @javascript
  Scenario: An answer of a learner who shares by name is told with the first name
    Given I log in as "student2"
    And I choose "Yes" from "Pick" choice activity
    And I log out
    When I am on the "Course 1" "course" page logged in as "student1"
    Then I should see "Sam from your group recently worked on \"Pick\"."
    And I should not see "Ada"

  @javascript
  Scenario: Improved results are told only anonymously, even with consent to names
    Given "student2" improved the result in "Essay" yesterday
    And "student3" improved the result in "Essay" yesterday
    And "student4" improved the result in "Essay" yesterday
    When I am on the "Course 1" "course" page logged in as "student1"
    Then I should see "A few others in your group recently improved their result in \"Essay\"."
    And I should not see "Sam"

  @javascript
  Scenario: Without a grade history improvements are unknown, never told and named in the settings
    Given the following config values are set as admin:
      | disablegradehistory | 1 |
    And "student2" improved the result in "Essay" yesterday
    And "student3" improved the result in "Essay" yesterday
    And "student4" improved the result in "Essay" yesterday
    When I am on the "Course 1" "course" page logged in as "student1"
    Then I should not see "improved their result"
    And I log out
    And I am on the "Coach" "sideline activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    And I should see "This site has no data for: Improved a result (anonymous only)."
