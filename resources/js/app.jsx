import React from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { startSelfMonitoring } from './selfmonitoring.js';
import AppShell from './shell/AppShell.jsx';
import Dashboard from './shell/pages/Dashboard.jsx';
import Performance from './shell/pages/Performance.jsx';
import PerformanceTransaction from './shell/pages/performance/Transaction.jsx';
import Components from './shell/pages/Components.jsx';
import Profile from './shell/pages/Profile.jsx';
import OrganizationsIndex from './shell/pages/organizations/Index.jsx';
import OrganizationsShow from './shell/pages/organizations/Show.jsx';
import OrganizationsAuditLog from './shell/pages/organizations/AuditLog.jsx';
import TeamsShow from './shell/pages/teams/Show.jsx';
import NotificationsIndex from './shell/pages/notifications/Index.jsx';
import NotificationsPreferences from './shell/pages/notifications/Preferences.jsx';
import NotificationsUnsubscribe from './shell/pages/notifications/Unsubscribe.jsx';
import IssuesIndex from './shell/pages/issues/Index.jsx';
import IssuesShow from './shell/pages/issues/Show.jsx';
import IssuesTags from './shell/pages/issues/Tags.jsx';
import TagsIndex from './shell/pages/tags/Index.jsx';
import FeedbackIndex from './shell/pages/feedback/Index.jsx';
import TracesShow from './shell/pages/traces/Show.jsx';
import ProjectsIndex from './shell/pages/projects/Index.jsx';
import ProjectsShow from './shell/pages/projects/Show.jsx';
import ProjectsSetup from './shell/pages/projects/Setup.jsx';
import ProjectsKeys from './shell/pages/projects/Keys.jsx';
import ProjectsAlerts from './shell/pages/projects/Alerts.jsx';
import ProjectsIssueAlerts from './shell/pages/projects/IssueAlerts.jsx';
import ProjectsAlertOverview from './shell/pages/projects/AlertOverview.jsx';
import ProjectsAlertDetail from './shell/pages/projects/AlertDetail.jsx';
import ProjectsCrons from './shell/pages/projects/Crons.jsx';
import ProjectsUptime from './shell/pages/projects/Uptime.jsx';
import ProjectsGrouping from './shell/pages/projects/Grouping.jsx';
import ProjectsFilters from './shell/pages/projects/Filters.jsx';
// Eine Seite für beide Ebenen: die Kontingente eines Projekts und die einer
// Organisation unterscheiden sich im Gegenstand, nicht im Aufbau. Beide
// Seitennamen brauchen trotzdem ihre eigene Datei — `ensure_pages_exist` prüft
// serverseitig, dass es sie gibt.
import ProjectsQuotas from './shell/pages/projects/Quotas.jsx';
import OrganizationsQuotas from './shell/pages/organizations/Quotas.jsx';
import ProjectsDigest from './shell/pages/projects/Digest.jsx';
import ProjectsSampling from './shell/pages/projects/Sampling.jsx';
import ProjectsSpikes from './shell/pages/projects/Spikes.jsx';
import ProjectsPerformance from './shell/pages/projects/Performance.jsx';
import ProjectsOwnership from './shell/pages/projects/Ownership.jsx';
import PerformanceIssues from './shell/pages/performance/Issues.jsx';
import PerformanceIssueDetail from './shell/pages/performance/IssueDetail.jsx';
import PerformanceTrends from './shell/pages/performance/Trends.jsx';
import WebVitalsIndex from './shell/pages/performance/WebVitals.jsx';
import WebVitalShow from './shell/pages/performance/WebVital.jsx';
import DiscoverIndex from './shell/pages/discover/Index.jsx';
import ProfilingIndex from './shell/pages/profiling/Index.jsx';
import ProfilingShow from './shell/pages/profiling/Show.jsx';
import ReplaysIndex from './shell/pages/replays/Index.jsx';
import ReplaysShow from './shell/pages/replays/Show.jsx';
import ReleasesIndex from './shell/pages/releases/Index.jsx';
import ReleasesShow from './shell/pages/releases/Show.jsx';
import RepositoriesIndex from './shell/pages/repositories/Index.jsx';
import PrivacyIndex from './shell/pages/privacy/Index.jsx';
import InvitationsAccept from './shell/pages/invitations/Accept.jsx';
import ApiTokensIndex from './shell/pages/api-tokens/Index.jsx';
import OperationsIndex from './shell/pages/operations/Index.jsx';
import Login from './shell/pages/auth/Login.jsx';
import Register from './shell/pages/auth/Register.jsx';
import ForgotPassword from './shell/pages/auth/ForgotPassword.jsx';
import ResetPassword from './shell/pages/auth/ResetPassword.jsx';
import ConfirmPassword from './shell/pages/auth/ConfirmPassword.jsx';
import VerifyEmail from './shell/pages/auth/VerifyEmail.jsx';

// Seiten-Registry: Inertia löst den vom Server gelieferten Seitennamen hier
// auf. Jede Seite wird in die AppShell gehängt (gemeinsames Layout), sofern sie
// nicht selbst ein Layout mitbringt — die Anmeldeseiten bringen mit der
// GuestShell ihr eigenes mit.
const pages = {
    Dashboard,
    Performance,
    // Die Detailanalyse einer Transaktion — die Frage „warum" hinter der
    // Übersicht.
    'performance/Transaction': PerformanceTransaction,
    Components,
    Profile,
    'organizations/Index': OrganizationsIndex,
    'organizations/Show': OrganizationsShow,
    'organizations/AuditLog': OrganizationsAuditLog,
    'teams/Show': TeamsShow,
    'notifications/Index': NotificationsIndex,
    'notifications/Preferences': NotificationsPreferences,
    'notifications/Unsubscribe': NotificationsUnsubscribe,
    'issues/Index': IssuesIndex,
    'issues/Show': IssuesShow,
    // Eine Seite für Übersicht und Merkmal-Detail — der Unterschied ist eine
    // Liste, nicht ein Bildschirm.
    'issues/Tags': IssuesTags,
    'tags/Index': TagsIndex,
    'feedback/Index': FeedbackIndex,
    // Der Ablauf eines Aufrufs über alle Dienste. Keine Liste daneben: eine Spur
    // wird nicht gesucht, sondern von einem Fehler oder einer Messung aus
    // aufgerufen.
    'traces/Show': TracesShow,
    'projects/Index': ProjectsIndex,
    'projects/Show': ProjectsShow,
    // Der Einrichtungs-Assistent. Eine eigene Seite und kein Abschnitt der
    // Einstellungen: er wird einmal am Anfang gebraucht und danach selten —
    // umgekehrt sind die Einstellungen eine Liste von Schaltern und keine
    // Anleitung, der man von oben nach unten folgt.
    'projects/Setup': ProjectsSetup,
    'projects/Keys': ProjectsKeys,
    'projects/Alerts': ProjectsAlerts,
    'projects/IssueAlerts': ProjectsIssueAlerts,
    // Übersicht und Detail sind zwei Seiten: die Übersicht führt beide
    // Alarm-Arten zusammen, das Detail zeigt genau eine Regel. Dieselbe Seite
    // mit einer Weiche wäre an jeder zweiten Stelle eine Abfrage „welcher Fall
    // ist das gerade".
    'projects/AlertOverview': ProjectsAlertOverview,
    'projects/AlertDetail': ProjectsAlertDetail,
    'projects/Crons': ProjectsCrons,
    'projects/Uptime': ProjectsUptime,
    'projects/Grouping': ProjectsGrouping,
    'projects/Filters': ProjectsFilters,
    'projects/Quotas': ProjectsQuotas,
    'organizations/Quotas': OrganizationsQuotas,
    'projects/Digest': ProjectsDigest,
    'projects/Sampling': ProjectsSampling,
    // Der Ausschlag-Schutz (A7): Zustand, Verlauf und Einstellungen auf einer
    // Seite — wer in einer Flut hierherkommt, soll nicht zwischen zwei
    // Bildschirmen suchen.
    'projects/Spikes': ProjectsSpikes,
    'projects/Performance': ProjectsPerformance,
    'projects/Ownership': ProjectsOwnership,
    'performance/Issues': PerformanceIssues,
    'performance/IssueDetail': PerformanceIssueDetail,
    // Übersicht der schlechtesten Seiten und das Ladeerlebnis einer einzelnen —
    // derselbe Schnitt wie bei den Profilen.
    'performance/WebVitals': WebVitalsIndex,
    'performance/WebVital': WebVitalShow,
    'performance/Trends': PerformanceTrends,
    // Die freie Auswertung. Eine Seite und nicht zwei: Tabelle und Diagramm
    // sind dieselbe Abfrage, einmal mit und einmal ohne Schrittweite.
    'discover/Index': DiscoverIndex,
    // Übersicht und Einzelprofil sind zwei Seiten und nicht eine: die Übersicht
    // legt viele Profile übereinander, das Einzelprofil zeigt genau einen
    // Aufruf. Dieselbe Seite mit einer Weiche wäre an jeder zweiten Stelle eine
    // Abfrage „welcher Fall ist das gerade".
    'profiling/Index': ProfilingIndex,
    'profiling/Show': ProfilingShow,
    'replays/Index': ReplaysIndex,
    'replays/Show': ReplaysShow,
    'releases/Index': ReleasesIndex,
    'releases/Show': ReleasesShow,
    'repositories/Index': RepositoriesIndex,
    // Eine Seite für beide Ebenen — Projekt und Organisation liefern dieselbe
    // Nutzlast mit unterschiedlichem `scope`.
    'privacy/Index': PrivacyIndex,
    'invitations/Accept': InvitationsAccept,
    'api-tokens/Index': ApiTokensIndex,
    // Der Zustand der Installation selbst — sichtbar nur für den Betreiber.
    'operations/Index': OperationsIndex,
    'auth/Login': Login,
    'auth/Register': Register,
    'auth/ForgotPassword': ForgotPassword,
    'auth/ResetPassword': ResetPassword,
    'auth/ConfirmPassword': ConfirmPassword,
    'auth/VerifyEmail': VerifyEmail,
};

createInertiaApp({
    resolve: (name) => {
        const page = pages[name];

        if (!page) {
            throw new Error(`Unbekannte Inertia-Seite: ${name}`);
        }

        page.layout = page.layout ?? ((content) => <AppShell>{content}</AppShell>);

        return page;
    },
    setup({ el, App, props }) {
        // Vor dem Zeichnen und ohne `await`: die Überwachung soll den ersten
        // Bildaufbau nicht aufhalten, aber schon stehen, wenn er schiefgeht.
        startSelfMonitoring(props.initialPage.props.selfMonitoring);

        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#f43f5e' },
});
