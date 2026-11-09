@mod @mod_sideline
Feature: Anonymous group statements stay the same for the day and keep nothing about people who stop sharing
  In order to learn how my group is doing without learning what one person did
  As a learner
  I need an anonymous statement that does not change within a day, and that forgets people who stop sharing

  Background:
    Given the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Stu       | Dent     |
      | student2 | Sam       | Two      |
      | student3 | Kim       | Three    |
      | student4 | Lou       | Four     |
      | student5 | Ada       | Five     |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
      | student2 | C1     | student |
      | student3 | C1     | student |
      | student4 | C1     | student |
      | student5 | C1     | student |
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
      | activity | name     | course | idnumber | completion |
      | page     | Unit one | C1     | page1    | 1          |
    And the following "activities" exist:
      | activity | name  | course | idnumber | display | sharingmode | grouppublish | groupminsize |
      | sideline | Coach | C1     | coach1   | inline  | closed      | general      | 3            |
    And "student1" gives the consent for "Coach" elsewhere
    And "student2" shares completions in "Coach" elsewhere
    And "student3" shares completions in "Coach" elsewhere
    And "student4" shares completions in "Coach" elsewhere
    And "student2" completed "Unit one" yesterday
    And "student3" completed "Unit one" yesterday
    And "student4" completed "Unit one" yesterday

  @javascript
  Scenario: A completion stamped again today does not change the statement, stopping to share removes the person
    When I am on the "Course 1" "course" page logged in as "student1"
    Then I should see "A few others in your group recently completed \"Unit one\"."
    And the day count of "Coach" should include "student2"
    And the day count of "Coach" should not include "student5"
    And I log out
    # Ticked off and on again: Moodle stamps the completion with the current time; every text is computed anew.
    And "student2" completed "Unit one" again today
    And I am on the "Course 1" "course" page logged in as "student1"
    And I should see "A few others in your group recently completed \"Unit one\"."
    And I log out
    And "student2" stops sharing completions in "Coach" elsewhere
    And the day count of "Coach" should not include "student2"
    And I am on the "Course 1" "course" page logged in as "student1"
    And I should not see "others in your group recently completed"
    And the day count of "Coach" should not include "student2"

  @javascript
  Scenario: A withdrawal of consent removes the person from the day count
    When I am on the "Course 1" "course" page logged in as "student1"
    Then I should see "A few others in your group recently completed \"Unit one\"."
    And "student3" withdraws the consent for "Coach" elsewhere
    And the day count of "Coach" should not include "student3"
    And the day count of "Coach" should include "student4"
