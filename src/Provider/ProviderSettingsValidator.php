<?php

declare(strict_types=1);

namespace App\Provider;

/**
 * Provider settings are stored in clear (JSON column): they must never contain a
 * secret. Secrets go to the encrypted credentials (CredentialVault) or to an
 * environment variable referenced in "env".
 */
final class ProviderSettingsValidator
{
    /** Keys that name a secret. */
    private const SECRET_KEY = '/((^|_)(secret|password|passwd|passphrase|token|apikey|credentials?)(_|$))|((api|private|signing|secret|access)_?key)/i';
    /** Values that look like a secret, whatever their key. */
    private const SECRET_VALUE = '/^(sk[-_]|rk_|pk_live|whsec_|ghp_|gho_|github_pat_|xox[abp]-|AKIA[0-9A-Z]{12}|-----BEGIN)/';

    /**
     * @return list<string> problems (empty when the settings are acceptable)
     */
    public function validate(mixed $settings): array
    {
        if (!\is_array($settings) || ([] !== $settings && array_is_list($settings))) {
            return ['The settings must be a JSON object.'];
        }
        $problems = [];
        $this->walk($settings, '', $problems);

        return $problems;
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $problems
     */
    private function walk(array $node, string $path, array &$problems): void
    {
        foreach ($node as $key => $value) {
            $here = '' === $path ? (string) $key : $path.'.'.$key;
            if ('env' === $key && '' === $path) {
                if (!\is_array($value)) {
                    $problems[] = '"env" must map credential names to environment variable names.';
                    continue;
                }
                foreach ($value as $name => $variable) {
                    if (!\is_string($name) || !preg_match('/^[a-z][a-z0-9_]{1,39}$/', $name) || !\is_string($variable) || !preg_match('/^[A-Z][A-Z0-9_]{1,79}$/', $variable)) {
                        $problems[] = \sprintf('env.%s must be the NAME of an environment variable (e.g. OPENAI_API_KEY), never its value.', (string) $name);
                    }
                }
                continue;
            }
            if (\is_string($key) && preg_match(self::SECRET_KEY, $key)) {
                $problems[] = \sprintf('"%s" looks like a secret: store it as an encrypted credential instead.', $here);
                continue;
            }
            if (\is_array($value)) {
                $this->walk($value, $here, $problems);
            } elseif (\is_string($value) && preg_match(self::SECRET_VALUE, $value)) {
                $problems[] = \sprintf('The value of "%s" looks like a secret: store it as an encrypted credential instead.', $here);
            }
        }
    }
}
