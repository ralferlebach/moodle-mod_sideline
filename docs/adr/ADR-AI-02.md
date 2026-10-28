# ADR-AI-02: KI-Protokolle in Moodle-Core und beim Provider

Status: technisch geprüft (0.3.0). Rechtliche Bewertung offen. Bezug: P-26, Blueprint ADR-06, P-48.

## Fragestellung
Welche Daten speichern Moodle-Core und der Provider, wenn Sideline Texte über `core_ai` erzeugt, und wie werden sie gelöscht?

## Befund in Moodle-Core (geprüft im Code von 4.5, 5.0, 5.1, 5.2 und 5.3)

| Tabelle | Inhalt je Aufruf | Personenbezug |
|---|---|---|
| `ai_action_register` | Aktion, Erfolg, `userid`, `contextid` (Kursmodul), Provider, Fehlercode, Zeiten; ab 5.0 auch das Modell, in 5.3 zusätzlich Tokenzahlen und `courseid` | ja: Person, Kursmodul, Zeitpunkt |
| `ai_action_generate_text` | vollständiger Prompt, Antwort-ID, Fingerprint, vollständige Antwort; bis 5.2 auch die Tokenzahlen | nur Platzhalter, keine Klarnamen (siehe Nachweis) |
| `ai_policy_register` | Zustimmung zur KI-Nutzungsrichtlinie | ja |

Bei Sideline enthalten Prompt und Antwort nur Platzhalter (`[anonuser1]`, `[anonmod1]`, `[anondate1]`, `[anonpeer1]`, `[anongroup1]`), grobe relative Fristkategorien, Zählwerte und den Persona-Stil. Das gilt auch für den gesonderten Übersetzungsschritt (P-24): Er erhält nur den bereits geprüften Text mit Platzhaltern, keine Fakten.

Für Kontext und Person ist die Zuordnung weiterhin möglich: Das Register verbindet jeden Aufruf mit Person und Kursmodul. Die Pseudonymisierung schützt also die Inhalte, nicht die Tatsache der Nutzung.

### Löschung
- **Keine Aufbewahrungsfrist in Core:** In 4.5 bis 5.3 gibt es keine geplante Aufgabe, die KI-Protokolle nach einer Frist löscht. Es gibt auch keinen Observer, der sie beim Löschen eines Kursmoduls oder Kurses entfernt.
- **Privacy API:** `core_ai\privacy\provider` leert bei Datenanfragen (`delete_data_for_user`, `delete_data_for_users`, `delete_data_for_all_users_in_context`) Prompt, Antwort-ID, Fingerprint und Antwort. Die Registerzeile bleibt ohne Inhalt bestehen.
- **Fristen über das Datenregister:** Eine Standort-Aufbewahrung lässt sich über *Website-Administration → Datenschutz und Richtlinien → Datenregister* einstellen, mit Fristen je Kontextebene (Kurs, Aktivität). Dann leert die Aufgabe für abgelaufene Kontexte (`tool_dataprivacy`) dieselben Felder.
- **Kein Löschen durch Sideline:** Sideline löscht Core-Daten nicht selbst (P-48: „referenzierte Core-Daten nicht unberechtigt löschen“). Sein eigenes Kostenbuch `sideline_aiusage` enthält keine Person, keinen Kontext und keinen Text und wird nach 31 Tagen gelöscht.

## Nachweis (automatisiert)
`tests/ai_integration_test.php` läuft mit dem echten OpenAI-Provider von Core und gemocktem HTTP-Endpunkt:
- Der HTTP-Body enthält keinen Namen, keine E-Mail-Adresse, keinen Aktivitäts- oder Kursnamen und keine URL. Das gilt auch für die zwei Aufrufe mit Übersetzungsschritt.
- `ai_action_generate_text` enthält nur Platzhalter.
- Core registriert beide Aufrufe. Sideline übernimmt die vom Provider gemeldeten Tokenzahlen in sein Kostenbuch.
- Nach `core_ai\privacy\provider::delete_data_for_user()` enthält kein Prompt mehr einen Platzhalter, die Felder sind geleert.

## Provider-Seite
Was der Provider speichert, entscheidet sein Vertrag, nicht Moodle. Beispiele: Speicherung zur Missbrauchserkennung, Abschaltbarkeit (Zero Data Retention), Serverstandort, Auftragsverarbeitung. Sideline beeinflusst nur, *was* gesendet wird. Das ist minimal und pseudonymisiert.

## Entscheidung
- Sideline bleibt beim Core-Weg (ADR-AI-01) und sendet ausschließlich Platzhalter.
- Für Aufbewahrung und Löschung der Core-Protokolle ist die Website zuständig, über das Datenregister von Moodle. Die README weist darauf hin.
- **Offen und nicht technisch lösbar:**
  - die rechtliche Bewertung (Rechtsgrundlage, DSFA nach Risikoprüfung, Vertrag mit dem Provider);
  - die Frage, ob der verbleibende Personenbezug des Registers (Person × Kursmodul × Zeitpunkt) hinnehmbar ist.
