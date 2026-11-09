# Nachweise 0.4.2 zu den Issues #28, #29, #30, #24, #26 und zum Stand der übrigen offenen Issues

Stand: 03.11.2026. Grundlage: Critical-Friend-Re-Audit von 0.4.1 (03.11.2026, Commit `afabccc`) mit den GitHub-Läufen 41614876661 (Main-CI), 41615032344 (Playwright) und 41615056963 (k6).

Version `2026110302`, Release `0.4.2`. Die Versionsnummer folgt dem Schema Datum plus laufende Nummer; 0.4.1 hätte `2026110301` tragen müssen (auf GitHub stand `2026110300`, dieselbe Nummer wie 0.4.0, siehe #29).

Dieses Dokument ordnet jedem Akzeptanzkriterium (AK) und DoD-Punkt die Ursache, die Korrektur, die Fundstelle und den ausgeführten Nachweis zu. Es ist zum Übertragen in die Issues gedacht. Die Issues verwaltet ausschließlich das Audit; aus dieser Sitzung heraus wurde kein Issue geändert.

**Keine Release-Gates:** Kein neuer Job in der Main-CI, keine neue Abhängigkeit von „CI complete“. Der Upgrade-Job hat zwei weitere Matrixzellen (Ausgangsstand 0.4.0). Die Messprofile laufen in einem neuen **manuellen** Workflow `bench.yml`, wie k6.

**Wo die Läufe herkommen:** lokal mit denselben Schritten und Optionen wie die Workflows (`tools/ci-local/`), auf dem GitHub-Checkout des entpackten Auslieferungs-ZIP (`checkout.sh`). Die Benchmarks liefen auf eigenen Moodle-5.3-Sites mit PostgreSQL 17 und MariaDB 11.4 im Container. Das ist Evidenzstufe C im Sinne des Audits, bis `bench.yml` und die Main-CI auf GitHub gelaufen sind.

**Unabhängige Prüfung:** Nach der ersten Fassung prüfte ein eigenständiger Agent ohne die Schlussfolgerungen der Entwicklung jedes AK und DoD gegen Code und Tests (nur lesend). Seine Befunde sind eingearbeitet und je Issue als „Befund der Prüfung“ gekennzeichnet. Drei waren echte Lücken, die vor der Auslieferung behoben wurden: Tageszählung bei geändertem Freigabekreis (#30), Zuordnung fremder KI-Einträge im Upgrade (#28), Wettlauf beim Löschen der Herkunftssätze (#28). Was nur eine Entscheidung klären kann, ist als ◐ markiert und nicht als erfüllt.

Legende: ✔ erfüllt und ausgeführt · ◐ technisch erfüllt, GitHub-Lauf oder fachliche Entscheidung steht aus · ✘ nicht erfüllt

## Gesamtergebnis der Abnahmeläufe

Alle Läufe vom 03.11.2026 im Container, auf dem GitHub-Checkout des entpackten ZIP (`checkout.sh`: 263 Dateien, keine ignoriert, keine Ausführungsrechte):

| Prüfung | Ergebnis |
|---|---|
| CI-Matrix (moodle-plugin-ci 4.5.11, Schritte wie `moodle-plugin-ci-main.yml`): 4.5/8.2/pgsql, 4.5/8.3/mariadb, 5.0/8.2/pgsql, 5.0/8.3/mariadb, 5.1/8.3/pgsql, 5.2/8.3/mariadb, 5.3/8.3/pgsql, 5.3/8.3/mariadb | **8/8 Zellen**, je 14 Schritte: install, phplint, phpcpd, phpmd, codechecker (`--max-warnings 0`), phpdoc (`--max-warnings 0`), validate, savepoints, mustache, grunt, PHPUnit, Adapter-Suites, Races, Behat |
| PHPUnit `mod_sideline_testsuite` (`--fail-on-warning --fail-on-skipped --fail-on-incomplete`) | **477/477**, 29 763 Assertions, 0 Skips, in jeder Zelle (vorher 461; neu `ai_records_test` 5, `day_count_test` 10, `config_transfer_test` 1) |
| Adapter-Suites, Races | grün in jeder Zelle („All races held.“) |
| Behat | **32/32 Szenarien, 480/480 Schritte** in jeder Zelle, ohne Wiederholung (vorher 30 und 433; neu `group_daycount.feature`) |
| Coverage (pcov, wie der Coverage-Job) | **89,97 %** Zeilen dieses Plugins (4017/4465), Untergrenze 80 % (vorher 87,05 %) |
| Frontend (`js-checks.sh`, Moodle 5.3, leeres `MPCI`, Werkzeugkette frisch aus der Lockdatei) | ESLint 0, `eslint:react` 0, `tsc` strict 0, Jest React **23/23**, AMD **19/19**, ESM- und AMD-Bundle aktuell (kein Frontend-Code geändert) |
| Upgrade (`upgrade.sh`, wie der Job) | **6/6 Zellen:** 0.4.1 → 0.4.2 auf 4.5/pgsql und 5.3/mariadb (je 23/23 Prüfungen), 0.4.0 → 0.4.2 auf 4.5/mariadb und 5.3/pgsql (je 22/22), 0.3.2 → 0.4.2 auf 4.5/pgsql und 5.3/mariadb (je 24/24). Schema ohne Abweichung |
| Negativkontrolle #29 (0.4.0 → GitHub-Stand 0.4.1 ohne Versionssprung), 5.3/pgsql und 4.5/mariadb | siehe #29: Definition erst nach `upgrade.php` bzw. beim ersten Zugriff mit Cache-Reset und Debug-Meldung |
| Release-Artefakt (`git archive` mit `.gitattributes`) | 262 Einträge, keine Entwicklerwerkzeuge, alle Pflichtinhalte, kein `db/caches.php` |
| Veraltete Dateien | kein Pfad aus `db/removed_files.txt` vorhanden (neu: `db/caches.php`) |
| Playwright (`playwright.sh`, frische Installation wie der Workflow, `retries: 0`) | Moodle 5.3 (React): **3 volle Läufe je 10/10**, danach die Gruppen-Journey **10-mal hintereinander** auf einem Kurs grün. Moodle 4.5 (AMD): ebenso **3 × 10/10** und **10/10** |
| Namensprüfung, Import (`bench_names.php`, `bench_import.php`) | alle Fälle im Budget auf PostgreSQL und MariaDB, siehe #24 und #26 |
| Gruppenpfad (`bench_groups.php`) | 100, 1000, 5000 Personen, 0 bis 200 Gruppen, je 12 Kombinationen im Budget (12 Lesezugriffe warm, 300 ms kalt). PostgreSQL (Moodle 5.3): warm **9** Lesezugriffe (0.4.1 auf derselben Site: 8), kalt höchstens 102 ms. MariaDB (Moodle 4.5): warm **7** (0.4.1: 6), kalt höchstens 122 ms. Der eine zusätzliche Lesezugriff ist das Laden der Tageszählung |
| k6 | nicht erneut gelaufen; der HTTP-Pfad ist unverändert, der Gruppenpfad ist über `bench_groups.php` gemessen |

Alle Zeilen dieser Tabelle beziehen sich auf den ausgelieferten Stand: ZIP und Checkout wurden nach der letzten Codeänderung (Befunde der unabhängigen Prüfung) neu gebaut, und alle Läufe wurden darauf wiederholt. Die Messprofile von #24 und #26 liefen auf dem Stand davor; geändert hat sich danach nur eine zusätzliche Löschabfrage beim Import (`forget_if_terms_changed()`), nicht die Namensprüfung.

Unterschiede zu GitHub: ChromeDriver unter Xvfb statt Selenium-Container; PostgreSQL 17 und MariaDB 11.4 als lokale Server statt als Dienst-Container; zwei CPU-Kerne.

---

## #28 Widerruf löscht Core-AI-Daten fremder Plugins und anderer Instanzen

**Ursache (bestätigt am Kerncode 4.5 und 5.3):** `core_ai\privacy\provider::delete_data_for_user()` leert für die genannten Kontexte alle `generate_text`-, `generate_image`-, `summarise_text`- und `explain_text`-Einträge der Person, gleich welches Plugin sie erzeugt hat, und löscht ihre Einträge in `ai_policy_register` (Zustimmung zur KI-Richtlinie). 0.4.1 rief das beim Widerruf für den ganzen Nutzerkontext auf. Der Befund ist damit weiter als im Audit beschrieben: Auch die Richtlinienzustimmung im Nutzerkontext ging verloren.

**Zweiter Befund beim Testen:** Ein Widerruf während der Antwort des Anbieters ließ in 0.4.1 den Eintrag dieses Aufrufs stehen. Der Kern schreibt ihn erst nach der Antwort, also nach dem Aufräumen des Widerrufs, und die Prüfung direkt nach der Erzeugung kehrte zurück, ohne aufzuräumen. Gefunden durch `ai_records_test::test_a_withdrawal_while_the_provider_answers_leaves_nothing_of_the_call` (rot vor der Korrektur).

| AK / DoD | Status | Umsetzung und Nachweis |
|---|---|---|
| Nur nachweislich A zuzuordnende Inhalte entfernen | ✔ (nach Befund der Prüfung) | `classes/local/ai/ai_records.php`: Jeder Aufruf hält fest, welchen Eintrag in `ai_action_register` er verursacht hat (Tabelle `sideline_aicall`: Instanz, Person, Register-ID, Zeit). Zuordnung: Während des Aufrufs hält das Plugin die KI-Sperre der Person (`ai_records::lock()`, Wartezeit höchstens 20 s, sonst Vorlage statt KI). Zugeordnet wird der danach entstandene Eintrag für diese Person und diesen Kontext mit **genau** dem gesendeten Prompt. `consent_manager::forget_generated()` leert nur diese Einträge. **Befund der Prüfung:** Das Upgrade ordnete in der ersten Fassung jeden Eintrag im Kontext der Aktivität der Instanz zu, auch einen des Texteditors, mit dem eine Lehrperson die Beschreibung bearbeitet. Jetzt nur Einträge mit der Form der Prompts des Plugins (`ai_records::is_plugin_prompt()`, `ai_records_test::test_the_upgrade_attributes_only_prompts_of_the_plugin_in_the_activity_context`; im Upgrade-Job: Editor-Eintrag im Aktivitätskontext nie zugeordnet, nie geleert). |
| Daten anderer Plugins und anderer Sideline-Aktivitäten unverändert | ✔ | `ai_records_test::test_a_withdrawal_in_one_instance_empties_only_its_own_entries`: Instanz A, Instanz B und ein simuliertes zweites Plugin (direkter `generate_text`-Aufruf über `core_ai\manager`) rufen im **selben** Nutzerkontext über den echten Kernpfad mit gemocktem OpenAI-Endpunkt auf. Ein Widerruf in A leert A. B und das andere Plugin behalten Prompt und Antwort, die Richtlinienzustimmung bleibt, die Registerzeilen (Zeit, Tokens) bleiben wie nach der Kernlöschung. |
| Herkunft eindeutig, ohne neue Parallelprotokolle ohne Löschung | ✔ | `sideline_aicall` enthält nur IDs und eine Zeit, keinen Text. Die Tabelle ist in der Privacy-API (Metadaten, Kontexte, Nutzer, Export, Löschung) und wird mit dem Leeren gelöscht: bei Widerruf, Privacy-Löschung und beim Löschen der Instanz. |
| Laufende Anfragen, Widerrufe, Bereinigungen synchronisiert und idempotent | ✔ | Widerruf während der Antwort: `ai_records_test::test_a_withdrawal_while_the_provider_answers_leaves_nothing_of_the_call` (der Mock widerruft, während der Anbieter antwortet; danach ist der Eintrag leer, der des anderen Plugins nicht). Wiederholter Widerruf: zweimal `forget_generated()` und `record_decision()` im selben Test, ohne weitere Änderung. Ordnung: Zuordnung im Generator, dann die Prüfungen in `commentary_service`. Ein Widerruf, der vor der Prüfung festgeschrieben ist, wird von ihr gesehen und räumt auf; einer danach räumt selbst auf. **Befund der Prüfung:** `forget()` löschte die Herkunftssätze per Bedingung statt der gelesenen IDs, und Privacy-Löschung und Instanzlöschung löschten sie ein zweites Mal ohne Leeren. Ein dazwischen zugeordneter Aufruf hätte seinen Satz verloren. Jetzt werden genau die gelesenen Sätze gelöscht, und nur `ai_records::forget()` löscht in `sideline_aicall`. Einen Paralleltest mit echten Prozessen für Privacy-Löschung gibt es nicht (siehe DoD unten). |
| Moodle 4.5 und 5.3, ohne direkte Eingriffe in Kerntabellen ohne begründeten API-Vertrag | ◐ Entscheidung | Der Kern hat keine API, um einen einzelnen Eintrag zu leeren. `ai_records::forget()` setzt genau die Felder leer, die `core_ai\privacy\provider` selbst leert (`prompt`, `responseid`, `fingerprint`, `generatedcontent`), nur für zugeordnete IDs. Die Felder werden gegen `get_columns()` geprüft, damit eine Kernänderung nicht zu einem SQL-Fehler führt. Das ist der einzige schreibende Zugriff des Plugins auf eine Kerntabelle; er ist in `docs/privacy/DATENSCHUTZ.md` dokumentiert. Die Tests laufen in jeder Zelle der Matrix (4.5 bis 5.3). Ob dieser Zugriff als begründet gilt oder ein Kern-Patch angestrebt wird, ist zu entscheiden; ohne eine solche Entscheidung ist das AK im Wortlaut nicht erfüllt. Grenze: Die Feldliste ist fest; neue Textfelder eines späteren Moodle blieben stehen. Kennt die Tabelle keines der Felder, bleiben Einträge und Herkunftssätze erhalten (kein stiller Verlust). |
| Auswirkungen auf bisherige Löschhistorien dokumentiert und begrenzt | ✔ | `DATENSCHUTZ.md`, Abschnitt 3 „Altbestand“: Widerrufe unter 0.4.1 haben alle KI-Einträge der Person im Nutzerkontext geleert, auch fremde; nicht umkehrbar. Aufrufe von Releases vor 0.4.1 (Aktivitätskontext) ordnet das Upgrade zu (`mod_sideline_upgrade_attribute_ai_calls()`). Aufrufe aus 0.4.1 im Nutzerkontext bleiben unzugeordnet und werden von einem Widerruf nicht geleert; dafür bleibt die Privacy-API des Kerns. 0.4.1 war ein Beta-Stand vom selben Tag. |
| DoD: Integrationstest zwei Instanzen plus simuliertes Fremdplugin | ✔ | `ai_records_test` (5 Tests), siehe oben; zusätzlich Privacy-Export, Privacy-Löschung je Kontext und Löschen der Instanz. |
| DoD: PHPUnit und reale Core-AI-Adaptertests auf 4.5 und 5.3, PG und MariaDB | ✔ lokal, ◐ GitHub | Matrix 8/8 (siehe Gesamtergebnis). Upgrade-Prüfung: Zuordnung des alten Eintrags im Aktivitätskontext, keine Zuordnung des Eintrags des anderen Plugins, Widerruf leert nur den eigenen; in allen sechs Upgrade-Zellen. |
| DoD: Konkurrenztests Widerruf während Antwort, Privacy-Export/-Löschung, wiederholter Widerruf | ◐ | deterministische Verschränkungen in `ai_records_test` (siehe oben). Echte Parallelprozesse (`tools/race/run.php`) prüfen weiter Widerruf während und nach der Erzeugung, aber mit dem Testgenerator, nicht mit dem Kernpfad; Privacy-Löschung parallel zu einer Erzeugung ist nur durch die Reihenfolge im Code abgesichert (siehe AK „synchronisiert“), nicht durch einen Paralleltest. |
| DoD: fachlicher Review zu Datenschutz und Datenverlust | ◐ | technische Bewertung in `DATENSCHUTZ.md`, Abschnitt 3; die fachliche Bewertung gehört zur DSB-Entscheidung D3 (#14). |

## #29 Neue MUC-Definition ohne Versionssprung

**Ursache:** `db/caches.php` kam in 0.4.1 hinzu, die Versionsnummer blieb bei `2026110300`. Moodle sieht dann kein Update (`moodle_needs_upgrading()` vergleicht nur die Versionsnummern). Das verstößt gegen die Regel der Moodle-Dokumentation, bei Änderungen unter `db/` die Version zu erhöhen.

**Reproduziert, mit genauerem Ergebnis als im Audit vermutet** (Negativkontrollen auf 5.3/PostgreSQL und 4.5/MariaDB: 0.4.0 installiert, Daten angelegt, durch den GitHub-Stand `afabccc` ersetzt; die Cache-Konfiguration wird direkt aus `muc/config.php` gelesen, ohne Moodle zu starten):

| Schritt | `mod_sideline/groupsnapshot` in der Cache-Konfiguration |
|---|---|
| vor dem Austausch des Codes | nein |
| nach dem Austausch des Codes | nein |
| nach `admin/cli/upgrade.php` („No upgrade needed …“) | **ja** |
| ohne `upgrade.php`, erster `cache::make()` | nein; Moodle liest alle Definitionen neu ein, setzt den Cache zurück und meldet „Cache definitions reparsed causing cache reset in order to locate definition. You should bump the version number …“ |

Eine Ausnahme tritt also nicht auf. Das CLI-Upgrade schreibt die Definitionen als Nebenwirkung neu, auch wenn es nichts zu tun meldet. Unter `CACHE_DISABLE_ALL` löst die abgeschaltete Cache-Fabrik jede Definition über den echten Konfigurationsschreiber auf (`disabled_factory::create_definition()` → `factory::create_definition()` → `config_writer::update_definitions()`). Ohne CLI-Upgrade holt der erste Zugriff die Definition nach, mit Cache-Reset und Debug-Meldung. Der Regelverstoß war real, die Folge kleiner als befürchtet.

**Lösung:** Versionsnummer `2026110302` und Upgrade-Schritt. Die Cache-Definition selbst entfällt in 0.4.2, weil die Tageszählung in eine Tabelle umzieht (#30). Beim Upgrade liest Moodle die Definitionen neu ein (`cache_helper::update_definitions()` in `upgrade_noncore()`), `groupsnapshot` ist danach verschwunden. Die Einträge von 0.4.1 entfernt das Leeren aller Caches, das ein reguläres Upgrade ausführt. Ein gezieltes Leeren im Upgrade-Schritt wäre wirkungslos: Das CLI-Upgrade schaltet die Cache-API ab (`CACHE_DISABLE_ALL`). Bei einem Upgrade ohne Wartungsmodus (`--maintenance=false`) leert Moodle nichts und verlangt das Leeren danach; das steht in `DATENSCHUTZ.md`. `db/caches.php` steht in `db/removed_files.txt`.

| AK / DoD | Status | Umsetzung und Nachweis |
|---|---|---|
| Versionsnummer gegenüber 0.4.0 erhöht, ohne unnötigen Schemawechsel | ✔ | `2026110302`. Der Schemawechsel ist nötig: neue Tabellen `sideline_daycount` (#30) und `sideline_aicall` (#28). |
| Definition auf Bestandssystemen nach regulärem Upgrade verfügbar, ohne Rescan oder Purge | ✔ (geänderter Gegenstand), Grenze | Es gibt keine Definition mehr, die verfügbar sein müsste. Geprüft wird das Gegenstück: Direkt nach `upgrade.php`, bevor Code des Plugins läuft, enthält `muc/config.php` keinen Eintrag `mod_sideline/groupsnapshot`, und im Dateispeicher liegt im Verzeichnis der Definition (`mod_sideline_groupsnapshot`) keine `.cache`-Datei mehr (`upgrade_verify.php`). Grenze: Ein Upgrade ohne Wartungsmodus (`--maintenance=false`) leert keine Caches; Moodle verlangt das Leeren danach selbst. Bis dahin lägen die Einträge von 0.4.1 noch im Speicher. Bei 0.4.1 als Ausgangsstand legt der Seed dort einen Eintrag mit Nutzer-ID ab, und die Prüfung verlangt, dass er vor dem Upgrade als Datei existierte (sonst wäre „nichts übrig“ wertlos). Die erste Fassung dieser Prüfung suchte unter einem falschen Verzeichnisnamen und wäre so immer grün gewesen; das fiel durch diese Gegenprobe auf. |
| Gruppenaussage direkt nach dem Update unter 4.5 und 5.3, auch bei persistentem MUC und mehreren Knoten | ✔ | `upgrade_verify.php` ruft direkt nach dem Upgrade `group_service::facts()` auf (Seed: Gruppe mit vier teilenden Mitgliedern, drei mit Erledigung gestern) und verlangt genau eine Aussage, **keine** Debug-Meldung und drei Zeilen in `sideline_daycount`. Die Tageszählung liegt in der Datenbank und ist damit auf jedem Knoten dieselbe. |
| Leere, gefüllte, abgelaufene Snapshots; kein Offenlegen bei Fehlschlag | ✔, Fehlerfall ◐ | `day_count_test`: leere Zählung (erster Aufruf des Tages), gefüllte (zweiter Aufruf nach Neuabstempeln), abgelaufene (nächster Tag nutzt die Zeilen des Vortags nicht, `test_rows_of_earlier_days_are_not_used_and_are_deleted`). Ein Fehler beim Speichern bricht mit Ausnahme ab, bevor eine Aussage ausgeliefert wird (Codepfad, ohne eigenen Test); eine doppelte Zeile eines parallelen Knotens wird erkannt (`test_a_withdrawal_between_the_count_and_its_storage_leaves_no_row`). |
| Doku trennt Build-/Schemaänderung vom Dateiwechsel, keine falsche Aussage „kein Upgrade nötig“ | ✔ | `CHANGELOG.md`: Abschnitt 0.4.1 korrigiert; 0.4.2 nennt Schemaänderung und Upgrade-Schritt. `docs/environment-setup.md`, Abschnitt 8: Versionsschema und die Regel, dass jede Änderung unter `db/` eine höhere Nummer braucht. |
| DoD: echter In-place-Upgrade-Test von 0.4.0 mit Gruppen, aktivem Cache und Gruppenkommentar | ✔ lokal, ◐ GitHub | Upgrade-Matrix: 0.4.0 → 0.4.2 auf 4.5/MariaDB und 5.3/PostgreSQL, 0.4.1 → 0.4.2 auf 4.5/PostgreSQL und 5.3/MariaDB, 0.3.2 → 0.4.2 auf 4.5/PostgreSQL und 5.3/MariaDB. Jede Zelle mit Gruppe, persistenter Cache-Konfiguration im Datenverzeichnis und Gruppenaussage direkt danach. |
| DoD: 4.5 und 5.3 mit PG und MariaDB, Nachweis der geladenen Definition | ✔ lokal, ◐ GitHub | wie oben; geprüft wird die Cache-Konfiguration nach dem Upgrade. |
| DoD: PHPUnit- und Behat-Regressionstests für Cache-Initialisierung und Tageswechsel, mit SHA und Run-IDs | ◐ | PHPUnit: `day_count_test::test_no_cache_of_the_plugin_holds_user_ids` (kein MUC-Eintrag des Plugins), `::test_rows_of_earlier_days_are_not_used_and_are_deleted` (Tageswechsel). Behat `group_daycount.feature` prüft die Stabilität innerhalb des Tages und das Löschen bei Ende des Teilens und Widerruf, **nicht** den Tageswechsel (Behat kann die Uhr nicht vorstellen) und keine Cache-Initialisierung (es gibt keine Definition mehr). SHA und Run-IDs nach dem Push. |

## #30 Personenbezogene IDs im Gruppen-Snapshot bleiben nach Widerruf

**Ursache, erweitert:** Neben dem fehlenden gezielten Löschen gab es ein zweites Problem. Moodles Dateispeicher prüft die TTL erst beim Lesen und löscht dann. Die Schlüssel von 0.4.1 enthielten den Tag und wurden ab dem Folgetag nie wieder gelesen. Die Nutzer-IDs wären also unbegrenzt im Datenverzeichnis liegen geblieben, unabhängig vom Widerruf.

**Lösung:** keine Personendaten mehr im MUC. Die Tageszählung liegt in `sideline_daycount` (`classes/local/group/day_count.php`): eine Zeile je Person, Gruppe, Aktivität, Ereignisart und Tag.

| AK / DoD | Status | Umsetzung und Nachweis |
|---|---|---|
| Einträge bei Privacy-Löschung und Widerruf zuverlässig entfernt | ✔ | Gelöscht wird bei Ende des Teilens (`sharing_manager::record()`), Widerruf der Zustimmung (`consent_manager::forget_generated()`), Privacy-Löschung (`TABLES` des Providers: `delete_data_for_user`, `_users`, `_all_users_in_context`), Löschen der Instanz. Tests: `day_count_test::test_stopping_to_share_or_withdrawing_removes_the_person_at_once`, `::test_privacy_deletion_removes_the_rows_in_every_mode`. |
| Gruppenwechsel, Sichtbarkeit, Freigabekreis ohne veraltete Einträge | ✔ | Verlassen einer Gruppe (`group_member_removed`) und des Kurses (`user_enrolment_deleted`): Observer löschen die Zeilen der Person in allen Instanzen des Kurses (`::test_leaving_the_group_or_the_course_removes_the_person`). **Befund der Prüfung:** In der ersten Fassung löschte nur das Speichern der Einstellungen die Zählung; ein wachsender Freigabekreis (neue Gruppe, Gruppierung, Sichtbarkeit, Restore), ein Import, eine neue Einwilligungsversion, das Löschen einer Gruppe und eine gesperrte Einschreibung ließen Zeilen bis zum nächsten Tag stehen, und wer unter neuen Bedingungen wieder teilte, zählte aus alten Zeilen mit. Der Testname versprach mehr, als der Test prüfte. Jetzt: `day_count::forget_if_terms_changed()` (Gruppen- oder Einwilligungsversion, Modus) nach dem Formular, nach dem Import und in `refresh_group_circle()` bei wachsendem Kreis; `group_deleted` löscht die Zeilen der Gruppe; `user_enrolment_updated` die der Person. Tests: `::test_a_wider_circle_an_import_or_a_new_consent_version_drop_every_count`, `::test_a_deleted_group_or_a_suspended_enrolment_removes_the_rows`, `::test_the_end_of_group_mode_in_the_settings_drops_every_count`. Sichtbarkeit: Die Aussage schneidet mit dem aktuellen Kreis (bestehende Tests `group_mode_test` 5). Zeilen älterer Tage löscht die nächste Aussage der Instanz und die Aufgabe `purge_cache`. |
| Kein seitenweiter Cache-Purge als Standard | ✔ | Gelöscht wird gezielt per SQL. Das Plugin leert nie einen Cache; das Leeren beim Upgrade ist das von Moodle selbst. |
| Anonyme Aussagen bleiben resistent gegen Differenzangriffe | ✔ | Tagesstabilität unverändert: `group_privacy_test::test_a_completion_stamped_again_today_does_not_change_the_statement_of_the_day`, `::test_the_statement_does_not_change_within_a_day`, `::test_a_statement_never_covers_every_other_member_of_the_group` laufen weiter grün. Neu: Wer am selben Tag widerruft und wieder teilt, wird nicht aus alten Zeilen mitgezählt (`day_count_test::test_a_person_who_shares_again_is_not_counted_from_old_rows`). Die Aussage ändert sich in diesem Fall, weil die Person selbst sie geändert hat. |
| Datenregister und Metadaten beschreiben Umfang, Ort, Aufbewahrung, Widerruf/Export/Löschung, Cluster | ✔ | `DATENSCHUTZ.md`, Abschnitt 1 (neue Zeilen, Absatz „keine personenbezogenen Daten in Moodle-Caches“); Privacy-Metadaten mit allen Feldern. Cluster: Tabelle statt Cache, auf jedem Knoten sofort dieselbe. |
| DoD: Integrationstest, dass die ID nach Widerruf nicht mehr gespeichert ist | ✔ | siehe oben; zusätzlich `::test_no_cache_of_the_plugin_holds_user_ids` (das Plugin hat keine Cache-Definition mehr) und im Upgrade: kein Eintrag im Dateispeicher. |
| DoD: Privacy-Löschung über Moduswechsel, Restore, Gruppenumzug, TTL, ohne Wiederkehr nach erneutem Opt-in | ✔ (Restore als Kopie) | Moduswechsel und fehlende Freigabezeile: `::test_privacy_deletion_removes_the_rows_in_every_mode`. Restore: Eine Kopie der Aktivität (Backup und Restore über `duplicate`) startet ohne Zählung; die Tabelle ist nicht Teil der Sicherung. Gruppenumzug, Tageswechsel, erneutes Opt-in: siehe oben. |
| DoD: PHPUnit/Behat mit mehreren Personen und Zwei-Knoten-Simulation auf 4.5 und 5.3, inklusive Datenbankspeicher | ✔ lokal, ◐ GitHub | `day_count_test` (10 Tests) und Behat `group_daycount.feature` (5 Personen, 2 Szenarien) in jeder Matrixzelle. Zwei Knoten: `::test_a_withdrawal_between_the_count_and_its_storage_leaves_no_row` simuliert sie im selben Prozess nacheinander (ein Knoten zählt, die Person widerruft, der Knoten speichert; zwei Knoten speichern dieselben Zeilen). Echte parallele Prozesse auf mehreren Knoten sind nicht getestet; die Tabelle macht die Knoten gleich, die Reihenfolge Speichern-dann-Prüfen sichert den Wettlauf. Ein Datenbank-Cache-Speicher gehört nicht zum Kern von 4.5 bis 5.3; die Zählung liegt jetzt ohnehin in der Datenbank. |
| DoD: k-Anonymität, Tagesstabilität und Abfragebudget nicht geschwächt (#7, #12) | ✔ | Mindestgröße, Bänder und Tagesfenster unverändert. Abfragen: eine Abfrage für die Zeilen des Tages je Aufruf, Schreibzugriffe nur, wenn jemand am Tag erstmals gezählt wird. `group_privacy_test::test_queries_do_not_grow_with_groups_or_members` grün. `tools/bench_groups.php`: ein Lesezugriff mehr als 0.4.1 (PostgreSQL 9 statt 8, MariaDB 7 statt 6), unabhängig von Personen und Gruppen. Die erste Fassung las die Zeilen als Recordset; auf PostgreSQL ist das ein Cursor mit drei Rundreisen (11 Lesezugriffe). Gefunden durch den Vergleich mit 0.4.1 auf derselben Site, korrigiert auf eine Abfrage. |

## #24 Prompt-Guard-Performance: p95/p99 und Spitzenspeicher

**Neuer Befund durch die Messung:** Auf MariaDB lag der Worst-Case-Text bei 20 000 Einschreibungen über dem Budget (p99 223 ms gegen 200 ms). Ursache: Die Wortfolgen gingen in Blöcken zu 400 in die Datenbank, ein Text von 500 Zeichen hat bis zu etwa 750, also zwei Abfragen, und jede liest die Einschreibungen des Kurses einmal. Die Blockgröße ist jetzt 750 (`prompt_text_guard::CHUNK`, Begründung im Code), damit ist es eine Abfrage. Die zwei Listen bleiben unter den Parametergrenzen aller von Moodle unterstützten Datenbanken.

| AK / DoD | Status | Nachweis |
|---|---|---|
| AK1 Budget für 100, 1000, 5000, 20 000 | ✔ (Prüfung isoliert) | `tools/bench_names.php`: 7 Texte × 30 Läufe je Größe; Budget je Prüfung 2 Abfragen, p99 200 ms, 1 MiB Spitzenspeicher. Ergebnisse unten. Gemessen wird `prompt_text_guard::course_name()` selbst, die Prüfung, die eigene Regelvorlagen, Site-Personas und die Persona-Vorschau durchlaufen; die übrigen Schritte dieser Pfade (Vorlagenprüfung, Pseudonymisierung) sind nicht Teil der Messung. |
| AK2 kein Name verloren, Fail-closed | ✔ | Text `namelast` (Name der zuletzt eingeschriebenen Person am Ende) wird in jedem Lauf gefunden; Datenbankfehler → `budget` → nichts geht an die KI (bestehende Tests `prompt_guard_test`). |
| AK3 begrenztes Caching | ✔ | Request-Cache des Guards (höchstens `CACHE_SIZE` Einträge, nur für die Anfrage), keine Materialisierung von Namen. Gemessen ohne diesen Cache (zurückgesetzt vor jedem Lauf). |
| AK4 Alternativen abgewogen | ✔ | Abgleich in der Datenbank über die Wortfolgen des Texts statt Laden der Namen: Kosten unabhängig von der Zahl der Namen in PHP, linear in der Zahl der Einschreibungen in der Datenbank (gemessen 100 → 20 000). |
| DoD5 SQL-Profil, p95/p99 und Speicher auf PG und MariaDB, adversariale Texte | ✔ lokal (ohne Ausführungsplan), ◐ GitHub | siehe Tabelle; manueller Workflow `bench.yml` für den GitHub-Nachweis. |
| Negativtest: Grenze, Fehler oder Speicher lösen keinen ungeprüften KI-Aufruf aus | ✔ | Text über `MAX_LENGTH` → `length` vor jeder Abfrage (im Benchmark je Größe geprüft); `prompt_guard_test`. |

Messwerte, 20 000 Einschreibungen (die Hälfte historisch), je 30 Läufe, Zeit in ms, Speicher in KiB über dem Stand vor der Prüfung:

| Text | PostgreSQL: Abfragen · p50 · p95 · p99 · max · Spitze KiB | MariaDB: Abfragen · p50 · p95 · p99 · max · Spitze KiB |
|---|---|---|
| distinct | 1 · 76.5 · 118.8 · **125.7** · 125.7 · 683 | 1 · 54.7 · 76.8 · **77.1** · 77.1 · 683 |
| unicode | 1 · 74.8 · 112.7 · **127.7** · 127.7 · 392.8 | 1 · 71.7 · 80.4 · **81.1** · 81.1 · 386.8 |
| nearmiss | 1 · 60.2 · 105.1 · **110.3** · 110.3 · 188.6 | 1 · 41.9 · 80.9 · **81** · 81 · 188.3 |
| hyphens | 1 · 83.7 · 140.2 · **143.6** · 143.6 · 682.1 | 1 · 72.2 · 87.5 · **88.1** · 88.1 · 682.1 |
| repeated | 1 · 62.8 · 86.5 · **90.4** · 90.4 · 25 | 1 · 32.3 · 39.8 · **40** · 40 · 25 |
| letters | 1 · 69.8 · 101.9 · **137.2** · 137.2 · 699.3 | 1 · 53.5 · 163.3 · **166.1** · 166.1 · 695.3 |
| namelast | 1 · 82.9 · 154.4 · **185.8** · 185.8 · 677.9 | 1 · 52.9 · 155.7 · **160.3** · 160.3 · 677.9 |

Über alle Größen (100, 1000, 5000, 20 000) und Texte: höchstens **1 Abfrage** je Prüfung, p99 höchstens **185.8 ms** (PostgreSQL) bzw. **166.1 ms** (MariaDB), Spitzenspeicher höchstens **699.3 KiB** bzw. **695.3 KiB**, CPU-Zeit p99 höchstens 5.7 bzw. 8 ms. Die Zeit liegt in der Datenbank (Spalte „dbms“ der Rohdaten) und wächst linear mit den Einschreibungen; die PHP-CPU-Zeit bleibt unter 10 ms. Alle Fälle im Budget. Bei 30 Läufen je Text ist p99 nach dem Rangverfahren gleich dem Maximum, also die konservative Angabe. Vor der Korrektur der Blockgröße: MariaDB `distinct` bei 20 000 p99 223 ms mit 2 Abfragen (über dem Budget). Rohdaten: `bench-names-*.json` (Artefakt des Workflows `bench.yml`). Umgebung: PostgreSQL 17, MariaDB 11.4, PHP 8.3, Moodle 5.3, 2 CPU-Kerne.

## #26 Import: CPU- und RAM-Budget für den Worst Case

**Neuer Befund durch die Messung:** Die Vorschau einer Datei mit 1000 Gruppen kostete eine bis zwei Abfragen je Gruppe, und die Zuordnung jeder Aktivität durchsuchte den ganzen Kurs. Größte Datei: 1746 ms, 1231 Abfragen. Jetzt lädt `preview_groups()` Gruppen und Gruppierungen des Kurses einmal, `map_activity()` nutzt einen Index je Kursstruktur: 148 ms, 233 Abfragen. Die verbleibenden Abfragen hängen nur von den Höchstzahlen der Datei ab (Regeln, Personas, Quellen), nicht von der Zahl der Einträge (`config_transfer_test::test_the_preview_of_a_file_at_the_limits_costs_no_query_per_entry`). Gruppennamen werden jetzt auf jeder Datenbank exakt verglichen; MariaDB verglich vorher ohne Groß- und Kleinschreibung.

| AK / DoD | Status | Nachweis |
|---|---|---|
| Importvertrag, vollständige Ablehnung, Negativtests, gleiche Normalform | ✔ (0.4.1) | unverändert, `config_transfer_test`. |
| DoD Tests mit Grenzwerten, Format 1/2 und 3 | ✔ | `config_transfer_test::test_older_files_never_admit_all_groups` (Version 2), `::test_malformed_files_are_rejected_as_a_whole` (Version als Zeichenkette, falsche Strukturen), `::test_a_file_at_every_limit_is_read_completely` (Version 3), Behat `transfer.feature`. |
| DoD CPU-/RAM-Budget für 512 KiB unter PG und MariaDB | ✔ lokal, ◐ GitHub | `tools/bench_import.php`, je 10 Läufe. Budget: Decode p99 250 ms, 16 MiB, keine Abfrage; Vorschau und Übernahme p99 1000 ms, 16 MiB, 400 bzw. 1000 Abfragen. Herleitung: das Fünf- bis Zehnfache der gemessenen Höchstwerte (Decode 59 ms, Vorschau 184 ms, Übernahme 220 ms, Spitze 4 MiB), damit langsamere Runner und Datenbanken Platz haben; die Abfragen sind durch die Höchstzahlen der Datei begrenzt (Regeln, Personas, Quellen). Bei 10 Läufen ist p99 gleich dem Maximum. Ergebnisse unten. |

| Datei | Schritt | PostgreSQL: p50 · p95 · p99 · CPU p99 · Spitze MiB · Abfragen | MariaDB: p50 · p95 · p99 · CPU p99 · Spitze MiB · Abfragen | Ergebnis |
|---|---|---|---|---|
| wide (512 KiB) | decode | 6.3 · 12 · **12** · 12 · 3.16 · 0 | 10.6 · 12.1 · **12.1** · 12.1 · 3.16 · 0 | accepted |
| wide (512 KiB) | preview | 100.5 · 138.8 · **138.8** · 108.2 · 2.27 · 232 | 130.4 · 184.2 · **184.2** · 152.5 · 2.11 · 234 | done |
| wide (512 KiB) | apply | 187.7 · 220.4 · **220.4** · 76.1 · 1.13 · 631 | 159.3 · 184.1 · **184.1** · 72.6 · 1.02 · 635 | done |
| deep (512 KiB) | decode | 2.2 · 5.8 · **5.8** · 4.4 · 1.24 · 0 | 3.8 · 4.5 · **4.5** · 4.2 · 1.24 · 0 | accepted |
| deep (512 KiB) | preview | 64.9 · 86.1 · **86.1** · 48.6 · 1.02 · 211 | 64.6 · 71.6 · **71.6** · 43.6 · 1.02 · 211 | done |
| deep (512 KiB) | apply | 78.5 · 106.4 · **106.4** · 40.2 · 0.04 · 250 | 91.4 · 110 · **110** · 45.3 · 0.05 · 251 | done |
| tiny (512 KiB) | decode | 30.5 · 58.7 · **58.7** · 58.6 · 4.01 · 0 | 33.3 · 49.7 · **49.7** · 49.4 · 4.01 · 0 | err_transfer_limit |
| oversize (512 KiB) | decode | 0.1 · 0.3 · **0.3** · 0.3 · 0.01 · 0 | 0 · 0.3 · **0.3** · 0.3 · 0.01 · 0 | err_transfer_limit |
| toodeep (0.1 KiB) | decode | 0 · 0.1 · **0.1** · 0.1 · 0.01 · 0 | 0 · 0.1 · **0.1** · 0.1 · 0.01 · 0 | err_transfer_limit |

Zeiten in ms, je 10 Läufe. Alle Schritte im Budget. `tiny` (rund 175 000 leere Objekte) ist der speicherintensivste Fall: 4 MiB Spitze, abgelehnt nach dem Dekodieren durch die Höchstzahl. `oversize` wird vor dem Dekodieren abgelehnt, `toodeep` vom Decoder.

---

## Stand der übrigen offenen Issues

| Issue | Stand nach 0.4.2 | Was fehlt, und von wem |
|---|---|---|
| #6 Gruppenereignisse | technisch unverändert: `viewed`, `completed`, `passed` konfigurierbar (AK1), einheitlich für offene/geschlossene und benannte/anonyme Aussagen (AK3), Misserfolge nie (AK4), eine neue Ereignisart verlangt neue Entscheidungen (AK5) | **AK2 nicht umgesetzt:** Leistungs- und Notenänderung, Zugang, Zeitfenster und „unbekannte Datenlage“ fehlen. **Produktentscheidung** (Produktverantwortung), ob die Matrix bewusst auf drei Arten begrenzt bleibt; danach DoD1 (fachliche Abnahme der Matrix). Nicht still als erfüllt werten. |
| #12 Reidentifikation | technische Negativtests vorhanden (k = 2/3, Überschneidungen, sequenzielle Abfragen, Sichtbarkeit; neu: erneutes Opt-in am selben Tag) | **Datenschutzbewertung** der Restrisiken in `GRUPPENAGGREGATE.md` durch die DSB. |
| #14 Core-AI, Privacy, Retention | AK2 (Gruppencaches unabhängig vom Modus löschen) ist mit #30 technisch erfüllt; der Widerruf greift nicht mehr in fremde KI-Daten ein (#28) | DSB-Entscheidungen D1 bis D5 (Rechtsgrundlage, DSFA, Aufbewahrung im Kern, Anbieter), Nachweis auf dem Zielsystem (DoD2), DSFA-Entscheidung (DoD3). Neu zur Entscheidung durch #28: der schreibende Zugriff auf `ai_action_generate_text`, die unzugeordneten Einträge aus 0.4.1, keine eigene Frist für `sideline_aicall` (lebt so lange wie der Kerneintrag). |
| #24 | siehe oben | GitHub-Lauf `bench.yml` (manuell). |
| #25 A11y manuell | automatische Prüfungen unverändert grün (Zoom 200/400 %, RTL, Touch, axe, Tastatur) | **manueller Test** mit NVDA/VoiceOver nach `docs/A11Y_SMOKE.md` auf 5.3 (React) und 4.5 (AMD) mit Protokoll; kann nicht automatisiert werden. „Bewegung reduzieren“ prüft keiner der automatischen Tests. |
| #26 | siehe oben | GitHub-Lauf `bench.yml` (manuell). |
| #28, #29, #30 | siehe oben | GitHub-Lauf der Main-CI auf dem neuen Commit. |

## Bilanz: Was diese Arbeit schließt

| Issue | Technisch | Kann geschlossen werden, wenn … |
|---|---|---|
| #29 | alle AK erfüllt (AK2 mit geändertem Gegenstand: Definition entfällt; Grenze beim Upgrade ohne Wartungsmodus) | die Main-CI auf dem neuen Commit grün ist und das Audit akzeptiert, dass Behat den Tageswechsel nicht prüfen kann (PHPUnit prüft ihn). |
| #30 | alle AK erfüllt; mehrere Knoten im selben Prozess simuliert, nicht mit echten Parallelprozessen | die Main-CI auf dem neuen Commit grün ist. |
| #24 | alle AK erfüllt, gemessen auf beiden Datenbanken (lokal) | `bench.yml` auf GitHub gelaufen ist (Evidenz A); ein Ausführungsplan ist nicht Teil der Messung. |
| #26 | DoD erfüllt, gemessen auf beiden Datenbanken (lokal) | `bench.yml` auf GitHub gelaufen ist. |
| #28 | AK1 bis AK4 und AK6 erfüllt | entschieden ist, ob der schreibende Zugriff auf die Kerntabelle hingenommen wird (AK5), der fachliche Review vorliegt (DoD4) und ein Paralleltest mit echten Prozessen für Privacy-Löschung nicht verlangt wird (DoD3). |
| #6, #12, #14, #25 | nicht durch Code schließbar | Produktentscheidung (#6), DSB-Bewertung (#12, #14) und manueller Screenreader-Test (#25). |

Keines der Issues ist allein durch diese Arbeit vollständig schließbar: Für jedes fehlt mindestens der Lauf auf GitHub (Evidenzstufe A), für #28 zusätzlich eine Entscheidung.

