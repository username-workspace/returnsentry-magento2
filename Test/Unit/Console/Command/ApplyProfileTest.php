<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Console\Command;

use Magento\Config\Console\Command\EmulatedAdminhtmlAreaProcessor;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Console\Command\ApplyProfile;
use ReturnSentry\Connector\Model\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ApplyProfileTest extends TestCase
{
    public function testTheProfileIsCheckedThenSavedInTheAdminArea(): void
    {
        $entries = ['default' => ['scope' => 'default', 'code' => null, 'values' => ['features/beacon' => '0']]];
        $settings = $this->createMock(Settings::class);
        $settings->expects($this->once())->method('profile')->with('profile.json')->willReturn($entries);
        $settings->expects($this->once())->method('apply')->with($entries)->willReturn(['features/beacon']);

        $tester = $this->tester($settings);

        $this->assertSame(Command::SUCCESS, $tester->execute(['file' => 'profile.json']));
        $this->assertStringContainsString('Profile applied: default.', $tester->getDisplay());
        $this->assertStringContainsString(
            'Locked in app/etc/env.php or config.php, left unchanged: features/beacon.',
            $tester->getDisplay()
        );
    }

    public function testARefusedProfileExitsWithItsReason(): void
    {
        $settings = $this->createMock(Settings::class);
        $refusal = new LocalizedException(__('%1 does not accept this value.', 'x'));
        $settings->method('profile')->willThrowException($refusal);
        $settings->expects($this->never())->method('apply');

        $tester = $this->tester($settings);

        $this->assertSame(Command::INVALID, $tester->execute(['file' => 'profile.json']));
        $this->assertStringContainsString('x does not accept this value.', $tester->getDisplay());
    }

    private function tester(Settings $settings): CommandTester
    {
        $area = $this->createMock(EmulatedAdminhtmlAreaProcessor::class);
        $area->expects($this->once())->method('process')->willReturnCallback(static fn (callable $run) => $run());
        return new CommandTester(new ApplyProfile($area, $settings));
    }
}
