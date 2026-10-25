# Changelog

## 0.2.1 (beta)

* Unterstützt Moodle 4.5, 5.0, 5.1, 5.2 und 5.3 (vorher nur 5.3)
* AMD-Panel für Moodle 4.5 bis 5.2 mit demselben Funktionsumfang wie das React-Panel (React erst ab 5.3)
* Größere Klickflächen für Auswahlfelder (WCAG 2.5.8), durch axe auf Moodle 4.5 gefunden
* Kompatibilität: Modul-Feature-Konstante nur ab 5.x entfernt, KI-Kontextprüfung nur wo vorhanden, Tests mit PHPUnit 9.6 und 11

## 0.2.0 (beta)

* Inline-Anzeige auf der Kursseite wie ein Text- und Medienfeld, mehrere Instanzen je Kurs
* Vollständiges Zustandsmodell: Nutzung, Verfügbarkeit mit AND/OR/NOT, Sperrgrund, Versuche, Abgaben, Kursabschluss
* Adapter-Subplugins `slcmod_*` mit `slcmod_assign` und `slcmod_quiz`
* Abschnitts-Scope mit verschachtelten Subsektionen, Kursverbünde mit Rechteprüfung je Quellkurs
* Standort-Personas mit Versionen, Freigabe und Widerruf; Lernende wählen aus den angebotenen Personas
* Interviewfragen (Multiple Choice, Freitext, Schieberegler, semantisches Differenzial) mit sichtbarer Wirkung
* Eigene Regeln mit Cooldown und Wochenlimit, Vorschau mit Beispieldaten, selektiver Export/Import
* Gruppenmodus (geschlossen, offen) mit getrennten Opt-ins für Freigabe und Namensnennung
* Debug-Export nur für Standortadmins mit Audit-Log, zeitlich begrenzte Entscheidungsprotokolle, Aufbewahrungsfristen
* Sicherheit: `require_login` in allen Endpunkten, Prüfung jedes Links vor Auslieferung, Widerrufs-Recheck, Compare-and-Swap im Cache
* KI: eigene Capability `mod/sideline:useai`, kein eigenes Placement (ADR-AI-01)
* Upgrade von 0.1.0 ohne Datenverlust

## 0.1.0 – MVP (alpha)

* Regelbasierte Kommentare, KI-Phrasierung mit Pseudonymisierung und Fallback, Einwilligung, Privacy API, Backup/Restore der Konfiguration
