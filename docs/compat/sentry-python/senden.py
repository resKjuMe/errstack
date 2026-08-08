"""Beispiel: sentry-python

Schickt Nachricht, Fehler, Transaktion und Sitzung mit dem offiziellen
Python-SDK. Getauscht wird nur die DSN:

    python -m venv .venv
    .venv/Scripts/pip install -r requirements.txt     # Linux/macOS: .venv/bin/pip

    # gegen den laufenden Klon
    SENTRY_DSN="http://<public_key>@localhost:8000/1" .venv/Scripts/python senden.py

    # gegen den Mitschnitt-Server (Aufnahme neu erstellen)
    .venv/Scripts/python senden.py
"""

import os
import time

import sentry_sdk

dsn = os.environ.get(
    "SENTRY_DSN", "http://aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa@127.0.0.1:9911/1"
)

sentry_sdk.init(
    dsn=dsn,
    release="compat@1.0.0",
    environment="compat",
    # Ohne das schickt das SDK die Transaktion nicht ab, sondern verwirft sie
    # als nicht gezogene Stichprobe.
    traces_sample_rate=1.0,
    attach_stacktrace=True,
    server_name="compat-beispiel",
)

sentry_sdk.set_user({"id": "4711", "username": "kompatibilitaet"})
sentry_sdk.set_tag("beispiel", "sentry-python")
sentry_sdk.add_breadcrumb(
    category="beispiel", message="Rechnung geladen", level="info"
)

# 0. Eine Sitzung von Hand — sie umschließt alles Weitere. Nicht über
#    `auto_session_tracking`: dessen Sitzung schickt das SDK zusammengefasst und
#    zeitgesteuert ab, ein Beispielskript ist vorher fertig.
sentry_sdk.start_session(session_mode="application")

# 1. Eine Nachricht ohne Fehler.
sentry_sdk.capture_message(
    "Kompatibilitätsprobe: Nachricht aus sentry-python", level="info"
)
sentry_sdk.flush()

# 2. Ein echter Fehler mit Stacktrace und Ursache.
try:
    try:
        raise ValueError("Rechnungsnummer 4711 ist unbekannt")
    except ValueError as ursache:
        raise RuntimeError("Rechnung konnte nicht erstellt werden") from ursache
except RuntimeError as fehler:
    sentry_sdk.capture_exception(fehler)

sentry_sdk.flush()

# 3. Eine Transaktion mit zwei Einzelschritten.
with sentry_sdk.start_transaction(op="http.server", name="GET /rechnungen"):
    for op, beschreibung in (
        ("db.sql.query", "select * from invoices"),
        ("http.client", "GET https://zahlungen.example/status"),
    ):
        with sentry_sdk.start_span(op=op, description=beschreibung):
            time.sleep(0.015)

sentry_sdk.flush()

# 4. Die Sitzung beenden. Abgeschickt wird sie erst beim Schließen des Clients:
#    Sitzungen sammelt das SDK und gibt sie gebündelt ab.
sentry_sdk.end_session()
sentry_sdk.get_client().close()

print(
    f"sentry-python: Nachricht, Fehler, Transaktion und Sitzung an {dsn} geschickt"
)
