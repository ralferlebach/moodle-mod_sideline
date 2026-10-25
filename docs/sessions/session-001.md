# Session 001 – 0.1.0 MVP bis 0.2.0 (24.–25.10.2026)

## Ergebnis
- 0.1.0: MVP aus Plugin-Template (Core-Kommentare, KI-Fallback, Einwilligung, Privacy, Backup).
- Audit-Nacharbeit 0.2.0 vollständig umgesetzt, einschließlich Gruppenmodus V1.2.
- Entscheidungen Gruppenmodus (Auftraggeber: „Alle umsetzen“, Empfehlungen übernommen): nur im Kurs, aktives Opt-in,
  keine Negativereignisse, nur Abschluss-Aggregate mit Unterdrückung unter der Mindestgröße.
- ADR-AI-01: kein eigenes AI-Placement, eigene Capability `mod/sideline:useai`.

## Geprüft (lokal, nicht CI)
PHPUnit 172/172 auf PostgreSQL 17.11 und MariaDB 11.4.13; Behat 11/11 (Headless-Chrome 153, axe); Jest 20/20 aus dem
Moodle-Root; Moodles ESM-Build; phpcs 0/0; Upgrade 0.1.0 → 0.2.0 mit echten Daten; Messung `tools/bench.php`;
unabhängiger Review mit 8 Befunden, alle behoben und getestet.

## Offen
- GitHub-CI-Lauf, `local_moodlecheck`, echter KI-Provider, HTTP-Lasttest (k6/JMeter).
- Rechtliche Prüfung: Einwilligungs- und Freigabetexte, Core-/Provider-Protokolle (P-26).
- Lücken laut `docs/TRACEABILITY.md`: P-07 (Template-Versionen), P-09 (semantische Prüfung), P-14 (Persona-Vorschau),
  P-24 (Übersetzungsschritt), P-31 (standortweite Interview-Policy), Kurszweige als Quelle.
- Template-Reste ohne Bezug zu Sideline: `tests/playwright/`, `.github/workflows/load-*.yml`, `.github/workflows/playwright.yml`.

## Nachtrag 25.10.2026: Moodle 4.5 bis 5.3 (0.2.1)
- Anforderung des Auftraggebers: alle Versionen von 4.5 bis 5.3. Zuvor nur 5.3, weil das Pflichtenheft (P-37) 5.3 als Pflichtziel nannte.
- Gefundene Unterschiede: React erst ab 5.3 (5.2 ohne ESM-Ajax), `FEATURE_CAN_DISPLAY` erst ab 5.x, `is_action_enabled_in_context` ab 5.1,
  KI-Provider-Instanzen ab 5.0, `mod_subsection` in 4.5 deaktiviert, PHPUnit 9.6 in 4.5, ältere Kurs-API-Hilfen in 4.5/5.0.
- Ergebnis: PHPUnit 172/172 auf allen fünf Versionen (PG), MariaDB mit 4.5 und 5.3; Behat 11/11 auf allen fünf Versionen.
