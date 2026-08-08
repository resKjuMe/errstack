import React, { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import { PlatformIcon } from '../../icons.jsx';
import { PrimaryButton, SecondaryButton } from '../../components/Form.jsx';
import { useT } from '../../i18n.js';
import useSetupWatch from './useSetupWatch.js';

// Der Einrichtungs-Assistent: Plattform wählen, Beispiel kopieren, Fehler
// auslösen, Erfolg abwarten.
//
// Die Seite hat keinen gespeicherten Fortschritt — welche Anleitung gewählt ist,
// steht in der Adresszeile, wie weit die Einrichtung ist, sagen die Daten
// (kam eine Meldung an?). Deshalb ist sie jederzeit erneut aufrufbar, und
// deshalb steht der Wartebildschirm auch dann noch da, wenn jemand die Seite
// zwischendurch verlässt.
//
// Wartet der Bildschirm zu lange, klappt die Hilfe von selbst auf: wer nach
// einer halben Minute noch dasteht, hat ein Problem und keine Geduldsfrage.
const HELP_AFTER_MS = 30000;

export default function Setup({
    project,
    organization,
    dsn,
    keyName,
    guides,
    guide,
    statusHref,
    live,
    state: initialState,
}) {
    const { shell } = usePage().props;
    const t = useT();

    const state = useSetupWatch(initialState, { statusHref, live });

    return (
        <>
            <PageHead
                title={t('setup.title', { project: project.name })}
                appName={shell.appName}
                help={t('setup.help')}
                meta={
                    <div className="flex items-center gap-3">
                        <Link
                            href={project.href}
                            className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            {t('setup.to_settings')}
                        </Link>
                        <Link
                            href={organization.href}
                            className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            {organization.name}
                        </Link>
                    </div>
                }
            />

            <div className="space-y-4">
                <Platforms guides={guides} selected={guide.value} setupHref={project.setupHref} />

                {dsn ? (
                    <Instructions guide={guide} dsn={dsn} keyName={keyName} />
                ) : (
                    <MissingKey project={project} />
                )}

                <Waiting state={state} />

                <Help state={state} guide={guide} project={project} received={state.received} />
            </div>
        </>
    );
}

// Schritt 1. Die Auswahl steht in der Adresszeile (`?anleitung=…`) — so ist ein
// bestimmter Einstieg verlinkbar, und ein Neuladen ändert nichts daran.
function Platforms({ guides, selected, setupHref }) {
    const t = useT();

    return (
        <Card title={t('setup.platform.title')} description={t('setup.platform.description')}>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {guides.map((entry) => {
                    const active = entry.value === selected;

                    return (
                        <button
                            key={entry.value}
                            type="button"
                            aria-pressed={active}
                            onClick={() =>
                                router.get(
                                    setupHref,
                                    { anleitung: entry.value },
                                    { preserveScroll: true, preserveState: true }
                                )
                            }
                            className={`flex items-center gap-3 rounded-lg border p-3 text-left transition ${
                                active
                                    ? 'border-rose-500 bg-rose-50 dark:border-rose-400 dark:bg-rose-950/30'
                                    : 'border-gray-200 hover:border-gray-300 dark:border-gray-700 dark:hover:border-gray-600'
                            }`}
                        >
                            <PlatformIcon
                                platform={entry.platform}
                                short={entry.platformShort}
                                label={entry.label}
                            />
                            <span>
                                <span className="block text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {entry.label}
                                </span>
                                <code className="block font-mono text-xs text-gray-500 dark:text-gray-400">
                                    {entry.package}
                                </code>
                            </span>
                        </button>
                    );
                })}
            </div>
        </Card>
    );
}

// Schritt 2. Drei Blöcke zum Kopieren — installieren, einstellen, ausprobieren.
// Die DSN steht schon eingesetzt darin; sie zusätzlich einzeln zu zeigen ist
// kein Doppel, sondern der Wert, den man in eine bestehende Konfiguration
// überträgt, statt den ganzen Block zu übernehmen.
function Instructions({ guide, dsn, keyName }) {
    const t = useT();

    return (
        <Card
            title={t('setup.code.title', { guide: guide.label })}
            description={t('setup.code.description', { package: guide.package })}
        >
            <div className="space-y-5">
                <div>
                    <Caption>{t('setup.code.dsn', { key: keyName })}</Caption>
                    <Copyable value={dsn} className="font-mono text-sm break-all" />
                </div>

                <Step number="1" title={t('setup.code.install')} code={guide.steps.install} />
                <Step number="2" title={t('setup.code.configure')} code={guide.steps.configure} />
                <Step number="3" title={t('setup.code.verify')} code={guide.steps.verify} />

                <p className="text-xs text-gray-500 dark:text-gray-400">
                    {t('setup.code.official')}{' '}
                    <a
                        href={guide.docsHref}
                        target="_blank"
                        rel="noreferrer noopener"
                        className="underline hover:text-gray-900 dark:hover:text-gray-100"
                    >
                        {t('setup.code.docs', { package: guide.package })}
                    </a>
                </p>
            </div>
        </Card>
    );
}

function Step({ number, title, code }) {
    return (
        <div>
            <Caption>
                <span className="mr-2 inline-flex h-5 w-5 items-center justify-center rounded-full bg-gray-200 text-[0.625rem] font-bold text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                    {number}
                </span>
                {title}
            </Caption>
            <Copyable value={code} multiline />
        </div>
    );
}

function Caption({ children }) {
    return (
        <p className="mb-1 flex items-center text-sm font-medium text-gray-700 dark:text-gray-300">
            {children}
        </p>
    );
}

// Ein Kasten mit Text zum Kopieren. Ohne Zugriff auf die Zwischenablage (kein
// HTTPS, abgelehnte Berechtigung) bleibt der Text zum Markieren stehen — genau
// wie auf der Schlüssel-Seite.
function Copyable({ value, multiline = false, className = '' }) {
    const t = useT();
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!copied) {
            return undefined;
        }

        const timer = window.setTimeout(() => setCopied(false), 2000);

        return () => window.clearTimeout(timer);
    }, [copied]);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    };

    return (
        <div className="flex flex-wrap items-start gap-3">
            <pre
                className={`grow overflow-x-auto rounded-md bg-gray-100 px-3 py-2 text-gray-800 dark:bg-gray-900 dark:text-gray-200 ${
                    // Code bleibt zeilengetreu und scrollt notfalls; die DSN ist
                    // eine einzelne lange Zeile und darf umbrechen — sonst
                    // schiebt sie die Karte auseinander.
                    multiline ? 'font-mono text-xs whitespace-pre' : 'whitespace-pre-wrap'
                } ${className}`}
            >
                {value}
            </pre>

            <SecondaryButton type="button" onClick={copy}>
                {t(copied ? 'setup.copied' : 'setup.copy')}
            </SecondaryButton>
        </div>
    );
}

// Schritt 3. Der Wartebildschirm — und die Erfolgsmeldung, sobald die erste
// Meldung da ist. Beides ist dieselbe Karte: der Wechsel soll dort passieren,
// wohin man ohnehin sieht.
function Waiting({ state }) {
    const t = useT();

    if (!state.received) {
        return (
            <Card title={t('setup.waiting.title')} description={t('setup.waiting.description')}>
                <div className="flex items-center gap-3 text-sm text-gray-600 dark:text-gray-400">
                    <span
                        className="h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-gray-300 border-t-rose-500 dark:border-gray-600 dark:border-t-rose-400"
                        role="status"
                        aria-label={t('setup.waiting.spinner')}
                    />
                    {t('setup.waiting.hint')}
                </div>
            </Card>
        );
    }

    return (
        <Card
            title={t('setup.received.title')}
            description={
                state.sdk
                    ? t('setup.received.description_sdk', {
                          sdk: state.sdk,
                          time: state.receivedAt,
                      })
                    : t('setup.received.description', { time: state.receivedAt })
            }
        >
            {state.issue ? (
                <div className="space-y-3">
                    <p className="text-sm text-gray-700 dark:text-gray-300">{state.issue.title}</p>
                    <Link href={state.issue.href}>
                        <PrimaryButton type="button">{t('setup.received.open')}</PrimaryButton>
                    </Link>
                </div>
            ) : (
                // Angenommen ist die Meldung, ausgewertet noch nicht: der
                // Fehlereintrag entsteht in der Warteschlange. Statt hier auf
                // ihn zu warten und den Erfolg zu verschweigen, steht der Weg
                // zur Fehlerliste da — der Verweis auf den Fehler kommt von
                // selbst nach, sobald es ihn gibt.
                <div className="space-y-3">
                    <p className="text-sm text-gray-600 dark:text-gray-400">
                        {t('setup.received.processing')}
                    </p>
                    <Link href={state.issuesHref}>
                        <SecondaryButton type="button">
                            {t('setup.received.to_issues')}
                        </SecondaryButton>
                    </Link>
                </div>
            )}
        </Card>
    );
}

// Die Hilfe. Sie klappt von selbst auf, wenn eine halbe Minute nichts kommt —
// und verschwindet nicht, sobald etwas ankommt: wer sie gelesen hat, soll den
// Absatz zu Ende lesen können.
function Help({ state, guide, project, received }) {
    const t = useT();
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (received || open) {
            return undefined;
        }

        const timer = window.setTimeout(() => setOpen(true), HELP_AFTER_MS);

        return () => window.clearTimeout(timer);
    }, [received, open]);

    if (!open) {
        return (
            <Card>
                <SecondaryButton type="button" onClick={() => setOpen(true)}>
                    {t('setup.help_section.open')}
                </SecondaryButton>
            </Card>
        );
    }

    return (
        <Card
            title={t('setup.help_section.title')}
            description={t('setup.help_section.description')}
        >
            <div className="space-y-4 text-sm text-gray-600 dark:text-gray-400">
                {state.discards.length > 0 && <Discards discards={state.discards} />}

                <ul className="list-disc space-y-2 pl-5">
                    <li>{t('setup.help_section.causes.dsn')}</li>
                    <li>{t('setup.help_section.causes.reachable')}</li>
                    <li>{t('setup.help_section.causes.flush')}</li>
                    <li>{t('setup.help_section.causes.sample_rate')}</li>
                    <li>{t('setup.help_section.causes.key_disabled')}</li>
                    <li>{t('setup.help_section.causes.filters')}</li>
                </ul>

                <p className="flex flex-wrap items-center gap-3">
                    <Link
                        href={project.keysHref}
                        className="underline hover:text-gray-900 dark:hover:text-gray-100"
                    >
                        {t('setup.help_section.to_keys')}
                    </Link>
                    <a
                        href={guide.docsHref}
                        target="_blank"
                        rel="noreferrer noopener"
                        className="underline hover:text-gray-900 dark:hover:text-gray-100"
                    >
                        {t('setup.help_section.to_docs', { package: guide.package })}
                    </a>
                </p>
            </div>
        </Card>
    );
}

// Was angekommen und trotzdem nicht geblieben ist. Das ist die wertvollste
// Auskunft dieser Seite: sie unterscheidet „nichts gesendet" von „gesendet und
// abgewiesen" — zwei Probleme, die nichts miteinander zu tun haben.
function Discards({ discards }) {
    const t = useT();

    return (
        <div className="rounded-md border border-amber-300 bg-amber-50 p-3 dark:border-amber-500/40 dark:bg-amber-950/30">
            <p className="font-medium text-amber-900 dark:text-amber-200">
                {t('setup.help_section.discards.title')}
            </p>
            <p className="mt-1 text-amber-800 dark:text-amber-300/80">
                {t('setup.help_section.discards.description')}
            </p>
            <ul className="mt-2 space-y-1 text-amber-900 dark:text-amber-200">
                {discards.map((discard) => (
                    <li key={`${discard.origin}-${discard.reason}`}>
                        {t('setup.help_section.discards.entry', {
                            count: discard.quantity,
                            reason: discard.label,
                            origin: t(`setup.help_section.discards.origin.${discard.origin}`),
                        })}
                    </li>
                ))}
            </ul>
        </div>
    );
}

// Ohne aktiven Schlüssel gibt es keine DSN und damit nichts einzurichten. Der
// Fall ist selten — jedes Projekt entsteht mit einem —, aber wer seinen
// einzigen abschaltet, soll hier nicht vor einem Beispiel mit leerer DSN stehen.
function MissingKey({ project }) {
    const t = useT();

    return (
        <Card title={t('setup.no_key.title')} description={t('setup.no_key.description')}>
            <Link href={project.keysHref}>
                <PrimaryButton type="button">{t('setup.no_key.to_keys')}</PrimaryButton>
            </Link>
        </Card>
    );
}
