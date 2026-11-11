# Nachweise 0.4.3 zu den Issues #31, #30, #29, #28, #26, #25, #24, #14, #12 und #6

Stand: 10.11.2026. Grundlage: GitHub-Lauf 115655888628 (Main-CI auf 0.4.2), die offenen Issues mit dem Auftrag, sie vollständig abzuarbeiten, und das Critical-Friend-Re-Audit von 0.4.2 vom 09.11.2026 (Commit `893bbbb`, Lauf 42701753942; dessen neuer Befund zu #28 und der Hinweis zu #30 sind eingearbeitet).

Version `2026110900`, Release `0.4.3`, Auslieferung `mod_sideline-2026110900.zip` (Schema `<Plugintyp>_<Pluginname>-<Versionsnummer>.zip`).

Dieses Dokument ordnet jedem Akzeptanzkriterium (AK) und DoD-Punkt die Ursache, die Korrektur, die Fundstelle und den ausgeführten Nachweis zu. Es ist zum Übertragen in die Issues gedacht; die Issues verwaltet ausschließlich das Audit. Was nur eine Entscheidung oder eine Handlung außerhalb des Codes schließen kann, ist als ◐ markiert, mit Vorlage in `docs/privacy/ENTSCHEIDUNGEN.md`.

**Keine Release-Gates:** kein neuer Job, keine neue Abhängigkeit von „CI complete“. Bestehende Jobs wurden erweitert: zwei Zellen im Upgrade-Job (Ausgangsstand 0.4.1 mit Darüberkopieren), zwei Pflichtdateien im Release-Artefakt-Job, ein Wettlauf in `race`, zwei Läufe in `bench.yml` (manuell).

**Wo die Läufe herkommen:** lokal mit denselben Schritten wie die Workflows (`tools/ci-local/`), auf dem GitHub-Checkout des entpackten Auslieferungs-ZIP (`checkout.sh`). Evidenzstufe C im Sinne des Audits, bis die Workflows auf GitHub gelaufen sind.

## Gesamtergebnis der Abnahmeläufe

Alle Läufe vom 10.11.2026 im Container, auf dem GitHub-Checkout des entpackten ZIP `mod_sideline-2026110900.zip` (`checkout.sh`: 272 Dateien, keine ignoriert, keine Ausführungsrechte), mit moodle-plugin-ci 4.5.11:

| Prüfung | Ergebnis |
|---|---|
| CI-Matrix (Schritte wie `moodle-plugin-ci-main.yml`): 4.5/8.2/pgsql, 4.5/8.3/mariadb, 5.0/8.2/pgsql, 5.0/8.3/mariadb, 5.1/8.3/pgsql, 5.2/8.3/mariadb, 5.3/8.3/pgsql, 5.3/8.3/mariadb | **8/8 Zellen**, je 14 Schritte grün: install, phplint, phpcpd, phpmd, codechecker (`--max-warnings 0`), phpdoc (`--max-warnings 0`), validate, savepoints, mustache, grunt, PHPUnit, Adapter-Suites, Races, Behat |
| PHPUnit `mod_sideline_testsuite` (`--fail-on-warning --fail-on-skipped --fail-on-incomplete`) | **500/500**, 29 899 Assertions, 0 Skips, in jeder Zelle (0.4.2: 477; neu `group_events_test` 12, `operational_check_test` 7, `ai_records_test` +4) |
| Races (`tools/race/run.php`) | **9/9** in jeder Zelle („All races held.“), davon neu: Privacy-Löschung während und nach einer Erzeugung durch den KI-Kern, ein anderes Plugin mit demselben Prompt während der Erzeugung, Ende des Teilens während einer Gruppenaussage auf einem zweiten Prozess |
| Behat | **35/35 Szenarien, 538/538 Schritte** in jeder Zelle, ohne Wiederholung (0.4.2: 32 und 480; neu `group_events.feature`) |
| Upgrade (`upgrade.sh`, wie der Job) | **8/8 Zellen:** „previous“ (0.4.2 im GitHub-Stand mit veralteter `db/caches.php`) auf 4.5/pgsql und 5.3/mariadb je 33/33 Prüfungen; 0.4.1 mit Darüberkopieren auf 4.5/mariadb und 5.3/pgsql je 34/34 (Abbruch mit `db/caches.php`, danach Upgrade); 0.4.0 auf 4.5/mariadb und 5.3/pgsql je 32/32; 0.3.2 auf 4.5/pgsql und 5.3/mariadb je 34/34. Schema ohne Abweichung; operative Datenschutzprüfung ohne FAIL in jeder Zelle |
| Coverage (pcov, wie der Coverage-Job) | **90,20 %** Zeilen dieses Plugins (4197/4653), Untergrenze 80 % (0.4.2: 89,97 %) |
| Frontend (`js-checks.sh`, Moodle 5.3, leeres `MPCI`) | ESLint 0, `eslint:react` 0, `tsc` strict 0, Jest React **24/24**, AMD **20/20**, ESM- und AMD-Bundle aktuell |
| Release-Artefakt (`git archive` mit `.gitattributes`) | 271 Einträge, keine Entwicklerwerkzeuge, alle Pflichtinhalte einschließlich `db/removed_files.txt` und `cli/privacy_check.php`, kein `db/caches.php` |
| Veraltete Dateien | kein Pfad aus `db/removed_files.txt` vorhanden |
| Playwright (`playwright.sh`, frische Installation, `retries: 0`) | Moodle 5.3 (React): **3 volle Läufe je 15/15**, danach die Gruppen-Journey **10/10** hintereinander. Moodle 4.5 (AMD): ebenso **3 × 15/15** und **10/10** |
| Namensprüfung (`bench_names.php`, 30 Läufe, frische CI-Site) | alle Fälle im Budget; p99 bei 20 000 Einschreibungen höchstens 37,8 ms (PostgreSQL) und 61,0 ms (MariaDB) |
| Namensprüfung, große Website (`--siteusers=1000000 --siteenrol`, 10 Läufe) | alle Fälle im Budget; p99 je Kursgröße 100/1000/5000/20 000: PostgreSQL 5,5/8,6/21,6/79,6 ms, MariaDB 4,6/6,9/24,9/69,6 ms |
| Import (`bench_import.php`) | alle Schritte im Budget; Vorschau der größten Datei p99 83 ms (PostgreSQL) und 62 ms (MariaDB), Übernahme 122 bzw. 89 ms, Spitze höchstens 4 MiB |
| Gruppenpfad (`bench_groups.php`), nur Abschlüsse | alle 12 Kombinationen im Budget: warm **7** Lesezugriffe (0.4.2: 9), kalt höchstens 80 ms (PostgreSQL) |
| Gruppenpfad mit allen fünf Ereignisarten (`--events=…`) | alle 12 Kombinationen im Budget: warm **11** Lesezugriffe (Budget 12), kalt höchstens 140 ms (PostgreSQL) bzw. 165 ms (MariaDB, Stand vor den letzten Änderungen) bei 5000 Personen |
| k6 mit Gruppenpfad (`seed_load.php --groups=5`, 50 Lernende, 20 gleichzeitig für 60 s, danach 20 Sitzungen derselben Person im Race; Moodle 5.3, PostgreSQL, eingebauter Server mit 8 Workern) | 2440 Anfragen; Kommentar aus dem Cache p95 553 ms, nach Zustandswechsel p95 552 ms; Race: längste Antwort 532 ms, danach genau eine aktuelle Cachezeile (`verify_load.php`: OK); 0 Web-Service-Fehler; 1 von 40 Anmeldungen der Lastskript-Sitzungen scheiterte (0,04 % HTTP-Fehler, Schwelle 1 %) |

Zwei Codeänderungen kamen nach dem ersten vollständigen Durchgang: die Kursbedingung „Bewertungen anzeigen“ in der Abfrage „Ergebnis verbessert“ (zweite unabhängige Prüfung) und die Zuordnung über die Antwort-ID des Anbieters (#28, Re-Audit 0.4.2). Danach wurden ZIP und Checkout neu gebaut und Matrix, Upgrade, Coverage, Frontend, Playwright, k6 und der Gruppenpfad auf PostgreSQL darauf wiederholt; die Zahlen oben sind die dieses letzten Durchgangs. Namensprüfung, Import und der Gruppenpfad auf MariaDB stammen aus dem ersten Durchgang; die Änderungen berühren sie nicht (Namensprüfung, Import) oder nur um eine Kursbedingung (Gruppenpfad). Das ausgelieferte ZIP unterscheidet sich vom geprüften Checkout nur in Dokumentation unter `docs/`.

Unterschiede zu GitHub: ChromeDriver unter Xvfb statt Selenium-Container; PostgreSQL 17 und MariaDB 11.4 als lokale Server statt als Dienst-Container; zwei CPU-Kerne.

---

## #31 CI rot nach 0.4.2: veraltete `db/caches.php` im Repository

**Ursache (Lauf 115655888628):** Stale-Files-Job, alle PHPUnit-Zellen (`day_count_test::test_no_cache_of_the_plugin_holds_user_ids`), Coverage und alle sechs Upgrade-Jobs („FAIL no cache definition groupsnapshot“) hatten eine Ursache: Auf GitHub lag `db/caches.php` von 0.4.1, weil das Release 0.4.2 über das Repository kopiert worden war. Moodle meldete die Definition `groupsnapshot` dadurch wieder an. Die Datei ist auf GitHub gelöscht (durch den Nutzer).

| AK / DoD | Status | Umsetzung und Nachweis |
|---|---|---|
| CI-Fehler behoben, Ursache benannt | ✔ lokal, ◐ GitHub | Ursache oben. Matrix, Coverage und Upgrade-Jobs auf dem Checkout des ZIP grün (Gesamtergebnis). Der erste Lauf auf GitHub nach dem Push steht aus. |
| Ein darüberkopiertes Release fällt auf, statt still falsch zu laufen | ✔ | `db/upgrade.php`: `mod_sideline_upgrade_check_removed_files()` läuft vor jedem Upgrade-Schritt und bricht mit `err_removed_files` ab, solange ein Pfad aus `db/removed_files.txt` vorhanden ist; die Meldung nennt die Dateien und die Abhilfe („Verzeichnis vollständig löschen und das neue Release an seine Stelle legen“). `db/removed_files.txt` wird jetzt mit ausgeliefert (`.gitattributes`), der Release-Artefakt-Job verlangt es. Tests: `operational_check_test::test_a_removed_file_stops_the_upgrade_and_fails_the_check`; Upgrade-Zellen ab 0.4.1 kopieren über das alte Verzeichnis und erwarten den Abbruch mit `db/caches.php` (4.5/MariaDB und 5.3/PostgreSQL: „PASS the upgrade stops and names db/caches.php while it is still in the directory“). |
| Reste der Definition werden unabhängig vom Cache-Leeren entfernt | ✔ | Upgrade-Schritt `2026110900`: `mod_sideline_upgrade_remove_group_snapshots()` löscht die Verzeichnisse `mod_sideline_groupsnapshot` in `cachedir`, `localcachedir`, `dataroot/cache` und in Dateispeichern mit eigenem Pfad aus der Cache-Konfiguration. Test `operational_check_test::test_the_upgrade_removes_the_group_snapshots_of_0_4_1_from_file_stores` (andere Definitionen bleiben); Upgrade-Prüfung „no user ids of 0.4.1 left in the cache store“ in allen Zellen, mit vom Seed nachweislich geschriebenem Eintrag in den Zellen ab 0.4.1 und ab „previous“ (0.4.2 mit veralteter Datei). |
| Upgrade ohne Wartungsmodus | ✔ (geklärt) | Moodle verweigert `--maintenance=false`, sobald sich unter `db/` einer Komponente etwas geändert hat (`admin/cli/upgrade.php`: Vergleich von `core_component::get_all_component_hash()`, dem Hash aller `db/`-Verzeichnisse). Jedes Release mit Upgrade-Schritt läuft also im Wartungsmodus; die direkte Löschung wirkt unabhängig davon. |
| Anleitung zum Aktualisieren | ✔ | `README.md`, Abschnitt „Aktualisieren“; `docs/README.md`, „Was Pipelines schon rot gemacht hat“. |
| Prüfung auf dem Zielsystem | ✔ | `cli/privacy_check.php` meldet entfernte Dateien und Cache-Reste als FAIL (#14). |

## #6 Gruppenereignisse: Ereignismatrix

**Stand vor 0.4.3:** nur `viewed`, `completed`, `passed`; AK2 (Leistungs- und Notenänderung, Zugang, Zeitfenster, unbekannte Datenlage) nicht umgesetzt.

| AK / DoD | Status | Umsetzung und Nachweis |
|---|---|---|
| AK1 Ereignisarten konfigurierbar | ✔ | `instance_config::GROUP_EVENTS` = geöffnet, darin gearbeitet, abgeschlossen, bestanden, Ergebnis verbessert; Formular, Sicherung, Wiederherstellung, Import/Export (`config_transfer::SETTINGS`). Tests `backup_restore_test` und `config_transfer_test`: Arten und Fenster reisen mit. |
| AK2 Leistungs- und Notenänderung | ✔ (nach Befund der Prüfung) | „Ergebnis verbessert“ (`improved`): eine Bewertung der Aktivität (Bewertungsverlauf `grade_grades_history`, Itemnummer 0) liegt über **jeder** früheren derselben Person (neue Bestleistung), und der Kurs zeigt Lernenden Bewertungen, Bewertungsaspekt und Bewertung sind jetzt sichtbar (Kursbezug: Befund der Nachprüfung). Nie benannt (`GROUP_EVENTS_ANONYMOUS`), nie Note, Differenz oder Rang; eine Verschlechterung, eine erneut gespeicherte gleiche Bewertung, eine Bewertung unter einer früheren Bestleistung und eine erste Bewertung sind kein Ereignis. Tests `group_events_test::test_an_improved_result_is_told_only_anonymously_and_never_a_worse_one`, `::test_an_improvement_is_a_new_best_never_a_lower_or_repeated_grade` (50→80→60 und 40→70→70 zählen nicht), `::test_hidden_grades_are_never_told` (verborgen, verborgen bis morgen, verborgen bis gestern, Kurs ohne Bewertungsanzeige, einzelne Bewertung verborgen), `::test_improvements_of_every_other_member_are_never_told`; Behat `group_events.feature`, Szenario 2. Mutationsproben: Mit „irgendeine frühere niedrigere Bewertung“ statt der Bestleistung, ohne die Sichtbarkeitsbedingung oder mit Namensnennung schlagen die Tests fehl. **Befund der Prüfung:** Die erste Fassung zählte 50→80→60 als Verbesserung und beachtete verborgene Bewertungen nicht. |
| AK2 Zugang und Nutzung | ✔ | Zugang = „geöffnet“ (bisher). Neu „darin gearbeitet“ (`active`): eigene ändernde Ereignisse der Person in der Aktivität aus `logstore_standard_log` (crud c/u, Bildungsebene teilnehmend, Kontext der Aktivität). Lesende Ereignisse, Lehrendenhandlungen, als anonym protokollierte Ereignisse (anonymes Feedback, Abgabe bei anonymer Bewertung) und Handlungen einer anderen Person, die als die lernende angemeldet ist, zählen nicht (**Befund der Prüfung**, in der ersten Fassung fehlten die beiden letzten). Tests `::test_use_of_an_activity_is_told_from_the_standard_log`, `::test_reading_and_teaching_events_are_no_use`, `::test_anonymous_events_and_actions_of_somebody_logged_in_as_the_person_are_no_use`, `::test_named_use_needs_the_named_consent_and_takes_the_latest_change` (eine teilende Person ohne Namensfreigabe wird nicht genannt); Behat Szenario 1 mit einer echten Antwort in einer Abstimmung (echter Protokolleintrag). Freischaltung durch Voraussetzungen bleibt „nie“ (Begründung in der Matrix). |
| AK2 Zeitfenster | ✔ | `groupwindow` je Instanz: 1, 3, 7, 14, 28 Tage (Feld in `install.xml` und Upgrade-Schritt `2026110900`, Standard eine Woche). `::test_the_time_window_bounds_every_event_type` (Nutzung und Verbesserung); Upgrade-Prüfung „time window of group statements: a week“. |
| AK2 unbekannte Datenlage | ✔ | `instance_config::unknown_group_events()`: Standardprotokoll oder Bewertungsverlauf aus → die Art wird nie erzählt, das Formular warnt, der Trace führt `groupunknown`. `::test_event_types_without_data_on_the_site_are_unknown_and_never_told` (auch alte Zeilen eines abgeschalteten Protokolls werden nicht genutzt); Behat Szenario 3 (Bewertungsverlauf aus: keine Aussage, Warnung im Formular). Die Warnung erscheint beim Anlegen und beim Bearbeiten, unabhängig von der Auswahl. Wird eine eingestellte Art später bekannt, gilt das nicht als neue Art (Hinweis in E-6). |
| AK3 einheitlich für offene/geschlossene, benannte/anonyme Aussagen | ✔ | dieselbe Ereignisermittlung (`group_service::events()`) für alle Modi und Stufen; Unterschied nur bei `improved` (nie benannt). `::test_the_new_event_types_in_open_mode_tell_about_another_group_only_anonymously` (offener Modus, andere Gruppe: Verbesserung nur als Band mit Gruppenname, Nutzung benannt mit Gruppe). |
| AK4 Misserfolge nie | ✔ | unverändert; zusätzlich Verschlechterungen, Wiederholungen und erste Bewertungen nie. `group_privacy_test::test_event_types_are_configurable_and_failures_are_never_told`, `group_events_test` (siehe AK2). Die Information zum Teilen nennt die neuen Arten und schließt Punkte und schlechtere Ergebnisse ausdrücklich aus (`ui_sharingtext`). |
| AK5 neue Ereignisart verlangt neue Entscheidungen | ✔ | `instance_config::apply_version_bumps()`: neue Art oder **längeres Fenster** erhöhen die Gruppenversion; weniger Arten oder kürzeres Fenster nicht. `::test_a_new_event_type_or_a_longer_window_requires_new_decisions` (vier Fälle und Wirkung auf bestehende Freigaben). |
| Kosten | ✔ | eine Lesung je Ereignisart (ab 0.4.3 ohne Cursor); passende Kernindizes. **Befund der Messung:** Mit allen fünf Arten lag der Gruppenpfad zuerst bei 21 Lesezugriffen (Budget 12), weil ein Recordset auf PostgreSQL drei kostet; jetzt eindeutige Zeilenschlüssel und `get_records_sql`. `bench_groups.php --events=…`: warm 11 Lesezugriffe (Budget 12), kalt höchstens 140 ms (PostgreSQL) bzw. 165 ms (MariaDB) bei 5000 Personen und 10 Gruppen. Die Ganze-Gruppe-Regel zählt nur noch aktiv eingeschriebene Mitglieder (**Befund der Prüfung**: gesperrte Mitglieder ließen die Gruppe größer erscheinen). |
| DoD1 fachliche Abnahme der Matrix | ◐ Entscheidung | `docs/privacy/GRUPPENAGGREGATE.md`, Abschnitt 1; Vorlage E-6 mit Optionen A bis D in `docs/privacy/ENTSCHEIDUNGEN.md`. |

## #12 Reidentifikation

| AK / DoD | Status | Umsetzung und Nachweis |
|---|---|---|
| Risikoanalyse vollständig (kleine Gruppen, Überschneidungen, Sichten, historischer Vergleich, Zeitfenster, neue Ereignisarten) | ✔ | `GRUPPENAGGREGATE.md`, Abschnitt 3: 17 Angriffe mit Schutz; neu: historischer Vergleich, zugeschnittene Gruppe durch Lehrende, Zeitfenster, Nutzung, Verbesserung, Kombination der Arten, unbekannte Datenlage, KI. |
| Technische Negativtests | ✔ | bisherige (`group_privacy_test`, `day_count_test`) und neu `group_events_test::test_improvements_of_every_other_member_are_never_told` (zugeschnittene Gruppe), Fenster- und Unbekannt-Tests. |
| Restrisiken benannt | ✔ | Restrisikoregister R1 bis R9 mit Voraussetzung, Wahrscheinlichkeit, Schwere und nicht umgesetzter weiterer Maßnahme. |
| DoD2 Datenschutzbewertung der Restrisiken | ◐ Entscheidung | Vorlage E-12 (Annahme je Risiko). |

## #14 Core-AI, Privacy, Retention

| AK / DoD | Status | Umsetzung und Nachweis |
|---|---|---|
| AK1 Debug-Export trennt historische Ausgabe von Simulation, nur Admins | ✔ (0.4.1) | `debug_exporter`: `output_kind: simulation`, Traces mit ausgeliefertem Text, `differs_from_simulation`; `debug.php` nur `is_siteadmin()`. Prüfung `debug exports` meldet Abrufe von Personen, die keine Admins mehr sind. |
| AK2 Gruppenreste unabhängig vom Modus löschen | ✔ (0.4.2, #30) | unverändert. |
| Aufbewahrung der KI-Einträge des Plugins | ✔ (hängt an E-28) | `airetentiondays` (90), tägliche Aufgabe `purge_cache` → `ai_records::forget_older_than()`; derselbe schreibende Zugriff wie beim Widerruf, deshalb in E-28 und D3a genannt. `ai_records_test::test_the_retention_empties_only_entries_older_than_the_period` (älter geleert, jünger und fremd unberührt, Registerzeilen bleiben, 0 = keine Frist). |
| Einträge aus 0.4.1 ohne Instanz | ✔ (Werkzeug), ◐ Entscheidung | `ai_records::unattributed()`, `forget_unattributed()`, `cli/privacy_check.php --empty-unattributed`. Nur Einträge von vor dem Upgrade auf 0.4.3 (`aiattributionsince`, gesetzt im Upgrade-Schritt): ein laufender Aufruf, dessen Herkunftssatz erst nach der Antwort entsteht, wird nie erfasst (**Befund der Prüfung**). `ai_records_test::test_entries_of_0_4_1_without_an_instance_are_found_and_emptied_by_the_administrator` (frische Installation: keine; späterer Eintrag bleibt). Ob geleert wird: D3d. |
| Operative Prüfung auf dem Zielsystem | ✔ | `classes/local/privacy/operational_check.php`, `cli/privacy_check.php` (neun Prüfungen, OK/WARN/FAIL, Exit 1 bei FAIL; die Ausgabe enthält Zähler, Einstellungen und Pfade, nie Inhalte; um Einträge aus 0.4.1 zu erkennen, liest sie die Prompts der KI-Einträge von vor dem Upgrade auf 0.4.3). Cache-Reste in denselben Dateispeichern, die das Upgrade bereinigt, auch mit eigenem Pfad; aktive KI-Anbieter als Anbieter-Instanzen ab Moodle 5.0. `operational_check_test` (7 Tests, schreibt nicht in den Code-Baum). Läuft am Ende jeder Upgrade-Zelle ohne FAIL. |
| Lösch- und Auskunftsprozess dokumentiert | ✔ | `DATENSCHUTZ.md`, Abschnitt 7 (Auskunft, Löschung in fünf Wegen, Kontrolle, Debug-Export); Datenregister um die gelesenen Kernquellen (`logstore_standard_log`, `grade_grades_history`) und die Frist ergänzt. |
| DoD2 Nachweis auf dem Zielsystem | ◐ Handlung | Ausgabe von `cli/privacy_check.php` auf dem Zielsystem ablegen (E-14). |
| DoD3 DSFA, D1 bis D5 | ◐ Entscheidung | Vorlage E-14 mit Empfehlung je Punkt. |

## #28 Core-AI-Löschung an die Herkunft gebunden

Technisch in 0.4.2 umgesetzt (siehe `ISSUE_NACHWEISE_0.4.2.md`). Neu in 0.4.3:

| AK / DoD | Status | Umsetzung und Nachweis |
|---|---|---|
| DoD3 Konkurrenztest mit echten Prozessen für Privacy-Löschung | ✔ | `tools/race/run.php`, Fall 4: Privacy-Löschung (`provider::delete_data_for_user`) in einem zweiten Prozess, während die Erzeugung durch den echten `core_text_generator` und `core_ai\manager` läuft (nur der Netzaufruf des Anbieters ist durch `slow_ai_manager` ersetzt, Kern speichert selbst), und einmal danach. Danach hat kein Eintrag der Person in der Instanz Text, keine Herkunftszeile und keine Cachezeile bleibt, der Eintrag eines anderen Plugins im selben Nutzerkontext bleibt. Läuft in jeder Matrixzelle, 8/8 grün. |
| Neuer Befund des Re-Audits 0.4.2: fremder Aufruf mit identischem Prompt | ✔ | `ai_records::attribute()` verlangt neben Person, Kontext, Registerbereich und Prompt die Antwort-ID des Anbieters (`responseid`) des eigenen Aufrufs; `core_text_generator` übergibt sie aus der Antwort. Passt mehr als ein Eintrag oder keiner, wird nichts zugeordnet (fail-closed). Deterministischer Test `ai_records_test::test_a_call_of_another_plugin_with_the_same_prompt_at_the_same_moment_is_never_taken_for_its_own`: Während der Anbieter dem Coach antwortet, sendet ein anderes Plugin über den echten Kernpfad genau denselben Prompt für dieselbe Person im selben Kontext; geprüft werden die einzelnen Register-IDs (nur die eigene zugeordnet) und der Widerruf (eigene geleert, fremde unverändert). `::test_an_ambiguous_entry_is_never_recorded`: zwei gleiche Einträge ohne Antwort-ID → keine Zuordnung; mit Antwort-ID → genau die eigene. Wettlauf 5 mit echten Prozessen (`tools/race/run.php`): der fremde Prozess liest den Prompt des laufenden Aufrufs und sendet ihn; nur der eigene Eintrag wird zugeordnet, der fremde behält nach dem Widerruf seinen Text. Mutationsprobe: ohne die Antwort-ID schlagen beide Tests fehl. |
| AK5 schreibender Zugriff auf `ai_action_generate_text` | ◐ Entscheidung | Vorlage E-28 (Optionen A bis C). |
| DoD4 fachlicher Review | ◐ Entscheidung | Feld „Fachlicher Review“ in E-28. |
| Frist | ✔ technisch, ◐ mit E-28 | Die Frist (#14) nutzt dieselbe Löschfunktion (`forget_select()`), also dieselben Felder und dieselbe Herkunftsbindung; sie ist ab Werk aktiv (90 Tage) und fällt unter die Entscheidung E-28. |

## #29 und #30

Technisch in 0.4.2 umgesetzt. Neu zu #30 (Re-Audit 0.4.2: „2-Node-Fall nur im selben Prozess simuliert“): Wettlauf 6 in `tools/race/run.php` mit zwei echten Prozessen gegen dieselbe Datenbank, wie zwei Knoten: Einer zählt die Gruppenaussage des Tages, der andere beendet zugleich das Teilen eines gezählten Mitglieds, fünf Runden; danach hat das Mitglied keine Zeile in `sideline_daycount`. Zu Speicherarten (#31): Datei-Speicher bereinigt der Upgrade-Schritt direkt; Redis, Memcached und APCu leert das Upgrade im Wartungsmodus mit `cache_helper::purge_all(true)`, und ihre Einträge verfallen nach der Lebensdauer der Definition (zwei Tage); einen Redis-Server gibt es in der Prüfumgebung nicht, das ist nicht lokal gemessen. Ein Datenbank-Cache-Speicher ist im Kern nicht vorgesehen. Der rote Lauf 115655888628 lag an der veralteten Datei (#31), nicht an diesen Änderungen. Neu: Die Upgrade-Zellen „previous“ starten von 0.4.2 in dem Stand von GitHub (mit der veralteten Datei, Seed schreibt nachweislich einen Eintrag in den Dateispeicher), die Zellen ab 0.4.1 vom Release mit der Definition. Danach: keine Definition, keine Datei des Speichers, Gruppenaussage ohne Debug-Meldung, Tageszählung in der Tabelle. Ergebnis: 8/8 Zellen grün (Gesamtergebnis). Offen bleibt nur der Lauf auf GitHub.

## #24 Prompt-Guard-Performance

| AK / DoD | Status | Nachweis |
|---|---|---|
| DoD5 SQL-Profil mit Ausführungsplan | ✔ lokal, ◐ GitHub | `tools/bench_names.php` gibt je Größe den Plan der Namensabfrage aus, so wie `record_exists_sql()` sie stellt (mit `LIMIT 1`): `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` auf PostgreSQL, `ANALYZE FORMAT=JSON` auf MariaDB; im JSON-Bericht unter `plans`. `prompt_text_guard::name_query()` liefert dieselbe Abfrage wie die Prüfung. |
| Skalierung mit der Größe der Website | ✔ lokal | `--siteusers=1000000 --siteenrol`: über eine Million weiterer Nutzender, jede in einem von 100 anderen Kursen eingeschrieben. Plan auf beiden Datenbanken: Einschreibungsmethoden des Kurses (`enrol`), dann `user_enrolments` über den Index `enrolid, userid`, dann `user` über den Primärschlüssel; gelesen werden nur die Einschreibungen des Kurses (PostgreSQL: 33 bzw. 6 667 Zeilen je Methode bei 100 bzw. 20 000 Einschreibungen). Messung (10 Läufe je Text): p99 je Kursgröße 100/1000/5000/20 000 auf PostgreSQL 5,5/8,6/21,6/79,6 ms, auf MariaDB 4,6/6,9/24,9/69,6 ms, also mit dem Kurs wachsend; Pläne mit `LIMIT 1` auf PostgreSQL: Index `enrolid, userid` (33 bzw. 6 667 Zeilen je Methode), MariaDB: `enro_cou_ix`, `userenro_enruse_uix`, Primärschlüssel. **Befund unterwegs:** Ein Plan ohne `LIMIT 1` zeigte einen sequenziellen Scan über `user_enrolments`; das ist nicht die Abfrage der Prüfung. Der Planer wählt den Kurs als Einstieg wegen der Zeilengrenze; die Messung der echten Prüfung (p99) bestätigt das. |
| GitHub-Nachweis | ◐ | `bench.yml` (manuell) misst jetzt auch mit einer Million Nutzender. |

**Nachtrag 0.4.4:** Der erste Lauf von `bench.yml` auf GitHub (115989969517) zeigte auf PostgreSQL trotz `LIMIT 1` einen sequenziellen Scan über alle Einschreibungen (bis 241 ms). Die Aussage „beginnt beim Kurs“ galt nur lokal. 0.4.4 schreibt die Begrenzung in die Abfrage (Einschreibungsmethoden zuerst, dann `enrolid IN (…)`); siehe `docs/ISSUE_NACHWEISE_0.4.4.md`.

## #26 Import-Grenzen

DoD in 0.4.2 lokal erfüllt; Code des Imports unverändert. Erneut gemessen auf frischen CI-Sites: alle Schritte im Budget (Vorschau der größten Datei p99 83 ms auf PostgreSQL, 62 ms auf MariaDB; Übernahme 122 bzw. 89 ms; Spitze höchstens 4 MiB). Offen: Lauf von `bench.yml` auf GitHub.

## #25 Barrierefreiheit

| AK / DoD | Status | Umsetzung und Nachweis |
|---|---|---|
| Automatische Annäherung an den Screenreader | ✔ | `tests/playwright/screenreader.spec.js`, 5 Prüfungen je Panel: Barrierefreiheitsbaum (ARIA-Snapshot), Ansagen in Live-Regionen nach Zustimmung, Antwort und Widerruf per Tastatur, Fokus im Panel, keine Tastaturfalle, erzwungene Farben, reduzierte Bewegung. Ergebnis: 5/5 je Panel in jedem der drei Läufe auf 5.3 (React) und 4.5 (AMD). |
| Gefundene Fehler als Regressionen | ✔ | (1) Eine Antwort ohne Wirkung änderte im React-Panel nichts Sichtbares, ein Screenreader sagte nichts: Statusmeldung „Gespeichert.“ in beiden Panels (`ui_saved`), Jest-Tests beider Panels. (2) Im Windows-Kontrastmodus verschwand der Tastaturfokus (Bootstrap-Schatten): Umriss in `styles.css`. Beide Prüfungen schlugen vor der Korrektur fehl. |
| Reduzierte Bewegung | ✔ | Text sofort vollständig (bisher) und keine laufende Animation im Panel (neu). |
| Ergebnismatrix | ✔ | `docs/A11Y_SMOKE.md`, Abschnitt 2 (automatisch) und Abschnitt 3 (von Hand, mit Spalte „automatisch angenähert“). |
| Manueller Test mit NVDA und VoiceOver | ◐ Handlung | kann nicht automatisiert werden; Ablauf `A11Y_SMOKE.md`, Abschnitt 3. |

## Unabhängige Prüfung

Nach der ersten Fassung prüfte ein eigenständiger Agent ohne die Schlussfolgerungen der Entwicklung jedes AK und DoD gegen Code und Tests (nur lesend). Er fand 20 Punkte; alle sind bearbeitet und in einer zweiten Prüfung bestätigt. Echte Fehler waren:

- der Codechecker scheiterte an zwei zu langen Zeilen, die CI wäre rot geblieben (#31);
- „Ergebnis verbessert“ zählte 50 → 80 → 60 als Verbesserung, jetzt nur eine neue Bestleistung (#6);
- „darin gearbeitet“ nahm als anonym protokollierte Ereignisse und Handlungen von „Anmelden als“ mit (#6);
- verborgene Bewertungen und, aus der Nachprüfung, Kurse ohne Bewertungsanzeige wurden nicht beachtet (#6);
- die Ganze-Gruppe-Regel zählte gesperrte Mitglieder mit (#12);
- `--empty-unattributed` hätte einen laufenden Aufruf leeren können (#14);
- der Test der entfernten Dateien schrieb in den Code-Baum;
- die Skalierungsaussage zu #24 beruhte auf einem Plan ohne `LIMIT 1`.

Dazu kamen Lücken in Tests (Namensfreigabe, Fenster für beide Arten, offener Modus, Sicherung und Import) und in der Doku (Abhängigkeit der Frist von E-28, Feld für den fachlichen Review, Information zum Teilen um die neuen Arten ergänzt). Was eine Entscheidung braucht, ist als ◐ markiert.

## Bilanz

| Issue | Technisch | Schließbar, wenn … |
|---|---|---|
| #31 | alle AK erfüllt | die Main-CI auf dem neuen Commit grün ist. |
| #29, #30 | alle AK erfüllt (0.4.2) | die Main-CI auf dem neuen Commit grün ist. |
| #28 | alle technischen AK und DoD erfüllt, DoD3 jetzt mit echten Prozessen | E-28 (AK5) entschieden und der fachliche Review (DoD4) vorliegt. |
| #24, #26 | alle AK erfüllt, mit Ausführungsplan und Million-Nutzende-Messung (#24) | `bench.yml` auf GitHub gelaufen ist. |
| #6 | alle technischen AK erfüllt | die Matrix fachlich abgenommen ist (E-6). |
| #12 | Analyse, Tests und Register vollständig | die DSB die Restrisiken bewertet hat (E-12). |
| #14 | Frist, operative Prüfung und Prozess umgesetzt | D1 bis D5 entschieden und die Prüfung auf dem Zielsystem abgelegt ist (E-14). |
| #25 | automatische Annäherung vollständig, zwei Fehler behoben | der manuelle Screenreader-Test protokolliert ist. |
