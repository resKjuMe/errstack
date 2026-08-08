// Die Rechenarbeit hinter dem Wasserfall — ohne React, damit sie sich für sich
// lesen (und prüfen) lässt.
//
// Der Server liefert den Baum als **flache** Liste in der Reihenfolge, in der er
// dasteht; die Verschachtelung steckt in `depth`. Das ist die Voraussetzung
// dafür, dass eine Spur mit zehntausend Schritten bedienbar bleibt: gezeichnet
// wird nur, was ins Fenster passt, und dafür muss die Zeile Nr. 8000 auffindbar
// sein, ohne den Baum zu durchlaufen.

/**
 * Höhe einer Zeile in Pixeln.
 *
 * Fest und nicht gemessen: die Auswahl der sichtbaren Zeilen ist eine Division,
 * solange alle Zeilen gleich hoch sind. Zeilen unterschiedlicher Höhe hießen,
 * jede vorher zu zeichnen, um zu wissen, welche gezeichnet werden muss.
 */
export const ROW_HEIGHT = 28;

/**
 * Wie viele Zeilen über und unter dem Fenster zusätzlich gezeichnet werden.
 *
 * Ohne diesen Rand blitzt beim Rollen der leere Hintergrund auf, weil das
 * Zeichnen dem Rollen um einen Bildaufbau hinterherhinkt.
 */
export const OVERSCAN = 12;

/**
 * Die Zeilen, die nach dem Zuklappen übrig bleiben.
 *
 * Zugeklappt wird ohne Elternverweise: alles, was nach einer zugeklappten Zeile
 * kommt und tiefer liegt als sie, gehört zu ihr. Genau dafür ist die Liste in
 * der Reihenfolge des Baumes geliefert.
 */
export function visibleRows(rows, collapsed) {
    if (collapsed.size === 0) {
        return rows;
    }

    const out = [];
    let hiddenBelow = null;

    for (const row of rows) {
        if (hiddenBelow !== null) {
            if (row.depth > hiddenBelow) {
                continue;
            }

            hiddenBelow = null;
        }

        out.push(row);

        if (row.childCount > 0 && collapsed.has(row.key)) {
            hiddenBelow = row.depth;
        }
    }

    return out;
}

/** Alle Zeilen, die überhaupt etwas zum Zuklappen haben. */
export function collapsibleKeys(rows) {
    return rows.filter((row) => row.childCount > 0).map((row) => row.key);
}

/**
 * Der Ausschnitt, der bei diesem Rollstand gezeichnet wird.
 */
export function windowFor(scrollTop, viewportHeight, total) {
    const first = Math.max(0, Math.floor(scrollTop / ROW_HEIGHT) - OVERSCAN);
    const visible = Math.ceil(viewportHeight / ROW_HEIGHT) + OVERSCAN * 2;

    return { first, last: Math.min(total, first + visible) };
}

/**
 * Die Zeilen, die aufgeklappt sein müssen, damit eine bestimmte sichtbar ist.
 *
 * Gebraucht beim Einstieg aus einem Fehler: der Link zeigt auf einen Schritt,
 * und der liegt womöglich unter etwas Zugeklapptem. Ohne diesen Schritt landete
 * man auf einer Spur, in der die gesuchte Zeile nicht dasteht.
 */
export function ancestorsOf(rows, key) {
    const index = rows.findIndex((row) => row.key === key || row.spanId === key);

    if (index < 0) {
        return [];
    }

    const ancestors = [];
    let depth = rows[index].depth;

    for (let i = index - 1; i >= 0 && depth > 0; i--) {
        if (rows[i].depth < depth) {
            ancestors.push(rows[i].key);
            depth = rows[i].depth;
        }
    }

    return ancestors;
}

/**
 * Balkenposition und -breite in Prozent der Gesamtdauer.
 *
 * Die Mindestbreite ist kein Schönheitsfehler, sondern Absicht: ein Schritt von
 * 200 µs in einer Spur von 4 Sekunden wäre 0,005 % breit und damit unsichtbar —
 * und gerade die vielen kurzen Schritte sind es, deren Menge das Problem ist.
 */
export function bar(row, totalUs) {
    if (!totalUs || totalUs <= 0) {
        return { left: 0, width: 100 };
    }

    const left = Math.min(100, Math.max(0, (row.offsetUs / totalUs) * 100));
    const width = Math.max(0.4, Math.min(100 - left, (row.durationUs / totalUs) * 100));

    return { left, width };
}
