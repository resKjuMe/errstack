import { formatNumber } from '../../i18n.js';

// Die Umrechnungen, die Liste, Zeitleiste und Abspieler gemeinsam brauchen.
//
// Sie stehen hier und nicht je Seite, weil eine Dauer an drei Stellen gezeigt
// wird und überall dieselbe sein muss: „1:23" im Abspieler und „83 s" in der
// Liste wären zwei Angaben über denselben Wert, und wer sie vergleicht, hält
// eine davon für falsch.

// Eine Dauer als Uhrzeit — „1:23" bzw. „1:02:03".
//
// Für den Abspieler und die Zeitleiste ist das die einzig brauchbare Form: dort
// wird gesucht („was war bei 1:20?"), und dafür braucht es Minuten und
// Sekunden, keine gerundete Angabe in Sekunden.
export function clock(ms) {
    const total = Math.max(0, Math.round((ms ?? 0) / 1000));
    const seconds = total % 60;
    const minutes = Math.floor(total / 60) % 60;
    const hours = Math.floor(total / 3600);

    const pad = (value) => String(value).padStart(2, '0');

    return hours > 0 ? `${hours}:${pad(minutes)}:${pad(seconds)}` : `${minutes}:${pad(seconds)}`;
}

// Eine Größe, wie ein Mensch sie liest.
export function bytes(value, formats) {
    const units = ['B', 'KB', 'MB', 'GB'];
    let size = Math.max(0, value ?? 0);
    let unit = 0;

    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit += 1;
    }

    return `${formatNumber(size, formats, { maximumFractionDigits: unit === 0 ? 0 : 1 })} ${units[unit]}`;
}

// Eine Adresse so kürzen, dass die Seite erkennbar bleibt.
//
// Gekürzt wird der **Anfang** und nicht das Ende: die Herkunft ist bei allen
// Einträgen dieselbe, der Pfad ist das Unterscheidende. Wer hinten kürzt,
// bekommt eine Liste aus lauter „https://app.example.com/…".
export function shortUrl(url, max = 72) {
    if (!url) {
        return null;
    }

    let text = url;

    try {
        const parsed = new URL(url);
        text = `${parsed.pathname}${parsed.search}` || parsed.host;
    } catch {
        // Keine gültige Adresse — dann bleibt sie, wie sie ist.
    }

    return text.length > max ? `…${text.slice(text.length - max + 1)}` : text;
}

// Die Farbe eines Konsolen- bzw. Netzwerk-Eintrags.
//
// Nur drei Stufen, obwohl es mehr Ebenen gibt: die Zeitleiste soll auf einen
// Blick sagen, wo etwas schiefging, und eine fünffarbige Legende beantwortet
// diese Frage nicht schneller als eine zweifarbige.
export function severityClass(level) {
    if (level === 'error' || level === 'fatal') {
        return 'text-rose-600 dark:text-rose-400';
    }

    if (level === 'warning' || level === 'warn') {
        return 'text-amber-600 dark:text-amber-400';
    }

    return 'text-gray-600 dark:text-gray-300';
}

// Der Farbton einer HTTP-Antwort. Alles ab 400 ist auffällig, der Rest nicht.
export function statusClass(status) {
    if (!status) {
        return 'text-gray-500 dark:text-gray-400';
    }

    if (status >= 500) {
        return 'text-rose-600 dark:text-rose-400';
    }

    if (status >= 400) {
        return 'text-amber-600 dark:text-amber-400';
    }

    return 'text-emerald-600 dark:text-emerald-400';
}
