import React, { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import {
    Checkbox,
    DangerButton,
    InputError,
    InputLabel,
    PrimaryButton,
    SecondaryButton,
    TextInput,
} from '../../components/Form.jsx';
import { useTranslations } from '../../i18n.js';
import Grid from './Grid.jsx';
import WidgetForm from './WidgetForm.jsx';

// Ein Dashboard: das Raster mit seinen Kacheln.
//
// **Die Seite bringt das Raster mit, nicht die Zahlen.** Jede Kachel holt sie
// selbst, und die zwanzig Abrufe laufen nebeneinander — das Raster steht sofort
// und füllt sich. Eine Antwort, die alle Kacheln enthielte, wäre serverseitig
// eine Schleife und der Bildschirm so lange leer wie ihre Summe.
//
// **Der Zeitraum steht oben in der Filterleiste**, wie auf jeder
// Auswertungsseite: dieselbe Leiste, dieselben Regeln, derselbe Zustand in der
// Adresszeile. Kacheln, die davon abweichen, sagen es an sich selbst.
export default function DashboardShow({
    dashboard,
    widgets,
    catalog,
    grid,
    projectOptions,
    environments,
}) {
    const { shell } = usePage().props;
    const { t } = useTranslations();
    const [editing, setEditing] = useState(null);
    const [settings, setSettings] = useState(false);

    const closeForm = () => setEditing(null);

    const remove = (widget) => {
        if (window.confirm(t('dashboards.widget.delete_confirm'))) {
            router.delete(widget.href, { preserveScroll: true });
        }
    };

    return (
        <>
            <PageHead
                title={dashboard.name}
                appName={shell.appName}
                help={t('dashboards.help')}
                meta={
                    <div className="flex flex-wrap items-center gap-2">
                        {dashboard.canUpdate && (
                            <>
                                <SecondaryButton
                                    type="button"
                                    onClick={() => setEditing({ widget: null })}
                                    disabled={dashboard.full}
                                >
                                    {t('dashboards.grid.add')}
                                </SecondaryButton>
                                <SecondaryButton
                                    type="button"
                                    onClick={() => setSettings((v) => !v)}
                                >
                                    {t('dashboards.settings.title')}
                                </SecondaryButton>
                            </>
                        )}

                        <SecondaryButton
                            type="button"
                            onClick={() => router.post(dashboard.duplicateHref)}
                        >
                            {t('dashboards.settings.duplicate')}
                        </SecondaryButton>
                    </div>
                }
            />

            {dashboard.description && (
                <p className="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    {dashboard.description}
                </p>
            )}

            {!dashboard.canUpdate && (
                <Card className="mb-4">
                    <p className="text-sm text-gray-600 dark:text-gray-300">
                        {t('dashboards.settings.readonly', { name: dashboard.owner })}
                    </p>
                </Card>
            )}

            {dashboard.full && dashboard.canUpdate && (
                <p className="mb-4 text-sm text-amber-700 dark:text-amber-400">
                    {t('dashboards.grid.full', { limit: grid.maxWidgets })}
                </p>
            )}

            {settings && dashboard.canUpdate && (
                <Settings dashboard={dashboard} t={t} onClose={() => setSettings(false)} />
            )}

            {editing && (
                <WidgetForm
                    widget={editing.widget}
                    catalog={catalog}
                    grid={grid}
                    projectOptions={projectOptions}
                    environments={environments}
                    action={editing.widget ? editing.widget.href : dashboard.widgetsHref}
                    method={editing.widget ? 'patch' : 'post'}
                    onClose={closeForm}
                />
            )}

            {widgets.length === 0 ? (
                <Card>
                    <p className="text-sm text-gray-500 dark:text-gray-400">
                        {t('dashboards.grid.empty')}
                    </p>
                </Card>
            ) : (
                <Grid
                    widgets={widgets}
                    grid={grid}
                    editable={dashboard.canUpdate}
                    layoutUrl={dashboard.layoutHref}
                    onEdit={(widget) => setEditing({ widget })}
                    onDelete={remove}
                />
            )}

            {dashboard.canUpdate && widgets.length > 0 && (
                <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    {t('dashboards.grid.keyboard_hint')}
                </p>
            )}
        </>
    );
}

// Name, Beschreibung, Freigabe — und das Löschen.
function Settings({ dashboard, t, onClose }) {
    const form = useForm({
        name: dashboard.name,
        description: dashboard.description,
        shared: dashboard.shared,
    });

    const submit = (event) => {
        event.preventDefault();

        form.patch(dashboard.href, { preserveScroll: true, onSuccess: onClose });
    };

    const remove = () => {
        if (window.confirm(t('dashboards.settings.delete_confirm'))) {
            router.delete(dashboard.href);
        }
    };

    return (
        <Card className="mb-4">
            <form onSubmit={submit}>
                <div className="grid gap-4 md:grid-cols-2">
                    <div>
                        <InputLabel htmlFor="dashboard_name" value={t('dashboards.create.name')} />
                        <TextInput
                            id="dashboard_name"
                            className="mt-1 w-full"
                            value={form.data.name}
                            maxLength={80}
                            onChange={(e) => form.setData('name', e.target.value)}
                        />
                        <InputError message={form.errors.name} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="dashboard_description"
                            value={t('dashboards.create.description')}
                        />
                        <TextInput
                            id="dashboard_description"
                            className="mt-1 w-full"
                            value={form.data.description}
                            maxLength={500}
                            onChange={(e) => form.setData('description', e.target.value)}
                        />
                        <InputError message={form.errors.description} className="mt-1" />
                    </div>
                </div>

                <label className="mt-4 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <Checkbox
                        checked={form.data.shared}
                        onChange={(e) => form.setData('shared', e.target.checked)}
                    />
                    {t('dashboards.create.shared')}
                </label>
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {t('dashboards.settings.shared_hint')}
                </p>

                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {t('dashboards.settings.submit')}
                    </PrimaryButton>
                    <SecondaryButton type="button" onClick={onClose}>
                        {t('dashboards.widget.cancel')}
                    </SecondaryButton>

                    {dashboard.canDelete && (
                        <DangerButton type="button" className="ml-auto" onClick={remove}>
                            {t('dashboards.settings.delete')}
                        </DangerButton>
                    )}
                </div>
            </form>
        </Card>
    );
}
