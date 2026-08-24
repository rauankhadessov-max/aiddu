<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['law', 'code', 'government_resolution', 'order', 'rules', 'methodology', 'other'])],
            'input_method' => ['required', Rule::in(['docx', 'url'])],
            'docx_file' => ['nullable', 'required_if:input_method,docx', 'file', 'mimes:docx', 'max:2048'],
            'official_url' => ['nullable', 'required_if:input_method,url', 'url', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Укажите название НПА.',
            'type.required' => 'Выберите вид НПА.',
            'type.in' => 'Выбран недопустимый вид НПА.',
            'input_method.required' => 'Выберите источник нормативного текста.',
            'docx_file.required_if' => 'Выберите DOCX-файл нормативного акта.',
            'docx_file.mimes' => 'Можно загрузить только файл в формате DOCX.',
            'docx_file.max' => 'Размер DOCX-файла не должен превышать 2 МБ.',
            'official_url.required_if' => 'Укажите официальную ссылку на НПА.',
            'official_url.url' => 'Укажите корректную официальную ссылку.',
        ];
    }
}
