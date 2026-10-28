# Changelog

## 0.3.0 (beta)

Offene Punkte aus Lasten- und Pflichtenheft nachgezogen:

* **Sprache und Übersetzung (P-24, L-07):** Die Sprache jedes KI-Texts wird geprüft. Deutsch und Englisch werden direkt in der Sprache der Person erzeugt. Für weitere Sprachen mit Sprachprofil (Französisch, Spanisch, Italienisch, Niederländisch, Portugiesisch) wird auf Englisch erzeugt und geprüft und dann in einem gesonderten, geprüften Schritt übersetzt. Dabei müssen Platzhalter und Zahlen gleich bleiben. Eine Antwort in der falschen Sprache wird ebenso übersetzt. Für Sprachen ohne Profil kommt die lokalisierte Vorlage.
* **Semantische Plausibilitätsprüfung (P-09):** Aussagen wie „bestanden“, „nicht bestanden“, „freigeschaltet“, „gesperrt“, Fristen, Punkte und Noten, ein weiterer oder kein Versuch, Kursabschluss, Abgabe und Klick-Hinweise sind nur erlaubt, wenn die Regel sie trägt. Sonst kommt die Vorlage. Ein Erfolg ohne „bestanden“ darf nicht als „bestanden“ formuliert werden.
* **Template-Versionen (P-07):** Jede eingebaute Vorlage hat Variablenschema und Versionsnummer. Eigene Regeln erhöhen ihre Vorlagenversion bei jeder Änderung des Wortlauts. Die Versionen stehen in den Metadaten jedes Kommentars, in Traces und im Debug-Export, zusammen mit Regel- und Promptversion.
* **Persona-Vorschau (P-14):** Für Entwürfe, freigegebene und eingebaute Personas gibt es eine Vorschau mit erfundenen Situationen: Vorlagentexte mit Eröffnungssatz, der vollständige Prompt und optional ein KI-Beispiel, das dieselben Prüfungen durchläuft wie jeder Text für Lernende.
* **Interview-Richtlinie der Website (P-31):** Interviews lassen sich für die ganze Website schalten. Dazu kommen erlaubte Fragetypen, ein Mindestabstand zwischen zwei Fragen und eine Obergrenze pro Person und Woche über alle Aktivitäten. Alle Werte gelten als Obergrenzen für jede Aktivität.
* **Kostengrenzen (ADR-08):** Ein Kostenbuch ohne Personenbezug zählt jeden Aufruf, auch Übersetzungen und Vorschauen. Es gibt eine Höchstzahl an Aufrufen pro Stunde und ein Token-Budget pro Tag; fehlende Tokenzahlen werden geschätzt. Der aktuelle Verbrauch steht auf der Einstellungsseite.
* **Kurszweige (L-19, P-43):** Kategorien samt Unterkategorien können Quelle eines Kursverbunds sein. Beim Speichern wird das Recht in der Kategorie geprüft, bei jeder Anfrage die aktive Einschreibung. Höchstens 20 Kurse pro Person.
* **Core-KI-Protokolle (P-26, ADR-06):** technisch geprüft für 4.5 bis 5.3 und dokumentiert in `docs/adr/ADR-AI-02.md`, einschließlich Löschung über das Datenregister von Moodle. Die rechtliche Bewertung bleibt offen.
* **HTTP-Lasttest (P-39):** neuer k6-Plan mit echten Anmeldungen und dem AJAX-Endpunkt: Plateau, Zustandswechsel und Race auf eine Person, danach eine Prüfung auf dem Server. Workflow `load-k6.yml`.
* **Tests:**
  * 305 PHPUnit-Tests (vorher 172) auf Moodle 4.5 bis 5.3 mit PostgreSQL und MariaDB;
  * Line Coverage 89,4 %, jetzt einschließlich Backup und `lib.php`; CI-Untergrenze von 50 auf 80 % angehoben;
  * Property-Test der Regeln mit 2000 zufälligen Zuständen;
  * Restore einer 4.5-Sicherung auf einer anderen 5.3-Website;
  * Moodle 4.5 mit PHP 8.1 und Moodle 5.0 mit PHP 8.2 zur Laufzeit getestet;
  * Behat mit 13 Szenarien auf allen fünf Versionen;
  * Upgrade einer echten 0.1.0-Datenbank auf 0.3.0 mit Schemaabgleich.
* **Unabhängiger Review (zweiter Prüfer), alle Befunde behoben und mit Tests abgesichert:**
  * Eine Antwort, in der ein Block in einer anderen Sprache stand, umging Sprach- und Aussagenprüfung. Jetzt wird die Sprache je Block bestimmt und jeder Block in allen Sprachen mit Phrasenlisten geprüft. Ein unübersetzter Block nach dem Übersetzungsschritt wird abgelehnt.
  * „erfolgreich“, „geschafft“, „successfully“ galten als bloße Erledigung, auch nach einem Fehlschlag. Jetzt sind sie eine eigene Aussage, die nur bei Erfolgen erlaubt ist.
  * Neue Aussagen: Zeitangaben in Worten („in zwei Tagen“, „morgen“) nur, wo die Regel ein Datum hat; „steht dir jetzt zur Verfügung“, „you can start it now“ bei gesperrten Aktivitäten.
  * Ein Platzhalter eines anderen Blocks war erlaubt und konnte dessen Fakten falsch zuordnen. Jetzt nur noch eigene Platzhalter.
  * Abgelehnt werden jetzt auch E-Mail-Adressen, bloße Domains und Ziffern anderer Schriften.
  * Ein erreichtes Kostenlimit machte jeden zwischengespeicherten KI-Text ungültig. Jetzt gehört das Limit nicht mehr zum Fingerprint, KI-Texte bleiben gültig. Nach einem Limit oder einem Provider-Ausfall gilt die Vorlage nur 15 Minuten, danach wird KI erneut versucht.
  * Aufrufe werden vor dem Start im Kostenbuch reserviert, damit gleichzeitige Anfragen das Limit nicht gemeinsam überschreiten.
  * Kursverbund und Kurszweige speichern jetzt, wessen Rechte sie tragen, und prüfen diese Rechte bei jeder Auswertung erneut, auch gegen Verbote auf Kursebene (P-43). Speichert eine weitere Lehrperson die Einstellungen, bleiben Quellen erhalten, die sie selbst nicht verwalten darf.
  * Antworten auf Fragen, die die Interview-Richtlinie nicht mehr erlaubt, wirken nicht mehr. Eine Frage behält beim Bearbeiten ihren Typ.
  * Eigene Sprachanpassungen (`tool_customlang`) machen den Cache ungültig.
  * Der Lasttest prüfte das Race nicht wirklich, und die Zustandswechsel waren oft keine. Beides ist behoben; der Seed-Schutz gilt jetzt auch bei Debugging.
* **Aufgeräumt:**
  * Die Template-Reste `tests/playwright/`, `playwright.yml`, `load-jmeter.yml` und die alten Lastskripte sind entfernt. Sie verwiesen auf fremde Plugins und wären in der CI gescheitert. Die Pfade stehen in `db/removed_files.txt`.
  * `seed_load.php` und `tools/bench.php` laufen auch auf Moodle 4.5.

## 0.2.1 (beta)

* Unterstützt Moodle 4.5, 5.0, 5.1, 5.2 und 5.3 (vorher nur 5.3)
* AMD-Panel für Moodle 4.5 bis 5.2 mit demselben Funktionsumfang wie das React-Panel (React erst ab 5.3)
* Größere Klickflächen für Auswahlfelder (WCAG 2.5.8), durch axe auf Moodle 4.5 gefunden
* Kompatibilität: Modul-Feature-Konstante nur ab 5.x entfernt, KI-Kontextprüfung nur wo vorhanden, Tests mit PHPUnit 9.6 und 11
* CI: MariaDB-Service prüft die Bereitschaft mit `healthcheck.sh --connect --innodb_initialized` statt `mysqladmin ping` (das MariaDB-11.4-Image enthält `mysqladmin` nicht mehr)

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
