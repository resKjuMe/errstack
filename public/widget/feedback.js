/*
 * Das mitgelieferte Feedback-Widget — bewusst die Minimalvariante.
 *
 * Es ist eine Beigabe und kein Produkt: eine Datei, keine Abhängigkeiten, kein
 * Bauschritt, kein Build-Artefakt in `resources/js`. Wer ein Widget mit eigenem
 * Aussehen will, schickt selbst ein `POST` an dieselbe Adresse — das ist der
 * eigentliche Vertrag, und er steht unten in `send()`.
 *
 * Einbinden:
 *
 *     <script src="https://errstack.example/widget/feedback.js"
 *             data-dsn="https://<public_key>@errstack.example/<project_id>"
 *             defer></script>
 *
 * Damit erscheint unten rechts eine Schaltfläche. Wer die Stelle selbst
 * bestimmen will, lässt sie weg (`data-button="off"`) und ruft
 * `window.errstackFeedback.open()` auf, wo es passt.
 *
 * Abgeschickt wird als Formular und nicht als JSON. Das ist der Grund, aus dem
 * die Gegenstelle beide Formen annimmt: ein `POST` mit
 * `application/x-www-form-urlencoded` ist eine „einfache" Anfrage und löst
 * keine Vorab-Anfrage (OPTIONS) aus. Mit JSON bräuchte jede fremde Seite eine
 * beantwortete CORS-Vorabfrage, bevor überhaupt etwas ankommt.
 */
(function () {
    'use strict';

    var script = document.currentScript;

    if (!script) {
        return;
    }

    var dsn = parseDsn(script.getAttribute('data-dsn'));

    if (!dsn) {
        console.warn('[errstack] Ohne gültige data-dsn kann das Widget nichts senden.');

        return;
    }

    var texts = {
        button: script.getAttribute('data-label') || 'Feedback',
        title: script.getAttribute('data-title') || 'Was ist passiert?',
        name: script.getAttribute('data-label-name') || 'Name (optional)',
        email: script.getAttribute('data-label-email') || 'E-Mail (optional)',
        comments: script.getAttribute('data-label-comments') || 'Beschreibung',
        submit: script.getAttribute('data-label-submit') || 'Absenden',
        cancel: script.getAttribute('data-label-cancel') || 'Abbrechen',
        thanks: script.getAttribute('data-label-thanks') || 'Danke — die Rückmeldung ist da.',
        failed:
            script.getAttribute('data-label-failed') ||
            'Das hat nicht geklappt. Bitte später erneut versuchen.',
    };

    /**
     * Zerlegt die DSN in Schlüssel, Host und Projektnummer — dieselben drei
     * Angaben, die auch ein SDK daraus liest.
     */
    function parseDsn(value) {
        if (!value) {
            return null;
        }

        try {
            var url = new URL(value);
            var project = url.pathname.replace(/^\/+|\/+$/g, '');

            if (!url.username || !project) {
                return null;
            }

            return {
                key: url.username,
                endpoint: url.origin + '/api/' + project + '/user-feedback/',
            };
        } catch (error) {
            return null;
        }
    }

    /**
     * Der ganze Vertrag mit der Gegenstelle. Wer ein eigenes Widget baut,
     * braucht nur diese Funktion.
     *
     * `event_id` ist freiwillig: mit ihr wird die Rückmeldung an den gemeldeten
     * Fehler geheftet, ohne sie steht sie für sich.
     */
    function send(fields) {
        var body = new URLSearchParams();

        Object.keys(fields).forEach(function (key) {
            if (fields[key]) {
                body.append(key, fields[key]);
            }
        });

        body.append('url', window.location.href);

        return fetch(dsn.endpoint + '?sentry_key=' + encodeURIComponent(dsn.key), {
            method: 'POST',
            // Keine eigene Kopfzeile und keine Zugangsdaten: beides würde die
            // Anfrage aus der „einfachen" Klasse herausheben.
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            return response.json();
        });
    }

    function element(tag, attributes, text) {
        var node = document.createElement(tag);

        Object.keys(attributes || {}).forEach(function (name) {
            node.setAttribute(name, attributes[name]);
        });

        if (text) {
            node.textContent = text;
        }

        return node;
    }

    var dialog = null;

    function open(eventId) {
        if (dialog) {
            return;
        }

        dialog = element('dialog', {
            style:
                'border:1px solid #d4d4d8;border-radius:.5rem;padding:1.25rem;' +
                'max-width:28rem;width:90vw;font:14px/1.5 system-ui,sans-serif;color:#18181b',
        });

        var form = element('form', { method: 'dialog' });

        form.appendChild(element('h2', { style: 'margin:0 0 .75rem;font-size:1rem' }, texts.title));

        var name = element('input', { name: 'name', placeholder: texts.name, style: fieldStyle() });
        var email = element('input', {
            name: 'email',
            type: 'email',
            placeholder: texts.email,
            style: fieldStyle(),
        });
        var comments = element('textarea', {
            name: 'comments',
            rows: '5',
            required: 'required',
            placeholder: texts.comments,
            style: fieldStyle(),
        });

        [name, email, comments].forEach(function (field) {
            form.appendChild(field);
        });

        var note = element('p', { style: 'margin:.5rem 0 0;min-height:1.25rem;color:#71717a' });
        var actions = element('div', {
            style: 'margin-top:.75rem;display:flex;gap:.5rem;justify-content:flex-end',
        });

        var cancel = element('button', { type: 'button', style: buttonStyle(false) }, texts.cancel);
        var submit = element('button', { type: 'submit', style: buttonStyle(true) }, texts.submit);

        cancel.addEventListener('click', close);

        actions.appendChild(cancel);
        actions.appendChild(submit);

        form.appendChild(note);
        form.appendChild(actions);

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!comments.value.trim()) {
                return;
            }

            submit.disabled = true;
            note.textContent = '';

            send({
                event_id: eventId || '',
                name: name.value,
                email: email.value,
                comments: comments.value,
            })
                .then(function () {
                    note.textContent = texts.thanks;
                    // Kurz stehen lassen: ein Fenster, das im selben Augenblick
                    // verschwindet, in dem die Bestätigung erscheint, hat keine
                    // gezeigt.
                    window.setTimeout(close, 1200);
                })
                .catch(function () {
                    submit.disabled = false;
                    note.textContent = texts.failed;
                });
        });

        dialog.appendChild(form);
        document.body.appendChild(dialog);
        dialog.showModal();
        dialog.addEventListener('close', close);
    }

    function close() {
        if (!dialog) {
            return;
        }

        dialog.remove();
        dialog = null;
    }

    function fieldStyle() {
        return (
            'display:block;width:100%;margin-bottom:.5rem;padding:.5rem;' +
            'border:1px solid #d4d4d8;border-radius:.375rem;font:inherit;box-sizing:border-box'
        );
    }

    function buttonStyle(primary) {
        return (
            'padding:.4rem .9rem;border-radius:.375rem;font:inherit;cursor:pointer;border:1px solid ' +
            (primary
                ? '#4f46e5;background:#4f46e5;color:#fff'
                : '#d4d4d8;background:#fff;color:#18181b')
        );
    }

    if (script.getAttribute('data-button') !== 'off') {
        var button = element(
            'button',
            {
                type: 'button',
                style:
                    'position:fixed;right:1rem;bottom:1rem;z-index:2147483000;' + buttonStyle(true),
            },
            texts.button
        );

        button.addEventListener('click', function () {
            open(null);
        });

        // Nicht blind auf `DOMContentLoaded` warten: wird das Skript mit `async`
        // eingebunden oder erst später nachgeladen, ist das Ereignis längst
        // vorbei — und die Schaltfläche käme nie.
        if (document.body) {
            document.body.appendChild(button);
        } else {
            document.addEventListener('DOMContentLoaded', function () {
                document.body.appendChild(button);
            });
        }
    }

    // Der Weg für den Absturzbericht: das SDK kennt die Nummer der Meldung, die
    // es gerade abgeschickt hat, und reicht sie hier herein.
    window.errstackFeedback = { open: open, send: send };
})();
