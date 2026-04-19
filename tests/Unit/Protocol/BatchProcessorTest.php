<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Protocol;

use Lumen\JsonRpc\Protocol\BatchProcessor;
use Lumen\JsonRpc\Protocol\RequestValidator;
use PHPUnit\Framework\TestCase;

final class BatchProcessorTest extends TestCase
{
    private BatchProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new BatchProcessor(new RequestValidator(), 100);
    }

    public function testNullInputReturnsParseError(): void
    {
        $result = $this->processor->process(null);
        $this->assertFalse($result->isBatch);
        $this->assertTrue($result->hasErrors());
        $this->assertEquals(-32700, $result->errors[0]->error->code);
    }

    public function testEmptyArrayReturnsInvalidRequest(): void
    {
        $result = $this->processor->process([]);
        $this->assertFalse($result->isBatch);
        $this->assertEquals(-32600, $result->errors[0]->error->code);
    }

    public function testValidSingleRequest(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'method' => 'test.method',
            'id' => 1,
        ];
        $result = $this->processor->process($data);
        $this->assertFalse($result->isBatch);
        $this->assertTrue($result->hasRequests());
        $this->assertCount(1, $result->requests);
        $this->assertEquals('test.method', $result->requests[0]->method);
    }

    public function testBatchWithInvalidItems(): void
    {
        $data = [1, 2, 3];
        $result = $this->processor->process($data);
        $this->assertTrue($result->isBatch);
        $this->assertCount(3, $result->errors);
        foreach ($result->errors as $error) {
            $this->assertEquals(-32600, $error->error->code);
        }
    }

    public function testBatchWithMixedValidAndInvalid(): void
    {
        $data = [
            ['jsonrpc' => '2.0', 'method' => 'valid.method', 'id' => 1],
            ['foo' => 'bar'],
            ['jsonrpc' => '2.0', 'method' => 'another.method', 'id' => 2],
        ];
        $result = $this->processor->process($data);
        $this->assertTrue($result->isBatch);
        $this->assertCount(2, $result->requests);
        $this->assertCount(1, $result->errors);
    }

    public function testBatchExceedingMaxItems(): void
    {
        $processor = new BatchProcessor(new RequestValidator(), 2);
        $data = [
            ['jsonrpc' => '2.0', 'method' => 'a', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'b', 'id' => 2],
            ['jsonrpc' => '2.0', 'method' => 'c', 'id' => 3],
        ];
        $result = $processor->process($data);
        $this->assertEquals(-32600, $result->errors[0]->error->code);
    }

    public function testAllNotificationBatch(): void
    {
        $data = [
            ['jsonrpc' => '2.0', 'method' => 'notify_a'],
            ['jsonrpc' => '2.0', 'method' => 'notify_b'],
        ];
        $result = $this->processor->process($data);
        $this->assertTrue($result->hasOnlyNotifications());
    }

    public function testNonArrayInputReturnsInvalidRequest(): void
    {
        $result = $this->processor->process('not an array');
        $this->assertEquals(-32600, $result->errors[0]->error->code);
    }

    public function testAssociativeInvalidRequestReturnsSingleError(): void
    {
        $data = [
            'jsonrpc' => '1.0',
            'method' => 'test',
            'id' => 42,
        ];
        $result = $this->processor->process($data);
        $this->assertFalse($result->isBatch);
        $this->assertTrue($result->hasErrors());
        $this->assertFalse($result->hasRequests());
        $this->assertEquals(-32600, $result->errors[0]->error->code);
        $this->assertEquals(42, $result->errors[0]->id);
    }

    public function testRawIsObjectWithEmptyArrayTreatedAsSingleRequest(): void
    {
        $result = $this->processor->process([], rawIsObject: true);
        $this->assertFalse($result->isBatch);
        $this->assertTrue($result->hasErrors());
        $this->assertEquals(-32600, $result->errors[0]->error->code);
    }

    public function testRawIsObjectWithSequentialArrayTreatedAsSingleRequest(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'method' => 'test.method',
            'id' => 1,
        ];
        $result = $this->processor->process($data, rawIsObject: true);
        $this->assertFalse($result->isBatch);
        $this->assertTrue($result->hasRequests());
        $this->assertCount(1, $result->requests);
        $this->assertEquals('test.method', $result->requests[0]->method);
    }

    public function testHasOnlyNotificationsReturnsFalseForMixedBatch(): void
    {
        $data = [
            ['jsonrpc' => '2.0', 'method' => 'notify_a'],
            ['jsonrpc' => '2.0', 'method' => 'call_b', 'id' => 2],
        ];
        $result = $this->processor->process($data);
        $this->assertTrue($result->isBatch);
        $this->assertFalse($result->hasOnlyNotifications());
    }

    public function testHasOnlyNotificationsReturnsFalseForNoRequests(): void
    {
        $data = [1, 2, 3];
        $result = $this->processor->process($data);
        $this->assertTrue($result->isBatch);
        $this->assertFalse($result->hasOnlyNotifications());
    }
}
