import React from 'react';

// Der Verlauf einer Zeile: ein paar Balken, so breit wie ein Daumen.
//
// Bewusst ein SVG von Hand und keine Diagramm-Bibliothek: hier steht eine je
// Zeile, fünfzig auf einer Seite, und keine davon hat Achsen, Legende oder
// Werkzeugspitze. Eine Bibliothek wäre an dieser Stelle mehr Ladezeit als
// Grafik.
//
// Die Skala ist **je Zeile** und nicht über die ganze Liste: gefragt ist der
// Verlauf dieses einen Fehlers („war das gestern schon so?"), nicht sein Anteil
// am Aufkommen. Über eine gemeinsame Skala wäre neben einem Fehler mit einer
// Million Auftreten jeder andere ein flacher Strich.
export default function Sparkline({ values, label }) {
    if (!values || values.length === 0) {
        return <div className="h-8 w-28" aria-hidden="true" />;
    }

    const max = Math.max(...values);
    const width = 112;
    const height = 32;
    const gap = 1;
    const slot = width / values.length;
    const barWidth = Math.max(1, slot - gap);

    return (
        <svg
            viewBox={`0 0 ${width} ${height}`}
            width={width}
            height={height}
            role="img"
            aria-label={label}
            className="text-rose-500 dark:text-rose-400"
            preserveAspectRatio="none"
        >
            {values.map((value, index) => {
                // Ein Fenster mit Auftreten bekommt mindestens einen Pixel:
                // sonst sieht „einmal" neben „zehntausendmal" aus wie „nie".
                const bar = value === 0 ? 0 : Math.max(1, Math.round((value / max) * height));

                return (
                    <rect
                        key={index}
                        x={index * slot}
                        y={height - bar}
                        width={barWidth}
                        height={bar}
                        rx={0.5}
                        fill="currentColor"
                        opacity={value === 0 ? 0 : 1}
                    />
                );
            })}
        </svg>
    );
}
