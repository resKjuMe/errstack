# Errstack

Selbstgehosteter Error-Tracker: Fehler aus Anwendungen entgegennehmen, zu
Issues gruppieren, durchsuchbar machen und alarmieren.

Dieses Repository enthält bislang **nur das Grundgerüst** — Laravel 13 auf
PHP 8.3, PHPUnit 12, Pint sowie die Oberfläche (Inertia 3, React 19, Tailwind 4)
mit Navigation, Dunkelmodus und wiederverwendbaren Bausteinen unter
`resources/js/shell`. Fachlogik folgt in den nächsten Phasen.

## Installation

Voraussetzungen: PHP 8.3+ mit `pdo_sqlite`, Composer, Node 20+.

```bash
git clone git@github.com:resKjuMe/errstack.git
cd errstack
composer setup   # install, .env, App-Key, Migrationen, npm install, Build
composer dev     # Server, Queue, Logs und Vite starten
```

Danach läuft die Anwendung auf http://localhost:8000.

## Werkzeuge

| Befehl | Zweck |
| --- | --- |
| `composer setup` | einmalige Installation (auch auf einem frischen Rechner) |
| `composer dev` | Entwicklungsumgebung starten |
| `composer test` | PHPUnit (Suites `Unit` und `Feature`) |
| `vendor/bin/pint` | Formatierung (`--test` prüft nur) |

Datenbank ist standardmäßig SQLite (`database/database.sqlite`); für eine andere
Datenbank `DB_CONNECTION` in der `.env` umstellen.

## Aufbau

Verzeichnisse und Konventionen sind absichtlich identisch zu
[Planstack](https://github.com/resKjuMe/planstack) gehalten, damit Muster 1:1
übertragbar sind: `app/Concerns`, `app/Enums`, `app/Support`,
`app/Http/Controllers/Api`, Routen getrennt in
`routes/web.php`, `api.php`, `auth.php`, `console.php`, Tests in
`tests/Unit` und `tests/Feature`.
