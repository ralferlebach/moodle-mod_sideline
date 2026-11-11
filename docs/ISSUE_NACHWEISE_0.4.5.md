# Nachweise 0.4.5: `bench.yml` 116338059388 (#24)

Stand: 11.11.2026. Version `2026111100`, Release `0.4.5`, Auslieferung `mod_sideline-2026111100.zip`. Kein Upgrade-Schritt.

## 1. Befund

`bench.yml` auf 0.4.4 (Commit `a5b6525`):

- **MariaDB:** grün (die Umstellung auf `utf8mb4` aus 0.4.4 wirkt).
- **PostgreSQL:** grün in allen Schritten bis auf „Name check of free text on a site with a million users“.
- **Über dem Budget:** 4 von 28 Fällen, alle bei 20 000 Einschreibungen: `distinct`, `hyphens`, `letters` und `namelast`, p99 1167 bis 1207 ms (Budget 200 ms).
- **Unter dem Budget:** die Texte mit weniger Phrasen (`unicode`, `nearmiss`, `repeated`) bei rund 130 ms.

Der ausgegebene Plan zeigt die Ursache:

```
Limit rows=0 time=1178.39ms hit=5160 read=48480
  Merge Join rows=0 time=1178.39ms
    Index Scan on mdl_user_enrolments using mdl_userenro_use_ix  (Index auf userid)
    Index Scan on mdl_user using mdl_user_id_pk  read=37901
```

PostgreSQL lief beide Tabellen in der Reihenfolge der Nutzer-ID ab, also über die ganze Website. Bei 100 bis 5000 Einschreibungen wählte es den erwarteten Plan (Index `enrolid, userid`, dann Primärschlüssel; 4 bis 17 ms).

## 2. Ursache

Die Abfrage aus 0.4.4 verband `user_enrolments` und `user` per Join, und `record_exists_sql()` hängt `LIMIT 1` an. Der Planer schätzt die Namensbedingung (zweimal `IN` mit bis zu 662 Phrasen auf einem Ausdruck ohne Statistik) als wenig trennscharf. Er erwartet deshalb einen frühen Treffer und wählt den Plan mit den geringsten Anlaufkosten. Bei 20 000 Einschreibungen war das der Merge Join über die Indizes nach Nutzer-ID. Einen Treffer gibt es aber fast nie, also läuft der Merge Join bis zum Ende. 0.4.4 hatte die Begrenzung auf den Kurs in die Abfrage geschrieben, aber der Join ließ dem Planer weiter die Wahl der Reihenfolge.

## 3. Korrektur

`classes/local/ai/prompt_text_guard.php`, `name_query()`:

```sql
SELECT COUNT(1)
  FROM {user_enrolments} ue
 WHERE ue.enrolid IN (…Einschreibungsmethoden des Kurses…)
       AND (SELECT 1 FROM {user} u
             WHERE u.id = ue.userid AND u.deleted = 0
                   AND (LOWER(TRIM(u.firstname)) IN (…) OR LOWER(TRIM(u.lastname)) IN (…))) = 1
```

- **Kein Join mehr.** Die Person wird über eine skalare Unterabfrage gelesen. PostgreSQL zieht sie nicht in einen Join hoch (anders als `EXISTS`), und MariaDB führt sie als abhängige Unterabfrage aus. Beide lesen sie je Einschreibung über den Primärschlüssel. Die einzige Tabelle in `FROM` sind die Einschreibungen, begrenzt über `enrolid IN (…)`.
- **Kein `LIMIT 1` mehr.** `course_name()` zählt mit `get_field_sql()` statt `record_exists_sql()`, damit kein Plan auf einen frühen Treffer setzt. Die Zählung liest die Einschreibungen des Kurses vollständig, auch wenn früh ein Name passt. Das kostet bei einem Treffer etwas, ist aber nach oben durch die Kursgröße begrenzt.
- Weiter zwei Abfragen (Einschreibungsmethoden, Namen), höchstens ein Block von Phrasen.
- Bei einem Datenbankfehler wird nichts gesendet (`budget`). Test `prompt_guard_test::test_a_database_error_keeps_the_text_on_the_site` prüft das für beide Abfragen.

## 4. Messung

Lokal, `bench_names.php --siteusers … --siteenrol`, 10 Läufe je Text:

| | Website | Plan bei 20 000 Einschreibungen | p99 höchstens, Kursgröße 100 / 5000 / 20 000 |
|---|---|---|---|
| PostgreSQL 17 | 1,43 Mio. Nutzende, 1,43 Mio. Einschreibungen | `Aggregate` → `Index Only Scan` auf `mdl_userenro_enruse_uix`, je Zeile `Index Scan` auf `mdl_user_id_pk` | 4,9 / 20,7 / 78,8 ms |
| MariaDB 11.4 | 1,54 Mio. Nutzende, 1,20 Mio. Einschreibungen | `ue` range über `mdl_userenro_enruse_uix` (20 000 Zeilen), `u` eq_ref über `PRIMARY` | 4,3 / 28,7 / 76,1 ms |

Die Form des Plans folgt jetzt aus der Abfrage, nicht aus der Schätzung: Es gibt keinen Join, den der Planer umordnen könnte. Auf GitHub ist das mit dem nächsten Lauf von `bench.yml` zu bestätigen.

## 5. Abnahme auf dem Checkout des ZIP

Läufe vom 11.11.2026 im Container, auf dem GitHub-Checkout des entpackten ZIP `mod_sideline-2026111100.zip`, mit moodle-plugin-ci 4.5.11:

| Prüfung | Ergebnis |
|---|---|
| CI-Matrix (8 Zellen, 4.5 bis 5.3, PostgreSQL und MariaDB) | **8/8**, je 14 Schritte grün. In der Zelle 5.3/pgsql brach `grunt` einmal ab, weil moodle-plugin-ci sein eigenes Sicherungsverzeichnis unter `/tmp` nicht fand (Werkzeug, parallele Lanes). Der Schritt ist in derselben Zelle wiederholt und grün; derselbe Code war in den anderen sieben Zellen grün. |
| PHPUnit | **500/500**, 29 900 Assertions, in jeder Zelle |
| Behat | **35/35 Szenarien** in jeder Zelle |
| Races | **9/9** in jeder Zelle |
| Upgrade | **8/8**: „previous“ ist jetzt 0.4.4 (`a5b6525`), je 32/32; 0.4.1 mit Darüberkopieren je 34/34 (Abbruch mit `db/caches.php`); 0.4.0 je 32/32; 0.3.2 je 34/34 |
| Coverage | **90,18 %** (4199/4656) |
| Frontend | ESLint 0, `tsc` 0, Jest React 24/24, AMD 20/20, Bundles aktuell |
| Namensprüfung auf frischer CI-Site (30 Läufe) | alle Fälle im Budget; p99 bei 20 000 Einschreibungen höchstens 79,7 ms (PostgreSQL; die Zählung liest alle Einschreibungen des Kurses, 0.4.4 brach beim ersten Treffer ab) |

Playwright und k6 sind nicht wiederholt: Oberfläche und Gruppenpfad sind unverändert. Das ausgelieferte ZIP unterscheidet sich vom geprüften Checkout nur in Dokumentation unter `docs/`.
