<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

class DiConfigTest extends TestCase
{
    private const ETC = __DIR__ . '/../../../etc';
    private const MAGENTO_MODULES = __DIR__ . '/../../../vendor/mage-os/module-*/etc/di.xml';

    public function testNoAreaArgumentReplacesAnArgumentMagentoDeclaresGlobally(): void
    {
        $global = [];
        foreach (glob(self::MAGENTO_MODULES) ?: [] as $file) {
            $global += array_flip($this->typeArguments($file));
        }
        $this->assertArrayHasKey('Magento\Csp\Model\CompositePolicyCollector::collectors', $global);

        foreach (glob(self::ETC . '/*/di.xml') ?: [] as $file) {
            $area = basename(dirname($file));
            foreach ($this->typeArguments($file) as $argument) {
                $this->assertArrayNotHasKey($argument, $global, "etc/$area/di.xml replaces $argument in that area");
            }
        }
    }

    public function testTheBeaconJoinsTheStorefrontContentSecurityPolicy(): void
    {
        $xpath = $this->xpath(self::ETC . '/di.xml');
        $item = $xpath->query('/config/type[@name="Magento\Csp\Model\CompositePolicyCollector"]'
            . '/arguments/argument[@name="collectors"]/item[@name="returnsentry_beacon"]');

        $this->assertSame(1, $item->length);
    }

    /**
     * @return string[]
     */
    private function typeArguments(string $file): array
    {
        $arguments = [];
        foreach ($this->xpath($file)->query('/config/type/arguments/argument') ?: [] as $argument) {
            if ($argument instanceof \DOMElement && $argument->parentNode?->parentNode instanceof \DOMElement) {
                $type = ltrim($argument->parentNode->parentNode->getAttribute('name'), '\\');
                $arguments[] = $type . '::' . $argument->getAttribute('name');
            }
        }
        return $arguments;
    }

    private function xpath(string $file): \DOMXPath
    {
        $document = new \DOMDocument();
        $document->load($file);
        return new \DOMXPath($document);
    }
}
