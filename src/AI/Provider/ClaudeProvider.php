<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\AIProviderInterface;
use App\AI\Dto\AIMessage;
use App\AI\Dto\AIRequest;
use App\AI\Dto\AIResponse;
use App\AI\Exception\AIException;
use App\AI\Exception\InvalidAIOutputException;
use App\Provider\CredentialVault;
use App\Provider\Entity\Provider;
use App\Provider\Exception\ProviderNotConfiguredException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Anthropic Messages API driver. Structured output is obtained by forcing a single
 * tool whose input_schema is the expected JSON schema (tool_choice = that tool).
 */
final readonly class ClaudeProvider implements AIProviderInterface
{
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private HttpClientInterface $httpClient,
        private CredentialVault $vault,
    ) {
    }

    public static function getDriver(): string
    {
        return 'claude';
    }

    public function complete(AIRequest $request, Provider $provider): AIResponse
    {
        $apiKey = $this->vault->get($provider, 'api_key') ?? throw new ProviderNotConfiguredException('Anthropic API key is missing (Admin > Providers or ANTHROPIC_API_KEY).', $provider->getCode());
        $model = ModelResolver::resolve($provider);
        $baseUrl = rtrim((string) ($provider->getSettings()['base_url'] ?? 'https://api.anthropic.com/v1'), '/');

        $body = [
            'model' => $model,
            'max_tokens' => $request->maxTokens,
            'system' => $request->systemPrompt,
            'messages' => self::alternatingMessages($request->messages),
            'temperature' => $request->temperature,
        ];
        if (null !== $request->jsonSchema) {
            $schema = $request->jsonSchema;
            unset($schema['$comment']);
            $body['tools'] = [[
                'name' => $request->schemaName,
                'description' => 'Return the result of the task as structured data.',
                'input_schema' => $schema,
            ]];
            $body['tool_choice'] = ['type' => 'tool', 'name' => $request->schemaName];
        }

        try {
            $response = $this->httpClient->request('POST', $baseUrl.'/messages', [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => self::API_VERSION,
                ],
                'json' => $body,
                'timeout' => 60,
            ]);
            $status = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            throw AIException::transient('Anthropic API is unreachable: '.$e->getMessage(), $provider->getCode(), $e);
        }

        if ($status >= 400) {
            $error = (string) ($payload['error']['message'] ?? 'HTTP '.$status);
            throw new AIException('Anthropic error: '.$error, \in_array($status, [429, 529], true) || $status >= 500, $provider->getCode());
        }

        $text = '';
        $data = null;
        foreach ($payload['content'] ?? [] as $block) {
            if ('text' === ($block['type'] ?? null)) {
                $text .= (string) $block['text'];
            }
            if ('tool_use' === ($block['type'] ?? null) && ($block['name'] ?? null) === $request->schemaName && \is_array($block['input'] ?? null)) {
                $data = $block['input'];
            }
        }
        if (null !== $request->jsonSchema && null === $data) {
            throw new InvalidAIOutputException('Claude did not return the structured result', [], $provider->getCode());
        }

        return new AIResponse(
            null !== $data ? (string) json_encode($data, \JSON_UNESCAPED_UNICODE) : $text,
            $data,
            $provider->getCode(),
            (string) ($payload['model'] ?? $model),
            (int) ($payload['usage']['input_tokens'] ?? 0),
            (int) ($payload['usage']['output_tokens'] ?? 0),
        );
    }

    /**
     * The Messages API expects user/assistant turns starting with "user":
     * consecutive messages of the same role are merged.
     *
     * @param list<AIMessage> $messages
     *
     * @return list<array{role: string, content: string}>
     */
    public static function alternatingMessages(array $messages): array
    {
        $result = [];
        foreach ($messages as $message) {
            $role = AIMessage::ASSISTANT === $message->role ? 'assistant' : 'user';
            if ([] === $result && 'assistant' === $role) {
                continue;
            }
            $last = array_key_last($result);
            if (null !== $last && $result[$last]['role'] === $role) {
                $result[$last]['content'] .= "\n\n".$message->content;
                continue;
            }
            $result[] = ['role' => $role, 'content' => $message->content];
        }

        return [] === $result ? [['role' => 'user', 'content' => '(empty)']] : $result;
    }
}
