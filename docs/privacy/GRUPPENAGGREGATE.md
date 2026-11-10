# Gruppenmodus: Ereignismatrix, Freigabekreis und Schutz der Aggregate

Stand 0.4.3 (09.11.2026). Bezug: Issues #6, #7, #9, #12; L-G02 bis L-G08, P-G01 bis P-G06, N-02, N-04.

## 1. Ereignismatrix (#6) – zur fachlichen Freigabe

Die Ereignisarten und das Zeitfenster sind je Instanz einstellbar („Ereignisse, die erzählt werden dürfen“, „Zeitraum der Gruppenaussagen“). Voreinstellung ist „abgeschlossen“ und eine Woche, wie bisher. Erzählt wird je Aktivität höchstens die stärkste Ereignisart in der Reihenfolge bestanden, Ergebnis verbessert, abgeschlossen, darin gearbeitet, geöffnet, und je Kommentar höchstens eine Gruppenaussage.

| Ereignisart | Datenquelle (nur lesend) | Wann zählt sie | Aussage (Beispiel) | Benannt | Anonym | Begründung |
|---|---|---|---|---|---|---|
| Geöffnet (`viewed`) – **Zugang** | `course_modules_viewed` (Kern) | Aktivität mindestens einmal aufgerufen | „Einige andere aus deiner Gruppe haben kürzlich ‚Einheit 1‘ geöffnet.“ | ja | ja | positiv, sagt nichts über Leistung |
| Darin gearbeitet (`active`) – **Nutzung**, neu in 0.4.3 | `logstore_standard_log`: Ereignisse der Person selbst in der Aktivität mit `crud` *c* oder *u* und Bildungsebene *teilnehmend* (Abgabe, Beitrag, Versuch, Antwort); nie als anonym protokollierte Ereignisse (anonymes Feedback, Abgabe bei anonymer Bewertung) und nie Handlungen einer anderen Person, die als sie angemeldet ist | mindestens eine eigene Änderung im Zeitfenster | „Sam und Kim aus deiner Gruppe haben kürzlich ‚Wahl‘ bearbeitet.“ | ja | ja | positiv; sagt, *dass* jemand arbeitet, nicht *was* oder *wie gut*. Lesende Zugriffe, Lehrendenhandlungen und Ereignisse anderer über die Person zählen nicht |
| Abgeschlossen (`completed`) | `course_modules_completion`, *complete* oder *complete pass* | Abschluss in Moodle | „… abgeschlossen.“ | ja | ja (Standard) | wie bisher |
| Bestanden (`passed`) | `course_modules_completion`, *complete pass* | Abschluss mit Bestehensnote | „… bestanden.“ | ja | ja | positiv; nur bei echtem Bestehen |
| Ergebnis verbessert (`improved`) – **Leistungsänderung**, neu in 0.4.3 | `grade_grades_history` der Bewertung der Aktivität (Itemnummer 0), nur wenn der Kurs Lernenden Bewertungen zeigt und Bewertungsaspekt und Bewertung der Person jetzt sichtbar sind | eine Bewertung im Zeitfenster liegt über jeder früheren Bewertung derselben Person (neue Bestleistung) | „Einige andere aus deiner Gruppe haben kürzlich ‚Essay‘ mit verbessertem Ergebnis bearbeitet.“ | **nie** | ja | positiv, aber eine Verbesserung sagt auch, dass es vorher schlechter war. Darum nur anonym, mit Mindestgröße und Band, nie mit Note, Punkten, Differenz oder Rang. Die erste Bewertung, eine erneut gespeicherte gleiche und eine Bewertung unter einer früheren Bestleistung sind keine Verbesserung; verborgene Bewertungen zählen nie |
| Nicht bestanden, Fehlversuch | *complete fail* | – | – | **nie** | **nie** | negativ, beschämend (L-G04) |
| Noten, Punkte, Rang, verschlechtertes Ergebnis | Bewertungen | – | – | **nie** | **nie** | Leistungsdaten anderer; eine Verschlechterung ist nie ein Ereignis |
| Freischaltung durch Voraussetzungen anderer | Verfügbarkeit | – | – | **nie** | **nie** | Freischaltung folgt oft aus Ergebnissen; ihre Bedingungen sind Lehrendendaten; Prüfung je Person zu teuer |
| Interviewantworten | `sideline_answer` | – | – | **nie** | **nie** | privat (P-17) |
| **Unbekannte Datenlage** | Standardprotokoll aus (`tool_log` ohne `logstore_standard`) oder Bewertungsverlauf aus (`disablegradehistory`) | – | keine Aussage; Einstellungsformular warnt „Für diese Ereignisse hat die Website keine Daten“; Protokoll (Trace) führt sie als `groupunknown` | – | – | „keine Daten“ ist nicht „niemand hat es getan“; alte Zeilen eines abgeschalteten Protokolls werden nicht verwendet |

**Zeitfenster:** 1, 3, 7 (Standard), 14 oder 28 Tage. Benannte Aussagen betreffen das Fenster bis jetzt, anonyme ein gleich langes Fenster, das mit dem Beginn des heutigen Tages endet (tagesstabil, Abschnitt 3). Ein längeres Fenster und eine weitere Ereignisart sagen mehr über dieselben Personen: Beides erhöht die Gruppenversion, und alle Teilenden entscheiden neu (`instance_config::apply_version_bumps`). Ein kürzeres Fenster und weniger Ereignisarten gelten sofort. Wird eine eingestellte, bisher unbekannte Art später bekannt (Protokoll oder Bewertungsverlauf eingeschaltet), gilt das nicht als neue Art (Hinweis in E-6).

Aus Ereignissen anderer wird nie eine Leistungsentscheidung über die empfangende Person abgeleitet. Gruppenaussagen gehen nur in die Kategorie Orientierung ein. Die KI bekommt die Ereignisart als neutralen Schlüssel (`worked_on_the_activity`, `improved_a_result_in_the_activity`); die Antwortprüfung lässt „erledigt“ und „erfolgreich“ nur bei Abschluss und Bestehen zu, „erfolgreich“ zusätzlich bei Verbesserung.

Tests: `group_events_test` (Nutzung aus dem Protokoll, nur eigene ändernde Ereignisse, keine anonymen und keine „angemeldet als“, benannte Nutzung nur mit Namensfreigabe und nach der letzten Änderung, Verbesserung nur anonym und nur als neue Bestleistung, nie bei Verschlechterung, Wiederholung oder erster Bewertung, nie bei verborgener Bewertung, Zeitfenster für beide Arten, unbekannte Datenlage, offener Modus mit anderer Gruppe, ganze Gruppe, Versionssprung bei neuer Art und längerem Fenster), `backup_restore_test` und `config_transfer_test` (Arten und Fenster reisen mit), `group_privacy_test::test_event_types_are_configurable_and_failures_are_never_told`, `review_findings_test`; Behat `group_events.feature` (echte Antwort in einer Abstimmung mit Namen, Verbesserungen dreier Personen anonym trotz Namensfreigabe, abgeschalteter Bewertungsverlauf im Formular und ohne Aussage).

**Freigabe:** Die Matrix ist von der Produktverantwortung und der oder dem DSB freizugeben (Entscheidungsvorlage E-6 in `ENTSCHEIDUNGEN.md`, D4 in `DATENSCHUTZ.md`). Bis dahin ist die Voreinstellung „abgeschlossen“ die einzige Ereignisart, die ohne aktive Einstellung genutzt wird.

## 2. Freigabekreis und erneute Entscheidung (#9)

Eine Freigabe gilt für den **Freigabekreis**, also für die Gruppen, über die sie etwas sagt. Der Kreis ist die Menge der zugelassenen Gruppen, deren Mitgliedschaft für Mitglieder sichtbar ist („m“). Im offenen Modus kommen die zugelassenen Gruppen hinzu, die für alle sichtbar sind („p“). Er wird je Instanz gespeichert (`groupcircle`).

`instance_config::refresh_group_circle()` vergleicht nach jeder Änderung den neuen Kreis mit dem gespeicherten. **Wächst** er, wird die Gruppenversion erhöht: Alle bisherigen Freigaben gelten dann nicht mehr, und die Lernenden sehen „Die Informationen zur Freigabe haben sich geändert. Bitte entscheide neu.“. Ein **schrumpfender** Kreis gilt sofort und ohne neue Entscheidung. Dasselbe gilt für neue Ereignisarten.

| Änderungspfad | Auslöser | Test |
|---|---|---|
| Formular (Gruppen, Gruppierungen, Modus, Stufe, Mindestgröße, Ereignisarten) | `sideline_save_relations`, `apply_version_bumps` | `group_privacy_test::test_adding_a_group_asks_sharing_learners_again_removing_one_does_not`, `test_a_new_event_type_asks_again` |
| Gruppierung bekommt eine Gruppe | Observer `grouping_group_assigned` | `test_a_grouping_that_gets_a_new_group_asks_again` |
| Neue Gruppe, wenn alle Gruppen zugelassen sind | Observer `group_created` | `test_a_new_group_when_all_groups_are_admitted_asks_again` |
| Sichtbarkeit einer Gruppe geändert | Observer `group_updated` | Kreis enthält die Sichtbarkeitsklasse |
| Import | `config_transfer::apply` | `config_transfer_test` |
| Wiederherstellung | `restore_sideline_stepslib::after_restore` | – (Kreis wird Referenz; nicht wiederherstellbare Gruppen schalten das Teilen ab) |
| Änderung während einer KI-Anfrage | Kontributoren-Recheck, neue Berechnung | `test_a_change_during_ai_generation_delivers_no_statement_of_the_old_circle` |

Neue Mitglieder einer zugelassenen Gruppe gelten **nicht** als Erweiterung. Die Freigabe lautet „mit meiner Gruppe“, und die Mitgliedschaft verwaltet die Lehrperson (dokumentierte Entscheidung).

## 3. Risikoanalyse und Schutzkonzept gegen Re-Identifikation (#12)

Angreifende Person ist eine empfangende Lernende oder ein empfangender Lernender, die oder der mehr über einzelne andere erfahren will, auch mit Vorwissen (wer in der Gruppe ist, wer wann am Rechner saß) und über viele Tage. Lehrende empfangen keine Gruppenaussagen; eine Lehrperson kann aber Gruppen zuschneiden und eine Person einschleusen (Zeile „Zugeschnittene Gruppe“).

| Angriff | Beispiel | Schutz (Stand 0.4.3) | Restrisiko (Register unten) |
|---|---|---|---|
| Kleine Gruppe | k = 2, die empfangende Person kennt eine der beiden | Mindestgröße **3** (nicht konfigurierbar darunter; Upgrade hebt 2 auf 3) | R1 |
| Exakte Anzahl | „4 haben abgeschlossen“, gestern „3“ | **keine Zahl**, nur Bänder: einige (3–5), mehrere (6–11), viele (≥ 12) | R2 |
| Sequenzielle Abfragen am selben Tag | zweimal am Tag nachsehen, Differenz = die Person, die gerade fertig wurde | anonymes Zeitfenster **endet am Tagesbeginn**; wer am Tag einmal gezählt wurde, bleibt für den Tag gezählt (`sideline_daycount`), auch wenn Moodle die Erledigung neu abstempelt; wer das Teilen beendet, widerruft, Gruppe oder Kurs verlässt, fällt sofort heraus (#30) | R3 |
| Historischer Vergleich über Tage | Band gestern „einige“, heute „mehrere“ | Bänder, ein Fenster je Instanz, keine Zeitpunkte | R2, R3 |
| Ganze Gruppe | empfangende Person und drei andere, „einige andere haben …“ | keine anonyme Aussage, die **alle anderen Mitglieder** umfassen würde (gezählt werden alle Mitglieder, nicht nur teilende) | R4 |
| Zugeschnittene Gruppe | Lehrperson bildet eine Gruppe aus einer eingeschleusten Person und vier Zielpersonen | Mindestgröße und Ganze-Gruppe-Regel gelten für jede Gruppe; neue Gruppen im Kreis verlangen neue Entscheidungen (Abschnitt 2); die eingeschleuste Person erfährt höchstens „einige von vier“ | R4, R9 |
| Überlappende Gruppen | Person in G1 und G2, Differenz der Aussagen | **höchstens eine Gruppenaussage** je Kommentar; nur Bänder; Tageszählung je Gruppe | R5 |
| Verschiedene Sichten | offen/geschlossen, verschiedene Empfänger | Sichtbarkeit der Gruppenmitgliedschaft wird beachtet (`OWN`/`NONE` → nichts); offene Gruppen nur, wenn für alle sichtbar | – |
| Zeitfenster (neu einstellbar) | ein Tag statt einer Woche: „einige haben gestern …“ | Fenster nur aus fester Liste (1, 3, 7, 14, 28 Tage); anonym endet es am Tagesbeginn; ein längeres Fenster verlangt neue Entscheidungen | R6 |
| Ereignisart Nutzung (`active`, neu) | „einige haben in ‚Forum‘ gearbeitet“ – wer hat gepostet? | nur eigene ändernde Ereignisse der Person; anonym mit Mindestgröße und Band; benannt nur mit Namensfreigabe | R7 |
| Ereignisart Verbesserung (`improved`, neu) | „einige haben ‚Essay‘ verbessert“ heißt: sie waren vorher schlechter | **nie benannt**, nie Note, Differenz oder Rang; erste Bewertung zählt nicht; Mindestgröße, Band, Ganze-Gruppe-Regel | R8 |
| Kombination der Arten | Person ist heute benannt als „bestanden“, gestern anonym unter „verbessert“ | je Aktivität nur die stärkste Art, je Kommentar eine Aussage; die anonyme Zählung schließt benannte Personen ein | R8 |
| Unbekannte Datenlage | Protokoll aus: „niemand hat gearbeitet“ | Art wird nicht erzählt, Formular warnt, Trace führt `groupunknown` | – |
| Aktivitäts- oder Zeitdaten | seltene Aktivität, genaue Uhrzeit | keine Zeitpunkte, nur „kürzlich“; nur Aktivitäten, die die empfangende Person sieht | R7 |
| Historische Aussagen im Cache | Gruppe oder Rechte geändert, alter Text | Fingerabdruck enthält die Gruppenfakten, Observer markieren den Cache, Widerruf löscht die Instanz-Caches | – |
| Name und Gruppenname | Freigabe gilt nicht mehr | Namen nur mit aktueller Namens-Freigabe für den aktuellen Kreis (Gruppenversion) | – |
| KI | Modell „errät“ Namen oder Anzahl | Tokens statt Namen, Band statt Zahl; Antwortprüfung lehnt Zahlen, fremde Tokens und unzulässige Erfolgsbehauptungen ab | – |

Namensaussagen betreffen nur Personen, die der Nennung zugestimmt haben. Ihr Zeitfenster endet „jetzt“, denn die Nennung ist gewollt. Für sie gelten weder Mindestgröße noch Bänder; Verbesserungen werden auch dann nicht benannt.

Tests: `group_privacy_test` (k = 2 und k = 3, Bänder, Tagesstabilität auch bei neu abgestempelter Erledigung, ganze Gruppe, überlappende Gruppen, Sichtbarkeitswechsel, N+1), `group_events_test` (neue Arten, Fenster, unbekannte Datenlage, ganze Gruppe bei Verbesserungen), `day_count_test` und `group_mode_test`. Unabhängiges Review: `docs/security/REVIEW_0.4.1.md`.

### Restrisikoregister

Bewertung der Entwicklung; die Annahme oder Ablehnung ist Sache der oder des DSB (Entscheidungsvorlage E-12 in `ENTSCHEIDUNGEN.md`). Eintrittswahrscheinlichkeit und Schwere: gering, mittel, hoch.

| Nr. | Restrisiko | Voraussetzung beim Angreifer | Wahrsch. | Schwere | Weitere mögliche Maßnahme (nicht umgesetzt) |
|---|---|---|---|---|---|
| R1 | Bei genau drei Gezählten und Wissen über zwei von ihnen ist die dritte Person bestimmt | Vorwissen über zwei Personen | mittel | gering (positives Ereignis) | Mindestgröße 5 als Voreinstellung |
| R2 | Bandwechsel an einer Grenze (5→6, 11→12) zwischen zwei Tagen verrät, dass genau eine Person hinzukam | Beobachtung über Tage und Vorwissen, wer es war | gering | gering | Zufälliges Verzögern von Bandwechseln (verfälscht Aussagen) |
| R3 | Der Tageswechsel zeigt die Änderung des Vortags; wer gestern etwas tat, steckt im neuen Band | Vorwissen, wer gestern arbeitete | mittel | gering | längere Glättung über mehrere Tage |
| R4 | In einer Gruppe mit wenigen Mitgliedern ohne das Ereignis lässt sich die Menge der Übrigen eingrenzen | Kenntnis der Gruppe und einzelner Personen | gering | gering | Mindestabstand zur Gruppengröße größer als 1 |
| R5 | Sehr kleine Schnittmengen überlappender Gruppen | mehrere Gruppen, Beobachtung über Tage | gering | gering | Aussagen nur über disjunkte Gruppen |
| R6 | Ein kurzes Fenster (1 Tag) verortet ein Ereignis zeitlich genauer | Lehrperson stellt kurzes Fenster ein | mittel | gering | Mindestfenster 3 Tage |
| R7 | Nutzung verrät bei seltenen Aktivitäten (Einreichung, Forum) Aktivität, die eine Person lieber nicht zeigen würde | Teilen eingeschaltet; Vorwissen | gering | gering bis mittel | Nutzung nur für ausgewählte Aktivitätsarten |
| R8 | Verbesserung impliziert ein früheres schwächeres Ergebnis; mit Vorwissen über einzelne Gezählte oder einer benannten Bestehensaussage zur selben Aktivität ableitbar | Vorwissen, Beobachtung über Tage | gering | mittel (Leistungsbezug) | Verbesserung nur ab Band „mehrere“ (≥ 6) |
| R9 | Lehrperson schneidet Gruppen zu und schleust eine Person ein | Rolle mit Gruppenverwaltung | gering | gering | Freigabe gilt nur für Gruppen, die beim Opt-in bestanden |

## 4. Kosten (#7)

Je Abruf laufen eine Abfrage der Gruppen der Person, eine der Kursgruppen und je Ereignisart eine Abfrage. Diese liefert nur teilende, zustimmende, aktiv eingeschriebene Mitglieder mit Ereignis im Fenster. Es gibt keine Abfrage je Gruppe oder je Mitglied, und es werden nicht alle Kursteilnehmenden geladen. Test `group_privacy_test::test_queries_do_not_grow_with_groups_or_members`: 1 Gruppe und 3 Mitglieder gegen 11 Gruppen und 53 Mitglieder, höchstens zwei Lesezugriffe Unterschied.

Ab 0.4.3 kostet jede Ereignisart genau einen Lesezugriff (vorher drei auf PostgreSQL, weil ein Recordset dort ein Cursor ist). Die Abfragen für „darin gearbeitet“ und „Ergebnis verbessert“ treffen passende Kernindizes (`logstore_standard_log`: `userid, contextlevel, contextinstanceid, crud, edulevel, timecreated`; `grade_grades_history`: `userid, itemid, timemodified`). Gemessen mit allen fünf Arten (`tools/bench_groups.php --events=viewed,active,completed,passed,improved`, jede Person mit 20 lesenden Protokolleinträgen): höchstens 11 Lesezugriffe warm (Budget 12) und höchstens 170 ms (PostgreSQL 17) bzw. 165 ms (MariaDB 11.4) kalt (Budget 300) bei 5000 Personen, auf frischen CI-Sites (lokal).

Budgets und Messung: `tests/load/README.md`, Abschnitt „Gruppenpfad“.
