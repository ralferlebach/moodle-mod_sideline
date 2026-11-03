@mod @mod_sideline
Feature: Course unions of the Sideline Learning Coach across courses and course branches
  In order to see my progress over several courses
  As a learner
  I need the coach to count further courses only while a teacher with rights there authorises them

  Background:
    Given the following "categories" exist:
      | name     | category | idnumber |
      | Branch   | 0        | BRANCH   |
      | Elsewhere | 0       | ELSE     |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Home     | HOME      | 0        | 1                |
      | Further  | FURTHER   | 0        | 1                |
      | Inside   | INSIDE    | BRANCH   | 1                |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Tea       | Cher     |
      | student1 | Stu       | Dent     |
    And the following "course enrolments" exist:
      | user     | course  | role           |
      | teacher1 | HOME    | editingteacher |
      | teacher1 | FURTHER | editingteacher |
      | student1 | HOME    | student        |
      | student1 | FURTHER | student        |
      | student1 | INSIDE  | student        |
    And the following "activities" exist:
      | activity | name         | course  | idnumber | completion | display |
      | page     | Home unit    | HOME    | page1    | 1          |         |
      | page     | Further unit | FURTHER | page2    | 1          |         |
      | page     | Inside unit  | INSIDE  | page3    | 1          |         |
      | sideline | Coach        | HOME    | coach1   |            | inline  |
    And the following config values are set as admin:
      | crosscourse | 1 | mod_sideline |

  @javascript
  Scenario: A further course counts only while the teacher who added it may manage activities there
    Given the following "mod_sideline > sources" exist:
      | activity | course  | editor   |
      | coach1   | FURTHER | teacher1 |
    When I am on the "Home" "course" page logged in as "student1"
    Then I should see "You have completed 0 of 2 activities so far."
    # A completion in the other course reaches the cached commentary of this one.
    And I am on the "Further" "course" page
    And I toggle the manual completion state of "Further unit"
    And I am on the "Home" "course" page
    And I should see "You have completed 1 of 2 activities so far."
    # The teacher loses the right in the further course: it no longer counts, the cache follows.
    And "teacher1" is unenrolled from the course "FURTHER"
    And I reload the page
    And I should see "You have completed 0 of 1 activities so far."
    And I should not see "Further unit"

  @javascript
  Scenario: A further course without a teacher on record counts only after a teacher confirms it
    Given the following "mod_sideline > sources" exist:
      | activity | course  | editor |
      | coach1   | FURTHER |        |
    When I am on the "Home" "course" page logged in as "student1"
    Then I should see "You have completed 0 of 1 activities so far."
    And I log out
    And I am on the "Coach" "sideline activity editing" page logged in as "teacher1"
    And I should see "have no teacher on record who authorised them"
    And I press "Save and return to course"
    And I log out
    And I am on the "Home" "course" page logged in as "student1"
    And I should see "You have completed 0 of 2 activities so far."

  @javascript
  Scenario: A course of an admitted course branch counts while it stays in the branch and the right holds
    Given the following "role assigns" exist:
      | user     | role    | contextlevel | reference |
      | teacher1 | manager | Category     | BRANCH    |
    And the following "mod_sideline > source categories" exist:
      | activity | category | editor   |
      | coach1   | BRANCH   | teacher1 |
    When I am on the "Home" "course" page logged in as "student1"
    Then I should see "You have completed 0 of 2 activities so far."
    # Moved out of the branch: no longer part of the union.
    And the course "INSIDE" is moved to the category "ELSE"
    And I reload the page
    And I should see "You have completed 0 of 1 activities so far."
    # Back in the branch, but the teacher's right in the branch is gone.
    And the course "INSIDE" is moved to the category "BRANCH"
    And I reload the page
    And I should see "You have completed 0 of 2 activities so far."
    And the role "manager" of "teacher1" in the category "BRANCH" is unassigned
    And I reload the page
    And I should see "You have completed 0 of 1 activities so far."
