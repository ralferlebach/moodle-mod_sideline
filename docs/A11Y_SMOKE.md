# Barrierefreiheit: automatische Prüfungen und manueller Smoke-Test (Issue #25)

Stand 0.4.1.

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
| Reduzierte Bewegung: Text sofort vollständig; Tippanimation für Screenreader verborgen | 2.3.3, 4.1.2 | Playwright „with reduced motion“ |

Beide Panels laufen durch: React ab Moodle 5.3, AMD in 4.5 bis 5.2 (`tools/ci-local/playwright.sh MOODLE_503_STABLE` bzw. `MOODLE_405_STABLE`).

## 2. Was nur von Hand geprüft werden kann

Ein Screenreader, echter Browser-Zoom und die Wahrnehmung durch Menschen lassen sich nicht automatisieren. Der folgende Ablauf ist **vor einem produktiven Einsatz von Hand auszuführen**. Er ist für 0.4.1 noch **nicht** ausgeführt.

Umgebung: je einmal Moodle 5.3 (React-Panel) und Moodle 4.5 (AMD-Panel), Kurs aus `tests/playwright/seed.php`.

| Nr. | Schritt | Erwartung | 5.3 | 4.5 |
|---|---|---|---|---|
| 1 | NVDA (aktuelle Version) mit Firefox und Chrome: Kursseite öffnen, mit `D` zu den Landmarken springen | Region „Sideline Learning Coach“ wird angesagt | ☐ | ☐ |
| 2 | Einwilligungsfrage mit Pfeiltasten lesen, „I agree“ mit Enter | Frage vollständig vorgelesen; nach dem Klick wird der Kommentar angesagt (Live-Region), Fokus geht nicht verloren | ☐ | ☐ |
| 3 | Kommentar mit Tippanimation | Der vollständige Text wird einmal vorgelesen, nicht Buchstabe für Buchstabe | ☐ | ☐ |
| 4 | Frage mit Mehrfachauswahl: Checkboxen mit Leertaste, Hinweis „Select 1 to 2 options.“ | Hinweis wird mit der Gruppe angesagt; beim Überschreiten des Maximums ist „Answer“ als nicht verfügbar angesagt | ☐ | ☐ |
| 5 | „Show my answers“, „Delete answer“ | Ergebnis als Statusmeldung angesagt | ☐ | ☐ |
| 6 | Gruppenfreigabe: Checkboxen „Share …“ und „My first name may be shown“, „Save“ | Beschriftungen vollständig; nach dem Speichern Statusmeldung | ☐ | ☐ |
| 7 | Browser-Zoom 200 % und 400 % (Strg +), Fenster 1280 px | Kein waagrechtes Scrollen, nichts abgeschnitten, Fokus sichtbar | ☐ | ☐ |
| 8 | Windows-Kontrastmodus (forced colors) | Rahmen von Checkboxen und Schaltflächen sichtbar, Fokus sichtbar | ☐ | ☐ |
| 9 | VoiceOver auf iOS (Safari): Zustimmung und Frage per Wischen und Doppeltippen | wie 2 und 4 | ☐ | ☐ |
| 10 | RTL mit echtem Sprachpaket (`he` oder `ar` von download.moodle.org) | Panel gespiegelt, Lesereihenfolge stimmt | ☐ | ☐ |

Ergebnis je Zelle eintragen: ✔, ✘ mit Beschreibung, Datum und Name. Ein ✘ wird als Issue mit Schritt, Erwartung und tatsächlichem Verhalten erfasst.
