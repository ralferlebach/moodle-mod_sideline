# Datenschutz: Datenregister, KI-Protokolle, Löschung und offene Entscheidungen

Stand 0.4.3 (09.11.2026). Bezug: Issues #3, #4, #14, #6, #12, #18, #24, #28, #30, #31; Lastenheft L-13, L-16, L-17, L-20; Pflichtenheft P-22, P-23, P-26, P-40, P-41, P-45 bis P-48, N-04.

Dieses Dokument ist die **technische Grundlage** für die fachliche Datenschutzprüfung. Es ersetzt sie nicht. Rechtsgrundlagen, Fristen und die DSFA-Entscheidung legt die oder der Datenschutzbeauftragte der Einrichtung fest. Die Stellen, an denen diese Entscheidung aussteht, sind mit **[Entscheidung DSB]** markiert.

## 1. Datenregister

| Tabelle | Inhalt | Personenbezug | Zweck | Empfänger | Aufbewahrung (Einstellung) | Widerruf / Löschung |
|---|---|---|---|---|---|---|
| `sideline_consent` | Zustimmungsstatus je Lernende:r und Instanz, Informationsversion | ja | Einwilligungsnachweis, Gate vor jeder Verarbeitung | niemand außer System | bis Löschung der Instanz | Widerruf jederzeit im Panel; Privacy-API löscht |
| `sideline_consent_log` | Append-only-Nachweis: Status, Art (Analyse, Teilen, Name), Version, Kontext, Zeit | ja | Rechenschaft | Admin (Privacy-Export) | `consentlogretentiondays`, 0 = unbegrenzt **[Entscheidung DSB]** | Privacy-API löscht |
| `sideline_cache` | Erzeugter Kommentartext je Person (enthält Vornamen, Aktivitätsnamen, ggf. Vornamen anderer Gruppenmitglieder mit Zustimmung) | ja | Leistung (P-27) | nur die Person selbst | `cacheretentiondays` (90) | Widerruf löscht sofort; Privacy-Löschung löscht **alle** Cachezeilen der Instanz (#14) |
| `sideline_pref` | gewählte Persona | ja | Präferenz | Person selbst | bis Löschung | Privacy-API |
| `sideline_answer` | Interviewantworten (Freitext nie ausgewertet, nie an KI) | ja | Wirkung auf Kommentar (P-17) | nur die Person selbst; nie Lehrende | `answerretentiondays`, 0 = unbegrenzt **[Entscheidung DSB]** | Löschen im Panel; Privacy-API |
| `sideline_rulehit` | wann eine eigene Regel gezeigt wurde | ja | Abkühlzeit | System | 365 Tage | Privacy-API |
| `sideline_publish` | Opt-in Teilen und Name, Version des Freigabekreises | ja | Gruppenmodus (L-G05) | System | bis Widerruf | Widerruf im Panel; Privacy-API |
| `sideline_trace` | Entscheidungsprotokoll nur bei aktiver Ablaufverfolgung: Zustände, Kandidaten, Auswahl, **ausgelieferter Text**, KI-Austausch mit Tokens (Prompt-Hash, validierte Tokentexte) | ja | Support, Auskunft (P-41) | nur Website-Admins über den Debug-Export | `traceretentiondays` (14), Ablaufverfolgung schaltet sich zum gesetzten Ende ab | Privacy-API |
| `sideline_debuglog` | Zugriffsprotokoll des Debug-Exports: Admin, Zweck, Zeitraum | ja (Admin, betroffene Person) | Zugriffsaudit | Admins | `auditretentiondays` (365) | Privacy-API anonymisiert den Admin |
| `sideline_aiusage` | KI-Kostenbuch: Instanz, Zweck, Erfolg, Tokens, Zeit, Abschluss | nein | Kostenobergrenzen (P-31) | Admin (Zusammenfassung) | 31 Tage | – |
| `sideline_daycount` (ab 0.4.2) | Wer heute in einer anonymen Gruppenaussage mitgezählt wurde: Instanz, Person, Gruppe, Aktivität, Ereignisart, Tag | ja (IDs) | Tagesstabilität der anonymen Aussage (#12, F7) | niemand; nur die Zählung selbst | nur der laufende Tag: ältere Zeilen löscht die nächste Aussage der Instanz und die geplante Aufgabe (`purge_cache`, täglich 03:17) | sofort bei Ende des Teilens, Widerruf, Verlassen oder Löschen der Gruppe, Verlassen des Kurses oder gesperrter Einschreibung, neuen Freigabebedingungen (höhere Gruppen- oder Einwilligungsversion, anderer Modus; aus Formular, Import und Gruppenänderungen), Ende des Gruppenmodus; Privacy-API löscht unabhängig vom Modus (#30) |
| `sideline_aicall` (ab 0.4.2) | Welche Einträge im KI-Kern eine Instanz für eine Person verursacht hat: Instanz, Person, ID in `ai_action_register`, Zeit | ja (IDs) | gezieltes Leeren genau dieser Einträge bei Widerruf (#28) | niemand | `airetentiondays` (90, ab 0.4.3): die geplante Aufgabe leert ältere Einträge im Kern und löscht die Zeilen; 0 = so lange wie der Eintrag im Kern **[Entscheidung DSB, D3a]** | Widerruf, Privacy-API, Löschen der Instanz leeren die Kerneinträge und löschen die Zeilen |
| `ai_action_register`, `ai_action_generate_text` (Moodle-Kern) | KI-Aktion mit Nutzer- und Kontext-ID, Prompt und Antwort | ja (IDs); Prompt/Antwort enthalten nur Tokens | Kernprotokoll des KI-Subsystems | Admins; KI-Anbieter erhält den Prompt | der Kern löscht nicht von selbst; die Einträge **des Plugins** leert ab 0.4.3 die geplante Aufgabe nach `airetentiondays` (Abschnitt 5); Einträge anderer Plugins: **[Entscheidung DSB, D3]** | Widerruf und Privacy-API des Plugins leeren Prompt und Antwort **nur der Aufrufe dieser Instanz** (`ai_records`, Test `ai_records_test`); die Privacy-API von `core_ai` leert alle Einträge im Nutzerkontext |
| `logstore_standard_log` (Kern, nur gelesen, ab 0.4.3) | eigene ändernde Ereignisse einer Person in einer Aktivität (Abgabe, Beitrag, Versuch) | ja | Ereignisart „darin gearbeitet“ des Gruppenmodus (#6) | in Aussagen nur als „hat gearbeitet“ bzw. Band, nur über teilende Personen | Kern (Protokollfrist der Website) | das Plugin speichert daraus nichts |
| `grade_grades_history` (Kern, nur gelesen, ab 0.4.3) | Bewertungsverlauf einer Aktivität | ja (Leistung) | Ereignisart „Ergebnis verbessert“, nur ob eine Bewertung höher ist als eine frühere | in Aussagen nur anonym als Band, nie Note oder Differenz | Kern | das Plugin speichert daraus nichts |

Das Plugin hält ab 0.4.2 **keine personenbezogenen Daten in Moodle-Caches (MUC)**. Die Cache-Definition `groupsnapshot` von 0.4.1 (Nutzer-IDs, zwei Tage) ist entfallen. Moodles Dateispeicher löscht abgelaufene Einträge erst beim nächsten Lesen, und tagesgebundene Schlüssel werden nie wieder gelesen; Nutzer-IDs wären auf dem Datenträger liegen geblieben. Deshalb:

- Ab 0.4.3 entfernt der Upgrade-Schritt `2026110900` die Verzeichnisse `mod_sideline_groupsnapshot` aus jedem Dateispeicher selbst: aus den Standardverzeichnissen (`cachedir`, `localcachedir`, `dataroot/cache`) und aus Dateispeichern mit eigenem Pfad in der Cache-Konfiguration. Damit hängt die Löschung nicht mehr am Leeren aller Caches durch Moodle (#31); ein Upgrade ohne Wartungsmodus (`--maintenance=false`) verweigert Moodle bei einem Release mit Änderungen unter `db/` ohnehin. Speicher mit eigener Lebensdauer (Redis, Memcached, APCu) lassen die Einträge nach den zwei Tagen der Definition verfallen.
- Ein Release, das über das alte Plugin-Verzeichnis kopiert wurde, behält entfernte Dateien, etwa `db/caches.php`, das die Definition wieder anmelden würde. Das Upgrade bricht dann mit einer Anleitung ab („Verzeichnis mod/sideline vollständig löschen und das neue Release an seine Stelle legen“), solange eine Datei aus `db/removed_files.txt` vorhanden ist (#31).
- `cli/privacy_check.php` prüft beides auf dem Zielsystem (Abschnitt 7).

In einer Tabelle ist die Löschung transaktional, auf jedem Knoten sofort wirksam und von der Privacy-API erfasst.

Lehrende haben keinen Zugriff auf persönliche Daten, Antworten, Zustimmungen oder Gruppenfreigaben anderer (L-16, P-40; Tests `access_security_test`, Behat „Teachers manage questions but never see answers“).

## 2. Was den Server verlässt (KI-Anbieter)

Nur der Prompt, den `prompt_builder` aus `pseudonymizer` zusammensetzt:

- Sicherheitsregeln, Persona-Stil, Zielsprache;
- je Baustein: Kategorie, Tokens statt Aktivitätsnamen, Daten, Personen und Gruppennamen (`[anonmod1]`, `[anondate1]`, `[anonpeer1]`, `[anongroup1]`), grobe Zeitkategorien statt Zeitpunkten, Fortschrittszahlen, bei Gruppen ein grobes Band („a_few_people“) statt einer Anzahl;
- bei eigenen Regeln der **Text der Lehrperson als „intent“** – nur wenn `prompt_text_guard` darin nichts findet (#4).

`prompt_text_guard` weist einen frei konfigurierten Text zurück, wenn er eines davon enthält: E-Mail-Adressen, Links und Domains, @-Handles, IP-Adressen, Telefonnummern, genaue Daten (numerisch, ISO, Monatsnamen in sieben Sprachen), Uhrzeiten, Zahlen ab drei Stellen, Kennungen wie „id 42“, fremde oder gefälschte Tokens, Markup, Anweisungen an das Modell (Prompt-Injection) oder **Vor- und Nachnamen aller in den Kurs eingeschriebenen Personen** und der lernenden Person selbst. Dann wird **nichts gesendet**; ausgeliefert wird der lokale Vorlagentext (statischer Fallback, Status `unsafe_input:<grund>`). Persona-Stile (von Admins, websiteweit) laufen durch dieselbe Prüfung, ohne Namensliste.

**Namensprüfung ohne Namensliste (Issue #24):** Die Namen der Kursteilnehmenden werden nicht in den Speicher geladen. `prompt_text_guard::course_name()` bildet aus dem Freitext alle Wortfolgen aus einem bis vier Wörtern und fragt die Datenbank, ob eine davon der Vor- oder Nachname einer in den Kurs eingeschriebenen Person ist (aktiv oder nicht, jede Rolle; Groß- und Kleinschreibung egal, auf MariaDB auch Akzente). Ein Text hat höchstens 500 Zeichen, also höchstens zwei Abfragen, unabhängig von der Kursgröße. Gemessen (`tools/bench_names.php`, Worst-Case-Text): bei 20 000 Einschreibungen zwei Abfragen, 70 ms (PostgreSQL) bzw. 121 ms (MariaDB), unter 1 MiB Spitzenspeicher je Prüfung. Bei einem Datenbankfehler wird **nichts gesendet** (Grund `budget`). Ab 0.4.3 gibt das Messskript den Ausführungsplan aus (so, wie die Prüfung die Abfrage stellt, mit `LIMIT 1`) und misst auch auf einer Website mit über einer Million weiterer Nutzender und Einschreibungen in anderen Kursen: Seit 0.4.4 liest die Prüfung zuerst die Einschreibungsmethoden des Kurses und fragt die Einschreibungen über deren IDs ab (Index `enrolid, userid`), damit nicht der Planer entscheidet: Auf GitHub las PostgreSQL vorher alle Einschreibungen der Website. Gelesen werden genau die Einschreibungen des Kurses; p99 höchstens 74 ms auf PostgreSQL 17 und 49 ms auf MariaDB 11.4 bei 20 000 Einschreibungen (lokal; `docs/ISSUE_NACHWEISE_0.4.4.md`).

**Inventar: was ein Prompt enthält und was davon auf eine Person schließen lassen kann (#4)**

| Feld | Inhalt | Identifizierend? | Restrisiko |
|---|---|---|---|
| Sicherheitsregeln, Ausgabeformat | fester Text des Plugins | nein | – |
| Persona-Stil | Text der Admins, geprüft | nein (Prüfung wie oben, ohne Namensliste) | ein Name außerhalb des Kurses bliebe unerkannt |
| Zielsprache | z. B. „German (de)“ | nein | sehr seltene Sprache in einem kleinen Kurs als Merkmal |
| `learner_token` | `[anonuser1]` | nein | – |
| Kategorie und Schlüssel je Baustein | z. B. `next`, `custom_reminder` | nein | eigene Regelschlüssel könnten sprechend sein (`custom_reminder_mueller`) |
| Aktivitäten | Tokens `[anonmod1]` | nein | – |
| Termine | Tokens `[anondate1]` und grobe Kategorie (`this_week`) | nein | – |
| Fortschrittszahlen | `progress_done`, `progress_total` | mittelbar | mit Kurswissen zusammen ein Merkmal; ohne Kursname und Zeitpunkt nicht zuordenbar |
| Gruppenaussagen | Tokens für Personen und Gruppe, Band statt Anzahl, Ereignisart | nein | siehe `GRUPPENAGGREGATE.md` |
| Intent eigener Regeln | Text der Lehrperson, geprüft | nein, wenn die Prüfung greift | Name außerhalb des Kurses, unbekannte Kennungsformen, sprechender Regelschlüssel |
| Feld `user` der Anfrage (vom Kern, nicht vom Plugin) | Die Kern-Anbieter OpenAI, Azure AI, DeepSeek und Ollama senden `sha256(siteidentifier + Nutzer-ID)` (`core_ai\provider::generate_userid()`) | **pseudonym, verkettbar:** gleich für alle Anfragen derselben Person auf derselben Website | Der Anbieter kann Anfragen einer Person über die Zeit verbinden, ohne sie zu kennen. Das Plugin kann das nicht abschalten; `core_ai` braucht die echte Nutzer-ID für Richtlinie und Protokoll. **[Entscheidung DSB, D5]** |
| Lokale Metadaten | `core_ai` speichert Nutzer- und Kontext-ID, Prompt und Antwort | lokal ja | Aufbewahrung im Kern, siehe Abschnitt 5 |

Unabhängig vom Prompt wird jede Antwort lokal geprüft (Struktur, Tokens, Zahlen, Links, Sprache, Behauptungen je Sprache). Eine Prompt-Injection, die durchkäme, kann deshalb keine erfundenen Fakten, Namen, Links oder Zahlen ausliefern (Test `prompt_guard_test::test_an_injected_answer_is_still_checked_independently_of_the_persona`, für jede eingebaute Persona).

**Restrisiken (#4):**

- Namen von Personen außerhalb des Kurses und Kennungen in unbekannten Formen erkennt die Prüfung nicht. Ebenso wenig sprechende Schlüssel eigener Regeln.
- Häufige Wörter, die zugleich Namen in einem Kurs sind (etwa „Will“, „May“), führen zum Fallback. Das ist ein Fehlalarm, also kein Datenabfluss. Der Text kommt dann ohne KI aus.
- Pseudonymisierung ist keine Anonymisierung. Aus dem Kontext kann eine Person mittelbar identifizierbar bleiben (P-23).

**Falsifikationsfälle, die getestet werden:** `tests/local/ai/prompt_guard_test.php` (21 Muster, Namen, Injection, alle Personas) und `tests/local/ai/ai_integration_test.php` (HTTP-Body am echten Moodle-KI-Adapter `aiprovider_openai` mit Guzzle-Mock: kein Klarname, kein Aktivitätsname, kein Kursname im Request).

## 3. Widerruf und Nebenläufigkeit (#3)

- **Was ein Widerruf entfernt (ab 0.4.2):** Cache, Entscheidungsprotokolle (Traces), Regeltreffer, die Tageszählung der Gruppenaussagen sowie Prompt und Antwort der KI-Aufrufe **dieser Instanz** für diese Person (`consent_manager::forget_generated()`). Es bleiben die Interviewantworten, das Zustimmungsprotokoll und die Freigabeentscheidungen; sie gehören der Person und werden über ihre eigenen Wege gelöscht.
- **Herkunft der KI-Einträge (#28):** Der Kern unterscheidet im Nutzerkontext weder Instanzen noch Plugins, und seine Privacy-API leert alle Einträge dort; zusätzlich löscht sie die Zustimmung der Person zur KI-Richtlinie in diesem Kontext. 0.4.1 rief sie beim Widerruf auf und traf damit auch KI-Daten anderer Plugins und anderer Instanzen. Ab 0.4.2 hält jeder Aufruf fest, welchen Eintrag er verursacht hat (`sideline_aicall`). Ein Widerruf liest diese Herkunftssätze, leert die Einträge und löscht genau die gelesenen Sätze; ein gleichzeitig neu zugeordneter Aufruf behält seinen Satz und leert sich über die Prüfung nach der Erzeugung selbst. Zuordnung: Während des Aufrufs hält das Plugin eine Sperre je Person (`ai_records::lock()`, höchstens 20 s Wartezeit, sonst Vorlage statt KI), der Eintrag ist der danach entstandene für diese Person und diesen Kontext mit genau dem gesendeten Prompt und, ab 0.4.3, mit der Antwort-ID des Anbieters für genau diesen Aufruf. Die Sperre bindet nur das Plugin; ein anderes Plugin kann im selben Moment denselben Prompt für dieselbe Person senden. Die Antwort-ID trennt beide. Passt trotzdem mehr als ein Eintrag (etwa ohne Antwort), wird keiner zugeordnet: ein fremder Eintrag wird nie für einen eigenen gehalten, der eigene bleibt dann wie ein fremder im Kern (Tests `ai_records_test::test_a_call_of_another_plugin_with_the_same_prompt_at_the_same_moment_is_never_taken_for_its_own`, `::test_an_ambiguous_entry_is_never_recorded`, Wettlauf 5 in `tools/race/run.php` mit echten Prozessen). Ein Widerruf leert nur diese Einträge, mit denselben Feldern, die die Privacy-API des Kerns leert (`prompt`, `responseid`, `fingerprint`, `generatedcontent`). Der Kern bietet keine API für einzelne Einträge; das ist der einzige schreibende Zugriff des Plugins auf eine Kerntabelle, begrenzt auf eigene, nachweislich zugeordnete Einträge. **[Entscheidung, #14/#28]** Ob dieser Zugriff hingenommen wird oder statt dessen ein Kern-Patch (eine Privacy-API je Eintrag) angestrebt wird. Die Liste der Felder ist fest; bekommt ein späteres Moodle weitere Textfelder, blieben diese stehen, bis die Liste ergänzt ist. Kennt die Tabelle keines der Felder, bleibt alles stehen und die Herkunftssätze bleiben erhalten. Die Registerzeile (Zeit, Anbieter, Tokens) bleibt wie nach der Kernlöschung; die Zustimmung zur KI-Richtlinie bleibt unberührt.
- **Altbestand:** Releases vor 0.4.1 registrierten KI-Aufrufe im Kontext der Aktivität; das Upgrade ordnet sie der Instanz zu, aber nur Einträge, deren Prompt die Form der Prompts des Plugins hat (`ai_records::is_plugin_prompt()`). Andere Einträge im selben Kontext, etwa vom Texteditor einer Lehrperson, bleiben unberührt. Aufrufe aus 0.4.1 im Nutzerkontext lassen sich keiner Instanz zuordnen und werden von einem Widerruf nicht geleert (Leeren über die Privacy-API des Kerns oder `tool_dataprivacy`). Widerrufe unter 0.4.1 haben die KI-Einträge der Person im Nutzerkontext insgesamt geleert, auch die anderer Plugins; das lässt sich nicht rückgängig machen. 0.4.1 war ein Beta-Stand vom 03.11.2026.
- **Widerruf während der Antwort des Anbieters:** Der Kern schreibt seinen Eintrag erst nach der Antwort, also nach dem Aufräumen des Widerrufs. Die Prüfung direkt nach der Erzeugung leert deshalb selbst (`commentary_service`, Test `ai_records_test::test_a_withdrawal_while_the_provider_answers_leaves_nothing_of_the_call`). In 0.4.1 blieb dieser eine Eintrag stehen.
- **KI-Aufrufe im Nutzerkontext:** Das Plugin registriert KI-Aufrufe im Nutzerkontext der lernenden Person, nicht im Kurs. Der KI-Nutzungsbericht des Kurses zeigt Lehrenden deshalb nicht, wer den Coach nutzt (sonst ließe sich die Zustimmung ablesen, P-40).

- Ein Widerruf schreibt zuerst den neuen Status und löscht dann die Cachezeilen. Eine Erzeugung schreibt ihre Zeile und liest danach die Zustimmung erneut. Deshalb sieht in jeder Reihenfolge entweder die Erzeugung den Widerruf und löscht ihre eigene Zeile, oder der Widerruf löscht sie. Das hängt nicht an einer Sperre.
- Ein Cache-Schreibvorgang, der wegen einer zwischenzeitlichen Invalidierung scheitert (CAS), verwirft den Text. Die Berechnung läuft einmal neu. Scheitert sie erneut, wird nur der nicht personenbezogene Grundtext gezeigt.
- Vor jeder Auslieferung personenbezogener Inhalte läuft eine letzte Prüfung (`commentary_service::deliverable()`): bei Cache-Treffer, frischem Text und Debug-Simulation.
- Nachweise: `delivery_atomicity_test` (deterministische Verschränkungen und Sperr-Timeouts) und `tools/race/run.php` (echte Parallelprozesse).

## 4. Gruppenmodus

Siehe `docs/privacy/GRUPPENAGGREGATE.md`: Ereignismatrix (#6), Freigabekreis und erneute Entscheidung (#9), Schutz gegen Differenzangriffe (#12).

## 5. Löschung, Export, Debug (#14)

- **Privacy-Löschung einer Person** löscht ihre Zeilen in allen Tabellen von Abschnitt 1, auch die Tageszählung (`day_count_test::test_privacy_deletion_removes_the_rows_in_every_mode`). Sie löscht außerdem alle Cachezeilen der Instanz, und zwar unabhängig vom aktuellen Gruppenmodus und auch dann, wenn die Freigabezeilen schon fehlen. Ein Text anderer könnte die Person sonst nennen oder mitzählen (Test `provider_test::test_deletion_after_switching_group_mode_off_still_drops_texts_naming_the_user`).
- **Debug-Export** (nur Website-Admins, Zweck mit mindestens zehn Zeichen, jeder Abruf im Audit-Log):
  - Er enthält eine Live-Simulation, gekennzeichnet als `output_kind: simulation`: Vorlagentext ohne KI, ohne Schreibzugriff.
  - Er enthält die Entscheidungsprotokolle des Zeitraums mit dem damals tatsächlich ausgelieferten Text und dem KI-Austausch (Prompt-Version, Prompt-Hash, validierte Tokentexte).
  - Er markiert je Protokoll `differs_from_simulation`.
  - Rohantworten des Anbieters werden nicht gespeichert, nur validierte Tokentexte. Abgelehnte Antworten erscheinen nur als Ablehnungsgrund.
- **KI-Protokolle des Kerns:**
  - Moodle speichert jede KI-Aktion mit Nutzer- und Kontext-ID (`ai_action_register`) sowie Prompt und Antwort (`ai_action_generate_text`). Durch die Pseudonymisierung enthalten beide nur Tokens (getestet).
  - Die Privacy-API des Kerns leert Prompt und Antwort einer Person in einem Kontext (alle Plugins). Die Privacy-API des Plugins und ein Widerruf leeren nur die Einträge der eigenen Instanz (getestet, `ai_records_test`).
  - **Aufbewahrung im Kern (geprüft am Quelltext von Moodle 4.5 und 5.3):** Es gibt keine geplante Aufgabe und keine Einstellung, die alte Einträge in `ai_action_register` oder `ai_action_generate_text` löscht. Prompt und Antwort bleiben, bis eine Datenschutzanfrage sie leert (`core_ai\privacy\provider` setzt `prompt` und `generatedcontent` auf leer), ein Widerruf im Plugin die eigenen leert oder ein Admin sie entfernt.
  - **Frist für die Einträge des Plugins (ab 0.4.3):** Die geplante Aufgabe `purge_cache` (täglich) leert Prompt und Antwort der Aufrufe des Plugins, die älter als `airetentiondays` sind (Voreinstellung 90 Tage, 0 = keine Frist), und löscht ihre Herkunftssätze. Registerzeilen (Zeit, Anbieter, Tokens) bleiben wie nach der Kernlöschung. Einträge anderer Plugins berührt sie nicht (Test `ai_records_test::test_the_retention_empties_only_entries_older_than_the_period`).
  - **Einträge aus 0.4.1 ohne Instanz:** `ai_records::unattributed()` findet sie (Prompt in der Form des Plugins, Nutzerkontext, kein Herkunftssatz). Ein Widerruf erreicht sie nicht; leeren kann sie nur die Administration mit `php mod/sideline/cli/privacy_check.php --empty-unattributed` **[Entscheidung DSB, D3d]** (Test `ai_records_test::test_entries_of_0_4_1_without_an_instance_are_found_and_emptied_by_the_administrator`).
  - **[Entscheidung DSB, D3]** Aufbewahrungsfrist für die Einträge anderer Plugins in diesen Kern-Tabellen festlegen und auf dem Zielsystem umsetzen (z. B. regelmäßige Löschanfragen über `tool_dataprivacy` oder ein Wartungsskript des Betriebs). Prüfen, welche Protokolle der Anbieter selbst führt (D5).

## 6. Offene Entscheidungen (nicht technisch lösbar)

| Nr. | Entscheidung | Zuständig | Grundlage hier |
|---|---|---|---|
| D1 | Rechtsgrundlage der Analyse (Einwilligung über das Gate oder Aufgabe der Hochschule), Wortlaut des Informationstexts | DSB | Abschnitt 1, Gate `consentrequired` |
| D2 | DSFA erforderlich? Bei KI-Einsatz mit Lernständen ist das wahrscheinlich | DSB | Abschnitte 1, 2, 4 |
| D3 | Aufbewahrungsfristen Zustimmungsprotokoll, Antworten, KI-Einträge des Plugins (`airetentiondays`), Kern-KI-Protokolle anderer Plugins, Einträge aus 0.4.1 ohne Instanz | DSB | Einstellungen der Website, Abschnitt 5 |
| D4 | Freigabe der Ereignismatrix des Gruppenmodus und der akzeptierten Restrisiken | Produktverantwortung und DSB | `GRUPPENAGGREGATE.md` |
| D5 | Auftragsverarbeitung mit dem KI-Anbieter, Protokolle beim Anbieter, verkettbare Nutzerkennung `user` des Kerns | Verwaltung und DSB | Abschnitt 2 (Inventar) |

Die Vorlagen mit Optionen und Empfehlung stehen in `ENTSCHEIDUNGEN.md` (E-6, E-12, E-14, E-28). Solange D1 bis D5 offen sind, sind die Datenschutz-AK der Issues #4, #12 und #14 **technisch erfüllt, fachlich aber nicht abgenommen**.

## 7. Betrieb: Prüfung auf dem Zielsystem, Auskunft und Löschung (#14)

**Operative Prüfung.** `php mod/sideline/cli/privacy_check.php` (Website-Administration, Kommandozeile) gibt nur Zähler, Einstellungen und Pfade aus, nie Inhalte (um Einträge aus 0.4.1 ohne Instanz zu erkennen, liest sie die Prompts der KI-Einträge von vor dem Upgrade auf 0.4.3), und endet mit Status 1, sobald eine Prüfung FAIL meldet; sie eignet sich für ein Monitoring. Empfohlen: nach jedem Upgrade und monatlich, Ausgabe mit Datum ablegen.

| Prüfung | OK | WARN | FAIL |
|---|---|---|---|
| `retention` | Fristen gesetzt, KI-Frist > 0 | KI-Frist 0: Prompts und Antworten bleiben so lange wie im Kern | – |
| `ai entries` | kein Eintrag älter als Frist + 2 Tage | – | ältere Einträge: die geplante Aufgabe läuft nicht |
| `ai entries without instance` | keine | Einträge aus 0.4.1, mit `--empty-unattributed` leeren (D3d) | – |
| `ai providers` | KI aus oder kein Anbieter aktiv | aktive Anbieter genannt: deren Aufbewahrung liegt außerhalb von Moodle (D5) | – |
| `group day counts` | nur aktuelle Tage | – | Zeilen früherer Tage: die geplante Aufgabe läuft nicht |
| `caches` | keine Definition `mod_sideline/…`, kein Verzeichnis `mod_sideline_groupsnapshot` | – | Definition oder Verzeichnis vorhanden |
| `removed files` | keine Datei aus `db/removed_files.txt` vorhanden | – | Datei vorhanden: Release wurde über ein altes kopiert |
| `traces` | keine überfälligen Protokolle, Ablaufverfolgung aus | Ablaufverfolgung an (mit Ende) | Protokolle älter als Frist + 2 Tage |
| `debug exports` | alle Abrufe durch heutige Website-Admins | Abrufe durch Personen, die heute keine Admins mehr sind | – |

Tests: `operational_check_test` (frische Website ohne FAIL, überfällige Einträge, abgeschaltete Frist, Debug-Abrufe, keine Inhalte in der Ausgabe, entfernte Datei, Cache-Verzeichnisse).

**Auskunft (Art. 15 DSGVO).** Über `tool_dataprivacy` (Datenexport): Der Export des Plugins enthält Zustimmung und Protokoll, Freigaben, Antworten, Persona, Regeltreffer, Entscheidungsprotokolle und die Liste der KI-Einträge, die eine Instanz verursacht hat; Prompt und Antwort selbst exportiert `core_ai` im Nutzerkontext. Lehrende erhalten keine Auskunft über Lernende.

**Löschung (Art. 17 DSGVO).**

1. Durch die Person selbst: Widerruf im Panel (Cache, Protokolle, Regeltreffer, Tageszählung, KI-Einträge dieser Instanz), Löschen eigener Antworten, Ende des Teilens.
2. Durch die Administration: Löschanfrage über `tool_dataprivacy`; die Privacy-API des Plugins löscht alle Tabellen von Abschnitt 1 und leert die KI-Einträge der Instanz, die von `core_ai` leert die übrigen KI-Einträge im Nutzerkontext.
3. Automatisch: geplante Aufgabe `purge_cache` nach den Fristen von Abschnitt 1.
4. Beim KI-Anbieter: nach dem Vertrag der Auftragsverarbeitung (D5); Moodle hat darauf keinen Zugriff.
5. Kontrolle: `cli/privacy_check.php` ohne FAIL.

**Debug-Export.** Nur Website-Admins (`is_siteadmin()`), Zweck mit mindestens zehn Zeichen, jeder Abruf im Audit-Log (`sideline_debuglog`), Aufbewahrung `auditretentiondays`.
