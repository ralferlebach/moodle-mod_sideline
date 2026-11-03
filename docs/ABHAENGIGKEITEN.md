# Abhängigkeiten und bekannte Schwachstellen (Issue #27)

Stand 0.4.1, geprüft am 03.11.2026 mit `npm audit` gegen die GitHub Advisory Database.

## 1. Im Plugin ausgeliefert (Laufzeit)

| Bestandteil | Herkunft | Fremdcode | Befund |
|---|---|---|---|
| PHP-Code (`classes/`, `*.php`, `db/`, `backup/`, `adapter/`) | eigen | keiner, kein Composer, keine `thirdpartylibs.xml` nötig | – |
| AMD-Build `amd/build/panel.min.js` | eigen, gebaut mit Moodles `grunt amd` | keiner; nutzt `core/ajax` von Moodle | – |
| ESM-Build `js/esm/build/*.js` (Moodle 5.3) | eigen, gebaut mit Moodles `grunt esm` | keiner gebündelt: `react`, `react/jsx-runtime` und `@moodle/lms/core/ajax` sind Imports, die Moodle selbst bereitstellt (Import-Map) | React-Version und -Sicherheit liegen bei Moodle |
| `styles.css`, Sprachdateien, Templates | eigen | keiner | – |

Ergebnis: Das Plugin bündelt keine Bibliothek. Schwachstellen in React oder anderen Frontend-Bibliotheken betreffen es nur über die Moodle-Version der Website.

## 2. Nur für Entwicklung und Tests (nicht im Plugin-ZIP von `git archive`)

| Werkzeug | Festgelegt durch | Direkte Pakete | `npm audit` |
|---|---|---|---|
| Playwright-Journeys | `tests/playwright/package-lock.json` | `@playwright/test` 1.63.0, `@axe-core/playwright` 4.11.0 | 0 Befunde |
| Jest für das AMD-Panel | `tools/ci-local/jest-amd/package-lock.json` (neu in 0.4.1; vorher ungepinnt installiert) | `jest` 30.5.2, `jest-environment-jsdom` 30.5.2, `babel-jest` 30.5.2, `@babel/core` 7.29.7, `@babel/preset-env` 7.29.7, `jest-axe` 10.0.0 | 3 niedrig, 16 mittel, 0 hoch, 0 kritisch (alle transitiv, siehe unten) |
| Jest und ESLint für das React-Panel | Moodles eigene `node_modules` (`npm ci` im Moodle-Baum) | – | Sache von Moodle |
| k6-Lasttest | Binärdatei, Version in `tests/load/README.md` | – | nicht mit npm prüfbar |
| moodle-plugin-ci | Composer, `^4` | – | Sache des Projekts moodlehq |

Die 19 Befunde der Jest-Toolchain gehen auf drei Advisories in transitiven Paketen zurück:

| Advisory | Paket | Schwere | Bewertung |
|---|---|---|---|
| [GHSA-gcqx-w48m-w2h4](https://github.com/advisories/GHSA-gcqx-w48m-w2h4) Prototype Pollution in `mergeObject()` | `deepmerge` 4.x (über Jest) | niedrig | nur in der Jest-Konfiguration mit eigenen, vertrauenswürdigen Objekten; keine fremden Eingaben |
| [GHSA-3g3m-2fjr-6pw4](https://github.com/advisories/GHSA-3g3m-2fjr-6pw4) AbortSignal-Abbruch wird umgangen | `glob` ≤ 14.0.1 (über `test-exclude`, Jest) | mittel | Dateisuche im eigenen Repository, ohne Abbruchsignale |
| [GHSA-g3hv-75wg-m249](https://github.com/advisories/GHSA-g3hv-75wg-m249) ReDoS über Glob-Muster | `minimatch` < 11.0.1 (über Jest) | mittel | nur feste Muster aus `jest.config.js` |

Die von `npm audit` vorgeschlagene Behebung ist ein Rückschritt auf Jest 25 (Major-Downgrade). Das ist für eine reine Testumgebung ohne fremde Eingaben unverhältnismäßig. Die Befunde sind akzeptiert. Sobald Jest Versionen ohne diese Pakete freigibt, wird die Lockdatei aktualisiert.

## 3. GitHub Actions

Die Workflows nutzen Aktionen mit Haupt-Tag (`actions/checkout@v4`, `shivammathur/setup-php@v2`, `actions/setup-node@v4`, `actions/upload-artifact@v4`/`@v7`). Ein Pinning auf Commit-SHAs würde gegen ein kompromittiertes Tag schützen und ist eine Empfehlung, keine Voraussetzung.

## 4. Erneut prüfen

```bash
cd tests/playwright && npm audit
cd tools/ci-local/jest-amd && npm audit
```
