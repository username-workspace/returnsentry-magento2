<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Support;

use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Framework\App\Config\Data\ProcessorFactory;
use Magento\Framework\App\Config\Initial;
use Magento\Framework\App\Config\Initial\Converter;
use Magento\Framework\App\Config\MetadataProcessor;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Encryption\KeyValidator;
use Magento\Framework\Math\Random;
use PHPUnit\Framework\TestCase;

trait MagentoConfig
{
    /**
     * @param array<string, string> $saved
     */
    private function magentoScopeConfig(array $saved): ScopeConfigInterface
    {
        /** @var TestCase&MagentoConfig $this */
        $document = new \DOMDocument();
        $document->load(__DIR__ . '/../../../etc/config.xml');
        $converter = new Converter([
            'default' => '/config/default',
            'stores' => '/config/stores',
            'websites' => '/config/websites',
        ]);
        $initial = $converter->convert($document);
        $metadata = $initial['metadata'];
        $this->assertArrayHasKey('returnsentry/general/secret_key', $metadata);
        $deployment = $this->createMock(DeploymentConfig::class);
        $deployment->method('get')->willReturnMap([['crypt/key', null, bin2hex(random_bytes(16))]]);
        $encryptor = new Encryptor(new Random(), $deployment, new KeyValidator());
        $data = $initial['data']['default'] ?? [];
        foreach ($saved as $path => $value) {
            $data = $this->withValue($data, $path, isset($metadata[$path]) ? $encryptor->encrypt($value) : $value);
        }
        $backend = (new \ReflectionClass(Encrypted::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Encrypted::class, '_encryptor'))->setValue($backend, $encryptor);
        $processors = $this->createMock(ProcessorFactory::class);
        $processors->method('get')->willReturn($backend);
        $initialConfig = $this->createMock(Initial::class);
        $initialConfig->method('getMetadata')->willReturn($metadata);
        $served = (new MetadataProcessor($processors, $initialConfig))->process($data);

        return new class ($served) implements ScopeConfigInterface {
            /**
             * @param array<string, mixed> $data
             */
            public function __construct(private array $data)
            {
            }

            public function getValue($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
            {
                $value = $this->data;
                foreach (explode('/', (string) $path) as $key) {
                    if (!is_array($value) || !array_key_exists($key, $value)) {
                        return null;
                    }
                    $value = $value[$key];
                }
                return $value;
            }

            public function isSetFlag($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
            {
                return (bool) $this->getValue($path, $scopeType, $scopeCode);
            }
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function withValue(array $data, string $path, string $value): array
    {
        $keys = explode('/', $path);
        $node = &$data;
        foreach ($keys as $key) {
            if (!isset($node[$key]) || !is_array($node[$key])) {
                $node[$key] = [];
            }
            $node = &$node[$key];
        }
        $node = $value;
        return $data;
    }
}
