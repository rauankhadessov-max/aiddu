<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreWorkspaceSourceRequest extends StoreSourceRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'visibility' => $this->user()?->is_admin
                ? ['required', Rule::in(['global', 'personal'])]
                : ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'visibility.required' => 'Выберите доступность НПА.',
            'visibility.in' => 'Выбрана недопустимая доступность НПА.',
            'visibility.prohibited' => 'Только администратор может создавать глобальные НПА.',
        ];
    }
}
