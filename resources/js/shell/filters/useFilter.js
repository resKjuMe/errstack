import { usePage } from '@inertiajs/react';

// Die Nutzlast der globalen Filterleiste (App\Support\FilterData::bar). Sie kommt
// als geteilte Eigenschaft und nicht von der Seite: die Leiste gehört zum Rahmen,
// und eine Seite, die die Filterwerte braucht — für einen Link oder eine
// Beschriftung —, holt sie hier statt sie sich durchreichen zu lassen.
//
// `null` auf Seiten ohne Auswertungsbezug: dort steht keine Leiste, und es gibt
// keinen Filter, auf den man sich beziehen könnte.
export default function useFilter() {
    return usePage().props.filter ?? null;
}
