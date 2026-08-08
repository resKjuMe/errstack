import React from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import AppShell from './shell/AppShell.jsx';
import Dashboard from './shell/pages/Dashboard.jsx';
import Performance from './shell/pages/Performance.jsx';
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
import IssuesTags from './shell/pages/issues/Tags.jsx';
import TagsIndex from './shell/pages/tags/Index.jsx';
import ProjectsIndex from './shell/pages/projects/Index.jsx';
import ProjectsShow from './shell/pages/projects/Show.jsx';
import ProjectsKeys from './shell/pages/projects/Keys.jsx';
import ProjectsCrons from './shell/pages/projects/Crons.jsx';
import ProjectsGrouping from './shell/pages/projects/Grouping.jsx';
import ProjectsFilters from './shell/pages/projects/Filters.jsx';
import ProjectsSampling from './shell/pages/projects/Sampling.jsx';
import PrivacyIndex from './shell/pages/privacy/Index.jsx';
import InvitationsAccept from './shell/pages/invitations/Accept.jsx';
import ApiTokensIndex from './shell/pages/api-tokens/Index.jsx';
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
    // Eine Seite für Übersicht und Merkmal-Detail — der Unterschied ist eine
    // Liste, nicht ein Bildschirm.
    'issues/Tags': IssuesTags,
    'tags/Index': TagsIndex,
    'projects/Index': ProjectsIndex,
    'projects/Show': ProjectsShow,
    'projects/Keys': ProjectsKeys,
    'projects/Crons': ProjectsCrons,
    'projects/Grouping': ProjectsGrouping,
    'projects/Filters': ProjectsFilters,
    'projects/Sampling': ProjectsSampling,
    // Eine Seite für beide Ebenen — Projekt und Organisation liefern dieselbe
    // Nutzlast mit unterschiedlichem `scope`.
    'privacy/Index': PrivacyIndex,
    'invitations/Accept': InvitationsAccept,
    'api-tokens/Index': ApiTokensIndex,
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
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#f43f5e' },
});
