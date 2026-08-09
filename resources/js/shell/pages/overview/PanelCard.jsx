import React from 'react';
import { Link } from '@inertiajs/react';
import { useTranslations } from '../../i18n.js';
import { usePanel } from './usePanel.js';
import SeriesPanel from './SeriesPanel.jsx';
import RowsPanel from './RowsPanel.jsx';
import StatsPanel from './StatsPanel.jsx';
import SetupHint from './SetupHint.jsx';

// Eine Kachel einer Übersichtsseite: Überschrift, Inhalt, und der Weg in die
// Ansicht dahinter.
//
// **Die Zahlen holt sie selbst.** Die Seite liefert nur die Adresse; der Abruf
// läuft neben dem der anderen Kacheln. Solange er läuft, steht die Kachel
// bereits da — mit Überschrift und Rahmen —, statt dass der Bildschirm leer
// bleibt.
//
// **Was fehlt, sagt warum.** „Noch nicht angeschlossen", „im Zeitraum nichts
// passiert" und „die Abfrage wurde abgelehnt" sind drei verschiedene Auskünfte.
// Keine davon darf wie ein Diagramm mit einer Nulllinie aussehen — das ist der
// Punkt, an dem eine Übersicht anfängt, in die Irre zu führen.
export default function PanelCard({ href, title, description, emptyText = null, className = '' }) {
    const { t, formats } = useTranslations();
    const { status, panel, reload } = usePanel(href);

    return (
        <section
            className={`flex flex-col rounded-lg bg-white p-4 shadow dark:bg-gray-800 ${className}`}
            aria-label={title}
        >
            <header className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">
                        {title}
                    </h2>
                    {description && (
                        <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {description}
                        </p>
                    )}
                </div>

                {panel?.href && (
                    <Link
                        href={panel.href}
                        className="shrink-0 text-xs font-medium text-rose-600 hover:text-rose-500 dark:text-rose-400"
                    >
                        {t('overview.panel.all')}
                    </Link>
                )}
            </header>

            <div className="mt-3 flex-1">
                <Body
                    status={status}
                    panel={panel}
                    reload={reload}
                    emptyText={emptyText}
                    t={t}
                    formats={formats}
                />
            </div>
        </section>
    );
}

function Body({ status, panel, reload, emptyText, t, formats }) {
    // Beim ersten Abruf steht der Platzhalter; bei jedem weiteren bleiben die
    // alten Zahlen stehen, bis die neuen da sind. Eine Kachel, die bei jedem
    // Wechsel des Zeitraums kurz leer wird, flackert bei fünf Kacheln fünfmal.
    if (status === 'loading' && panel === null) {
        return <Placeholder />;
    }

    if (status === 'failed' && panel === null) {
        return (
            <div className="text-sm text-gray-500 dark:text-gray-400">
                <p>{t('overview.panel.failed')}</p>
                <button
                    type="button"
                    onClick={reload}
                    className="mt-2 text-xs font-medium text-rose-600 hover:text-rose-500 dark:text-rose-400"
                >
                    {t('overview.panel.retry')}
                </button>
            </div>
        );
    }

    if (panel === null) {
        return null;
    }

    // Der Motor hat nicht gerechnet und sagt warum — mit Grenze und verlangtem
    // Wert. Das ist eine Auskunft und kein Loch.
    if (panel.error) {
        return (
            <p className="text-sm text-amber-700 dark:text-amber-400">
                {panel.error.message ?? t('overview.panel.failed')}
            </p>
        );
    }

    // Steht alles noch aus, tritt der Einrichtungs-Hinweis an die Stelle des
    // Inhalts; steht nur ein Teil aus, tritt er daneben.
    if (panel.setup?.all) {
        return <SetupHint setup={panel.setup} t={t} />;
    }

    const fallback = emptyText ?? t('overview.panel.empty');

    return (
        <>
            {panel.setup && <SetupHint setup={panel.setup} t={t} className="mb-3" />}

            {panel.empty ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">{fallback}</p>
            ) : (
                <Content panel={panel} emptyText={fallback} t={t} formats={formats} />
            )}
        </>
    );
}

// Ladeplatzhalter ohne eigenen Kartenrahmen: den bringt die Kachel schon mit.
// Die Bausteine aus components/Skeleton.jsx zeichnen jeweils eine ganze Karte
// und säßen hier in einer zweiten.
function Placeholder() {
    return (
        <div className="animate-pulse space-y-2" aria-hidden="true">
            <div className="h-3 w-32 rounded bg-gray-200 dark:bg-gray-700" />
            <div className="h-3 w-full rounded bg-gray-200 dark:bg-gray-700" />
            <div className="h-3 w-2/3 rounded bg-gray-200 dark:bg-gray-700" />
        </div>
    );
}

function Content({ panel, emptyText, t, formats }) {
    if (panel.kind === 'series') {
        return <SeriesPanel panel={panel} t={t} formats={formats} />;
    }

    if (panel.kind === 'stats') {
        return <StatsPanel stats={panel.stats} t={t} formats={formats} />;
    }

    return <RowsPanel panel={panel} emptyText={emptyText} t={t} formats={formats} />;
}
