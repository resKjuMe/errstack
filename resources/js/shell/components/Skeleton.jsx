import React from 'react';

// Wiederverwendbare Ladeplatzhalter. Alle Bausteine pulsieren synchron
// (animate-pulse am jeweiligen Wurzelknoten) und nutzen dieselben Grautöne wie
// das restliche UI. Rein dekorativ — die aufrufende Ansicht setzt aria-busy
// bzw. entscheidet, wann der Platzhalter dem Inhalt weicht.

const bar = 'rounded bg-gray-200 dark:bg-gray-700';

// Reihe von Kennzahl-Kacheln.
export function KpiTilesSkeleton({ count = 4 }) {
    return (
        <div className="grid animate-pulse grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-hidden="true">
            {Array.from({ length: count }).map((_, i) => (
                <div key={i} className="rounded-lg bg-white p-5 shadow dark:bg-gray-800">
                    <div className={`h-3 w-24 ${bar}`} />
                    <div className={`mt-3 h-8 w-20 ${bar}`} />
                    <div className={`mt-3 h-1.5 w-full rounded-full ${bar}`} />
                </div>
            ))}
        </div>
    );
}

// Karte mit mehreren Textzeilen.
export function LinesCardSkeleton({ rows = 3 }) {
    return (
        <div className="animate-pulse rounded-lg bg-white p-6 shadow dark:bg-gray-800" aria-hidden="true">
            <div className={`h-3 w-32 ${bar}`} />
            <div className="mt-4 space-y-4">
                {Array.from({ length: rows }).map((_, i) => (
                    <div key={i} className="rounded-lg p-4 ring-1 ring-gray-100 dark:ring-gray-700">
                        <div className="flex items-center justify-between">
                            <div className={`h-4 w-32 ${bar}`} />
                            <div className={`h-4 w-24 ${bar}`} />
                        </div>
                        <div className={`mt-3 h-2.5 w-full rounded-full ${bar}`} />
                    </div>
                ))}
            </div>
        </div>
    );
}

// Karten-Raster. `cols` steuert die Spaltenzahl ab sm/lg, `lines` die Zahl der
// Textzeilen im Kartenkörper (0 = nur die Kopfzeile).
export function CardsSkeleton({ count = 3, cols = 3, lines = 2 }) {
    const grid =
        { 1: '', 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-2 lg:grid-cols-3' }[cols] ?? 'sm:grid-cols-2 lg:grid-cols-3';

    return (
        <div className={`grid animate-pulse grid-cols-1 gap-4 ${grid}`} aria-hidden="true">
            {Array.from({ length: count }).map((_, i) => (
                <div key={i} className="rounded-lg bg-white p-5 shadow dark:bg-gray-800">
                    <div className={`h-4 w-40 ${bar}`} />
                    {lines > 0 && (
                        <div className="mt-4 space-y-2">
                            {Array.from({ length: lines }).map((__, j) => (
                                <div key={j} className={`h-3 ${j === lines - 1 ? 'w-2/3' : 'w-full'} ${bar}`} />
                            ))}
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}

// Tabellen-Platzhalter (Kopfzeile + Datenzeilen).
export function TableSkeleton({ rows = 5, cols = 4 }) {
    return (
        <div className="animate-pulse overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800" aria-hidden="true">
            <div className="flex gap-4 border-b border-gray-100 px-5 py-3 dark:border-gray-700">
                {Array.from({ length: cols }).map((_, i) => (
                    <div key={i} className={`h-3 flex-1 ${bar}`} />
                ))}
            </div>
            {Array.from({ length: rows }).map((_, i) => (
                <div key={i} className="flex gap-4 px-5 py-4">
                    {Array.from({ length: cols }).map((__, j) => (
                        <div key={j} className={`h-4 flex-1 ${bar}`} />
                    ))}
                </div>
            ))}
        </div>
    );
}
