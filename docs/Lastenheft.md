# Sideline Learning Coach — Lastenheft v1.0
Stand: 24.10.2026 · Ziel: Moodle 5.3+ · Komponente: `mod_sideline`

## 1. Ausgangslage und Ziel
Eine textfeldähnliche Moodle-Aktivität begleitet Lernende situativ mit motivierenden, sachlich korrekten Kommentaren, Tipps, Handlungsempfehlungen und gelegentlichen Reflexionsinterviews. Sie ersetzt weder Lehrende noch Moodle-Bewertung, Abschluss- oder Zugangslogik.

## 2. Zielgruppen
Lernende (Kommentare, Orientierung, optionale Dialoge); Lehrende (Regeln, Geltungsbereich, Texte, Persona); Administration (KI, Datenfreigaben, Datenschutz, Ressourcen); Entwickler (modulspezifische Adapter).

## 3. Nutzungsszenarien
- Nach erfolgreichem Abschluss: Leistung anerkennen und nächste erreichbare Aktivität vorschlagen.
- Nach Nichtbestehen: respektvoll ermutigen; nur tatsächlich zulässige Wiederholungs-/Verbesserungsoptionen nennen.
- Vor Ablauf einer Frist: sachlich verbleibende Zeit und Optionen anzeigen.
- Bei Rückkehr nach Pause: niedrigschwelligen Wiedereinstieg anbieten, ohne Gründe zu unterstellen.
- Bei Zugangssperre: Voraussetzungen und, soweit bekannt, Zeitpunkt der Freigabe erklären.
- Bei Übergang zwischen Kursabschnitten oder freigegebenen Kursen: nächste Schritte empfehlen.
- Gelegentlich eine freiwillige Frage nach Entscheidungsgründen, Schwierigkeit oder Plänen stellen.

## 4. Funktionale Anforderungen
L-01 Einbettung als Moodle-Aktivität mit Textfeldcharakter, mehrfach pro Kurs.
L-02 Beobachtungsbereich: ausgewählte Aktivitäten, Abschnitte/Subsektionen, ganzer Kurs, freigegebene Kursverbünde.
L-03 Auswertung von Nutzung, Abschluss, Bewertungen, Bestehen, Fristen, Zugänglichkeit, Kursabschluss und Struktur.
L-04 Regelbasierte Auswahl statischer Texte, zusammengesetzter Templates oder KI-Formulierungen.
L-05 Personas: gelegentlich kritischer Sportreporter mit Athleteninterviews; energischer Ring-Coach mit respektvollen direkten Anweisungen; wohlwollender Mentor mit lösungsorientierten, systemischen und transaktionsanalytisch inspirierten Fragen; administrativ freigegebene Custom-Personas.
L-06 Freiwillige Mini-Interviews: MC, Freitext, Slider, kurzes semantisches Differenzial; Überspringen jederzeit möglich.
L-07 Sprache entsprechend Nutzereinstellung, statische Sprachpakete und KI-Text in Zielsprache.
L-08 Administrator kann KI und kursübergreifende Auswertung separat aktivieren/deaktivieren.
L-09 Daten aus modulspezifischen Subplugins `slcmod_*`.
L-10 Cache bis zu relevanter Zustandsänderung; Zeitabläufe gelten ebenfalls als Zustandsänderung.
L-11 Optional simulierte Chat-Tippanimation ohne irreführenden Hinweis auf Live-KI; reduzierte Bewegung respektieren.
L-12 Selektiver Export/Import von Einstellungen, Regeln, Templates, Personas ohne personenbezogene Daten oder Geheimnisse.
L-13 Datenschutzgerechte KI-Pseudonymisierung aller identifizierenden Daten und vollständige lokale Auflösung der Platzhalter.
L-14 Funktionsfähigkeit ohne KI und bei Provider-Ausfall.
L-15 Vollständige automatisierte Abdeckung spezifizierter Verhaltenspfade, Unit-/Integrationstests und Behat-End-to-End-Szenarien.

## 5. Nichtfunktionale Anforderungen
N-01 Moodle Coding Standards, Rollen-/Capability- und Kontextprüfungen, Barrierefreiheit WCAG 2.2 AA als Ziel.
N-02 Keine N+1-Abfragen, begrenzte Aggregation und KI-Kosten, keine synchronen KI-Aufrufe beim Seitenaufbau.
N-03 Nachvollziehbare Regeln, keine halluzinierten Freigaben, Bewertungen oder Fristen.
N-04 Datensparsamkeit, definierte Löschfristen, Privacy-API, KI-Nutzung transparent und mit erforderlicher Policy-Akzeptanz.
N-05 Keine psychologische Diagnostik aus Logdaten; keine manipulative, beschämende oder aggressive Ansprache.

## 6. Abgrenzung und Erfolgskriterien
Kein autonomer Agent, kein offener General-Chatbot, keine Notenänderung und keine automatische Aktivitätsfreischaltung. Erfolg: zutreffende Hinweise, nachweislich richtige Berechtigungen, stabile Cache-Invalidierung, kontrollierbare KI-Kosten, freiwillige und barrierefreie Interviews.

## 7. Offene Produktentscheidungen
Umfang der Kursverbünde; genaue Definition von „Nutzung“; Aufbewahrungsfristen; erlaubte Auswertung von Freitextantworten; Sichtbarkeit von Dialogantworten für Lehrende; Obergrenzen für Interviewfrequenz; Default-Persona; Umgang mit anonymisierten KI-Logs im Core.


## 8. Verbindliche Produktentscheidungen V1.1 (ersetzt entsprechende offene Punkte)
- **L-16 Vertraulichkeit:** Lehrende dürfen weder individuelle Interviewantworten noch individualisierte Ausgaben, Eingabefakten oder deren Konsequenzen einsehen. Gewöhnliche Moodle-Bewertungsrechte bleiben unberührt; Sideline schafft keinen zusätzlichen Einsichtskanal.
- **L-17 Admin-Debug-Export:** Nur berechtigte Standortadministration darf einen zweckgebundenen Export der Ausgaben einschließlich zugrunde liegender Eingaben, Befragungsantworten und konkreter Entscheidungsfolgen anfordern. Keine reguläre Lehrendenansicht.
- **L-18 Persona-Freigabe:** Personas einschließlich Custom-Personas werden administrativ freigegeben. Lehrende aktivieren ausschließlich freigegebene Personas; optional dürfen Lernende daraus selbst wählen und die Wahl ändern.
- **L-19 Kursverbünde:** Lehrende definieren die berücksichtigten Kurse und Kurszweige in der jeweiligen Aktivität, sofern die globale Freigabe besteht und alle Datenzugriffsberechtigungen erfüllt sind.
- **L-20 Einwilligungsoption:** Moodle AI Policy gilt. Zusätzlich kann die Aktivität vor jeglicher personenbezogener Analyse/Ausgabe eine gesonderte, protokollierte Entscheidung verlangen. Ablehnung und späterer Widerruf müssen möglich sein; personenbezogene Daten sind über die Privacy API zu behandeln.
- **Offene Umsetzungsparameter:** Aufbewahrungsfristen, Löschstrategie für Debug-Daten, zulässige Datenquellen und genaue Rechte je Quellkurs sowie konkrete Einwilligungstexte sind vor Implementierungsfreigabe festzulegen.
