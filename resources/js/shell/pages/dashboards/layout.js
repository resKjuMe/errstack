// Die Rechenregeln des Rasters: verschieben, vergrößern, ausweichen, aufrücken.
//
// **Sie stehen hier und nicht in der Kachel.** Eine Bewegung im Raster betrifft
// nie nur die bewegte Kachel: was ihr im Weg liegt, muss ausweichen, und was
// darüber frei wird, rückt nach. Beides in der Komponente zu erledigen hieße,
// die Anordnung aus dem zu erschließen, was gerade auf dem Bildschirm steht —
// hier ist sie eine Liste von Zahlen, die sich prüfen lässt.
//
// **Alle Funktionen geben eine neue Liste zurück.** Die Anordnung wird nach
// jeder Bewegung an den Server geschickt; wäre sie veränderlich, hinge das
// Abgeschickte daran, wer sie zuletzt in der Hand hatte.

// Zwei Kacheln liegen übereinander, wenn sich ihre Rechtecke schneiden.
function overlaps(a, b) {
    return (
        a.id !== b.id &&
        a.x < b.x + b.width &&
        a.x + a.width > b.x &&
        a.y < b.y + b.height &&
        a.y + a.height > b.y
    );
}

// Eine Lage, die im Raster liegt — dieselben Grenzen wie serverseitig
// (`DashboardLayout::normalize`). Doppelt gerechnet, aber nicht doppelt
// entschieden: der Server rückt zurecht, was hier durchrutscht, und die
// Oberfläche zeigt gar nicht erst etwas an, das er zurechtrücken müsste.
export function clamp(widget, grid) {
    const width = Math.max(grid.minWidth, Math.min(widget.width, grid.columns));
    const height = Math.max(grid.minHeight, Math.min(widget.height, grid.maxHeight));

    return {
        ...widget,
        width,
        height,
        x: Math.max(0, Math.min(widget.x, grid.columns - width)),
        y: Math.max(0, widget.y),
    };
}

// Was der bewegten Kachel im Weg liegt, weicht nach unten aus — und was dadurch
// wiederum im Weg liegt, ebenfalls.
//
// Nach unten und nicht zur Seite: eine Zeile ist begrenzt, die Höhe nicht. Wer
// zur Seite auswiche, würde beim zweiten Nachbarn aus dem Raster geschoben.
function push(widgets, moved) {
    const settled = [moved];
    const pending = widgets.filter((widget) => widget.id !== moved.id);

    // Von oben nach unten: eine Kachel, die ausweicht, darf nur auf solche
    // treffen, die noch nicht endgültig liegen — sonst schöben sich zwei
    // gegenseitig im Kreis.
    pending.sort((a, b) => a.y - b.y || a.x - b.x);

    for (const widget of pending) {
        let placed = { ...widget };

        // Die Schleife endet, weil jeder Durchgang `y` echt vergrößert und die
        // bereits liegenden Kacheln endlich sind.
        let collision = settled.find((other) => overlaps(placed, other));

        while (collision) {
            placed = { ...placed, y: collision.y + collision.height };
            collision = settled.find((other) => overlaps(placed, other));
        }

        settled.push(placed);
    }

    return settled;
}

// Alles rückt so weit nach oben, wie es kann.
//
// Ohne das Aufrücken bliebe nach jedem Verschieben ein Loch stehen, und nach
// dem dritten Mal sähe ein Dashboard aus wie ein Setzkasten. Gerechnet wird von
// oben nach unten, damit eine Kachel nur auf schon aufgerückte trifft.
export function compact(widgets) {
    const sorted = [...widgets].sort((a, b) => a.y - b.y || a.x - b.x);
    const settled = [];

    for (const widget of sorted) {
        let placed = { ...widget };

        while (placed.y > 0) {
            const above = { ...placed, y: placed.y - 1 };

            if (settled.some((other) => overlaps(above, other))) {
                break;
            }

            placed = above;
        }

        settled.push(placed);
    }

    return settled;
}

// Eine Kachel an eine neue Stelle legen: hineinrücken, ausweichen lassen,
// aufrücken.
export function place(widgets, id, placement, grid) {
    const moved = widgets.find((widget) => widget.id === id);

    if (!moved) {
        return widgets;
    }

    const next = clamp({ ...moved, ...placement }, grid);

    return compact(push(widgets, next));
}

// Die Anordnung, wie der Server sie annimmt — nur Lagen, nichts weiter.
export function toPlacements(widgets) {
    return widgets.map((widget) => ({
        id: widget.id,
        x: widget.x,
        y: widget.y,
        width: widget.width,
        height: widget.height,
    }));
}

// Haben sich Lagen geändert? Nur dann wird gespeichert — ein Klick auf eine
// Kachel, der nichts verschiebt, soll keine Anfrage auslösen.
export function sameLayout(a, b) {
    if (a.length !== b.length) {
        return false;
    }

    const byId = new Map(b.map((widget) => [widget.id, widget]));

    return a.every((widget) => {
        const other = byId.get(widget.id);

        return (
            other &&
            other.x === widget.x &&
            other.y === widget.y &&
            other.width === widget.width &&
            other.height === widget.height
        );
    });
}
