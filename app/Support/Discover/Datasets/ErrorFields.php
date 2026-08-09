<?php

namespace App\Support\Discover\Datasets;

use App\Models\Event;
use App\Support\Discover\Dataset;
use App\Support\Discover\FieldDefinition;
use App\Support\Discover\FieldType;
use App\Support\Discover\Sql;
use App\Support\Tags\EventTags;
use Illuminate\Database\Eloquent\Builder;

/**
 * Die Felder der Fehlermeldungen.
 *
 * **Dieselben Merkmale wie überall sonst.** Was in der Fehlerliste `browser` heißt,
 * heißt hier `browser` und bedeutet dasselbe: Name **und** Fassung („Chrome 124.0"),
 * daneben `browser.name` für die Frage nach allen Fassungen. Die Aufteilung stammt
 * aus {@see EventTags} und ist der Grund, warum ein Filterausdruck aus der
 * Fehlerliste hier dieselbe Menge meint. Der Unterschied ist nur, **wo** die Werte
 * herkommen: dort aus den vorberechneten Zähltabellen (S3), hier aus der Meldung
 * selbst — eine freie Auswertung fragt nach Zeiträumen, und die Zähltabellen haben
 * keine Zeitachse.
 *
 * **Die Adresse ohne Abfrageteil**, aus demselben Grund wie bei der Aufnahme:
 * `?id=4711` macht jede Adresse einzigartig, und „nach Seite gruppiert" mit einer
 * Zeile je Aufruf beantwortet keine Frage.
 *
 * **Nutzer-Angaben sind Felder und keine Merkmale.** Sie stehen in der Meldung und
 * nirgends sonst, damit das Scrubbing (I7) und die Aufbewahrung (O2) eine Stelle
 * haben, an der sie wirken. Abfragbar sind sie trotzdem — „welche Fehler hatte
 * dieser Kunde?" ist die Frage, um derer willen es die freie Auswertung gibt —, nur
 * ohne Index und damit erkennbar langsamer als ein Merkmal.
 */
final class ErrorFields extends AbstractDatasetFields
{
    public function dataset(): Dataset
    {
        return Dataset::Errors;
    }

    public function query(): Builder
    {
        return Event::query();
    }

    public function timeColumn(): string
    {
        return 'events.occurred_at';
    }

    protected function tagColumn(): string
    {
        return 'events.tags';
    }

    protected function freeTextColumns(): array
    {
        return ['events.title', 'events.culprit'];
    }

    /**
     * @return array<string, FieldDefinition>
     */
    protected function definitions(): array
    {
        $connection = $this->connection();

        $fields = [];

        foreach (['level', 'platform', 'environment', 'release', 'dist', 'server_name', 'transaction', 'logger', 'title', 'culprit', 'trace_id'] as $column) {
            $fields[] = $this->text($column, 'events.'.$column);
        }

        // Die Nummer der Meldung ist der Weg zu **einer** Meldung und keine
        // Gruppe: nach ihr zu gruppieren ergäbe eine Zeile je Zeile.
        $fields[] = $this->text('event_id', 'events.event_id', groupable: false);

        // Die zusammengesetzten Merkmale: Name und Fassung als ein Wert, und der
        // Name allein daneben.
        foreach (['browser', 'os', 'runtime'] as $context) {
            $name = $this->wrap('events.contexts->'.$context.'->name');
            $version = $this->wrap('events.contexts->'.$context.'->version');

            $fields[] = new FieldDefinition($context, FieldType::Text, null, Sql::join($connection, $name, $version), aggregatable: true);
            $fields[] = $this->text($context.'.name', 'events.contexts->'.$context.'->name');
        }

        // Das Gerät nennt seine Bauart in `family` („iPhone", „Pixel 7") und nicht
        // in `version` — deshalb neben der Schleife.
        $fields[] = $this->text('device', 'events.contexts->device->model');
        $fields[] = $this->text('device.family', 'events.contexts->device->family');
        $fields[] = $this->text('sdk', 'events.sdk->name');

        $fields[] = new FieldDefinition(
            'url',
            FieldType::Text,
            null,
            Sql::before($connection, $this->wrap('events.request->url'), '?'),
            aggregatable: true,
        );

        foreach (['user.id' => 'id', 'user.email' => 'email', 'user.username' => 'username', 'user.ip' => 'ip_address'] as $name => $key) {
            $fields[] = $this->text($name, 'events.user->'.$key);
        }

        // Woher der Betroffene kam. Die Aufnahme legt es unter `user.geo` ab
        // (I2); hier steht es als eigenes Feld, weil danach gefragt wird, ohne
        // dass jemand an den Nutzer denkt — „welches Land trifft es".
        //
        // `geo.country` ist das **Länderkürzel** und nicht der ausgeschriebene
        // Name: es ist das, was die SDKs mitschicken, und es ist der Schlüssel,
        // über den die Weltkarte einfärbt. Ein ausgeschriebener Name wäre in
        // jeder Sprache ein anderer Gruppenwert.
        foreach (['geo.country' => 'country_code', 'geo.region' => 'region', 'geo.city' => 'city'] as $name => $key) {
            $fields[] = $this->text($name, 'events.user->geo->'.$key);
        }

        $fields[] = $this->timestamp('occurred_at', 'events.occurred_at');
        $fields[] = $this->timestamp('received_at', 'events.received_at');

        return $this->keyed($fields);
    }
}
