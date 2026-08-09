import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import Card from '../../components/Card.jsx';
import {
    InputLabel,
    PrimaryButton,
    SecondaryButton,
    SelectInput,
    TextInput,
} from '../../components/Form.jsx';

// Die Abfrage-Leiste: Quelle, Gruppierung, Kennzahlen, Suchbedingung,
// Sortierung, Zeilenzahl und Schrittweite.
//
// **Sie schickt ab und ändert nicht bei jedem Klick.** Eine Auswertung ist eine
// Abfrage über eine Datenmenge; wer die Quelle wechselt und danach die
// Gruppierung, meinte nicht zwei Auswertungen dazwischen. Deshalb steht der
// Zustand hier lokal, bis „Auswerten" ihn in die Adresszeile schreibt — von dort
// liest ihn der Server, und von dort kommt er auch zurück.
//
// **Die Auswahl kommt vollständig vom Server** (`catalog`): welche Quellen es
// gibt, wonach sich in ihnen gruppieren und worüber sich rechnen lässt. Eine
// Liste, die hier entstünde, böte früher oder später etwas an, das der Motor
// abweist.
export default function QueryBar({ query, catalog, columns, t }) {
    const [dataset, setDataset] = useState(query.dataset);
    const [fields, setFields] = useState(query.fields);
    const [metrics, setMetrics] = useState(query.metrics.map(parseMetric));
    const [search, setSearch] = useState(query.q);
    const [sort, setSort] = useState(query.sort);
    const [limit, setLimit] = useState(String(query.limit));
    const [interval, setInterval] = useState(query.interval);

    const current =
        catalog.datasets.find((option) => option.value === dataset) ?? catalog.datasets[0];

    const submit = (event) => {
        event?.preventDefault();

        // Die Parameter der Filterleiste (Projekt, Umgebung, Zeitraum) bleiben
        // stehen: sie gehören zum Rahmen und nicht zu dieser Leiste. Übernommen
        // wird deshalb alles **außer** den eigenen Feldern — und nicht die
        // eigenen gelöscht: eine Liste steht in der Adresse mal als `fields[]`
        // und mal als `fields[0]`, und ein `delete('fields[]')` ließe die andere
        // Schreibweise stehen. Am Ende stünde das Feld zweimal drin.
        const params = new URLSearchParams();
        const own = ['dataset', 'fields', 'metrics', 'q', 'sort', 'limit', 'interval'];

        new URLSearchParams(window.location.search).forEach((value, key) => {
            if (!own.includes(key.replace(/\[.*$/, ''))) {
                params.append(key, value);
            }
        });

        params.set('dataset', dataset);
        fields.filter(Boolean).forEach((field) => params.append('fields[]', field));
        metrics.map(metricToString).forEach((metric) => params.append('metrics[]', metric));
        params.set('q', search);
        params.set('sort', sort);
        params.set('limit', limit);
        params.set('interval', interval);

        router.get(
            `${window.location.pathname}?${params.toString()}`,
            {},
            { preserveState: false }
        );
    };

    // Ein Quellenwechsel nimmt Gruppierung und Kennzahlen mit, soweit es die
    // Felder dort gibt. Alles stehen zu lassen hieße, die Abfrage mit Feldern
    // abzuschicken, die der Motor als unbekannt zurückweist; alles zu leeren
    // hieße, jemandem, der nur die Quelle vergleichen will, die Arbeit zweimal
    // aufzugeben.
    const changeDataset = (value) => {
        const next = catalog.datasets.find((option) => option.value === value);

        setDataset(value);
        setFields(fields.filter((field) => next?.group_by.includes(field)));
        setMetrics(
            metrics.filter(
                (metric) => metric.field === '' || (next?.aggregate ?? []).includes(metric.field)
            )
        );
    };

    const setField = (index, value) => {
        const next = [...fields];

        if (value === '') {
            next.splice(index, 1);
        } else {
            next[index] = value;
        }

        setFields(next);
    };

    const setMetric = (index, patch) => {
        const next = [...metrics];

        next[index] = { ...next[index], ...patch };

        // Eine Rechenart ohne Feld nimmt keines: `count(browser)` ist keine
        // Abfrage, die jemand gemeint hat.
        if (!needsField(catalog, next[index].aggregate)) {
            next[index].field = '';
        } else if (next[index].field === '') {
            next[index].field = current?.aggregate[0] ?? '';
        }

        setMetrics(next);
    };

    const removeMetric = (index) => setMetrics(metrics.filter((_, i) => i !== index));

    const sortOptions = columns.length
        ? columns.flatMap((column) => [
              { value: `-${column.key}`, label: `${column.label} ↓` },
              { value: column.key, label: `${column.label} ↑` },
          ])
        : [{ value: sort, label: sort }];

    return (
        <Card className="mb-4">
            <form onSubmit={submit} className="space-y-4">
                <div className="flex flex-wrap items-end gap-4">
                    <div>
                        <InputLabel
                            htmlFor="discover_dataset"
                            value={t('discover.query.dataset')}
                        />
                        <SelectInput
                            id="discover_dataset"
                            className="mt-1"
                            value={dataset}
                            options={catalog.datasets}
                            onChange={(e) => changeDataset(e.target.value)}
                        />
                    </div>

                    <div>
                        <InputLabel htmlFor="discover_limit" value={t('discover.query.limit')} />
                        <TextInput
                            id="discover_limit"
                            type="number"
                            min="1"
                            max={catalog.limits.maxRows}
                            className="mt-1 w-28"
                            value={limit}
                            onChange={(e) => setLimit(e.target.value)}
                        />
                    </div>

                    <div>
                        <InputLabel htmlFor="discover_sort" value={t('discover.query.sort')} />
                        <SelectInput
                            id="discover_sort"
                            className="mt-1"
                            value={sort}
                            options={sortOptions}
                            onChange={(e) => setSort(e.target.value)}
                        />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="discover_interval"
                            value={t('discover.query.interval')}
                        />
                        <SelectInput
                            id="discover_interval"
                            className="mt-1"
                            value={interval}
                            options={catalog.intervals}
                            onChange={(e) => setInterval(e.target.value)}
                        />
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <div>
                        <InputLabel value={t('discover.query.group_by')} />
                        <div className="mt-1 space-y-2">
                            {fields.map((field, index) => (
                                <SelectInput
                                    key={`${field}-${index}`}
                                    value={field}
                                    placeholder={t('discover.query.group_by_none')}
                                    options={fieldOptions(current?.group_by ?? [])}
                                    onChange={(e) => setField(index, e.target.value)}
                                />
                            ))}

                            {fields.length < catalog.limits.maxGroupFields && (
                                <SelectInput
                                    value=""
                                    placeholder={t('discover.query.group_by_add')}
                                    options={fieldOptions(
                                        (current?.group_by ?? []).filter(
                                            (field) => !fields.includes(field)
                                        )
                                    )}
                                    onChange={(e) =>
                                        e.target.value !== '' &&
                                        setFields([...fields, e.target.value])
                                    }
                                />
                            )}
                        </div>
                    </div>

                    <div>
                        <InputLabel value={t('discover.query.metrics')} />
                        <div className="mt-1 space-y-2">
                            {metrics.map((metric, index) => (
                                <div key={index} className="flex items-center gap-2">
                                    <SelectInput
                                        value={metric.aggregate}
                                        options={catalog.aggregates}
                                        onChange={(e) =>
                                            setMetric(index, { aggregate: e.target.value })
                                        }
                                    />

                                    {needsField(catalog, metric.aggregate) && (
                                        <SelectInput
                                            value={metric.field}
                                            options={fieldOptions(current?.aggregate ?? [])}
                                            onChange={(e) =>
                                                setMetric(index, { field: e.target.value })
                                            }
                                        />
                                    )}

                                    {metrics.length > 1 && (
                                        <button
                                            type="button"
                                            onClick={() => removeMetric(index)}
                                            className="text-sm text-gray-500 hover:text-rose-600 dark:text-gray-400 dark:hover:text-rose-400"
                                        >
                                            {t('discover.query.remove')}
                                        </button>
                                    )}
                                </div>
                            ))}

                            {metrics.length < catalog.limits.maxAggregations && (
                                <SecondaryButton
                                    type="button"
                                    onClick={() =>
                                        setMetrics([...metrics, { aggregate: 'count', field: '' }])
                                    }
                                >
                                    {t('discover.query.metrics_add')}
                                </SecondaryButton>
                            )}
                        </div>
                    </div>
                </div>

                <div>
                    <InputLabel htmlFor="discover_q" value={t('discover.query.search')} />
                    <TextInput
                        id="discover_q"
                        className="mt-1 w-full font-mono text-sm"
                        value={search}
                        placeholder={t('discover.query.search_placeholder')}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>

                <div className="flex items-center gap-3">
                    <PrimaryButton type="submit">{t('discover.query.submit')}</PrimaryButton>
                </div>
            </form>
        </Card>
    );
}

// `p95(duration)` — dieselbe Schreibweise wie in der Adresszeile und im Motor.
function parseMetric(expression) {
    const match = /^([a-z_0-9]+)\s*\(\s*(.*?)\s*\)$/i.exec(String(expression).trim());

    if (match === null) {
        return { aggregate: String(expression).trim(), field: '' };
    }

    return { aggregate: match[1], field: match[2] };
}

function metricToString(metric) {
    return `${metric.aggregate}(${metric.field})`;
}

function needsField(catalog, aggregate) {
    return catalog.aggregates.find((option) => option.value === aggregate)?.needsField ?? false;
}

function fieldOptions(fields) {
    return fields.map((field) => ({ value: field, label: field }));
}
