<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Anbindung an einen Anbieter (X1): der Zugang selbst, die Verknüpfung
 * eines Fehlers mit einem Ticket dort — und das Protokoll der eingehenden
 * Ereignisse.
 *
 * R2 hat den Ort geschaffen, an dem Commits liegen; wer sie dorthin bringt,
 * blieb offen: eine Bauumgebung übergibt sie über die Schnittstelle. Das ist
 * der Weg, der ohne Anbindung funktioniert, und er bleibt. Was fehlt, ist die
 * Gegenrichtung — **selbst holen**, und aus einem Fehler heraus **etwas
 * schreiben**. Beides braucht einen Zugang, der einer Organisation gehört und
 * länger lebt als eine Anfrage.
 *
 * Drei Tabellen, entlang dreier verschiedener Lebensdauern:
 *
 *   integrations               der Zugang — einer je Organisation und Anbieter,
 *                              bleibt bis jemand ihn löst
 *   issue_links                die Verknüpfung Fehler ↔ Ticket — eine Aussage
 *                              über zwei Dinge, die beide für sich bestehen
 *   integration_webhook_events das Eingangsbuch — jede Meldung von außen einmal,
 *                              und genau deshalb wiederholbar
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();

            // An der Organisation, wie das Repository (R2) und aus demselben
            // Grund: derselbe Zugang versorgt alle Projekte, und je Projekt neu
            // zu verbinden hieße, dieselben Repositories mehrfach zu führen.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Der Anbieter (siehe App\Enums\IntegrationProvider). Freitext und
            // keine Aufzählung in der Datenbank — mit X2 kommen Werte dazu.
            $table->string('provider', 50);

            // Wessen Zugang das ist, so wie der Anbieter ihn nennt: das Konto
            // oder die Organisation bei GitHub. Sie steht in der Oberfläche
            // („verbunden mit acme"), damit sichtbar ist, wessen Rechte hier
            // gelten — ein Zugang über ein persönliches Konto sieht sonst
            // genauso aus wie einer über die Firmen-Organisation.
            $table->string('account', 200)->nullable();

            // Die Kennung des Kontos beim Anbieter. Wie am Repository: ein
            // Konto kann sich umbenennen, ohne ein anderes zu werden.
            $table->string('external_id', 200)->nullable();

            // Das Zugriffstoken und was sonst zum Zugang gehört —
            // verschlüsselt, wie beim Benachrichtigungskanal (`config`) und aus
            // demselben Grund: wer die Datenbank liest, soll damit nicht bei
            // GitHub schreiben können. Was darin steht, weiß nur der Anbieter;
            // für die Datenbank ist es eine undurchsichtige Ablage.
            $table->text('credentials')->nullable();

            // Ob die Anbindung noch trägt (siehe App\Enums\IntegrationStatus).
            // Die Spalte ist der Unterschied zwischen „diese Version hatte
            // keine Commits" und „wir kommen seit Dienstag nicht mehr an das
            // Repository".
            $table->string('status', 20)->default('connected');

            // Woran das zuletzt gescheitert ist — im Klartext und für die
            // Anzeige. Bewusst kurz gehalten: hier steht die Meldung des
            // Anbieters, nie eine Antwort mit Zugangsdaten darin.
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_error_at')->nullable();

            // Wann zuletzt etwas geholt wurde. Nicht die Uhr des Betriebs,
            // sondern die Auskunft „die Anbindung hat heute etwas getan".
            $table->timestamp('last_synced_at')->nullable();

            // Wer verbunden hat. `nullOnDelete`: wer sein Konto löscht, löst
            // damit keine Anbindung — das Token gehört der Organisation.
            $table->foreignId('connected_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Ein Zugang je Organisation und Anbieter. Zwei GitHub-Zugänge
            // nebeneinander wären nicht doppelt gemoppelt, sondern
            // uneindeutig: bei welchem von beiden fragt man nach den Commits
            // eines Repositories, das in beiden vorkommt?
            $table->unique(['organization_id', 'provider']);
        });

        Schema::table('repositories', function (Blueprint $table) {
            // Über welche Anbindung dieses Repository hereinkam — und `null`
            // für die von Hand eingetragenen, die es weiterhin gibt.
            //
            // `nullOnDelete` und nicht `cascadeOnDelete`: wer die Anbindung
            // löst, wirft keine Commits weg. Das Repository fällt auf denselben
            // Stand zurück, den es ohne Anbindung hätte — eingetragen, mit
            // seiner Geschichte, nur ohne jemanden, der Neues holt.
            $table->foreignId('integration_id')->nullable()->after('organization_id')
                ->constrained()->nullOnDelete();
        });

        Schema::create('issue_links', function (Blueprint $table) {
            $table->id();

            // Der Fehler hier. Verschwindet er, verschwindet die Aussage über
            // ihn — das Ticket beim Anbieter bleibt selbstverständlich stehen.
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();

            // Die Anbindung, über die verknüpft wurde. Sie sagt, wen man fragen
            // muss, wenn sich der Zustand des Tickets ändert. `nullOnDelete`
            // aus demselben Grund wie oben: die Verknüpfung bleibt lesbar (sie
            // trägt Adresse und Nummer bei sich), sie wird nur nicht mehr
            // abgeglichen.
            $table->foreignId('integration_id')->nullable()->constrained()->nullOnDelete();

            $table->string('provider', 50);

            // Die Nummer beim Anbieter (`#42`) und das Repository, in dem sie
            // gilt — beides zusammen ist die Kennung, denn `#42` gibt es in
            // jedem Repository.
            $table->string('repository', 200);
            $table->unsignedInteger('number');

            // Titel und Adresse stehen als Text da und werden nicht bei jedem
            // Anzeigen nachgeschlagen: die Seite eines Fehlers soll sich nicht
            // deshalb um eine Netzwerkrunde verzögern, weil oben rechts ein
            // Ticket verlinkt ist. Aktuell hält sie der Webhook.
            $table->string('title', 500)->nullable();
            $table->string('url', 500);

            // Offen oder geschlossen (siehe App\Enums\ExternalIssueState). Die
            // Spalte, an der der Abgleich hängt.
            $table->string('state', 20)->default('open');

            // Ob die Verknüpfung ein neues Ticket angelegt hat oder ein
            // vorhandenes aufgegriffen. Für die Anzeige belanglos, für die
            // Frage „woher kommt das hier?" nicht.
            $table->boolean('created_remotely')->default(false);

            $table->foreignId('linked_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Dasselbe Ticket hängt nur einmal an demselben Fehler. Der Index
            // ist zugleich das Verfahren: zwei gleichzeitige Klicks auf
            // „verknüpfen" ergeben eine Zeile, nicht zwei.
            $table->unique(['issue_id', 'provider', 'repository', 'number']);

            // Die Gegenrichtung, und der eigentliche Grund für den Index: ein
            // eingehendes Ereignis nennt Repository und Nummer und sucht die
            // Fehler dazu.
            $table->index(['provider', 'repository', 'number']);
        });

        Schema::create('integration_webhook_events', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 50);

            // Aufgelöst über das Repository in der Nutzlast — best effort. Ein
            // Ereignis aus einem Repository, das hier niemand verbunden hat,
            // wird trotzdem festgehalten: sonst wäre die erste Frage bei einer
            // falsch eingerichteten Anbindung („kommt überhaupt etwas an?")
            // nicht zu beantworten.
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();

            // Die Kennung der Zustellung beim Anbieter (`X-GitHub-Delivery`).
            // Sie ist der Grund, dass dieses Protokoll kein Beiwerk ist: GitHub
            // stellt dieselbe Meldung erneut zu, wenn die Antwort ausbleibt,
            // und wiederholt sie auf Knopfdruck. Der eindeutige Index unten
            // macht daraus „einmal verarbeitet", ohne dass die Verarbeitung
            // selbst wiederholbar sein müsste.
            $table->string('delivery_id', 100);

            // Die Art (`push`, `issues`) und, wo es eine gibt, die Unterart
            // (`opened`, `closed`).
            $table->string('event', 50);
            $table->string('action', 50)->nullable();

            $table->string('repository', 200)->nullable();

            // Die rohe Nutzlast. Sie bleibt stehen, weil die Verarbeitung ein
            // Ereignis heute anders auswertet als morgen — und weil sich ohne
            // sie nicht klären lässt, warum ein Ereignis nichts bewirkt hat.
            $table->json('payload');

            // Wann verarbeitet, und was dabei herauskam. `null` heißt „liegt
            // noch in der Warteschlange"; eine Meldung hier heißt „angekommen,
            // aber nicht zugeordnet" — der häufigste Fall im Betrieb, und der,
            // den man beim Einrichten sehen will.
            $table->timestamp('processed_at')->nullable();
            $table->string('result', 200)->nullable();

            // Nur `created_at`: eine eingegangene Meldung ändert sich nicht.
            // Was sich ändert, ist der Vermerk über ihre Verarbeitung, und der
            // hat seine eigenen zwei Spalten darüber.
            $table->timestamp('created_at')->nullable();

            // Eine Zustellung einmal. Zusammen mit dem Anbieter, weil die
            // Kennungen zweier Anbieter nichts miteinander zu tun haben.
            $table->unique(['provider', 'delivery_id']);

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_webhook_events');
        Schema::dropIfExists('issue_links');

        Schema::table('repositories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('integration_id');
        });

        Schema::dropIfExists('integrations');
    }
};
