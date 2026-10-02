<?php

declare(strict_types=1);

namespace App\Tests\Integration\AI;

use App\AI\Dto\AIMessage;
use App\AI\Dto\AIRequest;
use App\AI\Exception\AIException;
use App\AI\Exception\InvalidAIOutputException;
use App\AI\Provider\ClaudeProvider;
use App\AI\Provider\OpenAIProvider;
use App\Provider\CredentialVault;
use App\Provider\Entity\Provider;
use App\Provider\Enum\ProviderType;
use App\Provider\Enum\ProvisioningMethod;
use App\Provider\Exception\ProviderNotConfiguredException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Real AI drivers against a mocked HTTP API: request shape, structured output parsing, errors.
 */
final class ProviderDriversTest extends KernelTestCase
{
    private const SCHEMA = ['type' => 'object', 'additionalProperties' => false, 'required' => ['answer'], 'properties' => ['answer' => ['type' => 'string', 'minLength' => 1]]];

    protected function setUp(): void
    {
        self::bootKernel();
        $_SERVER['MZIAN_TEST_AI_KEY'] = 'sk-test-123';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['MZIAN_TEST_AI_KEY']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function provider(string $driver, array $settings = []): Provider
    {
        $provider = new Provider($driver.'_test', ProviderType::Ai, $driver, $driver, ProvisioningMethod::Api);
        $provider->setSettings($settings + ['env' => ['api_key' => 'MZIAN_TEST_AI_KEY'], 'default_model' => 'model-x']);

        return $provider;
    }

    private function request(): AIRequest
    {
        return new AIRequest('test', 'System prompt', [AIMessage::assistant('Hi'), AIMessage::user('Hello'), AIMessage::user('Again')], self::SCHEMA, 'result');
    }

    public function testOpenAIStructuredOutput(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['method' => $method, 'url' => $url, 'headers' => $options['headers'], 'body' => json_decode($options['body'], true)];

            return new MockResponse(json_encode([
                'model' => 'model-x-2026',
                'choices' => [['message' => ['content' => '{"answer":"42"}']]],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 8],
            ]));
        });
        $driver = new OpenAIProvider($http, static::getContainer()->get(CredentialVault::class));
        $response = $driver->complete($this->request(), $this->provider('openai'));

        self::assertSame(['answer' => '42'], $response->data);
        self::assertSame(120, $response->inputTokens);
        self::assertSame('POST', $captured['method']);
        self::assertSame('https://api.openai.com/v1/chat/completions', $captured['url']);
        self::assertContains('Authorization: Bearer sk-test-123', $captured['headers']);
        self::assertSame('system', $captured['body']['messages'][0]['role']);
        self::assertSame('json_schema', $captured['body']['response_format']['type']);
        self::assertTrue($captured['body']['response_format']['json_schema']['strict']);
        self::assertArrayNotHasKey('minLength', $captured['body']['response_format']['json_schema']['schema']['properties']['answer'], 'Unsupported keywords are stripped for strict mode.');
    }

    public function testOpenAIErrorsAreClassified(): void
    {
        $vault = static::getContainer()->get(CredentialVault::class);

        $rateLimited = new OpenAIProvider(new MockHttpClient(new MockResponse('{"error":{"message":"Rate limit"}}', ['http_code' => 429])), $vault);
        try {
            $rateLimited->complete($this->request(), $this->provider('openai'));
            self::fail('Expected exception');
        } catch (AIException $e) {
            self::assertTrue($e->retryable);
        }

        $badJson = new OpenAIProvider(new MockHttpClient(new MockResponse(json_encode(['choices' => [['message' => ['content' => 'not json']]]]))), $vault);
        $this->expectException(InvalidAIOutputException::class);
        $badJson->complete($this->request(), $this->provider('openai'));
    }

    public function testMissingKeyIsReportedWithoutCallingTheApi(): void
    {
        unset($_SERVER['MZIAN_TEST_AI_KEY']);
        $driver = new OpenAIProvider(new MockHttpClient(static fn () => throw new \LogicException('must not be called')), static::getContainer()->get(CredentialVault::class));

        $this->expectException(ProviderNotConfiguredException::class);
        $driver->complete($this->request(), $this->provider('openai'));
    }

    public function testClaudeForcedToolUse(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['url' => $url, 'headers' => $options['headers'], 'body' => json_decode($options['body'], true)];

            return new MockResponse(json_encode([
                'model' => 'claude-x',
                'content' => [['type' => 'tool_use', 'name' => 'result', 'input' => ['answer' => 'yes']]],
                'usage' => ['input_tokens' => 300, 'output_tokens' => 20],
            ]));
        });
        $driver = new ClaudeProvider($http, static::getContainer()->get(CredentialVault::class));
        $response = $driver->complete($this->request(), $this->provider('claude'));

        self::assertSame(['answer' => 'yes'], $response->data);
        self::assertSame(300, $response->inputTokens);
        self::assertSame('https://api.anthropic.com/v1/messages', $captured['url']);
        self::assertContains('x-api-key: sk-test-123', $captured['headers']);
        self::assertContains('anthropic-version: 2023-06-01', $captured['headers']);
        self::assertSame(['type' => 'tool', 'name' => 'result'], $captured['body']['tool_choice']);
        self::assertSame('System prompt', $captured['body']['system']);
        // Leading assistant message dropped, consecutive user messages merged.
        self::assertSame([['role' => 'user', 'content' => "Hello\n\nAgain"]], $captured['body']['messages']);
    }

    public function testClaudeOverloadIsRetryable(): void
    {
        $driver = new ClaudeProvider(new MockHttpClient(new MockResponse('{"error":{"message":"Overloaded"}}', ['http_code' => 529])), static::getContainer()->get(CredentialVault::class));
        try {
            $driver->complete($this->request(), $this->provider('claude'));
            self::fail('Expected exception');
        } catch (AIException $e) {
            self::assertTrue($e->retryable);
            self::assertStringContainsString('Overloaded', $e->getMessage());
        }
    }
}
