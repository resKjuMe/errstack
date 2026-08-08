import React, { useMemo, useState } from 'react';
import { useTranslations } from '../../i18n.js';
import { InputLabel, SecondaryButton, TextInput } from '../../components/Form.jsx';
import { duration, frameLabel, frameLocation, percent } from './format.jsx';

// Der Flamegraph: jeder Balken eine Funktion, seine Breite ihre Gesamtzeit,
// die Zeile darunter das, was sie aufgerufen hat.
//
// Gezeichnet wird von oben nach unten (ein „Icicle Graph"), nicht von unten
// nach oben. Der Grund ist das Lesen: der Einstiegspunkt steht damit oben und
// der Blick wandert wie in einem Aufrufstapel nach unten, statt einen
// umgedrehten Baum von der Wurzel her rückwärts zu lesen.
//
// Drei Handgriffe machen das Bild bedienbar, und sie hängen zusammen:
//
//   Einzoomen  — ein Klick auf einen Balken macht ihn zur neuen Bezugsbreite.
//                Ohne das ist alles unterhalb von einem Prozent der Zeit ein
//                Strich, und genau dort steckt die Ursache.
//   Suchen     — hebt alle Balken hervor, deren Funktion oder Datei passt, und
//                sagt, wie viel Rechenzeit auf sie entfällt. Das ist die
//                Antwort auf „wie teuer ist unsere Datenbankschicht insgesamt?",
//                die kein einzelner Balken gibt.
//   Einklappen — verbirgt einen Ast. Ein Rahmenwerk, das die halbe Fläche
//                einnimmt und nichts erklärt, verdeckt sonst dauerhaft den Rest.
//
// Der Baum kommt vorbereitet aus dem Server (App\Support\Profiling\FlamegraphData):
// Zeiten in Mikrosekunden, Äste unter einem Tausendstel der Gesamtzeit bereits
// weggelassen, Kinder nach Gesamtzeit sortiert. Hier wird nur noch gerechnet,
// was von der Bedienung abhängt.

const ROW_HEIGHT = 22;

// Ab dieser Breite trägt ein Balken noch Text. Darunter würde die Beschriftung
// über den Nachbarn laufen, und ein Bild aus überlappenden Wortfragmenten ist
// unbrauchbarer als eines ohne Beschriftung.
const LABEL_MIN_SHARE = 0.04;

export default function Flamegraph({ roots, frames, totalUs, dropped = 0, pruned = 0 }) {
    const { t, formats } = useTranslations();
    const [zoomId, setZoomId] = useState(null);
    const [collapsed, setCollapsed] = useState(() => new Set());
    const [query, setQuery] = useState('');

    // Kennungen für die Knoten. Der Server schickt keine — er schickt einen
    // Baum, und die Stelle darin **ist** die Kennung. Vergeben wird sie einmal
    // je Baum: eine Kennung, die sich bei jedem Tastendruck im Suchfeld ändert,
    // würde eingeklappte Äste und den Ausschnitt zurücksetzen.
    const tree = useMemo(() => identify(roots), [roots]);
    const index = useMemo(() => flatten(tree), [tree]);

    const zoom = (zoomId && index.get(zoomId)) || null;
    const view = zoom ? zoom.node : { children: tree, total: totalUs, self: 0, id: null };
    const viewTotal = view.total || totalUs || 0;

    const needle = query.trim().toLowerCase();
    const matches = useMemo(() => findMatches(tree, frames, needle), [tree, frames, needle]);

    const rows = useMemo(() => layout(view, viewTotal, collapsed), [view, viewTotal, collapsed]);

    const depth = rows.reduce((max, row) => Math.max(max, row.depth), 0);

    const toggle = (id) => {
        setCollapsed((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    };

    if (!tree.length || totalUs <= 0) {
        return (
            <p className="py-8 text-center text-sm text-gray-600 dark:text-gray-300">
                {t('profiling.flamegraph.empty')}
            </p>
        );
    }

    return (
        <div>
            <div className="mb-3 flex flex-wrap items-end gap-3">
                <div className="min-w-64 flex-1">
                    <InputLabel
                        htmlFor="flamegraph_search"
                        value={t('profiling.flamegraph.search')}
                    />
                    <TextInput
                        id="flamegraph_search"
                        type="search"
                        value={query}
                        placeholder={t('profiling.flamegraph.search_placeholder')}
                        className="mt-1 font-mono"
                        onChange={(e) => setQuery(e.target.value)}
                    />
                </div>

                {needle !== '' && (
                    <p className="pb-2 text-sm text-gray-600 dark:text-gray-300">
                        {matches.count === 0
                            ? t('profiling.flamegraph.no_matches')
                            : t('profiling.flamegraph.matches', {
                                  count: matches.count,
                                  share: percent(
                                      totalUs > 0 ? matches.selfUs / totalUs : 0,
                                      formats
                                  ),
                              })}
                    </p>
                )}

                {(zoom || collapsed.size > 0) && (
                    <SecondaryButton
                        type="button"
                        className="mb-1"
                        onClick={() => {
                            setZoomId(null);
                            setCollapsed(new Set());
                        }}
                    >
                        {t('profiling.flamegraph.reset')}
                    </SecondaryButton>
                )}
            </div>

            {zoom && (
                <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">
                    {t('profiling.flamegraph.zoomed', {
                        function: frameLabel(frames[zoom.node.frame], t),
                    })}
                </p>
            )}

            <div
                className="relative w-full overflow-hidden rounded border border-gray-200 dark:border-gray-700"
                style={{ height: `${(depth + 1) * ROW_HEIGHT}px` }}
            >
                {rows.map((row) => (
                    <Bar
                        key={row.node.id}
                        row={row}
                        frames={frames}
                        matched={matches.ids.has(row.node.id)}
                        searching={needle !== ''}
                        collapsed={collapsed.has(row.node.id)}
                        onZoom={() => setZoomId(row.node.id)}
                        onToggle={() => toggle(row.node.id)}
                        t={t}
                        formats={formats}
                    />
                ))}
            </div>

            {(dropped > 0 || pruned > 0) && (
                <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    {t('profiling.flamegraph.incomplete', { dropped, pruned })}
                </p>
            )}
        </div>
    );
}

function Bar({ row, frames, matched, searching, collapsed, onZoom, onToggle, t, formats }) {
    const frame = frames[row.node.frame];
    const label = frameLabel(frame, t);
    const hasChildren = row.node.children.length > 0;
    const wide = row.width >= LABEL_MIN_SHARE;

    const tone = searching
        ? matched
            ? 'bg-amber-400/90 dark:bg-amber-500/80'
            : 'bg-gray-200 dark:bg-gray-700'
        : frame?.inApp
          ? 'bg-rose-400/80 hover:bg-rose-400 dark:bg-rose-500/70 dark:hover:bg-rose-500'
          : 'bg-sky-300/70 hover:bg-sky-300 dark:bg-sky-500/50 dark:hover:bg-sky-500/70';

    const title = [
        label,
        frameLocation(frame),
        `${t('profiling.flamegraph.total')}: ${asText(duration(row.node.total, t, formats))}`,
        `${t('profiling.flamegraph.self')}: ${asText(duration(row.node.self, t, formats))}`,
        `${t('profiling.flamegraph.samples')}: ${row.node.samples}`,
        percent(row.width, formats),
    ]
        .filter(Boolean)
        .join('\n');

    return (
        // Zwei Schaltflächen nebeneinander und nicht eine in der anderen: eine
        // Schaltfläche in einer Schaltfläche ist kein gültiges HTML, und was
        // die Browser daraus machen, ist von Fall zu Fall verschieden —
        // Tastaturbedienung und Vorleseprogramme trifft es zuerst.
        <div
            className={`absolute flex items-center gap-px overflow-hidden rounded-sm border border-white/60 text-[11px] leading-none dark:border-gray-900/60 ${tone}`}
            style={{
                left: `${row.offset * 100}%`,
                width: `${row.width * 100}%`,
                top: `${row.depth * ROW_HEIGHT}px`,
                height: `${ROW_HEIGHT - 2}px`,
            }}
        >
            {hasChildren && wide && (
                <button
                    type="button"
                    onClick={onToggle}
                    aria-expanded={!collapsed}
                    aria-label={t(
                        collapsed ? 'profiling.flamegraph.expand' : 'profiling.flamegraph.collapse'
                    )}
                    className="h-full shrink-0 px-1 text-gray-900 opacity-70 hover:opacity-100 dark:text-gray-50"
                >
                    {collapsed ? '▸' : '▾'}
                </button>
            )}
            <button
                type="button"
                onClick={onZoom}
                title={title}
                className="h-full min-w-0 flex-1 truncate px-1 text-start text-gray-900 dark:text-gray-50"
            >
                {wide ? label : ''}
            </button>
        </div>
    );
}

// Vergibt die Kennungen: der Weg von der Wurzel, als Text. Damit ist sie
// eindeutig, sie überlebt das Neuzeichnen, und sie sagt beim Suchen im
// Fehlerfall gleich, um welchen Ast es ging.
function identify(nodes, prefix = '') {
    return nodes.map((node, position) => {
        const id = prefix === '' ? String(position) : `${prefix}.${position}`;

        return { ...node, id, children: identify(node.children ?? [], id) };
    });
}

function flatten(nodes, into = new Map()) {
    for (const node of nodes) {
        into.set(node.id, { node });
        flatten(node.children, into);
    }

    return into;
}

// Legt die Balken aus: Ebene, waagerechter Anfang und Breite — jeweils als
// Anteil an der Bezugsbreite, damit die Anzeige in jeder Fensterbreite stimmt
// und beim Einzoomen nichts umgerechnet werden muss.
function layout(view, total, collapsed) {
    const rows = [];

    const walk = (node, depth, offset) => {
        const width = total > 0 ? node.total / total : 0;

        rows.push({ node, depth, offset, width });

        if (collapsed.has(node.id)) {
            return;
        }

        let x = offset;

        for (const child of node.children) {
            walk(child, depth + 1, x);
            x += total > 0 ? child.total / total : 0;
        }
    };

    if (view.id === null) {
        let x = 0;

        for (const root of view.children) {
            walk(root, 0, x);
            x += total > 0 ? root.total / total : 0;
        }
    } else {
        walk(view, 0, 0);
    }

    return rows;
}

// Die Treffer der Suche — und was sie zusammen kosten.
//
// Gezählt wird die **Selbstzeit**, nicht die Gesamtzeit: passen ein Aufrufer und
// sein Aufgerufener beide auf die Suche, wäre deren Zeit sonst doppelt in der
// Summe. Die Selbstzeit ist je Balken verbraucht und lässt sich addieren.
function findMatches(nodes, frames, needle) {
    const ids = new Set();
    let count = 0;
    let selfUs = 0;

    if (needle === '') {
        return { ids, count, selfUs };
    }

    const walk = (list) => {
        for (const node of list) {
            const frame = frames[node.frame];
            const haystack = `${frame?.module ?? ''} ${frame?.function ?? ''} ${frame?.file ?? ''}`;

            if (haystack.toLowerCase().includes(needle)) {
                ids.add(node.id);
                count++;
                selfUs += node.self;
            }

            walk(node.children);
        }
    };

    walk(nodes);

    return { ids, count, selfUs };
}

// `duration` gibt Text oder ein Element zurück (für fehlende Werte). In einem
// `title` steht nur Text — ein Element würde dort als „[object Object]" landen.
function asText(value) {
    return typeof value === 'string' ? value : '';
}
