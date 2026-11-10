# Barrierefreiheit: automatische Prüfungen und manueller Smoke-Test (Issue #25)

Stand 0.4.3 (09.11.2026).

## 1. Was automatisch geprüft wird

| Prüfung | Kriterium | Wo |
|---|---|---|
| axe-core, WCAG 2.2 A/AA, ohne ernste oder kritische Befunde | viele | Playwright (`sideline.spec.js`, `a11y.spec.js`), Behat `@accessibility`, Jest mit axe (React und AMD) |
| Bedienung nur mit Tastatur: Tab, Leertaste, Enter | 2.1.1 | Playwright „answers the question with the keyboard“, „selects several options with the keyboard“, `a11y.spec.js` |
| Sichtbarer Tastaturfokus an der ersten Checkbox des Panels | 2.4.7 | `a11y.spec.js` |
| Umbruch bei 400 % Zoom (320 CSS-Pixel breit) und 200 % (640 px): kein waagrechtes Scrollen von Seite und Panel, Panel im Sichtbereich | 1.4.10 | `a11y.spec.js` |
| Zielgröße mindestens 24 × 24 px für Schaltflächen, Checkboxen, Radiobuttons | 2.5.8 | `a11y.spec.js` (auch auf dem Mobilgerät) |
| Rechts-nach-links-Sprache: `dir="rtl"`, jede Checkbox rechts neben ihrer Beschriftung, keine Überlappung, kein Überlauf | 1.3.2, 1.4.10 | `a11y.spec.js` (Sprachpaket `he` für den Test, ohne eigene Texte) |
| Touch-Gerät (Pixel 7, Chromium): Zustimmung und Frage per Antippen | 2.5.x | `a11y.spec.js` |
| Reduzierte Bewegung: Text sofort vollständig; Tippanimation für Screenreader verborgen; keine laufende CSS-Animation im Panel | 2.3.3, 4.1.2 | Playwright „with reduced motion“ (`sideline.spec.js`, `screenreader.spec.js`) |
| Barrierefreiheitsbaum: Panel als benannte Region, vollständiger Text genau einmal, auch während der Tippanimation | 1.3.1, 4.1.2 | `screenreader.spec.js` (ARIA-Snapshot) |
| Ansagen: Zustimmung, Antwort und Widerruf erzeugen Text in einer Live-Region; eine Antwort ohne sichtbare Wirkung wird als „Gespeichert.“ angesagt (ab 0.4.3) | 4.1.3 | `screenreader.spec.js`, Jest beider Panels |
| Fokus nach jeder Aktion im Panel | 2.4.3 | `screenreader.spec.js`, Jest |
| Keine Tastaturfalle: Tab und Umschalt+Tab erreichen und verlassen das Panel | 2.1.2 | `screenreader.spec.js` |
| Erzwungene Farben (Windows-Kontrastmodus): Fokus als Umriss sichtbar, Ränder der Bedienelemente vorhanden (ab 0.4.3; vorher war der Fokus dort unsichtbar) | 1.4.11, 2.4.7 | `screenreader.spec.js` |

Beide Panels laufen durch: React ab Moodle 5.3, AMD in 4.5 bis 5.2 (`tools/ci-local/playwright.sh MOODLE_503_STABLE` bzw. `MOODLE_405_STABLE`).

## 2. Automatische Annäherung an den Screenreader (ab 0.4.3)

`tests/playwright/screenreader.spec.js` prüft, was der Browser einem Screenreader übergibt: den Barrierefreiheitsbaum (ARIA-Snapshot), was Live-Regionen nach einer Aktion ansagen (ein MutationObserver zeichnet Text auf, der in einer Live-Region erscheint und nicht `aria-hidden` ist), wohin der Fokus geht, ob die Tastatur gefangen wird, erzwungene Farben und reduzierte Bewegung. Das ist eine Annäherung: Wie NVDA oder VoiceOver den Baum vorlesen, prüft sie nicht. Die Prüfungen fanden zwei Fehler, die in 0.4.3 behoben sind:

- Eine Antwort ohne Wirkung auf den Kommentar änderte im React-Panel nichts Sichtbares; ein Screenreader sagte nach „Antworten“ nichts. Jetzt sagt eine Statusmeldung „Gespeichert.“ (beide Panels, nach jeder Aktion).
- Im Windows-Kontrastmodus verschwand der Tastaturfokus der Bedienelemente, weil Bootstrap ihn als Schatten zeichnet. Jetzt zeichnet das Plugin dort einen Umriss.

| Prüfung | 5.3 (React) | 4.5 (AMD) |
|---|---|---|
| Benannte Region, voller Text genau einmal, nie der getippte | ✔ | ✔ |
| Ansagen und Fokus bei Zustimmung, Antwort, Widerruf per Tastatur | ✔ | ✔ |
| Keine Tastaturfalle | ✔ | ✔ |
| Erzwungene Farben: Fokus und Ränder | ✔ | ✔ |
| Reduzierte Bewegung: keine laufende Animation | ✔ | ✔ |

Läufe: `tools/ci-local/playwright.sh MOODLE_503_STABLE 3 10` und `MOODLE_405_STABLE 3 10` am 10.11.2026 auf dem Checkout des Auslieferungs-ZIP, je drei volle Läufe mit 15/15 (lokal, Evidenzstufe C), auf GitHub mit dem manuellen Workflow `playwright.yml`.

## 3. Was nur von Hand geprüft werden kann

Ein Screenreader, echter Browser-Zoom und die Wahrnehmung durch Menschen lassen sich nicht automatisieren. Der folgende Ablauf ist **vor einem produktiven Einsatz von Hand auszuführen**. Er ist für 0.4.3 noch **nicht** ausgeführt. Die Spalte „automatisch angenähert“ nennt, was Abschnitt 2 davon bereits abdeckt; sie ersetzt die Prüfung von Hand nicht.

Umgebung: je einmal Moodle 5.3 (React-Panel) und Moodle 4.5 (AMD-Panel), Kurs aus `tests/playwright/seed.php`.

| Nr. | Schritt | Erwartung | automatisch angenähert | 5.3 | 4.5 |
|---|---|---|---|---|---|
| 1 | NVDA (aktuelle Version) mit Firefox und Chrome: Kursseite öffnen, mit `D` zu den Landmarken springen | Region „Sideline Learning Coach“ wird angesagt | Region und Name | ☐ | ☐ |
| 2 | Einwilligungsfrage mit Pfeiltasten lesen, „I agree“ mit Enter | Frage vollständig vorgelesen; nach dem Klick wird der Kommentar angesagt (Live-Region), Fokus geht nicht verloren | Ansage und Fokus | ☐ | ☐ |
| 3 | Kommentar mit Tippanimation | Der vollständige Text wird einmal vorgelesen, nicht Buchstabe für Buchstabe | voller Text einmal | ☐ | ☐ |
| 4 | Frage mit Mehrfachauswahl: Checkboxen mit Leertaste, Hinweis „Select 1 to 2 options.“ | Hinweis wird mit der Gruppe angesagt; beim Überschreiten des Maximums ist „Answer“ als nicht verfügbar angesagt | Hinweis (axe), Schaltfläche deaktiviert | ☐ | ☐ |
| 5 | „Show my answers“, „Delete answer“ | Ergebnis als Statusmeldung angesagt | Ansage „Gespeichert.“ | ☐ | ☐ |
| 6 | Gruppenfreigabe: Checkboxen „Share …“ und „My first name may be shown“, „Save“ | Beschriftungen vollständig; nach dem Speichern Statusmeldung | Beschriftungen (axe), Ansage | ☐ | ☐ |
| 7 | Browser-Zoom 200 % und 400 % (Strg +), Fenster 1280 px | Kein waagrechtes Scrollen, nichts abgeschnitten, Fokus sichtbar | Umbruch 320/640 px | ☐ | ☐ |
| 8 | Windows-Kontrastmodus (forced colors) | Rahmen von Checkboxen und Schaltflächen sichtbar, Fokus sichtbar | Fokus und Ränder | ☐ | ☐ |
| 9 | VoiceOver auf iOS (Safari): Zustimmung und Frage per Wischen und Doppeltippen | wie 2 und 4 | Tippen (Pixel 7) | ☐ | ☐ |
| 10 | RTL mit echtem Sprachpaket (`he` oder `ar` von download.moodle.org) | Panel gespiegelt, Lesereihenfolge stimmt | gespiegeltes Layout (`he` ohne Texte) | ☐ | ☐ |

Ergebnis je Zelle eintragen: ✔, ✘ mit Beschreibung, Datum und Name. Ein ✘ wird als Issue mit Schritt, Erwartung und tatsächlichem Verhalten erfasst.
