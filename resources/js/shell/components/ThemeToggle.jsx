import React, { useEffect, useState } from 'react';
import { SunIcon, MoonIcon, MonitorIcon } from '../icons.jsx';

// Dark-Mode-Umschalter der Shell. Zyklus: hell → dunkel → System → hell.
// Quelle der Wahrheit ist localStorage['theme'] (light|dark|system) plus die
// `.dark`-Klasse am <html>-Element — dieselbe Konvention wie das Anti-Flash-
// Script im <head> (partials/theme-init). Bei 'system' folgt die Anzeige der
// Einstellung des Betriebssystems.

const media = window.matchMedia('(prefers-color-scheme: dark)');

function isDark(mode) {
    return mode === 'dark' || (mode === 'system' && media.matches);
}

function apply(mode) {
    document.documentElement.classList.toggle('dark', isDark(mode));
}

const NEXT = { light: 'dark', dark: 'system', system: 'light' };

const ICONS = { light: SunIcon, dark: MoonIcon, system: MonitorIcon };

// Zwei Hüllen für denselben Umschalter: als Symbol (Mobil-Menü) und als Zeile mit
// Beschriftung. Als Zeile steht das Design im Nutzer-Menü unter seinem Namen,
// statt als Symbol ohne Text daneben zu hängen. Der Zyklus bleibt derselbe.
const VARIANTS = {
    icon: 'inline-flex items-center justify-center rounded-md p-2 text-gray-500 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-700 focus:outline-none dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200 ',
    row: 'flex w-full items-center gap-2 px-4 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none dark:text-gray-300 dark:hover:bg-gray-700 dark:focus:bg-gray-700 ',
};

export default function ThemeToggle({ labels, variant = 'icon', className = '' }) {
    const [mode, setMode] = useState(() => localStorage.getItem('theme') || 'system');

    // Auf Wechsel der Systemeinstellung reagieren, solange 'system' aktiv ist.
    useEffect(() => {
        const onChange = () => apply(mode);
        media.addEventListener('change', onChange);

        return () => media.removeEventListener('change', onChange);
    }, [mode]);

    const cycle = () => {
        const next = NEXT[mode] || 'light';
        localStorage.setItem('theme', next);
        apply(next);
        setMode(next);
    };

    const label = labels?.[mode] ?? mode;
    const Icon = ICONS[mode] ?? MonitorIcon;

    return (
        <button
            type="button"
            onClick={cycle}
            title={variant === 'row' ? undefined : label}
            aria-label={label}
            data-theme-mode={mode}
            className={(VARIANTS[variant] ?? VARIANTS.icon) + className}
        >
            <Icon
                className={
                    variant === 'row'
                        ? 'h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500'
                        : 'h-5 w-5'
                }
            />
            {variant === 'row' && <span className="truncate">{label}</span>}
        </button>
    );
}
