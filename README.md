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

`composer dev` startet neben Server und Vite auch den Queue-Worker, den
Websocket-Server (Reverb) und den Zeitplan.

## Hintergrund-Verarbeitung

Warteschlangen laufen über die Datenbank (`QUEUE_CONNECTION=database`). Es gibt
drei, in dieser Priorität: **`ingest`** (eingehende Fehlermeldungen) vor
**`notifications`** (Benachrichtigungen, Broadcasts) vor `default` — die
Reihenfolge steht in `App\Enums\QueueName` und gehört in jeden Worker-Aufruf:

```bash
php artisan queue:work --queue=ingest,notifications,default --tries=3
```

| Befehl | Zweck |
| --- | --- |
| `php artisan queue:failed` | Fehlerablage ansehen (Tabelle `failed_jobs`) |
| `php artisan queue:retry all` | fehlgeschlagene Jobs erneut einreihen |
| `php artisan queue:monitor ingest,notifications --max=100` | Warteschlangen-Länge überwachen |
| `php artisan schedule:list` | geplante Aufgaben anzeigen |
| `php artisan demo:ingest [--fail]` | Beispiel-Job einreihen (auch als Knopf auf der Übersicht) |

Der Zeitplan (`routes/console.php`) läuft in der Entwicklung über
`php artisan schedule:work`, auf dem Server über einen Minuten-Cron:

```cron
* * * * * cd /pfad/zu/errstack && php artisan schedule:run >> /dev/null 2>&1
```

## Live-Aktualisierung

Broadcasts erreichen offene Ansichten ohne Neuladen. Lokal übernimmt das der
selbst gehostete **Reverb** (`php artisan reverb:start`, Teil von
`composer dev`), in der Produktion kann stattdessen **Pusher Cloud** eingetragen
werden — dasselbe Protokoll, im Browser in beiden Fällen `pusher-js`:

```dotenv
BROADCAST_CONNECTION=reverb   # oder: pusher
```

Ohne Verbindungsdaten bleibt die Live-Aktualisierung einfach aus (`shell.broadcast.enabled`
= false); Jobs laufen trotzdem. Zum Ausprobieren die Übersicht in zwei Fenstern
öffnen und „Ingest einreihen" drücken.

## Aufbau

Verzeichnisse und Konventionen sind absichtlich identisch zu
[Planstack](https://github.com/resKjuMe/planstack) gehalten, damit Muster 1:1
übertragbar sind: `app/Concerns`, `app/Enums`, `app/Support`,
`app/Http/Controllers/Api`, Routen getrennt in
`routes/web.php`, `api.php`, `auth.php`, `console.php`, Tests in
`tests/Unit` und `tests/Feature`.
