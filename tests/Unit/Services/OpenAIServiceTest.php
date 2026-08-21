<?php

namespace Tests\Unit\Services;

use App\Services\OpenAIService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIServiceTest extends TestCase
{
    public function test_structured_response_sends_expected_payload_and_returns_safe_metadata(): void
    {
        config()->set('services.openai.key', 'fake-test-key-never-persisted');
        config()->set('services.openai.model', 'test-model');
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ['summary' => ['type' => 'string']],
            'required' => ['summary'],
        ];

        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_test_123',
                'model' => 'test-model-resolved',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode(['summary' => 'Готово'], JSON_UNESCAPED_UNICODE),
                    ]],
                ]],
            ], 200),
        ]);

        $result = app(OpenAIService::class)->respondStructured(
            'Проверить документ',
            $schema,
            ['schema_name' => 'legal_test', 'timeout' => 33],
        );

        $this->assertSame(['summary' => 'Готово'], $result['result']);
        $this->assertSame('test-model-resolved', $result['model']);
        $this->assertSame('resp_test_123', $result['response_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['request_payload_hash']);
        $this->assertArrayNotHasKey('api_key', $result);
        $this->assertArrayNotHasKey('authorization', $result);

        Http::assertSent(function (Request $request) use ($schema) {
            $payload = $request->data();

            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer fake-test-key-never-persisted')
                && $payload['model'] === 'test-model'
                && $payload['input'] === 'Проверить документ'
                && $payload['text']['format']['type'] === 'json_schema'
                && $payload['text']['format']['name'] === 'legal_test'
                && $payload['text']['format']['strict'] === true
                && $payload['text']['format']['schema'] === $schema
                && !str_contains(json_encode($payload), 'fake-test-key-never-persisted');
        });
    }
}
