@mod @mod_sideline
Feature: The Sideline Learning Coach in the language of the learner
  In order to understand the coach
  As a learner
  I need texts in my language, and a clear note when a text is translated or not available in it

  Background:
    Given the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Stu       | Dent     |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "activities" exist:
      | activity | name     | course | idnumber | completion |
      | page     | Unit one | C1     | page1    | 1          |
    And the Sideline AI is simulated

  @javascript
  Scenario: German learners get the German texts of the plugin and an AI text written in German
    Given the language pack "de" with the direction "ltr" is installed for testing
    And the preferred language of "student1" is "de"
    And the following "activities" exist:
      | activity | name  | course | idnumber | consentrequired | display | aienabled |
      | sideline | Coach | C1     | coach1   | 1               | inline  | 1         |
    When I am on the "Course 1" "course" page logged in as "student1"
    Then I should see "Darf der Coach deinen Fortschritt ansehen?"
    And I press "Ich stimme zu"
    And I should see "Simulierter Coach: es geht um"
    And I should not see "Automatisch übersetzt und geprüft."
    And I should not see "Diese Nachricht ist in deiner Sprache noch nicht verfügbar."
    And the simulated Sideline AI should have received 1 prompt

  @javascript
  Scenario: French learners get an AI text that was translated and checked again, and say so
    Given the language pack "fr" with the direction "ltr" is installed for testing
    And the preferred language of "student1" is "fr"
    And the following "activities" exist:
      | activity | name  | course | idnumber | display | aienabled |
      | sideline | Coach | C1     | coach1   | inline  | 1         |
    When I am on the "Course 1" "course" page logged in as "student1"
    Then I should see "Coach simulé : voici"
    And I should see "Translated automatically and checked."
    And I should not see "Simulated coach:"
    And the simulated Sideline AI should have received 2 prompts

  @javascript
  Scenario: A language without texts of the plugin and without checks for invented facts gets the English template
    Given the language pack "pl" with the direction "ltr" is installed for testing
    And the preferred language of "student1" is "pl"
    And the following "activities" exist:
      | activity | name  | course | idnumber | display | aienabled |
      | sideline | Coach | C1     | coach1   | inline  | 1         |
    When I am on the "Course 1" "course" page logged in as "student1"
    Then I should see "You have completed 0 of 1 activities so far."
    And I should see "This message is not available in your language yet."
    And I should not see "Translated automatically and checked."
    And the simulated Sideline AI should have received 0 prompts
