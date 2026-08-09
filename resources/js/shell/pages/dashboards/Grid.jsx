import React, { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import WidgetCard from './WidgetCard.jsx';
import { place, sameLayout, toPlacements } from './layout.js';

/** Höhe einer Rasterzeile in Pixeln. */
const ROW_HEIGHT = 56;

/** Abstand zwischen zwei Kacheln. */
const GAP = 12;

// Das Raster: Kacheln an ihrer Stelle, verschiebbar und in der Größe änderbar.
//
// **Gerechnet wird in Rasterfeldern, angezeigt in Pixeln.** Beim Ziehen wird die
// zurückgelegte Strecke in Felder umgerechnet und die Kachel dorthin gelegt; was
// im Weg liegt, weicht aus, und darüber wird aufgerückt (`layout.js`). Der
// Bildschirm zeigt damit während des Ziehens schon die Anordnung, die
// gespeichert wird — und nicht eine schwebende Kachel, die erst beim Loslassen
// irgendwo einrastet.
//
// **Gespeichert wird beim Loslassen, nicht auf Knopfdruck.** Eine Anordnung ist
// kein Formular: wer eine Kachel verschiebt, hat sie verschoben. Geschickt wird
// nur, wenn sich tatsächlich eine Lage geändert hat, und nur die Lagen.
//
// **Ohne Maus geht es auch.** Der Griff in der Ecke ist ein Knopf: mit den
// Pfeiltasten wandert die Kachel, mit Umschalt ändert sich ihre Größe. Ein
// Raster, das nur am Zeigegerät hängt, wäre für einen Teil der Benutzer gar
// kein Raster.
export default function Grid({ widgets, grid, editable, layoutUrl, onEdit, onDelete }) {
    const [layout, setLayout] = useState(widgets);
    const [dragging, setDragging] = useState(null);
    const container = useRef(null);
    const gesture = useRef(null);

    // Der jeweils neueste Stand — die Gesten lesen ihn, statt ihn aus dem
    // Zustand zu erschließen: eine Zustandsänderung wirkt erst im nächsten
    // Durchlauf, ein Zeiger bewegt sich aber währenddessen weiter.
    const latest = useRef(widgets);

    // Was der Server zuletzt kennt. Daran hängt, ob eine Bewegung überhaupt
    // geschickt wird.
    const saved = useRef(widgets);

    // Kommt die Seite mit neuen Kacheln zurück (hinzugefügt, gelöscht,
    // geändert), gilt das, was der Server sagt — nicht der Stand von vorhin.
    useEffect(() => {
        setLayout(widgets);
        latest.current = widgets;
        saved.current = widgets;
    }, [widgets]);

    const apply = (next) => {
        latest.current = next;
        setLayout(next);
    };

    const save = (next) => {
        if (sameLayout(next, saved.current)) {
            return;
        }

        saved.current = next;

        router.patch(
            layoutUrl,
            { widgets: toPlacements(next) },
            { preserveScroll: true, preserveState: true }
        );
    };

    const cellWidth = () => {
        const width = container.current?.clientWidth ?? 0;

        return (width - GAP * (grid.columns - 1)) / grid.columns + GAP;
    };

    // Eine Geste — Ziehen oder Größe ändern — beginnt an einem Zeiger und endet
    // an ihm. Der Zeiger wird eingefangen, damit sie nicht abbricht, sobald er
    // die Kachel verlässt: beim Ziehen ist genau das der Normalfall.
    const start = (widget, mode) => (event) => {
        if (!editable || event.button !== 0) {
            return;
        }

        event.preventDefault();
        event.currentTarget.setPointerCapture?.(event.pointerId);

        gesture.current = {
            id: widget.id,
            mode,
            pointerX: event.clientX,
            pointerY: event.clientY,
            origin: { x: widget.x, y: widget.y, width: widget.width, height: widget.height },
        };

        setDragging(widget.id);
    };

    const move = (event) => {
        const active = gesture.current;

        if (!active) {
            return;
        }

        const dx = Math.round((event.clientX - active.pointerX) / cellWidth());
        const dy = Math.round((event.clientY - active.pointerY) / (ROW_HEIGHT + GAP));

        const placement =
            active.mode === 'move'
                ? { x: active.origin.x + dx, y: active.origin.y + dy }
                : { width: active.origin.width + dx, height: active.origin.height + dy };

        apply(place(latest.current, active.id, placement, grid));
    };

    const end = () => {
        if (!gesture.current) {
            return;
        }

        gesture.current = null;
        setDragging(null);
        save(latest.current);
    };

    // Pfeiltasten verschieben, Umschalt + Pfeiltasten ändern die Größe.
    const keys = (widget) => (event) => {
        const steps = {
            ArrowLeft: [-1, 0],
            ArrowRight: [1, 0],
            ArrowUp: [0, -1],
            ArrowDown: [0, 1],
        };

        const step = steps[event.key];

        if (!step || !editable) {
            return;
        }

        event.preventDefault();

        const placement = event.shiftKey
            ? { width: widget.width + step[0], height: widget.height + step[1] }
            : { x: widget.x + step[0], y: widget.y + step[1] };

        const next = place(latest.current, widget.id, placement, grid);

        apply(next);
        save(next);
    };

    return (
        <div
            ref={container}
            onPointerMove={move}
            onPointerUp={end}
            onPointerCancel={end}
            className="grid"
            style={{
                gridTemplateColumns: `repeat(${grid.columns}, minmax(0, 1fr))`,
                gridAutoRows: `${ROW_HEIGHT}px`,
                gap: `${GAP}px`,
            }}
        >
            {layout.map((widget) => (
                <div
                    key={widget.id}
                    style={{
                        gridColumn: `${widget.x + 1} / span ${widget.width}`,
                        gridRow: `${widget.y + 1} / span ${widget.height}`,
                    }}
                >
                    <WidgetCard
                        widget={widget}
                        grid={grid}
                        dataHref={widget.dataHref}
                        editable={editable}
                        dragging={dragging === widget.id}
                        onEdit={() => onEdit(widget)}
                        onDelete={() => onDelete(widget)}
                        onMoveKey={keys(widget)}
                        dragHandlers={{ onPointerDown: start(widget, 'move') }}
                        resizeHandlers={{ onPointerDown: start(widget, 'resize') }}
                    />
                </div>
            ))}
        </div>
    );
}
