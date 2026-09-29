<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Api\Data;

class Assessment
{
    /**
     * @param string[] $reasons
     */
    public function __construct(
        public readonly string $action,
        public readonly string $level = 'low',
        public readonly int $score = 0,
        public readonly array $reasons = [],
        public readonly bool $failOpen = false,
        public readonly bool $assessed = true,
        public readonly ?ReturnTerms $returnTerms = null
    ) {
    }

    public static function notAssessed(): self
    {
        return new self('allow', assessed: false);
    }

    public static function failedOpen(): self
    {
        return new self('allow', failOpen: true);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $reasons = [];
        foreach (is_array($data['reasons'] ?? null) ? $data['reasons'] : [] as $reason) {
            $label = is_array($reason) ? ($reason['label'] ?? null) : $reason;
            if (is_string($label) && $label !== '') {
                $reasons[] = $label;
            }
        }
        return new self(
            is_string($data['action'] ?? null) ? $data['action'] : 'allow',
            is_string($data['level'] ?? null) ? $data['level'] : 'low',
            (int) ($data['score'] ?? 0),
            $reasons,
            (bool) ($data['failOpen'] ?? false),
            returnTerms: is_array($data['returnTerms'] ?? null) ? ReturnTerms::fromArray($data['returnTerms']) : null
        );
    }

    /**
     * @return array{
     *     action: string,
     *     level: string,
     *     score: int,
     *     reasons: string[],
     *     failOpen: bool,
     *     returnTerms: array{paid: bool, labelCost: float|null}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'level' => $this->level,
            'score' => $this->score,
            'reasons' => $this->reasons,
            'failOpen' => $this->failOpen,
            'returnTerms' => $this->returnTerms === null ? null : [
                'paid' => $this->returnTerms->isPaid(),
                'labelCost' => $this->returnTerms->getLabelCost(),
            ],
        ];
    }
}
