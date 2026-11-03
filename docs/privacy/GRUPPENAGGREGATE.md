# Gruppenmodus: Ereignismatrix, Freigabekreis und Schutz der Aggregate

Stand 0.4.1. Bezug: Issues #6, #7, #9, #12; L-G02 bis L-G08, P-G01 bis P-G06, N-02, N-04.

## 1. Ereignismatrix (#6) – zur fachlichen Freigabe

Die Ereignisarten sind je Instanz einstellbar (Feld „Ereignisse, die erzählt werden dürfen“). Voreinstellung ist „abgeschlossen“, wie bisher.

| Ereignisart | Datenquelle | Wann zählt sie | Aussage (Beispiel) | Zulässig | Begründung |
|---|---|---|---|---|---|
| Geöffnet (`viewed`) | `course_modules_viewed` (Kern) | Aktivität mindestens einmal geöffnet | „Einige andere aus deiner Gruppe haben kürzlich ‚Einheit 1‘ geöffnet.“ | ja, einstellbar | positiv, beschämt niemanden, sagt nichts über Leistung |
| Abgeschlossen (`completed`) | `course_modules_completion`, Status *complete* oder *complete pass* | Abschluss in Moodle | „… abgeschlossen.“ | ja, Standard | wie bisher |
| Bestanden (`passed`) | `course_modules_completion`, Status *complete pass* | Abschluss mit Bestehensnote | „… bestanden.“ | ja, einstellbar | positiv; nur bei echtem Bestehen, die KI darf nur dann „bestanden“ sagen |
| Nicht bestanden, Fehlversuch | *complete fail* | – | – | **nie** | negativ, beschämend (L-G04) |
| Noten, Punkte, Notenänderung | Gradebook | – | – | **nie** | Leistungsdaten anderer |
| Zugang, Freischaltung, Zeitfenster anderer | Verfügbarkeit | – | – | **nie** | sagt über andere nur indirekt etwas, Ableitungsrisiko |
| Interviewantworten | `sideline_answer` | – | – | **nie** | privat (P-17) |
| Unbekannter Status (keine Abschlussverfolgung) | – | – | keine Aussage | – | „unbekannt“ ist nicht „nicht erledigt“ |

Aus Ereignissen anderer wird nie eine Leistungsentscheidung über die empfangende Person abgeleitet. Gruppenaussagen gehen nur in die Kategorie Orientierung ein (Test `review_findings_test`, `group_privacy_test::test_event_types_are_configurable_and_failures_are_never_told`).

**Freigabe:** Die Matrix ist von der Produktverantwortung und der oder dem DSB freizugeben (D4 in `DATENSCHUTZ.md`). Bis dahin ist die Voreinstellung „abgeschlossen“ die einzige Ereignisart, die ohne aktive Einstellung genutzt wird.

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

| Angriff | Beispiel | Schutz in 0.4.0 | Restrisiko |
|---|---|---|---|
| Kleine Gruppe | k = 2, die empfangende Person kennt eine der beiden | Mindestgröße **3** (nicht konfigurierbar darunter; Upgrade hebt 2 auf 3) | bei k = 3 und zwei bekannten Personen ableitbar → akzeptiert nur mit Freigabe |
| Exakte Anzahl | „4 haben abgeschlossen“, gestern „3“ | **keine Zahl**, nur Bänder: einige (3–5), mehrere (6–11), viele (≥ 12) | Bandwechsel an der Grenze |
| Sequenzielle Abfragen | zweimal am Tag nachsehen, Differenz = die Person, die gerade fertig wurde | anonymes Zeitfenster **endet am Tagesbeginn**: innerhalb eines Tages dieselbe Aussage. Wer am Tag einmal gezählt wurde, bleibt für den Tag gezählt (Tagesschnappschuss, Cache `groupsnapshot`), auch wenn Moodle die Erledigung neu abstempelt (ab- und wieder angehakt) | Tageswechsel zeigt die Änderung des Vortags; wer das Teilen beendet oder das Ereignis verliert, fällt sofort heraus; nach einer Cache-Leerung wird neu gezählt |
| Ganze Gruppe | Gruppe aus der empfangenden Person und drei anderen, „einige andere haben abgeschlossen“ | keine anonyme Aussage, die **alle anderen Mitglieder** einer Gruppe umfassen würde (gezählt werden alle Mitglieder, nicht nur die teilenden, denn die Gruppengröße kennt die empfangende Person) | bei einer Person ohne Ereignis bleibt die Aussage über die übrigen; deren Zahl verrät das Band nicht genau |
| Überlappende Gruppen | Person in G1 und G2, Differenz der Aussagen | **höchstens eine Gruppenaussage** je Kommentar; nur Bänder | bei sehr kleinen Schnittmengen theoretisch ableitbar |
| Verschiedene Sichten | offen/geschlossen, verschiedene Empfänger | Sichtbarkeit der Gruppenmitgliedschaft wird beachtet (`OWN`/`NONE` → nichts) | – |
| Aktivitäts- oder Zeitdaten | seltene Aktivität, genaue Uhrzeit | keine Zeitpunkte, nur „kürzlich“; nur Aktivitäten, die die empfangende Person sieht | seltene Aktivität mit kleinem Band |
| Historische Aussagen im Cache | Gruppe oder Rechte geändert, alter Text | Fingerabdruck enthält die Gruppenfakten, Observer markieren den Cache, Widerruf löscht die Instanz-Caches | – |
| Name und Gruppenname | Freigabe gilt nicht mehr | Namen nur mit aktueller Namens-Freigabe für den aktuellen Kreis (Gruppenversion) | – |

Namensaussagen betreffen nur Personen, die der Nennung zugestimmt haben. Ihr Zeitfenster endet „jetzt“, denn die Nennung ist gewollt. Für sie gelten weder Mindestgröße noch Bänder.

Tests: `group_privacy_test` (k = 2 und k = 3, Bänder, Tagesstabilität auch bei neu abgestempelter Erledigung, ganze Gruppe, überlappende Gruppen, Sichtbarkeitswechsel, N+1) und `group_mode_test`. Unabhängiges Review: `docs/security/REVIEW_0.4.1.md`.

**Akzeptierte Restrisiken:** stehen in der Tabelle in der Spalte „Restrisiko“. Sie sind von der oder dem DSB zu bestätigen (D4).

## 4. Kosten (#7)

Je Abruf laufen eine Abfrage der Gruppen der Person, eine der Kursgruppen und je Ereignisart eine Abfrage. Diese liefert nur teilende, zustimmende, aktiv eingeschriebene Mitglieder mit Ereignis im Fenster. Es gibt keine Abfrage je Gruppe oder je Mitglied, und es werden nicht alle Kursteilnehmenden geladen. Test `group_privacy_test::test_queries_do_not_grow_with_groups_or_members`: 1 Gruppe und 3 Mitglieder gegen 11 Gruppen und 53 Mitglieder, höchstens zwei Lesezugriffe Unterschied.

Budgets und Messung: `tests/load/README.md`, Abschnitt „Gruppenpfad“.
