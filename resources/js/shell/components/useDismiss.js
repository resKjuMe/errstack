import { useEffect } from 'react';

// Klapp-Menüs der Shell schließen, sobald daneben geklickt oder Escape gedrückt
// wird. Steht als eigener Haken hier, weil es davon inzwischen zwei gibt
// (Nutzer-Menü im Fuß, Organisations-Umschalter am Kopf) und beide sich sonst
// dieselben Zuhörer noch einmal selbst registrieren müssten.
//
// `ref` zeigt auf den Wrapper des Menüs: ein Klick darin gilt als „innen" und
// schließt nicht. Ist `open` false, hängt gar kein Zuhörer am Dokument.
//
// Übergeben wird der Setter aus useState und keine eigene Funktion: der Setter
// bleibt über Renderzyklen derselbe, ein inline geschriebener Rückruf würde die
// Zuhörer bei jedem Rendern ab- und neu anmelden.
export default function useDismiss(open, ref, setOpen) {
    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const onDocClick = (e) => {
            if (ref.current && !ref.current.contains(e.target)) setOpen(false);
        };
        const onKey = (e) => {
            if (e.key === 'Escape') setOpen(false);
        };

        document.addEventListener('click', onDocClick);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('click', onDocClick);
            document.removeEventListener('keydown', onKey);
        };
    }, [open, ref, setOpen]);
}
