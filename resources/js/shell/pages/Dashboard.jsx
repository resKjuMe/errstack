import React from 'react';
import { usePage } from '@inertiajs/react';
import PageHead from '../components/PageHead.jsx';
import Card from '../components/Card.jsx';
import LiveDemo from '../components/LiveDemo.jsx';
import FilterBar from '../components/FilterBar.jsx';

// Beispielseite des Grundgerüsts: zeigt, dass eine Seite nichts weiter tun muss,
// als ihren Inhalt zu liefern — Rahmen, Navigation und Theme kommen von der
// AppShell. Wird von den Fachseiten der nächsten Phasen abgelöst.
//
// Zugleich die erste Seite mit der globalen Filterleiste: die kommenden
// Auswertungsseiten binden sie genauso ein und lesen dieselbe Nutzlast.
export default function Dashboard({ filter, selection }) {
    const { shell } = usePage().props;

    return (
        <>
            <PageHead
                title="Übersicht"
                appName={shell.appName}
                help={
                    <ul className="list-disc space-y-1 ps-4">
                        <li>Diese Seite ist eine Beispielseite des Oberflächen-Grundgerüsts.</li>
                        <li>
                            Alle Seiten liegen im gemeinsamen Rahmen mit Navigation und Dunkelmodus.
                        </li>
                        <li>
                            Die Filterleiste oben gilt für alle Auswertungen. Ihre Auswahl steht in
                            der Adresszeile — der Link zeigt beim Empfänger dieselbe Ansicht.
                        </li>
                    </ul>
                }
            />

            <FilterBar filter={filter} />

            <Card
                title="Aktuelle Auswahl"
                description="Solange es noch keine Auswertungen gibt, zeigt diese Karte, worauf der Filter zeigt."
                className="mb-4"
            >
                <dl className="divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <Row label="Projekte" value={projectSummary(selection.projects)} />
                    <Row label="Umgebung" value={selection.environment ?? 'Alle Umgebungen'} />
                    <Row label="Zeitraum" value={selection.rangeLabel} />
                </dl>
            </Card>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card
                    title="Rahmen"
                    description="Kopfzeile, Navigation, Mobil-Menü, Nutzer-Menü und Theme-Umschalter stehen bereit."
                />
                <Card
                    title="Bausteine"
                    description="Seitenkopf, Karten, Flash-Meldungen, Ladeplatzhalter und Toasts sind wiederverwendbar."
                />
                <Card
                    title="Nächste Schritte"
                    description="Fachseiten (Anmeldung, Projekte, Issues) ersetzen diese Beispielseite Schritt für Schritt."
                />
            </div>

            <div className="mt-4">
                <LiveDemo />
            </div>
        </>
    );
}

function Row({ label, value }) {
    return (
        <div className="flex justify-between gap-3 py-2">
            <dt className="text-gray-500 dark:text-gray-400">{label}</dt>
            <dd className="text-gray-900 dark:text-gray-100">{value}</dd>
        </div>
    );
}

function projectSummary(projects) {
    if (projects.length === 0) {
        return 'Keine Projekte';
    }

    return projects.join(', ');
}
