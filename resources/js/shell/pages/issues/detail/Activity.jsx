import React from 'react';

// Der Aktivitätsverlauf eines Fehlers: wer wann was getan hat.
//
// Der Satz kommt fertig vom Server — „stummgeschaltet, bis 100 weitere
// Ereignisse in 60 Minuten" ist ein Satz mit Beugung und Zahlen, und den aus
// Bausteinen im Browser zusammenzusetzen hieße, zwei Sprachen dort nachzubauen.
//
// Ohne Namen steht „automatisch": der einzige Vermerk ohne handelndes Konto ist
// der Ablauf einer Stummschaltung, und der fällt bei der Aufnahme an, wo niemand
// daneben steht.
export default function Activity({ entries, t }) {
    if (entries.length === 0) {
        return (
            <p className="text-sm text-gray-500 dark:text-gray-400">{t('issues.activity.empty')}</p>
        );
    }

    return (
        <ol className="space-y-2">
            {entries.map((entry) => (
                <li key={entry.id} className="flex flex-wrap items-baseline gap-x-2 text-sm">
                    <span className="text-gray-900 dark:text-gray-100">{entry.text}</span>
                    <span className="text-xs text-gray-500 dark:text-gray-400">
                        {t('issues.activity.by', {
                            actor: entry.actor ?? t('issues.activity.system'),
                        })}
                    </span>
                    <time
                        dateTime={entry.at ?? undefined}
                        className="ms-auto text-xs text-gray-500 dark:text-gray-400"
                    >
                        {entry.atLabel}
                    </time>
                </li>
            ))}
        </ol>
    );
}
