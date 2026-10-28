# Lasttest über HTTP (P-39)

`sideline-commentary.js` ist ein k6-Plan gegen eine echte Moodle-Installation. Er verwendet echte Anmeldungen und Sitzungen und denselben AJAX-Endpunkt (`lib/ajax/service.php`, Funktion `mod_sideline_get_commentary`), den auch das Panel aufruft.

## Szenarien
- **plateau:** `VUS` Lernende sind `DURATION` lang gleichzeitig aktiv.
  - Jede virtuelle Person meldet sich als eigene Person an, öffnet die Aktivitätsseite einmal und fragt dann den Kommentar alle 0,2 s ab.
  - Jede zehnte Abfrage folgt auf eine Zustandsänderung: Eine Aktivität wird als erledigt oder unerledigt markiert. Der Kommentar muss dann neu erzeugt werden.
  - Gemessen wird getrennt: `sideline_commentary_cached` (Cache-Treffer) und `sideline_commentary_after_change` (Neuerzeugung).
- **race:** Nach dem Plateau öffnen `RACE_VUS` Sitzungen *derselben* Person den Kommentar im selben Moment. Kurz davor hat eine Sitzung den Zustand geändert. Jede Anfrage muss beantwortet werden. Danach prüft `verify_load.php` auf dem Server, dass es genau eine aktuelle Cache-Zeile für diese Person gibt (Singleflight und Compare-and-Swap).

Schwellen, die den Lauf scheitern lassen:
- keine fehlgeschlagenen Web-Service-Aufrufe;
- weniger als 1 % HTTP-Fehler;
- Checks zu mehr als 99 % erfüllt;
- p95 der Cache-Treffer unter 800 ms;
- p95 nach Zustandswechsel unter 1500 ms;
- jede Race-Anfrage unter 10 s.

Die Zeitbudgets gelten für den eingebauten PHP-Server mit 8 Workern und sind daher großzügig. Für einen Produktionsserver sind sie neu festzulegen.

## Lokal
```bash
cd <moodle-root>
php [public/]mod/sideline/tests/load/seed_load.php --users=50 --activities=20 > /tmp/load.env
PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8000 -t <web-root> &       # web-root: public/ ab Moodle 5.1
set -a; eval "$(sed 's/^export //' /tmp/load.env)"; set +a
k6 run -e VUS=20 -e DURATION=60s tests/load/sideline-commentary.js
php [public/]mod/sideline/tests/load/verify_load.php --cmid=$CMID --prefix=$USER_PREFIX
```
`seed_load.php` verweigert Websites, die nicht auf localhost laufen. Ausnahme: `$CFG->sideline_allow_load_seed = true`.

In GitHub: Workflow **Load test (k6)** manuell starten (`.github/workflows/load-k6.yml`).

## Messung vom 29.10.2026
Moodle 5.3, PostgreSQL 17, PHP 8.3, eingebauter Server mit 8 Workern auf **2 CPU-Kernen**, 50 Lernende, 20 Aktivitäten, 20 gleichzeitige Lernende für 60 s, danach 20 Sitzungen im Race.

| Messgröße | Ergebnis |
|---|---|
| Anfragen | 2549, davon 0 fehlgeschlagen; 0 Web-Service-Fehler; 4593 von 4593 Checks erfüllt |
| Kommentar aus dem Cache, p95 | 541 ms (Median 255 ms) |
| Kommentar nach Zustandswechsel, p95 | 529 ms (Median 302 ms) |
| Race: 20 Sitzungen derselben Person gleichzeitig | alle beantwortet, längste 545 ms; danach genau eine aktuelle Cache-Zeile (`verify_load.php`: OK) |

Bei Cache-Treffer und Neuerzeugung liegen die Zeiten nah beieinander. Die Antwortzeit wird vom Moodle-Bootstrap je Anfrage und von den zwei CPU-Kernen bestimmt, nicht vom Plugin. Ohne HTTP misst `tools/bench.php` 9 ms (Treffer) und 36 ms (Erzeugung), siehe `docs/TRACEABILITY.md`.
