# Browser-Journeys mit Playwright

`sideline.spec.js` prüft das Panel in Chromium gegen eine echte Moodle-Installation. Die Tests laufen mit dem React-Panel (Moodle 5.3) und dem AMD-Panel (Moodle 4.5 bis 5.2).

## Journeys

`sideline.spec.js`:

1. **Einwilligung:** Lernende sehen zuerst die Einwilligungsfrage und keinen Kommentar. Nach „I agree“ kommt der Kommentar. Danach lässt sich die Einwilligung widerrufen.
2. **Interview per Tastatur:** Antwort mit Leertaste wählen, mit Enter absenden. Die Wirkung erscheint im Kommentar. Unter „Show my answers“ steht, was die Antwort bewirkt; danach wird sie gelöscht.
3. **Reduzierte Bewegung:** Mit `prefers-reduced-motion: reduce` steht der Text sofort vollständig da. Ohne die Einstellung wird er getippt. Der getippte Text ist für Screenreader verborgen (`aria-hidden`), der vollständige Text ist immer lesbar.
4. **Keine Sicht für Lehrende:** Eine Lehrperson sieht nur ihren eigenen Coach, nichts über Lernende.
5. **Mehrfachauswahl per Tastatur:** zwei Optionen, eine dritte überschreitet das Maximum.
6. **Gruppenaussage mit Namen:** sichtbar, solange die andere Person teilt; nach dem Abschalten verschwunden. Jede Person in einem eigenen Browserkontext; die Journey stellt ihren Ausgangszustand selbst her und ist beliebig oft wiederholbar.

`a11y.spec.js` (Issue #25, Einzelheiten in `docs/A11Y_SMOKE.md`):

7. **400 % und 200 % Zoom** (320 und 640 CSS-Pixel): kein waagrechtes Scrollen, Zielgrößen ab 24 px, sichtbarer Tastaturfokus, axe.
8. **Rechts nach links** (`he`): Panel gespiegelt, jede Checkbox rechts neben ihrer Beschriftung, axe.
9. **Touch-Gerät** (Pixel 7): Zustimmung und Frage per Antippen.

axe prüft die Bereiche Kommentar, Einwilligung und Frage (WCAG 2.2 A und AA). Fehlgeschlagene Tests werden nicht wiederholt (`retries: 0`).

## Lokal
```bash
cd <moodle-root>
php [public/]mod/sideline/tests/playwright/seed.php > /tmp/pw.env
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8000 -t <web-root> &       # web-root: public/ ab Moodle 5.1
set -a; eval "$(grep '^export ' /tmp/pw.env | sed 's/^export //')"; set +a
cd [public/]mod/sideline/tests/playwright
npm ci && npx playwright install chromium
npx playwright test
```
`seed.php` legt bei jedem Lauf einen neuen Kurs mit einer Lehrperson und fünf Lernenden an, damit Läufe sich nicht gegenseitig beeinflussen. Es schaltet Benutzertouren ab, wie Behat es tut: Ihr Dialog verbirgt sonst die Seite vor assistiven Techniken. Das Skript verweigert Websites, die nicht auf localhost laufen. Ausnahme: `$CFG->sideline_allow_load_seed = true`.

In GitHub: Workflow **Browser journeys (Playwright)** manuell starten (`.github/workflows/playwright.yml`). Es gibt keinen Zeitplan. Bei einem Fehler werden Bericht, Screenshots und Traces als Artefakt hochgeladen.

Der ganze Workflow lokal, mit frischer Moodle-Installation wie auf GitHub: `bash tools/ci-local/playwright.sh MOODLE_503_STABLE 3 10` (drei volle Läufe, danach die Gruppen-Journey zehnmal hintereinander auf einem Kurs).

Für den Rechts-nach-links-Test legt `seed.php` ein minimales Sprachpaket `he` an, wenn die Website keines hat. Ein vorhandenes Paket bleibt unberührt.

## Stand

Siehe `docs/ISSUE_NACHWEISE_0.4.1.md`, Abschnitt „Läufe“.
