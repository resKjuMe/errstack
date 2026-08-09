import React, { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import {
    Checkbox,
    InputError,
    InputLabel,
    PrimaryButton,
    SecondaryButton,
    SelectInput,
    TextInput,
} from '../../components/Form.jsx';
import { formatDateTime, useTranslations } from '../../i18n.js';

// Die Liste der Dashboards: die eigenen und die freigegebenen.
//
// **Angelegt wird hier und nicht auf einer eigenen Seite.** Ein neues Dashboard
// ist ein Name und höchstens eine Vorlage; wer dafür die Liste verlassen müsste,
// verlöre den Überblick über das, was es schon gibt.
export default function DashboardsIndex({ dashboards, templates, limits, createUrl }) {
    const { shell } = usePage().props;
    const { t, formats } = useTranslations();
    const [creating, setCreating] = useState(false);

    return (
        <>
            <PageHead
                title={t('dashboards.title')}
                appName={shell.appName}
                help={t('dashboards.help')}
                meta={
                    <SecondaryButton
                        type="button"
                        onClick={() => setCreating((v) => !v)}
                        disabled={dashboards.length >= limits.perUser}
                    >
                        {t('dashboards.create.title')}
                    </SecondaryButton>
                }
            />

            {creating && (
                <CreateForm
                    templates={templates}
                    createUrl={createUrl}
                    t={t}
                    onClose={() => setCreating(false)}
                />
            )}

            {dashboards.length === 0 ? (
                <Card>
                    <p className="text-sm text-gray-500 dark:text-gray-400">
                        {t('dashboards.list.empty')}
                    </p>
                </Card>
            ) : (
                <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {dashboards.map((dashboard) => (
                        <li key={dashboard.id}>
                            <Card className="h-full">
                                <div className="flex h-full flex-col">
                                    <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">
                                        <Link href={dashboard.href} className="hover:underline">
                                            {dashboard.name}
                                        </Link>
                                    </h2>

                                    {dashboard.description && (
                                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                            {dashboard.description}
                                        </p>
                                    )}

                                    <p className="mt-3 flex flex-wrap gap-x-3 text-xs text-gray-500 dark:text-gray-400">
                                        <span>
                                            {dashboard.widgets === 1
                                                ? t('dashboards.list.widgets_one')
                                                : t('dashboards.list.widgets', {
                                                      count: dashboard.widgets,
                                                  })}
                                        </span>
                                        {!dashboard.own && (
                                            <span>
                                                {t('dashboards.list.owner', {
                                                    name: dashboard.owner,
                                                })}
                                            </span>
                                        )}
                                        {dashboard.shared && (
                                            <span>{t('dashboards.list.shared')}</span>
                                        )}
                                        {dashboard.updatedAt && (
                                            <span>
                                                {t('dashboards.list.updated', {
                                                    at: formatDateTime(
                                                        dashboard.updatedAt,
                                                        formats
                                                    ),
                                                })}
                                            </span>
                                        )}
                                    </p>

                                    <div className="mt-4 flex items-center gap-2">
                                        <Link
                                            href={dashboard.href}
                                            className="inline-flex rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                                        >
                                            {t('dashboards.list.open')}
                                        </Link>

                                        <SecondaryButton
                                            type="button"
                                            onClick={() => router.post(dashboard.duplicateHref)}
                                        >
                                            {t('dashboards.settings.duplicate')}
                                        </SecondaryButton>
                                    </div>
                                </div>
                            </Card>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}

function CreateForm({ templates, createUrl, t, onClose }) {
    const form = useForm({ name: '', description: '', template: '', shared: false });

    const submit = (event) => {
        event.preventDefault();

        form.post(createUrl, { onSuccess: onClose });
    };

    // Ein Name muss sein; die Vorlage schlägt ihren vor, damit man ihn nicht
    // abtippt.
    const chooseTemplate = (value) => {
        const template = templates.find((option) => option.value === value);

        form.setData({
            ...form.data,
            template: value,
            name: form.data.name === '' && template ? template.name : form.data.name,
            description:
                form.data.description === '' && template
                    ? template.description
                    : form.data.description,
        });
    };

    return (
        <Card className="mb-4">
            <form onSubmit={submit}>
                <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">
                    {t('dashboards.create.title')}
                </h2>

                <div className="mt-4 grid gap-4 md:grid-cols-3">
                    <div>
                        <InputLabel
                            htmlFor="new_dashboard_template"
                            value={t('dashboards.create.template')}
                        />
                        <SelectInput
                            id="new_dashboard_template"
                            className="mt-1 w-full"
                            value={form.data.template}
                            placeholder={t('dashboards.create.template_none')}
                            options={templates.map((option) => ({
                                value: option.value,
                                label: `${option.name} (${option.widgets})`,
                            }))}
                            onChange={(e) => chooseTemplate(e.target.value)}
                        />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="new_dashboard_name"
                            value={t('dashboards.create.name')}
                        />
                        <TextInput
                            id="new_dashboard_name"
                            className="mt-1 w-full"
                            value={form.data.name}
                            maxLength={80}
                            onChange={(e) => form.setData('name', e.target.value)}
                        />
                        <InputError message={form.errors.name} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="new_dashboard_description"
                            value={t('dashboards.create.description')}
                        />
                        <TextInput
                            id="new_dashboard_description"
                            className="mt-1 w-full"
                            value={form.data.description}
                            maxLength={500}
                            onChange={(e) => form.setData('description', e.target.value)}
                        />
                    </div>
                </div>

                <label className="mt-4 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <Checkbox
                        checked={form.data.shared}
                        onChange={(e) => form.setData('shared', e.target.checked)}
                    />
                    {t('dashboards.create.shared')}
                </label>

                <div className="mt-4 flex items-center gap-2">
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {t('dashboards.create.submit')}
                    </PrimaryButton>
                    <SecondaryButton type="button" onClick={onClose}>
                        {t('dashboards.widget.cancel')}
                    </SecondaryButton>
                </div>
            </form>
        </Card>
    );
}
