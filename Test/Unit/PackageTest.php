<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit;

use Composer\Package\Archiver\ArchivableFilesFinder;
use PHPUnit\Framework\TestCase;

class PackageTest extends TestCase
{
    public function testAPathRepositoryCopyLeavesTheDevelopmentVendorBehind(): void
    {
        $root = (string) realpath(__DIR__ . '/../..');
        $copied = [];
        foreach (new ArchivableFilesFinder($root, []) as $file) {
            $copied[] = substr($file->getPathname(), strlen($root) + 1);
        }

        $this->assertContains('registration.php', $copied);
        $vendored = array_filter($copied, static fn (string $path) => str_starts_with($path, 'vendor/'));
        $this->assertSame([], array_values($vendored));
    }
}
