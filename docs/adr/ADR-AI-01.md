# ADR-AI-01: Kein eigenes `aiplacement_sideline`

Status: entschieden (0.2.0). Bezug: P-20, P-21, P-44, Audit SLC-020-P0-03.

## Kontext
Moodle 5.3 verarbeitet KI-Aktionen über `core_ai\manager::process_action()`. Placements (`aiplacement_*`) sind UI-Integrationen (Editor, Kursassistent) mit eigenen Schaltern je Aktion; die Verarbeitung selbst verlangt kein Placement. Geprüft im Code von Moodle 5.3 (`public/ai/classes/manager.php`): `is_action_available()` prüft Provider und Aktion, `is_action_enabled_in_context()` prüft die KI-Schalter des Kurses und des Kursmoduls, `get_user_policy_status()` liest die KI-Nutzungsrichtlinie der Person (Vorsicht: `user_policy_accepted()` **schreibt** eine Zustimmung).

## Entscheidung
Sideline nutzt `generate_text` direkt über den Manager, ohne eigenes Placement. Vor jedem Aufruf gelten alle Bedingungen zusammen: Standortschalter, Aktivitätsschalter, Capability `mod/sideline:useai` (pro Rolle steuerbar), Stundenlimit, KI-Richtlinie der Person, verfügbarer Provider und KI im Kurs/Kursmodul aktiv.

## Folgen
- Rechteverwaltung über die eigene Capability statt über Placement-Einstellungen.
- Moodle protokolliert die Aktion wie jede andere (`ai_action_register`, `ai_action_generate_text`); der gespeicherte Prompt enthält nur Platzhalter. Nachgewiesen in `tests/ai_integration_test.php` mit dem echten OpenAI-Provider und gemocktem HTTP-Endpunkt: weder der HTTP-Body noch die Core-Tabellen enthalten Namen, Aktivitätsnamen, Kursnamen oder URLs.
- Offen: rechtliche Bewertung der Protokollierung durch Core und Provider (P-26).
