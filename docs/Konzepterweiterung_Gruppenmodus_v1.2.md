# Sideline Learning Coach – Konzepterweiterung Gruppenmodus

**Status:** fachlicher Ergänzungsauftrag zu Lastenheft, Pflichtenheft und Blueprint V1.1; keine Implementierung.  
**Ziel:** Aufnahme in die nächste Spezifikations- und Entwicklungsiteration.

## 1. Zielbild

Der Sideline Learning Coach unterstützt drei Betriebsarten: (1) Einzelperson, (2) geschlossene Gruppe, (3) offene Gruppen. Gruppenereignisse können motivierende Kommentare und situative Interviewfragen auslösen. Leistungen anderer dürfen nicht in individuelle Noten oder Abschlussentscheidungen einfließen.

## 2. Anforderungen (Lastenheft)

- **L-G01 Einzelperson:** Standardmodus; ausschließlich eigene zulässige Lernereignisse.
- **L-G02 Geschlossene Gruppe:** Zusätzlich Ereignisse anderer Mitglieder derselben zugelassenen Moodle-Gruppe; Ausgabe wahlweise namentlich oder allgemein.
- **L-G03 Offene Gruppen:** Zusätzlich Ereignisse aus anderen ausdrücklich zugelassenen Gruppen; Ausgabe wahlweise Name plus Gruppe, nur Gruppenname oder allgemeine Aussage.
- **L-G04 Geltungsbereich:** Lehrende bestimmen in der Aktivität zugelassene Gruppen, Gruppierungen, Kurse und Ereignisarten; standortweite Kursverbund-Policy bleibt maßgeblich.
- **L-G05 Datenschutz:** Gruppenbezogene Veröffentlichung ist von individueller Analyse und KI-Policy getrennt zu behandeln; Identitätsnennung nur nach separater Freigabe der betroffenen Person. Lehrende erhalten dadurch keinen Zugriff auf private Interviewantworten.
- **L-G06 Widerruf:** Gruppenfreigaben sind änderbar; künftige Veröffentlichung unterbleibt sofort, vorhandene Caches und ausstehende Generierungen werden berücksichtigt.
- **L-G07 Transparenz:** Lernende sehen, welche Arten eigener Ereignisse für welche Empfängerkreise veröffentlicht werden können.
- **L-G08 Nichtteilnahme:** Ohne Gruppenfreigabe bleibt individuelle Nutzung möglich; kein Nachteil bei Leistungsbewertung.

## 3. Pflichten und Akzeptanzkriterien

### P-G01 Scope und Gruppenmitgliedschaften
- Gruppenmodus je Instanz festlegbar, Einzelperson als Default.
- Gruppen und Gruppierungen über Moodle-Core-APIs bestimmen; Mehrfachmitgliedschaft, Gruppenwechsel, unsichtbare Gruppen und Kursverbünde berücksichtigen.
- Je Ereignis Quelle, Zeitpunkt, Gruppenzugehörigkeit, zulässigen Empfängerkreis und Sichtbarkeit prüfen.
- Nicht autorisierte Kurs-/Gruppeninformationen niemals in API-Antworten ausgeben.

### P-G02 Veröffentlichungsstufen
- Geschlossen: `named`, `general`.
- Offen: `named_with_group`, `group_only`, `general`.
- Konfiguration ist eine Obergrenze, keine automatische Erlaubnis zur Veröffentlichung: individuelle Freigaben und Datenminimierung können Ausgabe weiter einschränken.
- Bei unzulässiger Nennung anonymisieren oder Ereignis unterdrücken; keine Rekonstruktion aus KI-Text, URLs, Zeitangaben oder Metadaten.

### P-G03 Datenschutz und Einwilligung
- Gesonderte versionierte Entscheidungen für Analyse, gruppenbezogene Veröffentlichung und Namensnennung vorsehen; Rechtsgrundlagen und Informationspflichten gesondert prüfen.
- Ablehnung/Widerruf blockiert künftige Offenlegung serverseitig; Race Conditions bei parallelen Aufrufen absichern.
- Kleine Aggregate, seltene Ereignisse, differenzierende Vergleiche und zeitlich exakte Aussagen auf Rückidentifikationsrisiko prüfen; kein pauschales Sicherheitsversprechen durch einen festen k-Wert.
- Interviewantworten und frei eingegebene Reflexionstexte standardmäßig **niemals** in Gruppenkommentare übernehmen; zusätzliche fachliche Freigabe wäre gesondert zu spezifizieren.
- Privacy API deckt neue Freigaben, abgeleitete Gruppen-Caches und Debugdaten ab.

### P-G04 Gruppenereignisse und Empfehlungen
- Ereignisse zu Nutzung, Abschluss, Bestehen, Bewertungen, Zugang und Zeitbedingungen über vorhandene Core-/`slcmod_*`-Adapter beziehen.
- Bei Gruppenaggregation Zählungen und Bezugsgrößen fachlich korrekt definieren (Personen vs. Versuche vs. Aktivitäten).
- Keine individuellen Leistungsentscheidungen aus fremden Ereignissen ableiten.
- Gruppenvergleiche ohne Beschämung, Druck oder manipulative Ranglisten; Persona beeinflusst Stil, nicht Tatsachen oder Datenschutz.

### P-G05 Cache und Invalidierung
- Fingerprint umfasst Gruppenmodus, Empfängerkreis, Mitgliedschaften, Freigaben, Regel-/Persona-Versionen und relevante Gruppenereignisse.
- Gruppenwechsel, Einwilligungswiderruf, Rechteentzug, Eventänderungen und Gruppenlöschung invalidieren betroffene Ausgaben.
- Personalisierte Texte bleiben empfängerbezogen; Gruppenaggregate nur bei identischer Sichtbarkeit und gleichem Berechtigungsstand wiederverwenden.
- Zeitabhängige Übergänge und periodische Reconciliation ergänzen Ereignisbeobachtung.

### P-G06 KI
- Ausschließlich minimierte, für den konkreten Empfänger freigegebene Fakten an Moodle AI übergeben.
- Identifizierende Angaben vor der KI-Anfrage pseudonymisieren; Rückauflösung ausschließlich serverseitig nach erneuter Berechtigungsprüfung.
- KI darf weder verbotene Gruppen-/Personennamen ergänzen noch ungeprüfte Behauptungen über einzelne Personen aufstellen.
- Deterministischer Template-Fallback bei Policy-/Provider-/Validierungsfehlern.

## 4. Technischer Blueprint – Ergänzungen

- `GroupScopeResolver`: Gruppen-/Gruppierungsauflösung, Mehrfachmitgliedschaften, Quellkursrechte.
- `GroupEventCollector`: empfängerbezogene, erlaubte Ereignisse aus Core und `slcmod_*`.
- `GroupVisibilityPolicy`: Empfängerkreis, individuelle Veröffentlichungsfreigaben, Identifizierbarkeit.
- `GroupAggregationService`: geprüfte Aggregation und Schutz gegen Rückidentifikation.
- `GroupCommentaryComposer`: sichere Faktenübergabe an Rule Engine, Templates und Moodle AI.
- Erweiterung von Instanzkonfiguration, Consent-Status, Cache-Schlüsseln, Audit-Metadaten und Privacy-Provider; keine unkontrollierte Kopie der Moodle-Leistungsdaten.
- Datenschutzprüfung muss **vor** KI-Generierung, Cache-Abruf und Auslieferung erfolgen.

## 5. Testmatrix / Release-Gate

1. Einzelperson: keine Fremdereignisse.
2. Geschlossen: namentlich/allgemein, jeweils mit und ohne Freigabe.
3. Offen: Name+Gruppe / nur Gruppe / allgemein; unzulässige Empfängerkreise ausgeschlossen.
4. Mehrfachgruppen, Gruppenwechsel, Gruppenlöschung, Gruppierungen und Kursverbünde.
5. Unsichtbare Gruppen, nicht eingeschriebene Personen, fehlende Rechte, Rechteentzug während Sitzung.
6. Einwilligung fehlt, abgelehnt, akzeptiert, widerrufen; paralleler KI-Auftrag und Cache-Invalidierung.
7. Kleine Gruppen, singuläre Ereignisse, differenzierende Aggregate, zeitliche Rückidentifikation.
8. Interviewantworten und Freitext bleiben auch bei Gruppenmodus privat.
9. Moodle-AI-Policy, Prompt-/Output-Validierung, statischer Fallback und lokalisierte Texte.
10. PHPUnit für Policies/Aggregation/Scopes; Behat-E2E mit mehreren Nutzern/Gruppen; Concurrency-/Security-/Privacy-Tests; tatsächliche CI-Ausführung belegen.

## 6. Offene Produktentscheidungen

- Gilt der offene Modus nur innerhalb eines Kurses oder auch über administrativ erlaubte Kursverbünde?
- Werden individuelle Gruppenereignisse nur nach aktivem Opt-in veröffentlicht, oder ist ein anderer rechtlich geprüfter Freigabemechanismus vorgesehen?
- Sollen Gruppenkommentare auf Gruppenmeilensteine beschränkt werden oder auch einzelne nicht-bestandene Versuche erwähnen? Empfehlung: keine personenbezogenen Negativereignisse veröffentlichen.
- Welche Ereignisarten sind für aggregierte Gruppenaussagen zulässig und welche Schutzkriterien gelten bei kleinen Gruppen?

## 7. Abgrenzung

Dieses Dokument ist eine konzeptionelle Ergänzung, kein Nachweis, dass die Funktionen bereits in der hochgeladenen Codebasis vorhanden sind. Kein Code und keine Repository-Dateien wurden verändert.
