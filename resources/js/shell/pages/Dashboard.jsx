import React from 'react';
import { usePage } from '@inertiajs/react';
import PageHead from '../components/PageHead.jsx';
import Card from '../components/Card.jsx';
import LiveDemo from '../components/LiveDemo.jsx';

// Beispielseite des Grundgerüsts: zeigt, dass eine Seite nichts weiter tun muss,
// als ihren Inhalt zu liefern — Rahmen, Navigation und Theme kommen von der
// AppShell. Wird von den Fachseiten der nächsten Phasen abgelöst.
export default function Dashboard() {
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
                        <li>Die wiederverwendbaren Bausteine zeigt die Seite „Bausteine".</li>
                    </ul>
                }
            />

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
