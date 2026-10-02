<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\AIProviderInterface;
use App\AI\Dto\AIRequest;
use App\AI\Dto\AIResponse;
use App\AI\Exception\AIException;
use App\AI\Exception\InvalidAIOutputException;
use App\AI\Schema\JsonSchemaValidator;
use App\Provider\CredentialVault;
use App\Provider\Entity\Provider;
use App\Provider\Exception\ProviderNotConfiguredException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenAI Chat Completions driver with Structured Outputs (response_format json_schema).
 */
final readonly class OpenAIProvider implements AIProviderInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private CredentialVault $vault,
    ) {
    }

    public static function getDriver(): string
    {
        return 'openai';
    }

    public function complete(AIRequest $request, Provider $provider): AIResponse
    {
        $apiKey = $this->vault->get($provider, 'api_key') ?? throw new ProviderNotConfiguredException('OpenAI API key is missing (Admin > Providers or OPENAI_API_KEY).', $provider->getCode());
        $model = ModelResolver::resolve($provider);
        $baseUrl = rtrim((string) ($provider->getSettings()['base_url'] ?? 'https://api.openai.com/v1'), '/');

        $messages = [['role' => 'system', 'content' => $request->systemPrompt]];
        foreach ($request->messages as $message) {
            $messages[] = ['role' => $message->role, 'content' => $message->content];
        }
        $body = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $request->temperature,
            'max_completion_tokens' => $request->maxTokens,
        ];
        if (null !== $request->jsonSchema) {
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => $request->schemaName, 'strict' => true, 'schema' => self::strictSchema($request->jsonSchema)],
            ];
        }

        try {
            $response = $this->httpClient->request('POST', $baseUrl.'/chat/completions', [
                'auth_bearer' => $apiKey,
                'json' => $body,
                'timeout' => 60,
            ]);
            $status = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            throw AIException::transient('OpenAI is unreachable: '.$e->getMessage(), $provider->getCode(), $e);
        }

        if ($status >= 400) {
            $error = (string) ($payload['error']['message'] ?? 'HTTP '.$status);
            throw new AIException('OpenAI error: '.$error, 429 === $status || $status >= 500, $provider->getCode());
        }

        $content = (string) ($payload['choices'][0]['message']['content'] ?? '');
        $data = null;
        if (null !== $request->jsonSchema) {
            $data = json_decode($content, true);
            if (!\is_array($data)) {
                throw new InvalidAIOutputException('OpenAI returned invalid JSON', [], $provider->getCode());
            }
        }

        return new AIResponse(
            $content,
            $data,
            $provider->getCode(),
            (string) ($payload['model'] ?? $model),
            (int) ($payload['usage']['prompt_tokens'] ?? 0),
            (int) ($payload['usage']['completion_tokens'] ?? 0),
        );
    }

    /**
     * Structured Outputs "strict" mode: every object closes its properties and
     * value-constraint keywords are removed (still enforced locally afterwards).
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public static function strictSchema(array $schema): array
    {
        $schema = JsonSchemaValidator::relaxed($schema);
        unset($schema['$comment']);

        return $schema;
    }
}
