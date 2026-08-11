<?php

namespace Pterodactyl\Http\Requests\Reseller;

class DomainFormRequest extends ResellerFormRequest
{
    public function rules(): array
    {
        return [
            'hostname' => [
                'required',
                'string',
                'max:191',
                // A plain hostname — no scheme, port or path. Unique across the
                // whole table: two resellers cannot both claim one host.
                'regex:/^(?!-)[a-z0-9-]{1,63}(\.[a-z0-9-]{1,63})+$/i',
                'unique:reseller_domains,hostname',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if ($this->has('hostname')) {
            $this->merge(['hostname' => mb_strtolower(trim((string) $this->input('hostname')))]);
        }
    }

    public function messages(): array
    {
        return [
            'hostname.regex' => 'Enter a hostname on its own, such as panel.example.com — no https:// and no trailing path.',
            'hostname.unique' => 'That hostname is already registered on this panel.',
        ];
    }
}
