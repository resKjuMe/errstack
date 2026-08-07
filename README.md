# Errstack

Selbstgehosteter Error-Tracker: Fehler aus Anwendungen entgegennehmen, zu
Issues gruppieren, durchsuchbar machen und alarmieren.

Dieses Repository enthält bislang **nur das Grundgerüst** — Laravel 13 auf
PHP 8.4, PHPUnit 12, Pint, PHPStan und Prettier sowie die Oberfläche
(Inertia 3, React 19, Tailwind 4) mit Navigation, Dunkelmodus und
wiederverwendbaren Bausteinen unter `resources/js/shell`. Fachlogik folgt in
den nächsten Phasen.

## Installation

Voraussetzungen: PHP 8.4+ mit `pdo_sqlite`, Composer, Node 20+.
(Laravel 13 zieht Symfony 8 nach, das PHP 8.4.1 voraussetzt — auf 8.3 bricht
schon `composer install` ab.)

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
| `composer check` | alle PHP-Prüfungen nacheinander (`lint`, `analyse`, `test`) |
| `composer lint` | Formatprüfung PHP (Pint, prüft nur) |
| `composer format` | Formatierung PHP anwenden (Pint) |
| `composer analyse` | statische Analyse (PHPStan/Larastan, Stufe 6) |
| `composer test` | PHPUnit (Suites `Unit` und `Feature`) |
| `composer audit --locked` | Abhängigkeits-Audit PHP |
| `npm run format:check` | Formatprüfung JavaScript/CSS/JSON (Prettier, prüft nur) |
| `npm run format` | Formatierung JavaScript/CSS/JSON anwenden (Prettier) |
| `npm run build` | Produktions-Build (Vite) |
| `npm audit --audit-level=high` | Abhängigkeits-Audit JavaScript |

Datenbank ist standardmäßig SQLite (`database/database.sqlite`); für eine andere
Datenbank `DB_CONNECTION` in der `.env` umstellen.

Die Oberfläche ist deutsch, deshalb gehört `APP_LOCALE=de` in die `.env`
(`APP_FALLBACK_LOCALE` bleibt `en`, damit Schlüssel ohne deutsche Übersetzung
weiterhin greifen). Wer die `.env` vor der Anmeldung (F3) angelegt hat, hat dort
noch `APP_LOCALE=en` stehen — die `.env` ist nicht versioniert und wird von
`composer setup` nicht nachgezogen. Die Anwendung sieht dann fast deutsch aus,
nur die Meldungen aus dem Framework kommen englisch heraus („The password field
is required." statt „Das Passwort ist erforderlich.").

`composer dev` startet neben Server und Vite auch den Queue-Worker, den
Websocket-Server (Reverb) und den Zeitplan.

## Automatische Prüfungen

Jeder Pull Request löst den Workflow `.github/workflows/ci.yml` aus. Die Jobs
laufen parallel und rufen **genau die Befehle aus der Tabelle oben** auf — es
gibt keine Sonderpfade, die es nur in der Pipeline gäbe:

| Job | Befehl |
| --- | --- |
| Format PHP (Pint) | `composer lint` |
| Statische Analyse (PHPStan) | `composer analyse` |
| Tests (PHP 8.4) | `php artisan migrate --force`, `composer test` |
| Frontend (Format & Build) | `npm ci`, `npm run format:check`, `npm run build` |
| Abhängigkeits-Audit | `composer audit --locked`, `npm audit --audit-level=high` |

Dasselbe lokal, vor dem Push:

```bash
composer check                                    # lint + analyse + test
npm run format:check && npm run build
composer audit --locked && npm audit --audit-level=high
```

Der abschließende Job **CI erfolgreich** fasst die Ergebnisse zusammen und ist
als Pflicht-Prüfung für `main` hinterlegt: Solange er nicht grün ist, lässt sich
ein Pull Request nicht mergen. Composer- und npm-Zwischenspeicher sind aktiv;
ein vollständiger Lauf bleibt dadurch deutlich unter zehn Minuten.

Erledigt Prettier die JavaScript-Formatierung, bleiben PHP (Pint), Markdown und
`composer.json` bewusst außen vor — siehe `.prettierignore`.

`package-lock.json` ist **für Linux** aufgelöst (`npm install --package-lock-only
--os=linux --cpu=x64 --libc=glibc`), weil `npm ci` in der Pipeline auf Linux
läuft und ein unter Windows erzeugtes Lockfile dort mit „Missing …" abbricht —
die optionalen wasm-Pakete von Tailwind lösen sich je Plattform anders auf.
Lokal deshalb `npm install` benutzen (macht `composer setup` ohnehin) und ein
dabei umgeschriebenes Lockfile **nicht** mitcommitten.

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
