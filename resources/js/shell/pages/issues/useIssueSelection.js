import { useEffect, useMemo, useState } from 'react';

// Die Mehrfachauswahl der Fehlerliste.
//
// Zwei Zustände und nicht einer, weil „diese drei hier" und „alle, auf die der
// Filter passt" verschiedene Aussagen sind. Die zweite lässt sich gar nicht als
// Liste von Kennungen führen — bei 100.000 Treffern wären das 100.000 Zahlen in
// der Adresszeile, für eine Aktion, die serverseitig ein `where` ist. Sie ist
// deshalb ein Schalter, und was er meint, weiß der Server aus demselben Filter,
// mit dem er die Seite gebaut hat.
//
// Die Auswahl gilt für die **angezeigte** Seite. Beim Blättern beginnt sie neu:
// die Seite wird bei jeder Inertia-Navigation neu aufgebaut, und eine Auswahl,
// die unsichtbare Zeilen enthält, ist eine, die man nicht mehr prüfen kann.
// Wer über Seitengrenzen hinweg etwas tun will, nimmt „alle auswählen".
export default function useIssueSelection(ids) {
    const [selected, setSelected] = useState(() => new Set());
    const [allMatching, setAllMatching] = useState(false);

    // Die Kennungen der Seite als stabiler Wert: `ids` ist bei jedem Rendern ein
    // neues Feld, und ohne diesen Schlüssel liefe der Effekt unten in einer
    // Schleife.
    const key = ids.join(',');

    useEffect(() => {
        setSelected(new Set());
        setAllMatching(false);
    }, [key]);

    const pageIds = useMemo(() => ids, [key]); // eslint-disable-line react-hooks/exhaustive-deps

    const allOnPage = pageIds.length > 0 && pageIds.every((id) => selected.has(id));

    return {
        selected,
        allMatching,
        isSelected: (id) => allMatching || selected.has(id),
        allOnPage: allMatching || allOnPage,

        toggle: (id) => {
            // Eine einzelne Abwahl beendet „alle": die Aussage stimmt dann nicht
            // mehr, und sie stillschweigend stehen zu lassen wäre die
            // gefährliche Variante — die Aktion träfe Zeilen, die gerade
            // abgewählt wurden.
            setAllMatching(false);
            setSelected((current) => {
                const next = new Set(allMatching ? pageIds : current);

                if (next.has(id)) {
                    next.delete(id);
                } else {
                    next.add(id);
                }

                return next;
            });
        },

        togglePage: () => {
            setAllMatching(false);
            setSelected(allOnPage ? new Set() : new Set(pageIds));
        },

        selectAllMatching: () => {
            setAllMatching(true);
            setSelected(new Set(pageIds));
        },

        clear: () => {
            setAllMatching(false);
            setSelected(new Set());
        },
    };
}
