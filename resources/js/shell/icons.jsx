import React from 'react';

// SVG-Icons der App-Shell. `className` wird durchgereicht, die Farbe kommt über
// currentColor vom umgebenden Text.

// Errstack-Logo: Bildmarke (Blitz im abgerundeten Quadrat) + Wortmarke. Der
// Schriftzug ist hell/dunkel-adaptiv. Größe über markClassName/nameClassName —
// die Gast-Seiten zeigen das Logo größer als die Kopfzeile.
export function LogoIcon({
    appName = 'Errstack',
    className = '',
    markClassName = 'h-8 w-8 p-1.5',
    nameClassName = 'text-xl',
}) {
    return (
        <span className={`inline-flex items-center gap-2 ${className}`}>
            <svg
                className={`shrink-0 rounded-md bg-rose-600 text-white ${markClassName}`}
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
            >
                <path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z" />
            </svg>
            {/* Ohne Namen bleibt die Bildmarke allein — so zeigt die
                eingeklappte Seitenleiste kein leeres Feld neben dem Zeichen. */}
            {appName && (
                <span
                    className={`font-semibold tracking-tight text-gray-800 dark:text-gray-100 ${nameClassName}`}
                >
                    {appName}
                </span>
            )}
        </span>
    );
}

// Hamburger: geschlossen drei Striche, offen ein Kreuz (wie in der Blade-Navi).
export function HamburgerIcon({ open = false, className = '' }) {
    return (
        <svg
            className={className}
            stroke="currentColor"
            fill="none"
            viewBox="0 0 24 24"
            aria-hidden="true"
        >
            <path
                className={open ? 'hidden' : 'inline-flex'}
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="2"
                d="M4 6h16M4 12h16M4 18h16"
            />
            <path
                className={open ? 'inline-flex' : 'hidden'}
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="2"
                d="M6 18L18 6M6 6l12 12"
            />
        </svg>
    );
}

export function ChevronDownIcon({ className = '' }) {
    return (
        <svg className={className} viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path
                fillRule="evenodd"
                d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                clipRule="evenodd"
            />
        </svg>
    );
}

export function SunIcon({ className = '' }) {
    return (
        <svg
            className={className}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2v2" />
            <path d="M12 20v2" />
            <path d="m4.93 4.93 1.41 1.41" />
            <path d="m17.66 17.66 1.41 1.41" />
            <path d="M2 12h2" />
            <path d="M20 12h2" />
            <path d="m6.34 17.66-1.41 1.41" />
            <path d="m19.07 4.93-1.41 1.41" />
        </svg>
    );
}

export function MoonIcon({ className = '' }) {
    return (
        <svg
            className={className}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z" />
        </svg>
    );
}

export function MonitorIcon({ className = '' }) {
    return (
        <svg
            className={className}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <rect x="2" y="3" width="20" height="14" rx="2" />
            <path d="M8 21h8" />
            <path d="M12 17v4" />
        </svg>
    );
}

export function QuestionIcon({ className = '' }) {
    return (
        <svg
            className={className}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <circle cx="12" cy="12" r="10" />
            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
            <path d="M12 17h.01" />
        </svg>
    );
}

// Farbe der Plattform-Kachel. Unbekannte Plattformen bekommen den grauen
// Standard, damit ein neuer Enum-Wert nichts kaputt macht.
const PLATFORM_COLORS = {
    php: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
    javascript: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    python: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
    node: 'bg-lime-100 text-lime-700 dark:bg-lime-900/40 dark:text-lime-300',
    java: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
    go: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-300',
    ruby: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    dotnet: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
};

const PLATFORM_FALLBACK = 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300';

// Symbol einer Plattform: farbige Kachel mit dem Kürzel aus dem PHP-Enum.
// Bewusst keine Marken-Logos — die wären lizenzbehaftet und müssten für jede
// neue Plattform gepflegt werden.
export function PlatformIcon({ platform, short, label, className = '' }) {
    return (
        <span
            title={label}
            aria-label={label}
            role="img"
            className={`inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-[0.625rem] font-bold uppercase ${
                PLATFORM_COLORS[platform] ?? PLATFORM_FALLBACK
            } ${className}`}
        >
            {short}
        </span>
    );
}

// Icons der Menü-Einträge (Nutzer-Menü, Primärlinks). Unbekannte Namen liefern
// nichts, damit ein neuer Eintrag ohne Icon kein Loch reißt.
const MENU_ICONS = {
    profile: (
        <>
            <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
            <circle cx="12" cy="7" r="4" />
        </>
    ),
    components: (
        <>
            <rect x="3" y="3" width="7" height="7" rx="1" />
            <rect x="14" y="3" width="7" height="7" rx="1" />
            <rect x="3" y="14" width="7" height="7" rx="1" />
            <rect x="14" y="14" width="7" height="7" rx="1" />
        </>
    ),
    bell: (
        <>
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
            <path d="M13.7 21a2 2 0 0 1-3.4 0" />
        </>
    ),
    key: (
        <>
            <circle cx="7.5" cy="15.5" r="4.5" />
            <path d="m10.7 12.3 8.8-8.8" />
            <path d="m17 6 3 3" />
            <path d="m14 9 3 3" />
        </>
    ),
    // Betrieb: ein Herzschlag auf einem Monitor — der Zustand der eigenen
    // Installation.
    pulse: (
        <>
            <rect x="2" y="4" width="20" height="14" rx="2" />
            <path d="M6 11h3l1.5-3 2 6 1.5-3h3" />
            <path d="M9 21h6" />
        </>
    ),
    logout: (
        <>
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
            <path d="m16 17 5-5-5-5" />
            <path d="M21 12H9" />
        </>
    ),
    login: (
        <>
            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
            <path d="m10 17 5-5-5-5" />
            <path d="M15 12H3" />
        </>
    ),
    // Übersicht: vier Kacheln, das Bild einer Startseite.
    dashboard: (
        <>
            <rect x="3" y="3" width="7" height="9" rx="1" />
            <rect x="14" y="3" width="7" height="5" rx="1" />
            <rect x="14" y="12" width="7" height="9" rx="1" />
            <rect x="3" y="16" width="7" height="5" rx="1" />
        </>
    ),
    // Fehler: das Warnzeichen — der Kern der Anwendung.
    issues: (
        <>
            <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0" />
            <path d="M12 9v4" />
            <path d="M12 17h.01" />
        </>
    ),
    // Rückmeldungen: eine Sprechblase — jemand hat etwas geschrieben.
    feedback: <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />,
    // Merkmale: das Preisschild, an dem Eigenschaften hängen.
    tags: (
        <>
            <path d="M20.6 13.4 12 4.8V2H4a2 2 0 0 0-2 2v8h2.8l8.6 8.6a2 2 0 0 0 2.8 0l4.4-4.4a2 2 0 0 0 0-2.8" />
            <circle cx="7" cy="7" r="1.2" />
        </>
    ),
    // Dashboards: vier Kacheln unterschiedlicher Größe — das Raster selbst und
    // nicht eine der Darstellungsarten darin.
    dashboards: (
        <>
            <rect x="3" y="3" width="8" height="7" rx="1" />
            <rect x="13" y="3" width="8" height="4" rx="1" />
            <rect x="3" y="12" width="8" height="9" rx="1" />
            <rect x="13" y="9" width="8" height="12" rx="1" />
        </>
    ),
    // Freie Auswertung: die Lupe über einem Balkendiagramm — selbst
    // zusammengestellte Zahlen, nicht eine fertige Ansicht.
    discover: (
        <>
            <path d="M4 20V10" />
            <path d="M9 20v-6" />
            <path d="M14 20V4" />
            <circle cx="18" cy="15" r="3" />
            <path d="m20.2 17.2 1.8 1.8" />
        </>
    ),
    // Leistung: die Anzeige eines Tachometers.
    performance: (
        <>
            <path d="M4 18a9 9 0 1 1 16 0" />
            <path d="m12 15 4-5" />
            <circle cx="12" cy="16" r="1.2" />
        </>
    ),
    // Leistungsprobleme: die Stoppuhr mit Ausrufezeichen.
    performance_issues: (
        <>
            <circle cx="12" cy="14" r="7" />
            <path d="M12 11v3" />
            <path d="M12 17h.01" />
            <path d="M9 2h6" />
        </>
    ),
    // Ladeerlebnis: was der Besucher im Browser erlebt.
    web_vitals: (
        <>
            <rect x="2" y="4" width="20" height="16" rx="2" />
            <path d="M2 9h20" />
            <path d="m7 16 3-3 2 2 3-4" />
        </>
    ),
    // Profile: die Balken eines Flammendiagramms.
    profiling: (
        <>
            <rect x="3" y="4" width="18" height="4" rx="1" />
            <rect x="3" y="10" width="12" height="4" rx="1" />
            <rect x="3" y="16" width="7" height="4" rx="1" />
        </>
    ),
    // Aufzeichnungen: der Bildschirm mit dem Abspielzeichen darauf.
    replays: (
        <>
            <rect x="2" y="4" width="20" height="14" rx="2" />
            <path d="M10 9.5v3l3-1.5z" />
            <path d="M8 21h8" />
        </>
    ),
    // Versionen: das ausgelieferte Paket.
    releases: (
        <>
            <path d="M21 8 12 3 3 8v8l9 5 9-5z" />
            <path d="m3 8 9 5 9-5" />
            <path d="M12 13v8" />
        </>
    ),
    // Projekte: der Ordner, in dem eine Anwendung steckt.
    projects: (
        <path d="M3 7a2 2 0 0 1 2-2h4l2 3h8a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
    ),
    // Organisationen: das Gebäude über allem.
    organizations: (
        <>
            <path d="M3 21h18" />
            <path d="M5 21V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v16" />
            <path d="M15 21V11h2a2 2 0 0 1 2 2v8" />
            <path d="M9 7h2" />
            <path d="M9 11h2" />
            <path d="M9 15h2" />
        </>
    ),
    // Einstellungen: das Zahnrad im Fuß der Leiste. Sechs Zähne als Speichen um
    // die Nabe — als geschlossener Umriss gezeichnet wäre der Pfad ein Vielfaches
    // so lang und bei 20 px nicht besser zu erkennen.
    settings: (
        <>
            <circle cx="12" cy="12" r="3.2" />
            <circle cx="12" cy="12" r="8" />
            <path d="M12 4v2.5" />
            <path d="M12 17.5V20" />
            <path d="m4.9 8 2.2 1.3" />
            <path d="m16.9 14.7 2.2 1.3" />
            <path d="m4.9 16 2.2-1.3" />
            <path d="m16.9 9.3 2.2-1.3" />
        </>
    ),
    // Anlegen: das Pluszeichen im Menü des Umschalters.
    plus: (
        <>
            <path d="M12 5v14" />
            <path d="M5 12h14" />
        </>
    ),
};

export function MenuIcon({ name, className = '' }) {
    const paths = MENU_ICONS[name];

    if (!paths) {
        return null;
    }

    return (
        <svg
            className={className}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            {paths}
        </svg>
    );
}

// Ein- und Ausklappen der Seitenleiste: ein Panel, dessen Pfeil in die Richtung
// zeigt, in die es geht.
export function SidebarToggleIcon({ collapsed = false, className = '' }) {
    return (
        <svg
            className={className}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <rect x="3" y="4" width="18" height="16" rx="2" />
            <path d="M9 4v16" />
            {collapsed ? <path d="m13 9 3 3-3 3" /> : <path d="m17 9-3 3 3 3" />}
        </svg>
    );
}
