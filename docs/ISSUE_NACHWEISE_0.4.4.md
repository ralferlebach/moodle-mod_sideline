# Nachweise 0.4.4: GitHub-Läufe 115988086541 (Main-CI) und 115989969517 (`bench.yml`)

Stand: 10.11.2026. Version `2026111000`, Release `0.4.4`, Auslieferung `mod_sideline-2026111000.zip`. Kein Upgrade-Schritt.

Die Nachweise zu den Issues stehen weiter in `docs/ISSUE_NACHWEISE_0.4.3.md`; dieses Dokument behandelt nur die roten Jobs der beiden Läufe auf 0.4.3 (Commit `46871af`).

## 1. Main-CI 115988086541: zwei Upgrade-Zellen ab 0.4.1 (#31)

**Befund:** Grün waren alle acht Matrixzellen (PHPUnit, Behat, Races 9/9), Coverage, Frontend, Release-Artefakt, Stale files und sechs von acht Upgrade-Zellen. Rot waren die beiden Zellen „Upgrade from 0.4.1“ (4.5/MariaDB, 5.3/PostgreSQL) mit „FAIL the upgrade ran although db/caches.php of the old release is still there“, also der Test des Darüberkopierens, nicht das Upgrade selbst.

**Ursache:** `rsync -a` hat `version.php` nicht kopiert.

- `rsync` hält eine Datei mit gleicher Größe und gleicher Änderungszeit (in ganzen Sekunden) für unverändert.
- `version.php` von 0.4.1 und von 0.4.3 sind gleich lang (1423 Byte; `2026110300`/`2026110900`, `'0.4.1'`/`'0.4.3'`).
- `actions/checkout` und `git worktree add ../previous` liefen in derselben Sekunde (13:53:09), und moodle-plugin-ci übernimmt beim Spiegeln die Änderungszeit.

Moodle sah deshalb keine neue Version und meldete „No upgrade needed“ mit Exit 0, nach 0,7 s statt mehrerer Sekunden für ein echtes Upgrade (Zeitstempel im Log: Seed 13:55:40,6, FAIL 13:55:41,4). Die Upgrade-Prüfung des Plugins kam gar nicht zum Zug. Lokal war der Test grün, weil `upgrade.sh` mit `tar` kopierte, das jede Datei überschreibt.

**Nachgestellt:** Ablauf des Jobs lokal Schritt für Schritt: Klon des Repositorys, `git worktree add` auf `afabccc`, `moodle-plugin-ci install --plugin ./previous --no-init`, Site, Seed, `rsync -a`. Alle drei `version.php` hatten danach Größe 1423 und dieselbe Sekunde; nach `rsync -a` stand weiter `'0.4.1'` im Plugin. Mit `rsync -a --checksum` stand `'0.4.3'` darin. Das Upgrade brach mit Exit 1 ab und nannte `db/caches.php`. Nach dem Ersetzen des Verzeichnisses liefen Upgrade, Schemaprüfung und `upgrade_verify.php` grün („Upgrade verified (from 2026110300 0.4.1)“).

**Korrektur** (`.github/workflows/moodle-plugin-ci-main.yml`, Job `upgrade`):

- Darüberkopieren mit `rsync -a --checksum`.
- Danach prüft `cmp`, dass `version.php` die aktuelle ist. Ein solcher Fehler zeigt sich dann als „FAIL the current version.php was not copied“ statt als irreführendes „the upgrade ran“.
- Die Vollkopie in das geleerte Verzeichnis ist nicht betroffen.
- `tools/ci-local/upgrade.sh` kopiert jetzt ebenso mit `rsync --checksum` (mit `tar` als Rückfall), damit lokal und GitHub gleich laufen.

## 2. `bench.yml` 115989969517

### 2.1 MariaDB: Installation scheiterte

**Befund:** `install.php` brach mit „unicode … must be installed and enabled“ ab; alle Messschritte danach scheiterten mit.

**Ursache:** Der Dienst-Container `mariadb:11.4` legt `MARIADB_DATABASE` mit dem Server-Standard `latin1` an. `moodle-plugin-ci install` legt seine Datenbank selbst mit `utf8mb4` an, deshalb betraf es nur diesen Workflow.

**Korrektur:** Vor `install.php` stellt ein PHP-Einzeiler (mysqli) die Datenbank auf `utf8mb4_unicode_ci` um und bricht bei Fehlern ab.

### 2.2 PostgreSQL: Namensprüfung über dem Budget auf einer Website mit einer Million Einschreibungen (#24)

**Befund:** Ein Fall von 28 lag über dem Budget: `repeated` bei 5000 Einschreibungen, p99 241 ms bei einem Budget von 200 ms. Alle anderen lagen bei 113 bis 185 ms. Der ausgegebene Plan zeigte die Ursache: „Seq Scan on mdl_user_enrolments rows=1 032 200“, danach eine verschachtelte Schleife über `enrol` und `user`. PostgreSQL las also bei jeder Prüfung alle Einschreibungen der Website.

**Ursache:** Die Abfrage verband `user_enrolments` mit `enrol` und filterte auf `enrol.courseid`. Ob der Planer beim Kurs anfängt, hing von seiner Schätzung ab: lokal ja, auf GitHub nicht. Die Aussage in 0.4.3, „beide Datenbanken beginnen beim Kurs“, stimmte nur lokal.

**Korrektur** (`classes/local/ai/prompt_text_guard.php`):

- `course_name()` liest zuerst die Einschreibungsmethoden der Kurse (`enrol_ids()`, Index `courseid`).
- Danach fragt `name_query()` die Einschreibungen über deren IDs ab: `ue.enrolid IN (…)`, Index `enrolid, userid`, Nutzende über den Primärschlüssel.
- Die Begrenzung auf den Kurs steht damit in der Abfrage selbst, unabhängig von der Schätzung.
- Das sind zwei Abfragen statt einer und damit genau das Budget von zwei. Mehr als ein Block von Phrasen entsteht bei höchstens 500 Zeichen nicht: höchstens 662 Phrasen bei einer Blockgröße von 750.
- Ohne Einschreibungsmethode wird nichts abgefragt. Ein Datenbankfehler bei beiden Abfragen ergibt weiter `budget`, der Text bleibt dann auf der Website: Test `prompt_guard_test::test_a_database_error_keeps_the_text_on_the_site`, jetzt für beide Abfragen.

**Messung** (lokal, `bench_names.php --siteusers=1000000 --siteenrol`, rund 1,2 Millionen Nutzende und Einschreibungen in anderen Kursen, 10 Läufe):

| | Plan bei 20 000 Einschreibungen | p99 höchstens, je Kursgröße 100 / 5000 / 20 000 |
|---|---|---|
| PostgreSQL 17 | Index Only Scan `mdl_userenro_enruse_uix` (20 000 Zeilen), dann `mdl_user_id_pk` | 4,3 / 20,1 / 73,9 ms |
| MariaDB 11.4 | `ue` range über `mdl_userenro_enruse_uix` (20 000 Zeilen), `u` eq_ref über `PRIMARY` | 4,4 / 25,9 / 49,3 ms |

Die Zahl der gelesenen Einschreibungen entspricht genau der Kursgröße (100, 5000, 20 000), nicht der Website. Auf GitHub ist der neue Plan erst mit dem nächsten Lauf von `bench.yml` belegt.

## 3. Abnahme auf dem Checkout des ZIP

Alle Läufe vom 10./11.11.2026 im Container, auf dem GitHub-Checkout des entpackten ZIP `mod_sideline-2026111000.zip`, mit moodle-plugin-ci 4.5.11:

| Prüfung | Ergebnis |
|---|---|
| CI-Matrix (8 Zellen: 4.5/8.2/pgsql, 4.5/8.3/mariadb, 5.0/8.2/pgsql, 5.0/8.3/mariadb, 5.1/8.3/pgsql, 5.2/8.3/mariadb, 5.3/8.3/pgsql, 5.3/8.3/mariadb) | **8/8**, je 14 Schritte grün, Codechecker und phpdoc mit `--max-warnings 0` |
| PHPUnit | **500/500**, 29 900 Assertions, in jeder Zelle |
| Behat | **35/35 Szenarien, 538/538 Schritte** in jeder Zelle |
| Races | **9/9** in jeder Zelle |
| Upgrade (`upgrade.sh`, wie der Job, Darüberkopieren mit `rsync --checksum`) | **8/8:** „previous“ ist jetzt 0.4.3 (`46871af`), auf 4.5/pgsql und 5.3/mariadb je 32/32; 0.4.1 mit Darüberkopieren auf 4.5/mariadb und 5.3/pgsql je 34/34 (Abbruch mit `db/caches.php`, danach Upgrade); 0.4.0 je 32/32; 0.3.2 je 34/34 |
| Coverage | **90,21 %** (4200/4656), Untergrenze 80 % |
| Frontend | ESLint 0, `tsc` strict 0, Jest React 24/24, AMD 20/20, Bundles aktuell |
| Release-Artefakt, veraltete Dateien | 271 Einträge, keine Entwicklerwerkzeuge, Pflichtdateien vorhanden; kein Pfad aus `db/removed_files.txt` vorhanden |
| Namensprüfung auf frischer CI-Site (30 Läufe) | alle Fälle im Budget, p99 bei 20 000 Einschreibungen höchstens 39,3 ms (PostgreSQL) |
| Namensprüfung mit über einer Million weiterer Einschreibungen | siehe Abschnitt 2.2 |

**Ein weiterer Befund unterwegs:** Mit 0.4.3 als „previous“ schlugen die beiden Zellen zuerst mit 30/32 fehl. Grund war ein Fehler im Testwerkzeug, nicht im Plugin: `upgrade_seed.php` legte den KI-Eintrag wie vor 0.4.2 ohne Herkunftssatz an, während das Plugin ihn seit 0.4.2 beim Aufruf selbst schreibt. Ab einem Stand mit `sideline_aicall` schreibt der Seed jetzt beides wie das Plugin. Auf GitHub wäre dieser Fall im nächsten Lauf aufgetreten.

Nicht wiederholt, weil Oberfläche und Gruppenpfad unverändert sind: Playwright und k6. Die Läufe auf 0.4.3 (`ISSUE_NACHWEISE_0.4.3.md`) gelten weiter. Das ausgelieferte ZIP unterscheidet sich vom geprüften Checkout nur in Dokumentation unter `docs/`.
