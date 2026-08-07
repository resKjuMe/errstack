# Sprachen

Errstack liegt auf Deutsch und Englisch vor. Übersetzt wird **serverseitig**;
das Frontend bekommt nur das fertige Ergebnis.

## Woher die Sprache kommt

`App\Support\Locales::resolve()` entscheidet je Anfrage, in dieser Reihenfolge:

1. die am Konto gespeicherte Wahl (`users.locale`),
2. der `Accept-Language`-Kopf des Browsers,
3. die Vorgabe aus `config('app.locale')`.

Gesetzt wird sie in `App\Http\Middleware\SetLocale`, das in der Gruppe `web`
hinter `StartSession` hängt — nur dort ist das angemeldete Konto bekannt.

Für alles, was **außerhalb** einer Anfrage entsteht (E-Mails, Benachrichtigungen
aus der Warteschlange), liest Laravel die Sprache über
`User::preferredLocale()` (`HasLocalePreference`). Wer an eine bloße Adresse
schickt, hat keinen Anhaltspunkt und nimmt `Locales::fallback()` — die Sprache
des Absenders wäre geraten.

## Wo die Texte stehen

`lang/de/*.php` und `lang/en/*.php`, eine Datei je Bereich:

| Datei | Inhalt |
|---|---|
| `common`, `nav` | Wiederkehrendes, Navigation, Nutzer-Menü |
| `auth_ui`, `profile` | Anmeldeseiten, Profil |
| `dashboard`, `filters`, `components` | Übersicht, Filterleiste, Musterseite |
| `projects`, `project_keys`, `organizations`, `teams` | Fachseiten samt Flash-Meldungen |
| `notifications`, `channels` | Benachrichtigungen und ihre Wege |
| `api_tokens`, `audit`, `invitations` | Zugriffstoken, Änderungsprotokoll, Einladungen |
| `emails` | Texte der versendeten E-Mails |
| `enums` | Beschriftungen der Aufzählungen (`app/Enums`) |
| `formats` | Schreibweise von Datum, Uhrzeit und Zahlen |
| `auth`, `passwords`, `validation` | Meldungen des Frameworks |

## Wie die Oberfläche sie liest

`App\Support\Translations::forInterface()` legt die Gruppen aus
`Translations::GROUPS` flach zusammen (`projects.settings.name`) und hängt sie
als Inertia-Shared-Prop `translations` an **jede** Antwort — nicht nur an die
erste: nach einem Sprachwechsel kommt die nächste Antwort ohne neues Root-Blade,
und eine einmal eingebettete Tabelle bliebe in der alten Sprache stehen.

In React:

```jsx
import { useT } from '../i18n.js';

const t = useT();
t('projects.create.description', { organization: name });
```

Die Platzhalter sind dieselben wie in Laravel (`:name`).

## Datum, Uhrzeit, Zahlen

Serverseitig über `App\Support\Formats` (Muster aus `lang/<sprache>/formats.php`),
im Browser über `formatNumber`/`formatDateTime` aus `resources/js/shell/i18n.js`
mit der BCP-47-Kennung aus `formats.intl`. Kein `d.m.Y` im Code.

## Eine Sprache ergänzen

1. `Locales::SUPPORTED` erweitern.
2. `lang/<sprache>/` anlegen — **alle** Dateien, **alle** Schlüssel.
3. `common.locales.<sprache>` in jeder Sprache ergänzen (der Name im Auswahlfeld).
4. `tests/Feature/TranslationParityTest.php` laufen lassen: er meldet jeden
   fehlenden Schlüssel, jede fehlende Datei und jeden Schlüssel, der sich nicht
   nachschlagen lässt.

## Was bewusst nicht übersetzt wird

- **Ausnahmen für Programmierfehler** (fehlende Middleware, unbekannter Kanal) —
  sie erreichen nie einen Nutzer.
- **Gespeicherte Daten**: Namen, Adressen, der Akteur „System" im Protokoll.
  Das Änderungsprotokoll speichert dafür neutrale Feldschlüssel (`role`) und bei
  Aufzählungen deren Wert (`member`); übersetzt wird erst beim Anzeigen — sonst
  stünde in derselben Spalte einmal Deutsch und einmal Englisch, je nachdem wer
  geklickt hat.
