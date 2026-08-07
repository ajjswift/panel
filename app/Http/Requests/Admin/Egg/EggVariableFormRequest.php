<?php

namespace Pterodactyl\Http\Requests\Admin\Egg;

use Illuminate\Validation\Rule;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Http\Requests\Admin\AdminFormRequest;

class EggVariableFormRequest extends AdminFormRequest
{
    /**
     * Define rules for validation of this request.
     */
    public function rules(): array
    {
        $egg = $this->route('egg');

        return [
            'name' => 'required|string|min:1|max:191',
            'description' => 'sometimes|nullable|string',
            'env_variable' => 'required|regex:/^[\w]{1,191}$/|notIn:' . EggVariable::RESERVED_ENV_NAMES,
            'options' => 'sometimes|required|array',
            'rules' => 'bail|required|string',
            'default_value' => 'present',
            'allocation_index' => [
                'nullable',
                'integer',
                'min:1',
                Rule::when($egg, 'max:' . $egg->initial_allocation_count),
            ],
        ];
    }
}
