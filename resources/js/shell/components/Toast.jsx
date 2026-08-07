import React, {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { useT } from '../i18n.js';

// Toast-Meldungen: kurzlebige Rückmeldungen rechts unten, unabhängig vom
// Seiteninhalt. Die AppShell hängt den Provider einmal um die ganze App; Seiten
// lösen Meldungen über useToast() aus:
//
//     const toast = useToast();
//     toast.success('Gespeichert.');
//     toast.error('Das hat nicht geklappt.');

const ToastContext = createContext(null);

const VARIANTS = {
    success: 'bg-green-600 text-white',
    error: 'bg-rose-600 text-white',
    info: 'bg-gray-800 text-white dark:bg-gray-700',
};

const DEFAULT_DURATION = 5000;

function ToastItem({ toast, onDismiss }) {
    const t = useT();

    // Ein Timer je Meldung; onDismiss bleibt über useCallback stabil, sodass der
    // Timer nicht bei jedem Render neu startet.
    useEffect(() => {
        if (toast.duration === 0) {
            return undefined;
        }

        const id = setTimeout(() => onDismiss(toast.id), toast.duration);

        return () => clearTimeout(id);
    }, [toast.id, toast.duration, onDismiss]);

    return (
        <div
            role="status"
            className={`es-toast-enter flex items-start gap-3 rounded-lg px-4 py-3 text-sm shadow-lg ${VARIANTS[toast.variant] ?? VARIANTS.info}`}
        >
            <p className="flex-1">{toast.message}</p>
            <button
                type="button"
                onClick={() => onDismiss(toast.id)}
                className="text-white/80 hover:text-white"
                aria-label={t('components.toasts.dismiss')}
            >
                ✕
            </button>
        </div>
    );
}

export function ToastProvider({ children }) {
    const [toasts, setToasts] = useState([]);
    const nextId = useRef(1);

    const dismiss = useCallback((id) => {
        setToasts((current) => current.filter((toast) => toast.id !== id));
    }, []);

    const api = useMemo(() => {
        const push = (message, variant, duration = DEFAULT_DURATION) => {
            const id = nextId.current++;
            setToasts((current) => [...current, { id, message, variant, duration }]);

            return id;
        };

        return {
            push,
            success: (message, duration) => push(message, 'success', duration),
            error: (message, duration) => push(message, 'error', duration),
            info: (message, duration) => push(message, 'info', duration),
            dismiss,
        };
    }, [dismiss]);

    return (
        <ToastContext.Provider value={api}>
            {children}
            <div className="pointer-events-none fixed bottom-6 end-6 z-50 flex w-full max-w-sm flex-col gap-2">
                {toasts.map((toast) => (
                    <div key={toast.id} className="pointer-events-auto">
                        <ToastItem toast={toast} onDismiss={dismiss} />
                    </div>
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export function useToast() {
    const context = useContext(ToastContext);

    if (!context) {
        throw new Error('useToast() benötigt einen ToastProvider (siehe AppShell).');
    }

    return context;
}
