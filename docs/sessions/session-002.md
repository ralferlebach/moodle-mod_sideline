# Session 002 – 0.3.0: offene Punkte aus Lasten- und Pflichtenheft (28.–29.10.2026)

## Auftrag
„Was ist noch aus dem initialen Pflichten- und Lastenheft offen? Bitte nachziehen.“ Nur `mod_sideline`.

## Ergebnis
- **P-24, L-07:** Sprachprüfung jeder KI-Antwort und gesonderter, geprüfter Übersetzungsschritt; Sprachen ohne Profil bekommen die Vorlage.
- **P-09:** semantische Aussagenprüfung (`claim_checker`), jede Vorlage in de und en besteht sie.
- **P-07:** Version und Variablenschema je Vorlage, Vorlagenversion je eigener Regel, Versionen in Traces und im Debug-Export.
- **P-14:** Persona-Vorschau mit erfundenen Daten.
- **P-31, ADR-08:** Interview-Richtlinie der Website, Kostenbuch mit Aufrufen pro Stunde und Token-Budget pro Tag.
- **L-19, P-43:** Kurszweige (Kategorien) als Quelle eines Kursverbunds.
- **P-26:** Core-KI-Protokolle technisch geprüft (ADR-AI-02).
- **P-39:** HTTP-Lasttest mit k6 (Plateau, Zustandswechsel, Race) und Prüfung auf dem Server.
- **L-15, P-35:**
  - Coverage von 71,9 auf 89,4 % (jetzt einschließlich Backup und lib.php), CI-Untergrenze 80 %;
  - Property-Test der Regeln;
  - Tests für Formulare, Dienste, lib.php und die geplante Aufgabe.
- **P-34:** Restore einer 4.5-Sicherung auf einer anderen 5.3-Website.
- **P-37:** PHP 8.1 (Moodle 4.5) und 8.2 (Moodle 5.0) zur Laufzeit.
- **Aufgeräumt:** Playwright- und JMeter-Reste des Templates entfernt und in `db/removed_files.txt` eingetragen.

## Geprüft (lokal, nicht CI)
PHPUnit 305/305 auf 4.5 (PHP 8.1), 5.0 (PHP 8.2), 5.1, 5.2 und 5.3 (PostgreSQL) sowie 4.5 und 5.3 (MariaDB); Behat 13/13 auf allen fünf Versionen; Jest 20 + 17; k6; phpcs 0/0; Upgrade 0.1.0 → 0.3.0 mit Schemaabgleich; unabhängiger Review mit 12 Befunden, alle behoben.

## Offen
- GitHub-CI-Lauf (Push durch den Auftraggeber), `local_moodlecheck` (läuft in der CI).
- Echter KI-Provider, manuelle Screenreader-Prüfung.
- Rechtliche Bewertung: Einwilligungstexte, Core- und Provider-Protokolle (P-26), Rechtsgrundlage des Gruppenmodus.
