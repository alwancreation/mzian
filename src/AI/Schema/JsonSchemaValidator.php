<?php

declare(strict_types=1);

namespace App\AI\Schema;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Loads the JSON schemas of AI outputs (config/mzian/ai/*.schema.json) and validates data.
 */
final class JsonSchemaValidator
{
    /** Keywords some providers do not accept in "strict" structured outputs. */
    private const CONSTRAINT_KEYWORDS = ['minLength', 'maxLength', 'pattern', 'minimum', 'maximum', 'minItems', 'maxItems', 'format'];

    /** @var array<string, array<string, mixed>> */
    private array $schemas = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/mzian/ai')]
        private readonly string $directory,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(string $name): array
    {
        if (!isset($this->schemas[$name])) {
            $file = \sprintf('%s/%s.schema.json', $this->directory, $name);
            if (!is_file($file)) {
                throw new \InvalidArgumentException(\sprintf('Unknown AI schema "%s".', $name));
            }
            $this->schemas[$name] = json_decode((string) file_get_contents($file), true, 64, \JSON_THROW_ON_ERROR);
        }

        return $this->schemas[$name];
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string> human readable errors (empty = valid)
     */
    public function validate(mixed $data, array $schema): array
    {
        $object = json_decode((string) json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));
        $schemaObject = json_decode((string) json_encode($schema, \JSON_THROW_ON_ERROR));
        $validator = new Validator();
        $validator->validate($object, $schemaObject, Constraint::CHECK_MODE_NORMAL);
        if ($validator->isValid()) {
            return [];
        }

        return array_values(array_map(
            static fn (array $e) => trim(($e['property'] ?? '').': '.($e['message'] ?? 'invalid'), ': '),
            $validator->getErrors(),
        ));
    }

    /**
     * Same schema without value constraints (for providers with a restricted dialect).
     * The full schema is still enforced locally on the answer.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public static function relaxed(array $schema): array
    {
        foreach (self::CONSTRAINT_KEYWORDS as $keyword) {
            unset($schema[$keyword]);
        }
        foreach ($schema as $key => $value) {
            if (\is_array($value)) {
                $schema[$key] = self::relaxed($value);
            }
        }

        return $schema;
    }
}
