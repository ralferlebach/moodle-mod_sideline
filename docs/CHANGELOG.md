# Changelog

## 0.4.5 (beta)

Behebt den roten Schritt des Laufs 116338059388 von `bench.yml` auf 0.4.4. Version `2026111100`, kein Upgrade-Schritt. Nachweise: `docs/ISSUE_NACHWEISE_0.4.5.md`.

* **Namensprüfung auf großen Websites, zweiter Anlauf (#24):** Auf GitHub wählte PostgreSQL bei 20 000 Einschreibungen eines Kurses und einer Million Nutzender einen Merge Join über alle Nutzenden (1,2 s, Budget 200 ms). Ursache: Die Abfrage verband Einschreibungen und Nutzende per Join, und mit dem `LIMIT 1` von `record_exists_sql()` setzt der Planer auf einen frühen Treffer, den es fast nie gibt. Die Person einer Einschreibung wird jetzt über eine skalare Unterabfrage gelesen (je Einschreibung über den Primärschlüssel), und die Abfrage zählt ohne `LIMIT`. Damit gibt es keinen Join, den der Planer anders ordnen könnte: Gelesen werden die Einschreibungen des Kurses, sonst nichts.

## 0.4.4 (beta)

Behebt die roten Jobs des GitHub-Laufs 115988086541 (Main-CI auf 0.4.3) und des ersten Laufs von `bench.yml` (115989969517). Version `2026111000`, kein Upgrade-Schritt. Nachweise: `docs/ISSUE_NACHWEISE_0.4.4.md`.

* **Upgrade-Job, Zellen ab 0.4.1 (#31):** Das Darüberkopieren mit `rsync -a` übersprang `version.php`. Die Dateien von 0.4.1 und 0.4.3 sind gleich groß, und beide Arbeitsbäume entstanden in derselben Sekunde; rsync hält Dateien mit gleicher Größe und gleicher Änderungszeit für gleich. Moodle sah dann keine neue Version („No upgrade needed“, Exit 0), und der Job meldete „the upgrade ran“. Jetzt `rsync --checksum` und eine Prüfung, dass `version.php` tatsächlich die aktuelle ist. Das Plugin war nicht betroffen: Mit kopierter `version.php` bricht das Upgrade wie vorgesehen ab, lokal mit genau diesem Ablauf nachgestellt.
* **Namensprüfung auf großen Websites (#24):** Auf GitHub wählte PostgreSQL 17 mit einer Million Einschreibungen in anderen Kursen einen sequenziellen Scan über alle Einschreibungen (bis 241 ms, Budget 200 ms). Die Prüfung liest jetzt zuerst die Einschreibungsmethoden des Kurses und fragt die Einschreibungen über deren IDs ab (Index `enrolid, userid`), unabhängig von der Schätzung des Planers. Zwei Abfragen statt einer, im Budget von zwei.
* **`bench.yml` auf MariaDB:** Der Dienst-Container legt die Datenbank mit `latin1` an, Moodle verlangt `utf8mb4`; die Datenbank wird vor der Installation umgestellt.
* **Upgrade-Job ab 0.4.3:** `upgrade_seed.php` legt den KI-Eintrag ab einem Stand mit `sideline_aicall` wie das Plugin mit Herkunftssatz an. Sonst wären die Zellen „previous“ im nächsten Lauf rot gewesen.

## 0.4.3 (beta)

Nacharbeit zu den offenen Issues #31, #30, #29, #28, #26, #25, #24, #14, #12 und #6 und zum CI-Lauf 115655888628. Nachweise je Issue: `docs/ISSUE_NACHWEISE_0.4.3.md`. Version `2026110900`, Upgrade-Schritt `2026110900` (Feld `groupwindow`). Auslieferung als `mod_sideline-2026110900.zip`.

**CI und Upgrade (#31)**
* **Ursache des roten CI-Laufs:** Auf GitHub lag nach 0.4.2 noch `db/caches.php` von 0.4.1, weil das Release über das Repository kopiert worden war. Moodle lud die Definition `groupsnapshot` wieder; PHPUnit, Coverage, der Stale-Files-Job und alle sechs Upgrade-Jobs schlugen deshalb fehl. Die Datei ist auf GitHub gelöscht.
* **Das Upgrade bricht ab, solange eine entfernte Datei vorhanden ist,** und nennt sie mit der Anweisung, das Verzeichnis zu ersetzen statt darüberzukopieren (`db/removed_files.txt`, das jetzt mit ausgeliefert wird). README: neuer Abschnitt „Aktualisieren“.
* **Der Upgrade-Schritt löscht die Dateien der Cache-Definition von 0.4.1 selbst** aus allen Dateispeichern, auch aus solchen mit eigenem Pfad. Das hängt nicht mehr am Leeren aller Caches durch Moodle.
* Upgrade-Job: acht statt sechs Zellen. „previous“ ist jetzt 0.4.2 in dem Stand, den GitHub an seinem Versions-Commit hat (mit der veralteten `db/caches.php`). Zwei neue Zellen ab 0.4.1 kopieren zuerst über das alte Verzeichnis und erwarten den Abbruch mit `db/caches.php`, danach ersetzen sie das Verzeichnis. Die Prüfung nach dem Upgrade läuft zusätzlich durch die operative Datenschutzprüfung. Der Release-Artefakt-Job verlangt `db/removed_files.txt` und `cli/privacy_check.php` im Archiv.
* Ein Upgrade ohne Wartungsmodus (`--maintenance=false`) verweigert Moodle, sobald sich unter `db/` einer Komponente etwas geändert hat (Hash aller `db/`-Verzeichnisse), also bei jedem Release mit Upgrade-Schritt wie diesem; eine eigene Upgrade-Zelle dafür gibt es deshalb nicht. Das direkte Löschen der Cache-Dateien wirkt trotzdem unabhängig davon.

**Gruppenmodus (#6, #12)**
* **Neue Ereignisarten:** „darin gearbeitet“ (eigene ändernde Ereignisse der Person aus dem Standardprotokoll: Abgabe, Beitrag, Versuch) und „Ergebnis verbessert“ (eine Bewertung über jeder früheren derselben Person, also eine neue Bestleistung, aus dem Bewertungsverlauf; nur sichtbare Bewertungen in Kursen, die Bewertungen zeigen; nur anonym, nie Note, Differenz oder Verschlechterung).
* **Zeitfenster je Instanz:** 1, 3, 7, 14 oder 28 Tage (Standard 7). Ein längeres Fenster und eine weitere Ereignisart verlangen neue Entscheidungen der Teilenden.
* **Unbekannte Datenlage:** Ist das Standardprotokoll oder der Bewertungsverlauf aus, wird die Art nie erzählt; das Formular warnt, das Entscheidungsprotokoll nennt sie.
* Benannte Aussagen über die Nutzung richten sich nach der letzten Änderung im Fenster, anonyme nach der ersten (tagesstabil).
* Ereignismatrix, Risikoanalyse mit Restrisikoregister R1 bis R9 und Entscheidungsvorlagen: `docs/privacy/GRUPPENAGGREGATE.md`, `docs/privacy/ENTSCHEIDUNGEN.md`.

**Datenschutz (#14, #28)**
* **Frist für KI-Einträge des Plugins:** neue Einstellung `airetentiondays` (90 Tage). Die tägliche Aufgabe leert Prompt und Antwort älterer Aufrufe des Plugins im KI-Kern; Einträge anderer Plugins bleiben.
* **Operative Datenschutzprüfung:** `php mod/sideline/cli/privacy_check.php` meldet Fristen, überfällige Einträge, Einträge aus 0.4.1 ohne Instanz, aktive KI-Anbieter, alte Tageszählungen, Cache-Reste, entfernte Dateien, Entscheidungsprotokolle und Debug-Abrufe (OK, WARN, FAIL; Exit 1 bei FAIL). `--empty-unattributed` leert die Einträge aus 0.4.1, die kein Widerruf erreicht.
* Prozess für Prüfung, Auskunft und Löschung auf dem Zielsystem: `docs/privacy/DATENSCHUTZ.md`, Abschnitt 7.
* **Herkunft auch bei gleichem Prompt eindeutig (#28, Re-Audit 0.4.2):** Die Zuordnung eines KI-Eintrags verlangt zusätzlich die Antwort-ID des Anbieters für genau diesen Aufruf. Ein anderes Plugin, das im selben Moment denselben Prompt für dieselbe Person sendet, wird nie zugeordnet; bei Mehrdeutigkeit wird nichts zugeordnet (fail-closed).
* Neue Wettläufe mit echten Prozessen (`tools/race/run.php`): Privacy-Löschung während und nach einer Erzeugung durch den KI-Kern; ein anderes Plugin mit demselben Prompt während der Erzeugung; Ende des Teilens auf einem Knoten, während ein anderer die Gruppenaussage zählt (#30).

**Barrierefreiheit (#25)**
* **Statusmeldung nach jeder Aktion** („Gespeichert.“) in beiden Panels: Eine Antwort ohne Wirkung auf den Kommentar änderte bisher nichts Sichtbares, ein Screenreader sagte nichts (WCAG 4.1.3).
* **Fokus im Windows-Kontrastmodus:** Bootstrap zeichnet den Fokus als Schatten, den erzwungene Farben entfernen; jetzt zeichnet das Plugin dort einen Umriss (WCAG 2.4.7).
* Neue Playwright-Prüfungen `screenreader.spec.js` für React und AMD: Barrierefreiheitsbaum, Ansagen, Fokus, keine Tastaturfalle, erzwungene Farben, reduzierte Bewegung. Ergebnismatrix in `docs/A11Y_SMOKE.md`. Der Test mit NVDA und VoiceOver bleibt von Hand.

**Leistung (#24, #26)**
* `tools/bench_names.php` gibt den Ausführungsplan der Namensprüfung aus, so wie die Prüfung sie stellt (`LIMIT 1`), und misst mit `--siteusers --siteenrol` auf einer Website mit über einer Million weiterer Nutzender und Einschreibungen: Beide Datenbanken beginnen beim Kurs, der Aufwand wächst mit dem Kurs, nicht mit der Website. `tools/bench_groups.php --events` misst den Gruppenpfad mit allen fünf Ereignisarten. `bench.yml` führt beides zusätzlich aus.

**Tests**
* PHPUnit 500 (0.4.2: 477): `group_events_test` (12), `operational_check_test` (7), vier neue Fälle in `ai_records_test`, Ergänzungen in `backup_restore_test` und `config_transfer_test`; Behat `group_events.feature` (3 Szenarien, echte Antwort in einer Abstimmung); Jest für die Statusmeldung beider Panels; Playwright `screenreader.spec.js` (5 je Panel); Races 9 (0.4.2: 5).
* Eine unabhängige Prüfung fand 20 Punkte, darunter echte Fehler in den neuen Ereignisarten (Verschlechterung als Verbesserung, anonyme Ereignisse, verborgene Bewertungen) und zwei zu lange Zeilen, an denen die CI gescheitert wäre; alle sind behoben (`docs/ISSUE_NACHWEISE_0.4.3.md`, „Unabhängige Prüfung“).

## 0.4.2 (beta)

Nacharbeit aus dem Critical-Friend-Re-Audit von 0.4.1 (03.11.2026, Issues #28 bis #30, #24, #26). Nachweise je Issue: `docs/ISSUE_NACHWEISE_0.4.2.md`. Version `2026110302` (Datum plus laufende Nummer; 0.4.1 hätte `2026110301` sein müssen). Neue Tabellen `sideline_daycount` und `sideline_aicall`, Upgrade-Schritt `2026110302`; die Cache-Definition `groupsnapshot` entfällt.

**Datenschutz**
* **Widerruf leert nur noch KI-Einträge der eigenen Instanz (#28):** 0.4.1 rief beim Widerruf die Privacy-API des KI-Kerns für den ganzen Nutzerkontext auf. Das leerte Prompts und Antworten anderer Plugins und anderer Sideline-Instanzen derselben Person und löschte deren Zustimmung zur KI-Richtlinie in diesem Kontext. Jetzt hält jeder KI-Aufruf fest, welchen Kerneintrag er verursacht hat (`sideline_aicall`, Zuordnung unter einer Sperre je Person, über den gesendeten Prompt). Widerruf, Privacy-Löschung und das Löschen der Instanz leeren genau diese Einträge, mit denselben Feldern wie der Kern. Das Upgrade ordnet Aufrufe älterer Releases im Aktivitätskontext ihrer Instanz zu, aber nur solche mit der Form der Prompts des Plugins; ein Eintrag des Texteditors im selben Kontext bleibt unberührt. Ein Widerruf löscht genau die gelesenen Herkunftssätze, sodass ein gleichzeitig neu zugeordneter Aufruf seinen behält.
* **Widerruf während der Antwort des Anbieters (#28):** Der Kern schreibt seinen Eintrag erst nach der Antwort. Die Prüfung direkt nach der Erzeugung räumt jetzt selbst auf; in 0.4.1 blieb dieser Eintrag stehen.
* **Keine Nutzer-IDs mehr im Moodle-Cache (#30):** Die Tageszählung anonymer Gruppenaussagen liegt in der Tabelle `sideline_daycount` statt im Anwendungscache. Ende des Teilens, Widerruf, Privacy-Löschung (in jedem Modus, auch ohne Freigabezeile), Verlassen von Gruppe oder Kurs, neue Freigabebedingungen und das Ende des Gruppenmodus löschen die Zeilen sofort, auf jedem Knoten. Ebenso ein wachsender Freigabekreis (neue Gruppe, Gruppierung, Sichtbarkeit, Restore), ein Import mit neuen Bedingungen, eine neue Einwilligungsversion, eine gelöschte Gruppe und eine gesperrte Einschreibung. Gespeichert wird erst, dann wird die Freigabe erneut geprüft. Ältere Tage löscht die nächste Aussage und die tägliche Aufgabe. Der Dateispeicher von Moodle hätte die abgelaufenen Einträge von 0.4.1 nie gelöscht, weil tagesgebundene Schlüssel nicht wieder gelesen werden. Die Einträge von 0.4.1 entfernt das Leeren aller Caches beim regulären Upgrade; nach einem Upgrade ohne Wartungsmodus sind die Caches zu leeren (Moodle-Hinweis).

**Upgrade (#29)**
* Versionsnummer erhöht. Der Upgrade-Job prüft jetzt drei Ausgangsstände (0.4.1, 0.4.0, 0.3.2), jeden auf 4.5 und 5.3 und auf PostgreSQL und MariaDB. Neu geprüft: die Cache-Konfiguration direkt nach dem Upgrade, die Speicher der alten Definition, eine Gruppenaussage sofort danach ohne Debug-Meldung, die Zuordnung und das gezielte Leeren alter KI-Einträge neben dem Eintrag eines anderen Plugins.

**Leistung**
* **Import-Vorschau (#26):** Eine Datei mit 1000 Gruppen kostete bis zu 2000 Abfragen, die Zuordnung der Aktivitäten durchsuchte für jede Referenz den ganzen Kurs. Gruppen und Gruppierungen werden jetzt einmal geladen, Aktivitäten einmal indexiert: Vorschau der größten Datei 148 statt 1746 ms, 233 statt 1231 Abfragen, unabhängig von der Zahl der Einträge (Test `config_transfer_test::test_the_preview_of_a_file_at_the_limits_costs_no_query_per_entry`). Namen von Gruppen werden auf jeder Datenbank exakt verglichen; MariaDB verglich zuvor ohne Groß- und Kleinschreibung.
* **Messprofile (#24, #26):** `tools/bench_names.php` misst p50, p95, p99, CPU-Zeit, Spitzenspeicher und Abfragen mit sechs adversarialen Texten. `tools/bench_import.php` misst Decode, Vorschau und Übernahme mit Worst-Case-Dateien von 512 KiB. Neuer manueller Workflow `bench.yml` auf PostgreSQL und MariaDB, ohne Einfluss auf „CI complete“.

**Tests**
* PHPUnit `ai_records_test` (zwei Instanzen und ein anderes Plugin im selben Nutzerkontext; Widerruf, wiederholter Widerruf, Widerruf während der Antwort, Privacy-Export und -Löschung, Löschen der Instanz, Zuordnung im Upgrade neben einem Editor-Eintrag), `day_count_test` (Widerruf, erneutes Teilen am selben Tag, Privacy-Löschung in jedem Modus, Gruppen- und Kursaustritt, gesperrte Einschreibung, gelöschte Gruppe, wachsender Kreis, Import, neue Einwilligungsversion, Moduswechsel, Duplikat, Tageswechsel und Aufgabe, Widerruf zwischen Zählung und Speicherung, gleichzeitiges Speichern zweier Knoten). Behat `group_daycount.feature`. Eine unabhängige Prüfung aller AK und DoD gegen Code und Tests fand drei Lücken, die behoben sind (`docs/ISSUE_NACHWEISE_0.4.2.md`). Gemeinsamer Test-Trait `tests/fixtures/core_ai_provider.php`.

## 0.4.1 (beta)

Nacharbeit aus dem Critical-Friend-Re-Audit von 0.4.0 (02.11.2026, Issues #3 bis #27). Nachweise je Issue: `docs/ISSUE_NACHWEISE_0.4.1.md`. Keine Schemaänderung, kein Upgrade-Schritt; neu ist eine Cache-Definition (`db/caches.php`). **Korrektur (0.4.2, #29):** Eine neue Cache-Definition ist eine Änderung unter `db/` und braucht eine höhere Versionsnummer, damit Moodle beim Update die Definitionen neu einliest. Auf GitHub blieb die Nummer bei `2026110300` (wie 0.4.0); eine Bestandsinstallation lud die Definition erst beim ersten Zugriff nach, mit Cache-Reset und Debug-Meldung.

**CI auf GitHub (#20, #21, #22, #15)**
* **Frontend-Job (#20):** Das Skript hatte im Repository kein Ausführungsrecht (`Permission denied`, Exit 126). Alle Skripte werden jetzt mit `bash` aufgerufen, im Workflow, im `makefile` und untereinander.
* **Upgrade-Job (#21):** Das Muster `ci/` in `.gitignore` galt in jedem Verzeichnis und versteckte `tools/ci/` mit der Upgrade-Fixture vor Git. Es ist jetzt auf die oberste Ebene verankert (`/ci/`, `/moodle/`). Der Job prüft zwei Ausgangsstände, den direkten Vorgänger (jetzt 0.4.0) und 0.3.2, je auf 4.5/pgsql und 5.3/mariadb. Seed und Prüfung hängen vom Ausgangsrelease ab.
* **Playwright-Gruppenjourney (#22):** Ursache war kein Fachfehler. Der Test löschte die Cookies unter einer Seite, deren JavaScript noch lief; deren nächste AJAX-Antwort leitete zum Login um und brach die Navigation des Tests ab (`net::ERR_ABORTED`). Der Wiederholungsversuch scheiterte dann, weil der erste Versuch den Zustand schon verändert hatte. Jetzt hat jede Person einen eigenen Browserkontext, die Journey stellt ihren Ausgangszustand selbst her, und es gibt keine Wiederholungen mehr (`retries: 0`).
* **Neue lokale Abnahme:** `tools/ci-local/checkout.sh` baut den Checkout, den GitHub sieht (mit `.gitignore`, ohne Ausführungsrechte), und meldet ignorierte Dateien. `playwright.sh` bildet den Playwright-Workflow nach. Alle Abnahmeläufe von 0.4.1 liefen auf diesem Checkout.
* Kein neuer Job, keine neue Abhängigkeit von „CI complete“.

**Sicherheit und Datenschutz (unabhängiges Review, #3, #4, #12, #14; `docs/security/REVIEW_0.4.1.md`)**
* Der KI-Anbieter sieht neutrale Blockkennungen (`b1`, `b2`) statt der frei benannten Schlüssel eigener Regeln. Die Wiederherstellung prüft Regeldefinitionen und verwirft ungültige.
* KI-Aufrufe laufen im Nutzerkontext der lernenden Person. Der KI-Nutzungsbericht des Kurses zeigt Lehrenden damit nicht mehr, wer den Coach nutzt.
* Ein Widerruf entfernt auch Entscheidungsprotokolle, Regeltreffer sowie Prompt und Antwort im KI-Kern. Die Erzeugung prüft Zustimmung und Freigaben erst nach dem letzten Schreibvorgang.
* Persona-Stile von Admins, Namen aus den weiteren Kursen eines Kursverbunds und KI-Antworten werden auf Namen geprüft.
* Die Prüfung von Freitexten erkennt mehr Datumsformen, jeden Hostnamen, verschleierte E-Mail-Adressen und Prompt-Injection in allen geprüften Sprachen.
* Gruppenmodus: Die anonyme Zählung bleibt für den Tag fest (Moodle-Cache `groupsnapshot`), auch wenn Moodle eine Erledigung neu abstempelt. Eine anonyme Aussage über alle anderen Mitglieder einer Gruppe entfällt.
* Privacy-API: Autor- und Freigabe-IDs in Kursquellen, Kursbereichen und Site-Personas werden exportiert und gelöscht.

**Fehler**
* **Einstellungsformular:** Eine Instanz mit einer Pause von 0 Sekunden zwischen zwei Fragen (Import, Generator, Playwright-Seed) oder einem Wert, der kein Vielfaches einer Stunde ist, ließ sich nicht mehr bearbeiten (Coding Error des Dauerfelds). Das Feld kennt jetzt Minuten und eine Standardeinheit. Gefunden durch das neue Behat-Szenario des Kursverbunds.
* **Hinweis auf unbestätigte Kursquellen:** stand in einem eingeklappten Abschnitt. Der Abschnitt wird jetzt aufgeklappt.
* **Regelvorschau:** kein TypeError mehr bei einer ungültigen Definition.
* **Zielgröße (WCAG 2.5.8):** Die als Link gestalteten Schaltflächen „Show my answers“, „Delete answer“ und „Withdraw my consent“ waren niedriger als 24 px. Jetzt mindestens 24 × 24 px, in beiden Panels. Gefunden durch die neue Playwright-Prüfung.
* **Playwright- und k6-Workflow:** riefen `install.php` unter `public/` auf. Ab Moodle 5.1 liegt `admin/cli` aber im Wurzelverzeichnis. Auf 5.1 bis 5.3 wären beide Workflows schon bei der Installation gescheitert; der GitHub-Lauf von 0.4.0 lief auf 4.5.
* **Sprache der Vorlagentexte (#17):** Ein installiertes Sprachpaket ohne Texte dieses Plugins (der Normalfall für Pakete vom Moodle-Sprachserver) führte zu einem englischen Text, der als Text in der Paketsprache galt. Der Hinweis „nicht in deiner Sprache verfügbar“ fehlte. Die Textsprache ist jetzt die Sprache, in der es Texte des Plugins gibt (Sprache, Elternsprache, Englisch). Der Hinweis vergleicht die Sprachfamilie.
* **Import (#26):** Kursquellen über dem Maximum eines Kursverbunds wurden beim Import stillschweigend verworfen. Jetzt wird der Import mit Meldung abgelehnt.

**Leistung (#24)**
* `prompt_text_guard` lädt die Namen eines Kurses nicht mehr in den Speicher. Die Wortfolgen des Freitexts werden in der Datenbank abgeglichen: höchstens zwei Abfragen, unabhängig von der Kursgröße (gemessen bis 20 000 Einschreibungen). Bei einem Datenbankfehler wird nichts an die KI gesendet.

**Import-Grenzen (#26)**
* Höchstens 512 KiB, Verschachtelungstiefe 16, Texte bis 10 000 Zeichen, je Teil eine Höchstzahl an Einträgen (Regeln und Kursquellen wie in der Instanz). Ungültiges JSON und jede Überschreitung lehnen die ganze Datei ab.

**Tests**
* Behat: `course_union.feature` (Kursverbund mit Abschluss in einem anderen Kurs, Rechteentzug, unbestätigte Altquelle, Kursbereich mit Verschiebung und Rollenentzug; #5, #16), `transfer.feature` (Downloads im echten HTTP-Pfad mit Content-Type, Dateiname, Caching und Negativfällen; Import aus einem anderen Kurs und von einer fremden Site; Backup und Restore; #10, #19), `languages.feature` (Deutsch, Französisch mit Übersetzungsschritt, Polnisch als Fallback; #17).
* Playwright `a11y.spec.js`: Reflow bei 400 % und 200 % Zoom, Zielgrößen, sichtbarer Fokus, Rechts nach links, Touch-Gerät (#25). Manueller Prüfablauf für Screenreader: `docs/A11Y_SMOKE.md`.
* PHPUnit: Namensprüfung in der Datenbank, Importgrenzen, Textsprache, Einstellungsformular, Widerruf räumt auf, Gruppen-Tagesschnappschuss und ganze Gruppe, Privacy der Konfigurationsautoren; 461 Tests (vorher 421).
* `tools/bench_names.php`: Kosten der Namensprüfung.

**Formales (#23, #27)**
* `styles.css` und zwei Jest-Dateien mit vollständigem GPL-Kopf.
* Die Jest-Werkzeugkette des AMD-Panels ist mit Lockdatei festgelegt (`tools/ci-local/jest-amd/`). Abhängigkeiten und bekannte Advisories: `docs/ABHAENGIGKEITEN.md`. Das Plugin bündelt keine Fremdbibliothek.
* Code-Kommentare ohne Verweise auf Issues und Versionshistorie (Produktionscode).

**Dokumentation (#3, #4, #12, #14)**
* Unabhängiges Security-/Privacy-Review: `docs/security/REVIEW_0.4.1.md`.
* `docs/privacy/DATENSCHUTZ.md`: Inventar der Prompt-Felder mit verbleibenden Identifikatoren, darunter die verkettbare Nutzerkennung, die der Moodle-Kern an den Anbieter sendet; Aufbewahrung der Kern-KI-Protokolle (keine automatische Löschung im Kern).

## 0.4.0 (beta)

Nacharbeit aus dem Re-Audit vom 02.11.2026 (Issues #1 bis #18). Nachweise je Issue: `docs/ISSUE_NACHWEISE_0.4.0.md`.

**CI (#1, #2, #15)**
* PHP 8.1 läuft in keiner Testmatrix mehr: Main, Dev und Coverage nutzen 8.2 und 8.3. `version.php` bleibt unverändert.
* `maturity-gate` („Release gate“) und `ecosystem-lockstep` sind entfernt, ersatzlos und ohne Umbenennung. „CI complete“ fasst nur die funktionalen Jobs zusammen.
* Neu in der Main-CI auf demselben Commit:
  * PHPUnit der Adapter-Suites;
  * `--fail-on-skipped` und `--fail-on-incomplete`;
  * echte Parallelprozess-Rennen (`tools/race/run.php`);
  * Frontend-Job (ESLint, strikter TypeScript-Check, ESM- und AMD-Build, Jest mit axe für beide Panels);
  * Upgrade-Job vom vorigen Release auf 4.5/pgsql und 5.3/mariadb mit Schema- und Datenprüfung.
* Playwright bleibt auf ausdrücklichen Wunsch manuell (`playwright.yml`). Neu sind Journeys für Mehrfachauswahl per Tastatur und für Gruppenaussagen mit Widerruf.

**Sicherheit und Datenschutz (#3, #4, #14, #18)**
* **#4 (P0):**
  * Freitexte von Lehrenden (Intent eigener Regeln) und Admins (Persona-Stil) prüft `prompt_text_guard`, bevor etwas an die KI geht: E-Mail, Links, Domains, Handles, IP, Telefon, genaue Daten und Uhrzeiten, Kennungen, fremde Tokens, Markup, Prompt-Injection sowie Namen aller Kursteilnehmenden.
  * Bei einem Fund wird nichts gesendet; es gilt der lokale Vorlagentext.
* **#3 (P0):**
  * Widerruf, Cache und Auslieferung sind atomar: Schreiben, dann prüfen gegen Status schreiben, dann löschen.
  * Ein gescheiterter Cache-Schreibvorgang (CAS) verwirft den Text, und die Berechnung läuft einmal neu.
  * Vor jeder Auslieferung personenbezogener Inhalte läuft eine letzte Prüfung. Bei Sperr-Timeout gibt es nur den nicht personenbezogenen Grundtext.
* **#14:**
  * Die Privacy-Löschung entfernt alle Cachezeilen der Instanz, unabhängig vom Gruppenmodus.
  * Entscheidungsprotokolle halten den tatsächlich ausgelieferten Text und den KI-Austausch nur mit Tokens.
  * Der Debug-Export kennzeichnet die Live-Simulation und markiert Abweichungen.
  * Datenregister und offene DSB-Entscheidungen: `docs/privacy/DATENSCHUTZ.md`.
* **#18:**
  * Der Import ist eine Rechte- und Zustimmungsgrenze: Fehlerhafte Dateien werden als Ganzes abgelehnt.
  * Kurse und Kursbereiche werden nur mit Verwaltungsrecht übernommen, Persona-Definitionen nur von Admins und nur als Entwurf.
  * Das Teilen wird nie stillschweigend für alle Gruppen eingeschaltet.

**Zugriff und Cache (#5, #16)**
* **#5:** Kursquellen ohne nachweisbare Lehrperson (vor 0.3.0) werden nicht mehr ausgewertet, bis eine berechtigte Lehrperson sie durch Speichern bestätigt. Das Formular zeigt die Zahl. Das Upgrade protokolliert die betroffenen Instanzen und verwirft deren Texte.
* **#16:** Ereignisse in Kursen eines Kursbereichs, verschobene Kurse und Kursbereiche, Rollen- und Rechteänderungen markieren die betroffenen Caches. Der Fingerabdruck stellt zusätzlich sicher, dass die nächste Auslieferung dem aktuellen Stand entspricht.

**Gruppenmodus (#6, #7, #9, #12)**
* **#6:** Ereignisarten sind einstellbar: geöffnet, abgeschlossen, bestanden. Fehlversuche, Noten und andere negative Ereignisse werden nie erzählt. Die Matrix steht in `docs/privacy/GRUPPENAGGREGATE.md`.
* **#7:** Mengenbasierte Abfragen: keine Abfrage je Gruppe oder Mitglied, Zustimmungen in einer Abfrage. Messwerkzeug: `tools/bench_groups.php`.
* **#9:**
  * Die Freigabe gilt für einen gespeicherten Freigabekreis. Wächst er, müssen Lernende neu entscheiden. Das gilt für neue Gruppen, erweiterte Gruppierungen, sichtbarer gewordene Gruppen und neue Ereignisarten, ebenso über Formular, Observer, Import und Wiederherstellung.
  * Eine Wiederherstellung ohne die zugelassenen Gruppen schaltet das Teilen ab.
* **#12:** Anonyme Aussagen brauchen mindestens drei Personen. Sie nennen Bänder statt Zahlen und verwenden ein Zeitfenster, das am Tagesbeginn endet. Es gibt höchstens eine Aussage je Kommentar.

**KI-Kosten (#8)**
* Die Reservierung ist websiteweit atomar (Sperre, fail closed) und rechnet mit dem schlechtesten Fall (Prompt plus maximale Antwort). Offene Reservierungen bleiben sichtbar und zählen weiter. Ein Anbieterausfall schließt die Reservierung als gescheitert ab.

**Interview (#11)**
* Multiple Choice mit Mehrfachauswahl: Mindest- und Höchstzahl, strenge Prüfung (keine Duplikate, keine unbekannten Optionen) und deterministische Verrechnung der Wirkungen. Unterstützt in beiden Panels, in den eigenen Antworten, beim Bearbeiten, Löschen, Export, Import und Backup.

**Sprache (#13, #17)**
* Prüfregeln für erfundene Fakten auch in Französisch, Spanisch, Italienisch, Niederländisch und Portugiesisch. KI-Text gibt es nur in Sprachen mit Prüfregeln; die Übersetzung wird immer erneut geprüft.
* Das Panel kennzeichnet übersetzte Texte und Fallback-Sprache. Eine Vorlage erscheint nie als übersetzt. Matrix: `docs/SPRACHMATRIX.md`.

**Datenbank:** `sideline.groupevents`, `sideline.groupcircle`, `sideline_aiusage.timecompleted`; Upgrade-Schritt 2026110300.

## 0.3.2 (beta)

CI-Befunde behoben. Seit dieser Version läuft vor jeder Auslieferung moodle-plugin-ci lokal mit denselben Schritten wie auf GitHub (`tools/ci-local/`, `docs/environment-setup.md`).

* **Installation brach auf allen Versionen ab:** Die Spalte `sideline_pref.persona` war `CHAR NOT NULL` mit `DEFAULT=""`. XMLDB gibt dazu eine Debug-Meldung aus, und moodle-plugin-ci bricht bei jeder Debug-Meldung ab. Der Default ist entfernt. Bestehende Datenbanken haben die Spalte bereits ohne Default (XMLDB hatte das beim Anlegen korrigiert), daher ist kein Upgrade-Schritt nötig.
* **Code Checker (`--max-warnings 0`):**
  * Auf Moodle 4.5 erkennt moodle-cs die Coverage-Attribute nicht und erwartet `@covers` im Docblock direkt vor der Klasse. Die Attribute stehen jetzt vor dem Docblock: 4.5 (PHPUnit 9) liest `@covers`, ab 5.0 gelten die Attribute.
  * 21 Testklassen lagen nicht im Verzeichnis ihres Namensraums. Sie liegen jetzt unter `tests/local/…` bzw. `tests/form/`.
  * `tools/` wird von moodle-plugin-ci mitgeprüft; zwei Skripte dort sind korrigiert. `.phpcsignore` ist entfernt, weil die CI sie nicht beachtet.
* **PHPDoc Checker:** fehlende `@param`-Angabe in einem Test.
* **grunt:** Das AMD-Bundle ist mit Moodles grunt neu gebaut (auf 4.5 und 5.3 byte-gleich). `tools/amd-build.js` ist entfernt.
* **React-Panel:** vier zu lange Zeilen und eine zu hohe Komplexität (ESLint `max-len`, `complexity`) behoben, dazu ein fehlender Swizzle-Eintrag für `QuestionForm`. Einwilligung und Antwortliste sind eigene Komponenten.
* Test- und Playwright-Skripte sind jetzt ebenfalls ESLint-sauber nach Moodles Regeln.
* Die verschobenen und entfernten Dateien stehen in `db/removed_files.txt`.

## 0.3.1 (beta)

CI repariert, keine Änderung am Verhalten des Plugins:

* **Installation in der CI:** Die Workflows fügten das Plugin zusätzlich mit `moodle-plugin-ci add-plugin` hinzu. Das Plugin lag damit doppelt im Baum, und `install` brach ab. Die Adapter `slcmod_assign` und `slcmod_quiz` sind Subplugins im Plugin selbst; es gibt keine externen Abhängigkeiten.
* **Versionsgleichlauf:** Der Job klonte vier Geschwister-Repositories (`auth_`, `enrol_`, `tool_sideline` …), die es nicht gibt (Exit 128). Er prüft jetzt, dass `mod_sideline` und seine Adapter dieselbe Version und dasselbe Release tragen.
* **Browser-Journeys (Playwright):** neu für dieses Plugin geschrieben (`tests/playwright/`): Einwilligung und Widerruf, Interview per Tastatur mit Wirkung und Löschen, reduzierte Bewegung und Tippanimation, keine Sicht für Lehrende, axe-Prüfung. Läuft mit React-Panel (5.3) und AMD-Panel (4.5 bis 5.2). Workflow `playwright.yml` nur manuell, ohne Zeitplan; `make playwright` lokal.
* `seed_load.php` unterdrückt E-Mails beim Anlegen der Personen; deren Fehlermeldungen landeten sonst in den ausgegebenen Exporten.
* `tests/playwright/` ist vom Release-Artefakt ausgeschlossen und steht im Release-Gate auf der Verbotsliste.

## 0.3.0 (beta)

Offene Punkte aus Lasten- und Pflichtenheft nachgezogen:

* **Sprache und Übersetzung (P-24, L-07):** Die Sprache jedes KI-Texts wird geprüft. Deutsch und Englisch werden direkt in der Sprache der Person erzeugt. Für weitere Sprachen mit Sprachprofil (Französisch, Spanisch, Italienisch, Niederländisch, Portugiesisch) wird auf Englisch erzeugt und geprüft und dann in einem gesonderten, geprüften Schritt übersetzt. Dabei müssen Platzhalter und Zahlen gleich bleiben. Eine Antwort in der falschen Sprache wird ebenso übersetzt. Für Sprachen ohne Profil kommt die lokalisierte Vorlage.
* **Semantische Plausibilitätsprüfung (P-09):** Aussagen wie „bestanden“, „nicht bestanden“, „freigeschaltet“, „gesperrt“, Fristen, Punkte und Noten, ein weiterer oder kein Versuch, Kursabschluss, Abgabe und Klick-Hinweise sind nur erlaubt, wenn die Regel sie trägt. Sonst kommt die Vorlage. Ein Erfolg ohne „bestanden“ darf nicht als „bestanden“ formuliert werden.
* **Template-Versionen (P-07):** Jede eingebaute Vorlage hat Variablenschema und Versionsnummer. Eigene Regeln erhöhen ihre Vorlagenversion bei jeder Änderung des Wortlauts. Die Versionen stehen in den Metadaten jedes Kommentars, in Traces und im Debug-Export, zusammen mit Regel- und Promptversion.
* **Persona-Vorschau (P-14):** Für Entwürfe, freigegebene und eingebaute Personas gibt es eine Vorschau mit erfundenen Situationen: Vorlagentexte mit Eröffnungssatz, der vollständige Prompt und optional ein KI-Beispiel, das dieselben Prüfungen durchläuft wie jeder Text für Lernende.
* **Interview-Richtlinie der Website (P-31):** Interviews lassen sich für die ganze Website schalten. Dazu kommen erlaubte Fragetypen, ein Mindestabstand zwischen zwei Fragen und eine Obergrenze pro Person und Woche über alle Aktivitäten. Alle Werte gelten als Obergrenzen für jede Aktivität.
* **Kostengrenzen (ADR-08):** Ein Kostenbuch ohne Personenbezug zählt jeden Aufruf, auch Übersetzungen und Vorschauen. Es gibt eine Höchstzahl an Aufrufen pro Stunde und ein Token-Budget pro Tag; fehlende Tokenzahlen werden geschätzt. Der aktuelle Verbrauch steht auf der Einstellungsseite.
* **Kurszweige (L-19, P-43):** Kategorien samt Unterkategorien können Quelle eines Kursverbunds sein. Beim Speichern wird das Recht in der Kategorie geprüft, bei jeder Anfrage die aktive Einschreibung. Höchstens 20 Kurse pro Person.
* **Core-KI-Protokolle (P-26, ADR-06):** technisch geprüft für 4.5 bis 5.3 und dokumentiert in `docs/adr/ADR-AI-02.md`, einschließlich Löschung über das Datenregister von Moodle. Die rechtliche Bewertung bleibt offen.
* **HTTP-Lasttest (P-39):** neuer k6-Plan mit echten Anmeldungen und dem AJAX-Endpunkt: Plateau, Zustandswechsel und Race auf eine Person, danach eine Prüfung auf dem Server. Workflow `load-k6.yml`.
* **Tests:**
  * 305 PHPUnit-Tests (vorher 172) auf Moodle 4.5 bis 5.3 mit PostgreSQL und MariaDB;
  * Line Coverage 89,4 %, jetzt einschließlich Backup und `lib.php`; CI-Untergrenze von 50 auf 80 % angehoben;
  * Property-Test der Regeln mit 2000 zufälligen Zuständen;
  * Restore einer 4.5-Sicherung auf einer anderen 5.3-Website;
  * Moodle 4.5 mit PHP 8.1 und Moodle 5.0 mit PHP 8.2 zur Laufzeit getestet;
  * Behat mit 13 Szenarien auf allen fünf Versionen;
  * Upgrade einer echten 0.1.0-Datenbank auf 0.3.0 mit Schemaabgleich.
* **Unabhängiger Review (zweiter Prüfer), alle Befunde behoben und mit Tests abgesichert:**
  * Eine Antwort, in der ein Block in einer anderen Sprache stand, umging Sprach- und Aussagenprüfung. Jetzt wird die Sprache je Block bestimmt und jeder Block in allen Sprachen mit Phrasenlisten geprüft. Ein unübersetzter Block nach dem Übersetzungsschritt wird abgelehnt.
  * „erfolgreich“, „geschafft“, „successfully“ galten als bloße Erledigung, auch nach einem Fehlschlag. Jetzt sind sie eine eigene Aussage, die nur bei Erfolgen erlaubt ist.
  * Neue Aussagen: Zeitangaben in Worten („in zwei Tagen“, „morgen“) nur, wo die Regel ein Datum hat; „steht dir jetzt zur Verfügung“, „you can start it now“ bei gesperrten Aktivitäten.
  * Ein Platzhalter eines anderen Blocks war erlaubt und konnte dessen Fakten falsch zuordnen. Jetzt nur noch eigene Platzhalter.
  * Abgelehnt werden jetzt auch E-Mail-Adressen, bloße Domains und Ziffern anderer Schriften.
  * Ein erreichtes Kostenlimit machte jeden zwischengespeicherten KI-Text ungültig. Jetzt gehört das Limit nicht mehr zum Fingerprint, KI-Texte bleiben gültig. Nach einem Limit oder einem Provider-Ausfall gilt die Vorlage nur 15 Minuten, danach wird KI erneut versucht.
  * Aufrufe werden vor dem Start im Kostenbuch reserviert, damit gleichzeitige Anfragen das Limit nicht gemeinsam überschreiten.
  * Kursverbund und Kurszweige speichern jetzt, wessen Rechte sie tragen, und prüfen diese Rechte bei jeder Auswertung erneut, auch gegen Verbote auf Kursebene (P-43). Speichert eine weitere Lehrperson die Einstellungen, bleiben Quellen erhalten, die sie selbst nicht verwalten darf.
  * Antworten auf Fragen, die die Interview-Richtlinie nicht mehr erlaubt, wirken nicht mehr. Eine Frage behält beim Bearbeiten ihren Typ.
  * Eigene Sprachanpassungen (`tool_customlang`) machen den Cache ungültig.
  * Der Lasttest prüfte das Race nicht wirklich, und die Zustandswechsel waren oft keine. Beides ist behoben; der Seed-Schutz gilt jetzt auch bei Debugging.
* **Aufgeräumt:**
  * Die Template-Reste `tests/playwright/`, `playwright.yml` (in 0.3.1 neu geschrieben), `load-jmeter.yml` und die alten Lastskripte sind entfernt. Sie verwiesen auf fremde Plugins und wären in der CI gescheitert. Die Pfade stehen in `db/removed_files.txt`.
  * `seed_load.php` und `tools/bench.php` laufen auch auf Moodle 4.5.

## 0.2.1 (beta)

* Unterstützt Moodle 4.5, 5.0, 5.1, 5.2 und 5.3 (vorher nur 5.3)
* AMD-Panel für Moodle 4.5 bis 5.2 mit demselben Funktionsumfang wie das React-Panel (React erst ab 5.3)
* Größere Klickflächen für Auswahlfelder (WCAG 2.5.8), durch axe auf Moodle 4.5 gefunden
* Kompatibilität: Modul-Feature-Konstante nur ab 5.x entfernt, KI-Kontextprüfung nur wo vorhanden, Tests mit PHPUnit 9.6 und 11
* CI: MariaDB-Service prüft die Bereitschaft mit `healthcheck.sh --connect --innodb_initialized` statt `mysqladmin ping` (das MariaDB-11.4-Image enthält `mysqladmin` nicht mehr)

## 0.2.0 (beta)

* Inline-Anzeige auf der Kursseite wie ein Text- und Medienfeld, mehrere Instanzen je Kurs
* Vollständiges Zustandsmodell: Nutzung, Verfügbarkeit mit AND/OR/NOT, Sperrgrund, Versuche, Abgaben, Kursabschluss
* Adapter-Subplugins `slcmod_*` mit `slcmod_assign` und `slcmod_quiz`
* Abschnitts-Scope mit verschachtelten Subsektionen, Kursverbünde mit Rechteprüfung je Quellkurs
* Standort-Personas mit Versionen, Freigabe und Widerruf; Lernende wählen aus den angebotenen Personas
* Interviewfragen (Multiple Choice, Freitext, Schieberegler, semantisches Differenzial) mit sichtbarer Wirkung
* Eigene Regeln mit Cooldown und Wochenlimit, Vorschau mit Beispieldaten, selektiver Export/Import
* Gruppenmodus (geschlossen, offen) mit getrennten Opt-ins für Freigabe und Namensnennung
* Debug-Export nur für Standortadmins mit Audit-Log, zeitlich begrenzte Entscheidungsprotokolle, Aufbewahrungsfristen
* Sicherheit: `require_login` in allen Endpunkten, Prüfung jedes Links vor Auslieferung, Widerrufs-Recheck, Compare-and-Swap im Cache
* KI: eigene Capability `mod/sideline:useai`, kein eigenes Placement (ADR-AI-01)
* Upgrade von 0.1.0 ohne Datenverlust

## 0.1.0 – MVP (alpha)

* Regelbasierte Kommentare, KI-Phrasierung mit Pseudonymisierung und Fallback, Einwilligung, Privacy API, Backup/Restore der Konfiguration
