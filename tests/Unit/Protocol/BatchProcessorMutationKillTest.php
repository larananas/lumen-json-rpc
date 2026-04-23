<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Protocol;

use Lumen\JsonRpc\Protocol\BatchProcessor;
use Lumen\JsonRpc\Protocol\RequestValidator;
use PHPUnit\Framework\TestCase;

final class BatchProcessorMutationKillTest extends TestCase
{
    private BatchProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new BatchProcessor(new RequestValidator(), 100);
    }

    public function testDefaultMaxItemsIs100(): void
    {
        $items = [];
        for ($i = 0; $i < 100; $i++) {
            $items[] = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => $i + 1];
        }
        $result = $this->processor->process($items);
        $this->assertFalse($result->hasErrors());
    }

    public function testBatchOf101IsRejected(): void
    {
        $items = [];
        for ($i = 0; $i < 101; $i++) {
            $items[] = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => $i + 1];
        }
        $result = $this->processor->process($items);
        $this->assertTrue($result->hasErrors());
    }

    public function testBatchExceedsMaximumErrorMessageContainsMaxItems(): void
    {
        $items = [];
        for ($i = 0; $i < 101; $i++) {
            $items[] = ['jsonrpc' => '2.0', 'method' => 'test', 'id' => $i + 1];
        }
        $result = $this->processor->process($items);
        $this->assertTrue($result->hasErrors());
        $json = $result->errors[0]->toJson();
        $this->assertStringContainsString('100', $json);
    }

    public function testNullInputReturnsParseError(): void
    {
        $result = $this->processor->process(null);
        $this->assertTrue($result->hasErrors());
    }

    public function testNonArrayInputReturnsInvalidRequest(): void
    {
        $result = $this->processor->process('string');
        $this->assertTrue($result->hasErrors());
    }
}
