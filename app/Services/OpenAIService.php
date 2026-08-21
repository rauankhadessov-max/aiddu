<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIService
{
    public function respond(string $input): array
    {
        $payload = [
            'model' => config('services.openai.model'),
            'input' => $input,
        ];

        $response = $this->send($payload, 120);
        $data = $response->json();

        return [
            'text' => $this->extractOutputText($data),
            'model' => $data['model'] ?? config('services.openai.model'),
            'response_id' => $data['id'] ?? null,
            'usage' => $data['usage'] ?? [],
            'request_payload_hash' => $this->payloadHash($payload),
        ];
    }

    public function respondStructured(
        string $input,
        array $schema,
        array $options = [],
    ): array {
        $payload = [
            'model' => $options['model'] ?? config('services.openai.model'),
            'input' => $input,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $options['schema_name'] ?? 'structured_response',
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ];

        if (isset($options['max_output_tokens'])) {
            $payload['max_output_tokens'] = (int) $options['max_output_tokens'];
        }

        $response = $this->send(
            $payload,
            (int) ($options['timeout'] ?? config('legal_analysis.timeout_seconds', 180)),
        );
        $data = $response->json();
        $text = $this->extractOutputText($data);

        try {
            $result = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException(
                'Не удалось преобразовать структурированный результат OpenAI в JSON.',
                previous: $exception,
            );
        }

        if (!is_array($result)) {
            throw new RuntimeException('Структурированный результат OpenAI имеет неверный формат.');
        }

        return [
            'result' => $result,
            'model' => $data['model'] ?? $payload['model'],
            'response_id' => $data['id'] ?? null,
            'usage' => $data['usage'] ?? [],
            'request_payload_hash' => $this->payloadHash($payload),
        ];
    }

    private function send(array $payload, int $timeout): Response
    {
        $response = Http::withToken(config('services.openai.key'))
            ->acceptJson()
            ->timeout($timeout)
            ->post('https://api.openai.com/v1/responses', $payload);

        if (!$response->successful()) {
            $message = $response->json('error.message')
                ?? 'Неизвестная ошибка OpenAI API.';

            throw new RuntimeException(
                'OpenAI API error ('.$response->status().'): '.$message,
            );
        }

        return $response;
    }

    private function extractOutputText(array $data): string
    {
        $message = collect($data['output'] ?? [])->firstWhere('type', 'message');

        if (!is_array($message)) {
            throw new RuntimeException('OpenAI API вернул ответ без сообщения.');
        }

        $content = collect($message['content'] ?? []);
        $refusal = $content->firstWhere('type', 'refusal');

        if (is_array($refusal)) {
            throw new RuntimeException('OpenAI отказался формировать структурированный ответ.');
        }

        $textContent = $content->first(
            fn ($item) => is_array($item)
                && in_array($item['type'] ?? null, ['output_text', 'text'], true)
                && is_string($item['text'] ?? null),
        );
        $text = $textContent['text'] ?? null;

        if (!is_string($text) || trim($text) === '') {
            throw new RuntimeException('OpenAI API вернул успешный ответ без текста результата.');
        }

        return $text;
    }

    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }
}
