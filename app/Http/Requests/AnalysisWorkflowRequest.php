<?php

namespace App\Http\Requests;

use App\Models\Analysis;
use App\Services\DefaultWorkspaceResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnalysisWorkflowRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('workspace_id') || !$this->user()) {
            return;
        }

        $analysis = $this->route('analysis');
        $workspace = $analysis instanceof Analysis
            ? $this->user()->workspaces()->find($analysis->workspace_id)
            : app(DefaultWorkspaceResolver::class)->resolve($this->user());

        if ($workspace) {
            $this->merge(['workspace_id' => $workspace->id]);
        }
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $isRun = $this->input('action') === 'run';

        return [
            'action' => ['required', Rule::in(['save_draft', 'run'])],
            'workspace_id' => ['required', 'integer', 'exists:workspaces,id'],
            'title' => ['required', 'string', 'max:255'],
            'current_text' => ['nullable', 'string'],
            'proposed_text' => ['nullable', 'string'],
            'analysis_instruction' => [$isRun ? 'required' : 'nullable', 'string'],
            'source_versions' => [$isRun ? 'required' : 'nullable', 'array', $isRun ? 'min:1' : 'max:100'],
            'source_versions.*' => ['required', 'integer', 'distinct', 'exists:source_versions,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'workspace_id.required' => 'Выберите рабочее дело.',
            'title.required' => 'Укажите название анализа.',
            'analysis_instruction.required' => 'Укажите поручение ИИ перед запуском анализа.',
            'source_versions.required' => 'Выберите хотя бы одну редакцию нормативного источника.',
            'source_versions.min' => 'Выберите хотя бы одну редакцию нормативного источника.',
            'source_versions.*.exists' => 'Выбрана недоступная редакция нормативного источника.',
        ];
    }
}
