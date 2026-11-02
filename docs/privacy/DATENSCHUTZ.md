# Datenschutz: Datenregister, KI-Protokolle, Löschung und offene Entscheidungen

Stand 0.4.0 (03.11.2026). Bezug: Issues #4, #14, #6, #12, #18; Lastenheft L-13, L-16, L-17, L-20; Pflichtenheft P-22, P-23, P-26, P-40, P-41, P-45 bis P-48, N-04.

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
| `ai_action_register`, `ai_action_generate_text` (Moodle-Kern) | KI-Aktion mit Nutzer- und Kontext-ID, Prompt und Antwort | ja (IDs); Prompt/Antwort enthalten nur Tokens | Kernprotokoll des KI-Subsystems | Admins; KI-Anbieter erhält den Prompt | Kern-Einstellung bzw. Privacy-API des Kerns **[Entscheidung DSB]** | Privacy-API von `core_ai` leert Prompt und Antwort (Test `ai_integration_test::test_translation_step_through_core_keeps_tokens_and_core_stores_only_tokens`) |

Lehrende haben keinen Zugriff auf persönliche Daten, Antworten, Zustimmungen oder Gruppenfreigaben anderer (L-16, P-40; Tests `access_security_test`, Behat „Teachers manage questions but never see answers“).

## 2. Was den Server verlässt (KI-Anbieter)

Nur der Prompt, den `prompt_builder` aus `pseudonymizer` zusammensetzt:

- Sicherheitsregeln, Persona-Stil, Zielsprache;
- je Baustein: Kategorie, Tokens statt Aktivitätsnamen, Daten, Personen und Gruppennamen (`[anonmod1]`, `[anondate1]`, `[anonpeer1]`, `[anongroup1]`), grobe Zeitkategorien statt Zeitpunkten, Fortschrittszahlen, bei Gruppen ein grobes Band („a_few_people“) statt einer Anzahl;
- bei eigenen Regeln der **Text der Lehrperson als „intent“** – nur wenn `prompt_text_guard` darin nichts findet (#4).

`prompt_text_guard` weist einen frei konfigurierten Text zurück, wenn er eines davon enthält: E-Mail-Adressen, Links und Domains, @-Handles, IP-Adressen, Telefonnummern, genaue Daten (numerisch, ISO, Monatsnamen in sieben Sprachen), Uhrzeiten, Zahlen ab drei Stellen, Kennungen wie „id 42“, fremde oder gefälschte Tokens, Markup, Anweisungen an das Modell (Prompt-Injection) oder **Vor- und Nachnamen aller in den Kurs eingeschriebenen Personen** und der lernenden Person selbst. Dann wird **nichts gesendet**; ausgeliefert wird der lokale Vorlagentext (statischer Fallback, Status `unsafe_input:<grund>`). Persona-Stile (von Admins, websiteweit) laufen durch dieselbe Prüfung, ohne Namensliste.

Unabhängig vom Prompt wird jede Antwort lokal geprüft (Struktur, Tokens, Zahlen, Links, Sprache, Behauptungen je Sprache). Eine Prompt-Injection, die durchkäme, kann deshalb keine erfundenen Fakten, Namen, Links oder Zahlen ausliefern (Test `prompt_guard_test::test_an_injected_answer_is_still_checked_independently_of_the_persona`, für jede eingebaute Persona).

**Restrisiken (#4):**

- Namen von Personen außerhalb des Kurses und Kennungen in unbekannten Formen erkennt die Prüfung nicht.
- Häufige Wörter, die zugleich Namen in einem Kurs sind (etwa „Will“, „May“), führen zum Fallback. Das ist ein Fehlalarm, also kein Datenabfluss. Der Text kommt dann ohne KI aus.
- Pseudonymisierung ist keine Anonymisierung. Aus dem Kontext kann eine Person mittelbar identifizierbar bleiben (P-23).

**Falsifikationsfälle, die getestet werden:** `tests/local/ai/prompt_guard_test.php` (21 Muster, Namen, Injection, alle Personas) und `tests/local/ai/ai_integration_test.php` (HTTP-Body am echten Moodle-KI-Adapter `aiprovider_openai` mit Guzzle-Mock: kein Klarname, kein Aktivitätsname, kein Kursname im Request).

## 3. Widerruf und Nebenläufigkeit (#3)

- Ein Widerruf schreibt zuerst den neuen Status und löscht dann die Cachezeilen. Eine Erzeugung schreibt ihre Zeile und liest danach die Zustimmung erneut. Deshalb sieht in jeder Reihenfolge entweder die Erzeugung den Widerruf und löscht ihre eigene Zeile, oder der Widerruf löscht sie. Das hängt nicht an einer Sperre.
- Ein Cache-Schreibvorgang, der wegen einer zwischenzeitlichen Invalidierung scheitert (CAS), verwirft den Text. Die Berechnung läuft einmal neu. Scheitert sie erneut, wird nur der nicht personenbezogene Grundtext gezeigt.
- Vor jeder Auslieferung personenbezogener Inhalte läuft eine letzte Prüfung (`commentary_service::deliverable()`): bei Cache-Treffer, frischem Text und Debug-Simulation.
- Nachweise: `delivery_atomicity_test` (deterministische Verschränkungen und Sperr-Timeouts) und `tools/race/run.php` (echte Parallelprozesse).

## 4. Gruppenmodus

Siehe `docs/privacy/GRUPPENAGGREGATE.md`: Ereignismatrix (#6), Freigabekreis und erneute Entscheidung (#9), Schutz gegen Differenzangriffe (#12).

## 5. Löschung, Export, Debug (#14)

- **Privacy-Löschung einer Person** löscht ihre Zeilen in allen Tabellen von Abschnitt 1. Sie löscht außerdem alle Cachezeilen der Instanz, und zwar unabhängig vom aktuellen Gruppenmodus und auch dann, wenn die Freigabezeilen schon fehlen. Ein Text anderer könnte die Person sonst nennen oder mitzählen (Test `provider_test::test_deletion_after_switching_group_mode_off_still_drops_texts_naming_the_user`).
- **Debug-Export** (nur Website-Admins, Zweck mit mindestens zehn Zeichen, jeder Abruf im Audit-Log):
  - Er enthält eine Live-Simulation, gekennzeichnet als `output_kind: simulation`: Vorlagentext ohne KI, ohne Schreibzugriff.
  - Er enthält die Entscheidungsprotokolle des Zeitraums mit dem damals tatsächlich ausgelieferten Text und dem KI-Austausch (Prompt-Version, Prompt-Hash, validierte Tokentexte).
  - Er markiert je Protokoll `differs_from_simulation`.
  - Rohantworten des Anbieters werden nicht gespeichert, nur validierte Tokentexte. Abgelehnte Antworten erscheinen nur als Ablehnungsgrund.
- **KI-Protokolle des Kerns:**
  - Moodle speichert jede KI-Aktion mit Nutzer- und Kontext-ID (`ai_action_register`) sowie Prompt und Antwort (`ai_action_generate_text`). Durch die Pseudonymisierung enthalten beide nur Tokens (getestet).
  - Die Privacy-API des Kerns leert Prompt und Antwort einer Person (getestet).
  - Ob und wie lange der Kern die Aktionen aufbewahrt, regelt der Kern. **[Entscheidung DSB]** Prüfen, ob die Kern-Aufbewahrung auf dem Zielsystem ausreichend begrenzt ist. Prüfen, welche Protokolle der Anbieter selbst führt (Auftragsverarbeitung).

## 6. Offene Entscheidungen (nicht technisch lösbar)

| Nr. | Entscheidung | Zuständig | Grundlage hier |
|---|---|---|---|
| D1 | Rechtsgrundlage der Analyse (Einwilligung über das Gate oder Aufgabe der Hochschule), Wortlaut des Informationstexts | DSB | Abschnitt 1, Gate `consentrequired` |
| D2 | DSFA erforderlich? Bei KI-Einsatz mit Lernständen ist das wahrscheinlich | DSB | Abschnitte 1, 2, 4 |
| D3 | Aufbewahrungsfristen Zustimmungsprotokoll, Antworten, Kern-KI-Protokolle | DSB | Einstellungen der Website |
| D4 | Freigabe der Ereignismatrix des Gruppenmodus und der akzeptierten Restrisiken | Produktverantwortung und DSB | `GRUPPENAGGREGATE.md` |
| D5 | Auftragsverarbeitung mit dem KI-Anbieter, Protokolle beim Anbieter | Verwaltung und DSB | Abschnitt 2 |

Solange D1 bis D5 offen sind, sind die Datenschutz-AK der Issues #4, #12 und #14 **technisch erfüllt, fachlich aber nicht abgenommen**.
