mod_sideline – Sideline Learning Coach
======================================

Eine Moodle-Aktivität, die Lernenden einen kurzen, persönlichen „Sportreporter“-Kommentar zu ihrem Fortschritt im Kurs zeigt.
PHP entscheidet deterministisch, was gesagt wird (Deadlines, Sperren, Erfolge); KI formuliert höchstens den Wortlaut.
Fällt KI aus oder liefert ungültigen Text, greifen lokalisierte Vorlagen, zuletzt ein generischer Text.

Status: **0.3.0 beta** – siehe `docs/CHANGELOG.md`, Nachweise in `docs/TRACEABILITY.md`.

Anforderungen
-------------

* Moodle 4.5, 5.0, 5.1, 5.2 und 5.3 (klassisches und `public/`-Layout)
* PHP gemäß Moodle-Version (ab 8.1 für Moodle 4.5), PostgreSQL oder MariaDB in den von der Moodle-Version verlangten Versionen
* Oberfläche: React-Panel ab Moodle 5.3, gleichwertiges AMD-Panel für Moodle 4.5 bis 5.2
* Optional: ein aktivierter KI-Provider im Moodle-AI-Subsystem (Aktion „Text generieren“)

Installation
------------

ZIP über die Plugin-Installation hochladen oder nach `public/mod/sideline` entpacken und das Upgrade ausführen
(`php admin/cli/upgrade.php`). Die Adapter `slcmod_assign` und `slcmod_quiz` liegen unter `adapter/` und werden mitinstalliert.
Die KI-Nutzung ist standardmäßig aus (Websiteeinstellung `aienabled`) und muss zusätzlich je Aktivität aktiviert werden.

Datenschutz
-----------

* Vor der Einwilligung der lernenden Person wird nichts über sie berechnet.
* Es gibt keine Lehrenden-Ansicht und keinen Endpunkt dafür.
* Vor KI-Aufrufen werden Aktivitätsnamen, Daten und Personen pseudonymisiert.
* Privacy-API-Provider für alle personenbezogenen Tabellen.
* Gruppenmodus nur nach aktivem Opt-in der betroffenen Person, Namen nur nach gesonderter Zustimmung.
* Debug-Export nur für Standortadministration, mit Zweckangabe und Audit-Log.
* KI-Texte werden auf Sprache und auf Aussagen geprüft, die die Fakten nicht tragen (z. B. „bestanden“ bei einem nicht bestandenen Test). Im Zweifel erscheint die Vorlage.
* Moodle-Core speichert jeden KI-Aufruf (mit Platzhaltern statt Namen) unbefristet. Die Aufbewahrung wird über das Datenregister von Moodle festgelegt (`docs/adr/ADR-AI-02.md`).

Administration
--------------

* KI an/aus, Aufrufe pro Stunde, Token-Budget pro Tag (mit Verbrauchsanzeige), Kursverbünde an/aus, Aufbewahrungsfristen.
* Interview-Richtlinie für die ganze Website: an/aus, erlaubte Fragetypen, Mindestabstand, Fragen pro Person und Woche.
* Personas freigeben und widerrufen, mit Vorschau auf erfundenen Daten.

Entwicklung
-----------

Siehe `docs/environment-setup.md` (Umgebung), `docs/README.md` (Werkzeuge und Pipelines) und `docs/TRACEABILITY.md` (Nachweise).
Tests: `make phpunit`, `make test-amd`, Behat unter `tests/behat`, Lasttest `make load-seed k6`. Stil: `make lint-php`.

Lizenz: GNU GPL v3 or later.
