mod_sideline – Sideline Learning Coach
======================================

Eine Moodle-Aktivität, die Lernenden einen kurzen, persönlichen „Sportreporter“-Kommentar zu ihrem Fortschritt im Kurs zeigt.
PHP entscheidet deterministisch, was gesagt wird (Deadlines, Sperren, Erfolge); KI formuliert höchstens den Wortlaut.
Fällt KI aus oder liefert ungültigen Text, greifen lokalisierte Vorlagen, zuletzt ein generischer Text.

Status: **0.2.0 beta** – siehe `docs/CHANGELOG.md`, Nachweise in `docs/TRACEABILITY.md`.

Anforderungen
-------------

* Moodle 5.3+ (`public/`-Layout), PHP ≥ 8.3, PostgreSQL 17 oder MariaDB 11.4
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

Entwicklung
-----------

Siehe `docs/environment-setup.md` (Umgebung) und `docs/ARBEITSPLANUNG.md` (Plan). Tests: `make phpunit`, Stil: `make lint-php`.

Lizenz: GNU GPL v3 or later.
