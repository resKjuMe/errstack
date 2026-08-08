<?php

namespace App\Http\Requests;

use App\Models\Repository;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ein Repository verbinden.
 *
 * Der Name ist die einzige Pflichtangabe, denn er ist der Schlüssel: unter ihm
 * übergibt eine Bauumgebung ihre Commits („acme/webshop"). Die Adresse ist
 * freiwillig und trotzdem der Grund, sie hier einzutragen — ohne sie bleibt
 * jeder Commit-Hash eine Zeichenkette ohne Ziel.
 */
class RepositoryRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.Repository::NAME_LIMIT],

            // `url` und nicht bloß `string`: eine SSH-Angabe
            // (`git@github.com:acme/webshop.git`) sieht wie eine Adresse aus,
            // führt im Browser aber nirgendwohin — und genau dafür steht das
            // Feld hier.
            'url' => ['nullable', 'string', 'url', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('repositories.fields.name'),
            'url' => __('repositories.fields.url'),
        ];
    }
}
