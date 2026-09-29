<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model;

use Magento\Config\Model\Config\Factory as ConfigFactory;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use ReturnSentry\Connector\Model\Source\RefundCategory;
use ReturnSentry\Connector\Model\Source\ReturnReason;

class Settings
{
    public const CONNECTION_PATHS = [
        'general/api_url',
        'general/secret_key',
        'general/publishable_key',
        'privacy/salt',
    ];

    private const ENABLED = 'general/enabled';

    public function __construct(
        private readonly ConfigFactory $configFactory,
        private readonly Structure $structure,
        private readonly StoreManagerInterface $storeManager,
        private readonly File $file,
        private readonly Json $json,
        private readonly ReturnReason $returnReasons,
        private readonly RefundCategory $refundCategories,
        private readonly SettingChecker $settingChecker
    ) {
    }

    /**
     * @param array<string, mixed> $values
     * @return string[]
     * @throws \Exception
     */
    public function save(
        array $values,
        string $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        ?string $scopeCode = null
    ): array {
        $config = $this->configFactory->create(['data' => ['scope' => $scope, 'scope_code' => $scopeCode]]);
        $locked = [];
        foreach ($values as $path => $value) {
            $config->setDataByPath('returnsentry/' . $path, $value);
            if ($this->settingChecker->isReadOnly('returnsentry/' . $path, $scope, $scopeCode)) {
                $locked[] = (string) $path;
            }
        }
        $config->save();
        return $locked;
    }

    /**
     * @param array<string, array{scope: string, code: ?string, values: array<string, mixed>}> $entries
     * @return string[]
     * @throws \Exception
     */
    public function apply(array $entries): array
    {
        $locked = [];
        foreach ($entries as $entry) {
            $locked[] = $this->save($entry['values'], $entry['scope'], $entry['code']);
        }
        return array_values(array_unique(array_merge([], ...$locked)));
    }

    /**
     * @return array<string, array{scope: string, code: ?string, values: array<string, mixed>}>
     * @throws LocalizedException
     */
    public function profile(string $file): array
    {
        try {
            $profile = $this->json->unserialize($this->file->fileGetContents($file));
        } catch (\InvalidArgumentException $exception) {
            throw new LocalizedException(__('%1 is not valid JSON: %2', $file, $exception->getMessage()));
        }
        if (!is_array($profile)) {
            throw new LocalizedException(__('A profile holds default, websites and stores objects.'));
        }
        $entries = [];
        foreach ($profile as $scope => $section) {
            if ($scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
                $entries[$scope] = $this->entry($scope, null, $section);
                continue;
            }
            $scoped = in_array($scope, [ScopeInterface::SCOPE_WEBSITES, ScopeInterface::SCOPE_STORES], true);
            if (!$scoped || !is_array($section)) {
                throw new LocalizedException(__('A profile holds default, websites and stores objects.'));
            }
            foreach ($section as $code => $values) {
                $entries[$scope . '/' . $code] = $this->entry($scope, (string) $code, $values);
            }
        }
        return $entries;
    }

    /**
     * @return array{scope: string, code: ?string, values: array<string, mixed>}
     * @throws LocalizedException
     */
    private function entry(string $scope, ?string $code, mixed $values): array
    {
        if ($scope === ScopeInterface::SCOPE_WEBSITES) {
            $this->storeManager->getWebsite($code);
        }
        if ($scope === ScopeInterface::SCOPE_STORES) {
            $this->storeManager->getStore($code);
        }
        if (!is_array($values)) {
            throw new LocalizedException(__('A profile holds default, websites and stores objects.'));
        }
        foreach ($values as $path => $value) {
            $this->check((string) $path, $value, $scope);
        }
        return ['scope' => $scope, 'code' => $code, 'values' => $values];
    }

    /**
     * @throws LocalizedException
     */
    private function check(string $path, mixed $value, string $scope): void
    {
        if (in_array($path, self::CONNECTION_PATHS, true)) {
            throw new LocalizedException(__('%1 goes to returnsentry:connect, never in a profile.', $path));
        }
        $field = isset($this->structure->getFieldPaths()['returnsentry/' . $path])
            ? $this->structure->getElementByConfigPath('returnsentry/' . $path)
            : null;
        if (!$field instanceof Field || !$this->shownIn($field, $scope)) {
            throw new LocalizedException(__('%1 is not a ReturnSentry setting at the %2 scope.', $path, $scope));
        }
        if ($path === self::ENABLED && $value !== '0') {
            throw new LocalizedException(
                __('A profile can only turn ReturnSentry off: returnsentry:connect turns it on once connected.')
            );
        }
        $allowed = $this->allowed($path, $field);
        $chosen = $this->chosen($path, $field, $value);
        if ($chosen === null || ($allowed !== null && array_diff($chosen, $allowed) !== [])) {
            throw new LocalizedException(__('%1 does not accept this value.', $path));
        }
    }

    private function shownIn(Field $field, string $scope): bool
    {
        return match ($scope) {
            ScopeInterface::SCOPE_WEBSITES => $field->showInWebsite(),
            ScopeInterface::SCOPE_STORES => $field->showInStore(),
            default => $field->showInDefault(),
        };
    }

    /**
     * @return string[]|null
     */
    private function allowed(string $path, Field $field): ?array
    {
        $options = match (true) {
            $path === 'mapping/return_reasons' => $this->returnReasons->toOptionArray(),
            $path === 'mapping/refund_codes' => $this->refundCategories->toOptionArray(),
            $field->hasOptions() => $field->getOptions(),
            default => null,
        };
        return $options === null ? null : $this->optionValues($options);
    }

    /**
     * @param array<mixed> $options
     * @return string[]
     */
    private function optionValues(array $options): array
    {
        $values = [];
        foreach ($options as $option) {
            $value = is_array($option) ? $option['value'] ?? '' : '';
            $values[] = is_array($value) ? $this->optionValues($value) : [(string) $value];
        }
        return array_merge([], ...$values);
    }

    /**
     * @return string[]|null
     */
    private function chosen(string $path, Field $field, mixed $value): ?array
    {
        if ($path === 'mapping/return_reasons' || $path === 'mapping/refund_codes') {
            return is_array($value) ? array_map($this->standard(...), $value) : null;
        }
        if (!is_string($value)) {
            return null;
        }
        return $field->getType() === 'multiselect' ? array_values(array_filter(explode(',', $value))) : [$value];
    }

    private function standard(mixed $row): string
    {
        $valid = is_array($row) && is_string($row['shop'] ?? null) && trim($row['shop']) !== '';
        return $valid && is_string($row['standard'] ?? null) ? $row['standard'] : '';
    }
}
