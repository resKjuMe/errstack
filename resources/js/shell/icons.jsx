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
            <span
                className={`font-semibold tracking-tight text-gray-800 dark:text-gray-100 ${nameClassName}`}
            >
                {appName}
            </span>
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
