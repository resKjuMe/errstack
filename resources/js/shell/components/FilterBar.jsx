import React from 'react';
import Card from './Card.jsx';
import { InputLabel, SecondaryButton, SelectInput, TextInput } from './Form.jsx';
import useGlobalFilter from '../filters/useGlobalFilter.js';

// Die globale Filterleiste: Projekt (auch mehrere), Umgebung und Zeitraum.
//
// Gezeichnet wird sie genau einmal, von der AppShell — sie gehört zum Rahmen und
// nicht zur Seite. Dadurch steht sie auf jeder Auswertungsseite an derselben
// Stelle, und eine neue bekommt sie, ohne sie einzubinden. Nutzlast
// (App\Support\FilterData) und Zustand (useGlobalFilter) sind ohnehin geteilt.
//
// Sie hat keine Schaltfläche „Filtern": jede Änderung ist sofort ein Aufruf mit
// neuer Adresse. Der Zeitraum darunter zeigt, was der Server daraus gemacht hat.
//
// Im Einstellungsbereich (U6) gibt es sie nicht — dort zeichnet die AppShell sie
// gar nicht erst.
//
// Die Projektauswahl fehlt dort, wo das Projekt bereits feststeht — auf der
// Detailseite einer Version etwa sagt die Adresse, welches gemeint ist. Das
// entscheidet der Server (`showProjects`, App\Support\FilterData::bar) und nicht
// die Seite: eine Auswahl, die dort ohne Wirkung bliebe, wäre schlimmer als
// keine.
export default function FilterBar({ filter }) {
    const { form, apply, reset, toggleProject } = useGlobalFilter(filter);
    const { labels } = filter;

    const showProjects = filter.showProjects !== false;
    const custom = form.period === 'custom';
    const filtered =
        (showProjects && form.projects.length > 0) ||
        form.environment !== '' ||
        form.period !== filter.defaultPeriod;

    return (
        <Card className="mb-4">
            <div className="flex flex-wrap items-end gap-4">
                {showProjects && (
                    <div className="min-w-48">
                        <InputLabel value={labels.projects} />
                        {filter.projectOptions.length === 0 ? (
                            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {labels.noProjects}
                            </p>
                        ) : (
                            <ProjectPicker
                                options={filter.projectOptions}
                                selected={form.projects}
                                onToggle={toggleProject}
                                allLabel={labels.allProjects}
                            />
                        )}
                    </div>
                )}

                <div>
                    <InputLabel htmlFor="filter_environment" value={labels.environment} />
                    <SelectInput
                        id="filter_environment"
                        className="mt-1"
                        value={form.environment}
                        options={filter.environmentOptions}
                        onChange={(e) => apply({ environment: e.target.value })}
                        placeholder={labels.allEnvironments}
                    />
                </div>

                <div>
                    <InputLabel htmlFor="filter_period" value={labels.period} />
                    <SelectInput
                        id="filter_period"
                        className="mt-1"
                        value={form.period}
                        options={filter.periodOptions}
                        onChange={(e) => apply({ period: e.target.value })}
                    />
                </div>

                {custom && (
                    <>
                        <div>
                            <InputLabel htmlFor="filter_from" value={labels.from} />
                            <TextInput
                                id="filter_from"
                                type="date"
                                className="mt-1"
                                value={form.from}
                                max={form.to}
                                onChange={(e) => apply({ from: e.target.value })}
                            />
                        </div>
                        <div>
                            <InputLabel htmlFor="filter_to" value={labels.to} />
                            <TextInput
                                id="filter_to"
                                type="date"
                                className="mt-1"
                                value={form.to}
                                min={form.from}
                                onChange={(e) => apply({ to: e.target.value })}
                            />
                        </div>
                    </>
                )}

                {filtered && (
                    <SecondaryButton type="button" onClick={reset}>
                        {labels.reset}
                    </SecondaryButton>
                )}

                <p className="ms-auto text-sm text-gray-500 dark:text-gray-400">
                    {filter.range.label}
                    <span className="ms-2 text-xs">({filter.timezone})</span>
                </p>
            </div>
        </Card>
    );
}

// Mehrfachauswahl ohne Aufklapp-Menü: bei einer Handvoll Projekten sind
// Schaltflächen übersichtlicher, und ohne Auswahl gelten alle.
function ProjectPicker({ options, selected, onToggle, allLabel }) {
    return (
        <div className="mt-1 flex flex-wrap gap-1">
            {selected.length === 0 && (
                <span className="rounded-md bg-indigo-600 px-2 py-1 text-sm text-white">
                    {allLabel}
                </span>
            )}

            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    aria-pressed={selected.includes(option.value)}
                    onClick={() => onToggle(option.value)}
                    className={`rounded-md px-2 py-1 text-sm ${
                        selected.includes(option.value)
                            ? 'bg-indigo-600 text-white'
                            : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600'
                    }`}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}
