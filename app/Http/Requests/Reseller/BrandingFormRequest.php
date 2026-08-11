<?php

namespace Pterodactyl\Http\Requests\Reseller;

class BrandingFormRequest extends ResellerFormRequest
{
    public function rules(): array
    {
        return [
            'app_name' => 'nullable|string|max:60',
            'brand_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            // Kept small on purpose: these are inlined into every page load's
            // chrome, and a huge upload would be a self-inflicted slowdown.
            'logo' => 'nullable|image|mimes:png,jpg,jpeg,svg,webp|max:512',
            'favicon' => 'nullable|image|mimes:png,ico,svg|max:128',
            'remove_logo' => 'sometimes|boolean',
            'remove_favicon' => 'sometimes|boolean',
        ];
    }

    public function attributes(): array
    {
        return [
            'app_name' => 'panel name',
            'brand_color' => 'brand color',
            'accent_color' => 'accent color',
        ];
    }
}
