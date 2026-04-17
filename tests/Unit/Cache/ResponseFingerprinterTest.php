<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Cache;

use Lumen\JsonRpc\Cache\ResponseFingerprinter;
use PHPUnit\Framework\TestCase;

final class ResponseFingerprinterTest extends TestCase
{
    public function testFingerprintIsDeterministic(): void
    {
        $fp = new ResponseFingerprinter(true);
        $data = ['result' => 'hello'];
        $fingerprint1 = $fp->fingerprint($data);
        $fingerprint2 = $fp->fingerprint($data);
        $this->assertEquals($fingerprint1, $fingerprint2);
    }

    public function testDifferentDataProducesDifferentFingerprint(): void
    {
        $fp = new ResponseFingerprinter(true);
        $fp1 = $fp->fingerprint(['result' => 'hello']);
        $fp2 = $fp->fingerprint(['result' => 'world']);
        $this->assertNotEquals($fp1, $fp2);
    }

    public function testIsUnchangedReturnsTrueForSameData(): void
    {
        $fp = new ResponseFingerprinter(true);
        $data = ['key' => 'value'];
        $fingerprint = $fp->fingerprint($data);
        $this->assertTrue($fp->isUnchanged($data, $fingerprint));
    }

    public function testIsUnchangedReturnsFalseForDifferentData(): void
    {
        $fp = new ResponseFingerprinter(true);
        $fingerprint = $fp->fingerprint(['key' => 'value']);
        $this->assertFalse($fp->isUnchanged(['key' => 'other'], $fingerprint));
    }

    public function testIsEnabledReturnsCorrectState(): void
    {
        $enabled = new ResponseFingerprinter(true);
        $disabled = new ResponseFingerprinter(false);
        $this->assertTrue($enabled->isEnabled());
        $this->assertFalse($disabled->isEnabled());
    }
}
