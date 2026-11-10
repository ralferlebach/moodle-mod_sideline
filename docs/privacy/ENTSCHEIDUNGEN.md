# Entscheidungsvorlagen: was der Code nicht entscheiden kann

Stand 0.4.3 (09.11.2026). Bezug: Issues #6, #12, #14, #25, #28.

Diese Vorlagen sammeln die offenen Entscheidungen so, dass sie ohne Lektüre des Codes getroffen werden können: die Frage, die Optionen mit ihren Folgen, die technische Grundlage und die Empfehlung der Entwicklung. Sie sind **keine Freigabe im Code und keine Bedingung der CI**. Das Plugin läuft mit den Voreinstellungen, unabhängig davon, ob und wie entschieden wurde. Das Ergebnis wird im jeweiligen Issue festgehalten; das Feld „Entscheidung“ hier ist nur eine Hilfe zum Ausfüllen.

## E-6: Ereignismatrix des Gruppenmodus (#6, DoD 1)

**Frage:** Welche Ereignisse anderer Gruppenmitglieder darf der Coach erzählen, in welcher Form und über welchen Zeitraum?

**Grundlage:** `GRUPPENAGGREGATE.md`, Abschnitt 1 (Matrix mit Datenquellen), Tests `group_events_test`, Behat `group_events.feature`.

| Option | Inhalt | Folge |
|---|---|---|
| A (Empfehlung) | Matrix wie umgesetzt: geöffnet, darin gearbeitet, abgeschlossen, bestanden, Ergebnis verbessert (nur anonym); nie Fehlversuche, Noten, Verschlechterungen, Freischaltungen; Zeitfenster 1 bis 28 Tage, Standard 7; Voreinstellung nur „abgeschlossen“ | Lehrende wählen je Instanz; jede neue Art und jedes längere Fenster verlangen neue Entscheidungen der Lernenden |
| B | wie A, aber ohne „Ergebnis verbessert“ | kein Leistungsbezug in Gruppenaussagen; die Einstellung wird entfernt oder auf der Website gesperrt (kleine Codeänderung) |
| C | wie A, aber „Ergebnis verbessert“ erst ab Band „mehrere“ (≥ 6) | geringeres Ableitungsrisiko (R8), seltener eine Aussage (kleine Codeänderung) |
| D | nur die drei Arten von 0.4.2 (geöffnet, abgeschlossen, bestanden) | AK 2 von #6 bewusst nicht umgesetzt; Code bleibt, Arten werden ausgeblendet |

Zuständig: Produktverantwortung; bei „Ergebnis verbessert“ und „darin gearbeitet“ zusammen mit der oder dem DSB (Leistungs- und Nutzungsbezug).

Hinweis zur unbekannten Datenlage: Die Entscheidung der Lernenden gilt für die eingestellten Ereignisarten. Schaltet die Administration später das Standardprotokoll oder den Bewertungsverlauf ein, wird eine bisher stumme, aber eingestellte Art ohne neue Entscheidung erzählt; die Art selbst ist nicht neu. Soll das als Erweiterung gelten, wäre die Menge der bekannten Arten je Instanz zu speichern und ihr Wechsel wie eine neue Art zu behandeln (kleine Codeänderung). Entscheidung: ☐ so lassen ☐ als Erweiterung behandeln

Entscheidung: ☐ A ☐ B ☐ C ☐ D ☐ andere: ______ Datum: ______ Name/Rolle: ______

## E-12: Restrisiken der Gruppenaggregate (#12, DoD 2)

**Frage:** Werden die Restrisiken R1 bis R9 hingenommen, oder soll eine der weiteren Maßnahmen umgesetzt werden?

**Grundlage:** `GRUPPENAGGREGATE.md`, Abschnitt 3 mit Restrisikoregister; Tests `group_privacy_test`, `group_events_test`, `day_count_test`.

| Nr. | Restrisiko (Kurzform) | Bewertung der Entwicklung | Annehmen? |
|---|---|---|---|
| R1 | k = 3 mit Vorwissen über zwei | hinnehmbar bei positiven Ereignissen; bei „verbessert“ siehe R8 | ☐ ja ☐ nein → Mindestgröße 5 |
| R2 | Bandwechsel an der Grenze | hinnehmbar | ☐ ja ☐ nein |
| R3 | Tageswechsel zeigt Vortagsänderung | hinnehmbar | ☐ ja ☐ nein → Glättung |
| R4 | kleine Restmenge der Gruppe | hinnehmbar | ☐ ja ☐ nein |
| R5 | kleine Schnittmengen überlappender Gruppen | hinnehmbar | ☐ ja ☐ nein |
| R6 | kurzes Fenster verortet zeitlich | hinnehmbar; Alternative Mindestfenster 3 Tage | ☐ ja ☐ nein |
| R7 | Nutzung seltener Aktivitäten | hinnehmbar mit Opt-in | ☐ ja ☐ nein |
| R8 | Verbesserung impliziert früheres schwächeres Ergebnis | vertretbar, weil nur anonym; Alternative C in E-6 | ☐ ja ☐ nein |
| R9 | zugeschnittene Gruppen durch Lehrende | hinnehmbar; Rolle ist vertrauenswürdig und protokolliert | ☐ ja ☐ nein |

Zuständig: DSB. Entscheidung: Datum: ______ Name/Rolle: ______

## E-14: Datenschutzrechtliche Grundlagen, Fristen und DSFA (#14, DoD 2 und 3)

**Grundlage:** `DATENSCHUTZ.md` (Datenregister, Abschnitt 5 Aufbewahrung, Abschnitt 7 Prozess), `cli/privacy_check.php`.

| Nr. | Frage | Optionen | Empfehlung der Entwicklung | Entscheidung |
|---|---|---|---|---|
| D1 | Rechtsgrundlage der Analyse | Einwilligung über das Gate (`consentrequired`) / Aufgabe der Einrichtung | Einwilligung, solange KI oder Gruppenmodus genutzt werden | |
| D2 | DSFA | erforderlich / nicht erforderlich (mit Begründung) | erforderlich, sobald KI-Anbieter außerhalb der Einrichtung oder Gruppenmodus genutzt werden | |
| D3a | Frist für Prompts und Antworten im KI-Kern (`airetentiondays`, ab 0.4.3) | 0 (unbegrenzt) / Tage | 90 Tage (Voreinstellung); kürzer, wenn kein Support-Bedarf. Die Frist leert Einträge in `ai_action_generate_text` wie ein Widerruf, hängt also an E-28: Fällt E-28 auf B, ist sie auf 0 zu setzen | |
| D3b | Frist Zustimmungsprotokoll (`consentlogretentiondays`) | 0 / Tage | Nachweispflicht der Einwilligung: so lange die Verarbeitung läuft plus Verjährung | |
| D3c | Frist Interviewantworten (`answerretentiondays`) | 0 / Tage | 365 (Voreinstellung) | |
| D3d | Einträge aus 0.4.1 ohne Instanz leeren (`cli/privacy_check.php --empty-unattributed`) | leeren / stehen lassen | leeren: ein Widerruf erreicht sie nicht | |
| D5 | Auftragsverarbeitung mit dem KI-Anbieter, Protokolle beim Anbieter, verkettbare Kennung `user` des Kerns | Vertrag, Konfiguration des Anbieters | Vertrag mit Löschfrist beim Anbieter; Anbieter ohne Training auf Eingaben | |

Nachweis auf dem Zielsystem (DoD 2): Ausgabe von `php mod/sideline/cli/privacy_check.php` nach dem ersten Lauf der geplanten Aufgabe, ohne FAIL, mit Datum ablegen.

Zuständig: DSB, Verwaltung (D5). Datum: ______ Name/Rolle: ______

## E-28: Schreibender Zugriff auf eine Kerntabelle (#28, AK 5)

**Frage:** Darf das Plugin bei Widerruf, Privacy-Löschung, Löschung der Instanz und Ablauf der Frist Prompt und Antwort seiner **eigenen**, nachweislich zugeordneten Einträge in `ai_action_generate_text` leeren?

**Grundlage:** `DATENSCHUTZ.md`, Abschnitt 3; `classes/local/ai/ai_records.php`; Tests `ai_records_test` (drei Aufrufer im selben Nutzerkontext, Frist, Altbestand).

| Option | Folge |
|---|---|
| A (Empfehlung) | Zugriff wie umgesetzt: nur die Felder, die auch die Privacy-API des Kerns leert, nur Einträge mit Herkunftssatz; Registerzeilen bleiben. Derselbe Zugriff dient der Frist `airetentiondays` (Voreinstellung 90 Tage, also ab Werk aktiv) und `--empty-unattributed` |
| B | kein Zugriff; der Widerruf leert nichts im Kern, nur Admins über `tool_dataprivacy` (leert alle Einträge des Nutzerkontexts, auch anderer Plugins); `airetentiondays` auf 0 |
| C | A bis zu einem Kern-Patch (Privacy-API je Eintrag), danach dessen Nutzung; Tracker-Issue anlegen |

Zuständig: Produktverantwortung, Betrieb. Entscheidung: ☐ A ☐ B ☐ C Datum: ______ Name/Rolle: ______

**Fachlicher Review (#28, DoD 4):** Datenschutz und Datenverlust durch das gezielte Leeren (eigene Einträge ja, fremde nie; Widerrufe unter 0.4.1 haben fremde Einträge unwiderruflich geleert, `DATENSCHUTZ.md`, Abschnitt 3 „Altbestand“). Geprüft von: ______ Datum: ______ Ergebnis: ☐ ohne Einwand ☐ mit Auflagen: ______

## E-25: Manueller Screenreader-Test (#25)

Keine Entscheidung, sondern eine Durchführung: `A11Y_SMOKE.md`, Abschnitt 3, mit NVDA und VoiceOver auf 5.3 und 4.5. Die automatische Annäherung (Abschnitt 2 dort) ersetzt sie nicht. Durchgeführt am: ______ von: ______
