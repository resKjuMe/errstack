<?php

namespace App\Support\Ingest\Filtering;

/**
 * Was die Filter ohne eigene Liste erkennen.
 *
 * Drei der sieben Arten — Browser-Erweiterungen, lokale Entwicklung,
 * Crawler — kommen ohne Eintrag aus, und das ist der Grund, warum sie
 * überhaupt eigene Arten sind: was eine Erweiterung ist, ändert sich nicht je
 * Projekt. Stünde es in den Listen, müsste jedes Projekt dieselben zwanzig
 * Zeilen eintragen, und beim nächsten Browser-Hersteller wären es
 * hundertfünfzig Projekte zum Nachpflegen.
 *
 * Die Listen sind absichtlich **eng** gehalten — das Gegenteil der
 * Datenschutz-Standardregeln, wo Großzügigkeit die richtige Wahl ist. Hier
 * kostet ein Fehltreffer eine Fehlermeldung, die nie ankommt und deren Fehlen
 * niemandem auffällt. Im Zweifel also nicht filtern.
 */
final class Defaults
{
    /**
     * Adressschemata, unter denen Browser ihre Erweiterungen laden.
     *
     * Der zuverlässigste Hinweis überhaupt: eine Datei unter
     * `chrome-extension://` gehört keiner Anwendung, die wir überwachen.
     * Verglichen wird der Anfang der Datei-Angabe eines Stapelrahmens.
     *
     * @var list<string>
     */
    public const EXTENSION_SCHEMES = [
        'chrome-extension://',
        'moz-extension://',
        'safari-extension://',
        'safari-web-extension://',
        'ms-browser-extension://',
        'chrome://',
        'resource://',
        'webkit-masked-url://',
    ];

    /**
     * Herkünfte, die zwar über `https` geladen werden, aber trotzdem keine
     * Anwendung sind: Virenscanner und Werkzeugleisten, die sich in die Seite
     * einklinken.
     *
     * @var list<string>
     */
    public const EXTENSION_HOSTS = [
        '*kaspersky-labs.com*',
        '*127.0.0.1:4001/isrunning*',
        '*webappstoolbarba.texthelp.com*',
        '*metrics.itunes.apple.com*',
    ];

    /**
     * Fehlertexte, die Erweiterungen und eingeklinkte Skripte hinterlassen.
     *
     * Sie tragen keinen Stapelrahmen, an dem sie zu erkennen wären — das ist
     * der Grund, warum diese Liste neben den Schemata steht. Übernommen von
     * Sentry, wo dieselben Texte seit Jahren dieselben Erweiterungen verraten.
     *
     * @var list<string>
     */
    public const EXTENSION_MESSAGES = [
        '*top.GLOBALS*',
        '*originalCreateNotification*',
        '*canvas.contentDocument*',
        '*MyApp_RemoveAllHighlights*',
        '*http://tt.epicplay.com*',
        "*Can't find variable: ZiteReader*",
        '*jigsaw is not defined*',
        '*ComboSearch is not defined*',
        '*http://loading.retry.widdit.com/*',
        '*atomicFindClose*',
        '*conduitPage*',
        '*window.bannerNight*',
    ];

    /**
     * Kennzeichen im User-Agent, an denen ein Crawler zu erkennen ist.
     *
     * Die allgemeinen Wörter zuerst — sie decken den größten Teil ab, weil
     * ernsthafte Betreiber ihren Roboter als solchen ausweisen. Die namentlich
     * genannten sind die, die das nicht tun.
     *
     * @var list<string>
     */
    public const CRAWLERS = [
        // Mit Trennzeichen und nicht als nackter Teiltreffer: `*bot*` trifft
        // auch `CUBOT NOTE 20`, und das ist ein Android-Telefon und kein
        // Roboter. Ein Crawler weist sich dagegen als `Googlebot/2.1`,
        // `Googlebot-Image/1.0` oder schlicht `Applebot` aus — mit
        // Schrägstrich, Strichpunkt, Klammer, Bindestrich oder Ende dahinter.
        //
        // Das Leerzeichen fehlt bewusst: `*bot *` fängt `Slackbot 1.0` ein,
        // aber eben auch `CUBOT NOTE 20 Build/…`. Der Bindestrich ist die
        // teurere Wahl in dieselbe Richtung — `CUBOT-X30` gibt es —, ohne ihn
        // fiele aber die halbe Google-Familie durch, und die kommt in Mengen.
        '*bot/*', '*bot)*', '*bot;*', '*bot-*', '*bot',
        '*crawler*',
        '*spider*',
        '*slurp*',
        '*facebookexternalhit*',
        '*mediapartners-google*',
        '*feedfetcher*',
        '*headlesschrome*',
        '*phantomjs*',
        '*pingdom*',
        '*lighthouse*',
        '*curl/*',
        '*wget/*',
        '*python-requests/*',
        '*postmanruntime/*',
    ];

    /**
     * Rechnernamen und Adressen, die für lokale Entwicklung stehen.
     *
     * `*.test` und `*.localhost` sind dabei, weil die üblichen
     * Entwicklungsumgebungen genau diese Endungen vergeben — ohne sie greift
     * der Filter bei niemandem, der mit mehr als einer Anwendung zugleich
     * arbeitet.
     *
     * **`*.local` steht bewusst nicht dabei**, obwohl es naheliegt. Die Endung
     * gehört nicht nur der Namensauflösung im Heimnetz: Kubernetes vergibt
     * `dienst.namensraum.svc.cluster.local`, und in Windows-Netzen heißt die
     * Produktionsmaschine `web01.firma.local`. Wer „lokale Entwicklung"
     * einschaltet, verlöre damit die Meldungen seiner Produktion — und zwar
     * ohne Lücke in der Liste, an der es aufzufallen hätte.
     *
     * @var list<string>
     */
    public const LOCAL_HOSTS = [
        'localhost',
        '127.0.0.1',
        '0.0.0.0',
        '::1',
        '*.localhost',
        '*.test',
        '*.localdomain',
    ];

    /**
     * Ab welcher Fassung ein Browser als aktuell genug gilt — solange ein
     * Projekt nichts anderes einträgt.
     *
     * Ein eingeschalteter Filter ohne Eintrag täte sonst nichts, und das wäre
     * die schlechtere Vorgabe: wer „veraltete Browser" anschaltet, meint die
     * Browser, die niemand mehr bedient, und will dafür keine Fassungsnummern
     * nachschlagen.
     *
     * `null` heißt „ganz und gar veraltet" — für den Internet Explorer gibt es
     * keine Fassung, die noch unterstützt würde.
     *
     * @var array<string, int|null>
     */
    public const BROWSER_VERSIONS = [
        'ie' => null,
        'internet explorer' => null,
        'edge' => 79,
        'opera' => 15,
        'opera mini' => 8,
        'safari' => 6,
        'mobile safari' => 6,
        'android' => 4,
    ];
}
