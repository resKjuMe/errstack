import React from 'react';

// Eine Quote aus den Sitzungsdaten — oder der sichtbare Hinweis, dass es keine
// gibt.
//
// Der ganze Sinn dieses Bausteins ist der zweite Fall. Eine Version ohne
// Sitzungen ist **nicht** gesund, sondern unbekannt (siehe
// App\Support\Releases\Health\ReleaseHealthSummary), und ein Strich mit einem
// Titel dahinter ist die einzige Anzeige, die das nicht in „100 %" verdreht.
//
// `tone="crash_free"` färbt die Zahl: unterhalb der Schwellen ist eine
// Crash-Free-Rate keine Kennzahl mehr, sondern ein Alarm — und der soll in einer
// Liste von fünfzig Zeilen ins Auge fallen, ohne dass man jede Zahl liest. Die
// Verbreitung bleibt farblos: „wenig verbreitet" ist kein Fehler, sondern der
// Normalfall am Tag einer Auslieferung.
export default function HealthValue({ value, tone = null, t }) {
    if (!value) {
        return (
            <span
                title={t('releases.health.unknown_hint')}
                className="text-sm text-gray-400 dark:text-gray-500"
            >
                —
            </span>
        );
    }

    return (
        <span className={`text-sm font-medium ${toneClass(tone, value.value)}`}>{value.label}</span>
    );
}

// Die beiden Schwellen sind bewusst grob: 99 % und 95 % sind die Marken, an
// denen im Betrieb über eine Version gesprochen wird. Feiner abgestuft wäre es
// ein Farbverlauf, aus dem sich nichts mehr ablesen lässt.
function toneClass(tone, value) {
    if (tone !== 'crash_free') {
        return 'text-gray-900 dark:text-gray-100';
    }

    if (value < 95) {
        return 'text-rose-600 dark:text-rose-400';
    }

    if (value < 99) {
        return 'text-amber-600 dark:text-amber-400';
    }

    return 'text-emerald-700 dark:text-emerald-400';
}
