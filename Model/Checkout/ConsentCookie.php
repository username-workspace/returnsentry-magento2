<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Checkout;

class ConsentCookie
{
    private const SEPARATORS = '/[,;|&\s]+/';
    private const TRIMMED = " \"'{}[]";
    private const ACCEPTED = ['1', 'true'];

    public function grants(string $cookie, string $expected): bool
    {
        $expected = trim($expected);
        if ($expected === '') {
            return false;
        }
        foreach (preg_split(self::SEPARATORS, $cookie) ?: [] as $token) {
            foreach ($this->candidates($token) as $candidate) {
                if ($candidate === $expected || $this->acceptedKey($candidate) === $expected) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return string[]
     */
    private function candidates(string $token): array
    {
        $token = trim($token, self::TRIMMED);
        $equals = strpos($token, '=');
        $value = $equals === false ? '' : trim(substr($token, $equals + 1), self::TRIMMED);
        return array_values(array_filter([$token, $value], static fn (string $candidate) => $candidate !== ''));
    }

    private function acceptedKey(string $candidate): ?string
    {
        $pair = preg_split('/[:=]/', $candidate, 2) ?: [];
        if (count($pair) !== 2 || !in_array(strtolower(trim($pair[1], self::TRIMMED)), self::ACCEPTED, true)) {
            return null;
        }
        return trim($pair[0], self::TRIMMED);
    }
}
