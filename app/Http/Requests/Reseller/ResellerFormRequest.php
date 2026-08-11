<?php

namespace Pterodactyl\Http\Requests\Reseller;

use Illuminate\Foundation\Http\FormRequest;

abstract class ResellerFormRequest extends FormRequest
{
    /**
     * Keys that must never be honoured from a reseller-submitted payload, no
     * matter what a subclass's rules say. Stripped in prepareForValidation()
     * so they cannot reach a model even through a `->all()` call — privilege
     * escalation should not be one forgotten validation rule away.
     */
    protected const FORBIDDEN_KEYS = ['root_admin', 'reseller_id', 'owner_id', 'external_id'];

    /**
     * The rules to apply to the incoming form request.
     */
    abstract public function rules(): array;

    /**
     * Mirrors AdminFormRequest, but gated on reseller ownership.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->isReseller();
    }

    protected function prepareForValidation(): void
    {
        $this->replace($this->except($this->forbiddenKeys()));
    }

    /**
     * Overridable so a request that legitimately owns one of these keys (the
     * server form picks an owner_id) can narrow the list — it is then that
     * request's job to validate the value against ResellerContext.
     */
    protected function forbiddenKeys(): array
    {
        return static::FORBIDDEN_KEYS;
    }

    /**
     * Return only the fields that we are interested in from the request.
     * This will include empty fields as a null value.
     */
    public function normalize(?array $only = null): array
    {
        return $this->only($only ?? array_keys($this->rules()));
    }
}
