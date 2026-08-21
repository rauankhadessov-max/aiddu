<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIService
{
    public function respond(string $input): array
    {
        $response = Http::withToken(config('services.openai.key'))
            ->acceptJson()
            ->timeout(120)
            ->post('https://api.openai.com/v1/responses', [
                'model' => config('services.openai.model'),
                'input' => $input,
            ]);

        if (!$response->successful()) {
            $message = $response->json('error.message')
                ?? 'Неизвестная ошибка OpenAI API.';

            throw new RuntimeException(
                'OpenAI API error (' . $response->status() . '): ' . $message
            );
        }

        $data = $response->json();

        $message = collect($data['output'] ?? [])
            ->firstWhere('type', 'message');

        $text = $message['content'][0]['text'] ?? null;

        if (!$text) {
            throw new RuntimeException(
                'OpenAI API вернул успешный ответ, но текст результата не найден.'
            );
        }

        return [
            'text' => $text,
            'model' => $data['model'] ?? config('services.openai.model'),
            'response_id' => $data['id'] ?? null,
            'usage' => $data['usage'] ?? [],
        ];
    }
}
