<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Protocol;

use Lumen\JsonRpc\Protocol\BatchResult;
use Lumen\JsonRpc\Protocol\Request;
use Lumen\JsonRpc\Protocol\Response;
use Lumen\JsonRpc\Protocol\Error;
use PHPUnit\Framework\TestCase;

final class BatchResultEdgeCaseTest extends TestCase
{
    public function testSingleRequestHasNoErrors(): void
    {
        $request = Request::fromArray(['jsonrpc' => '2.0', 'method' => 'test', 'id' => 1]);
        $result = BatchResult::singleRequest($request);
        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->hasRequests());
        $this->assertFalse($result->isBatch);
    }

    public function testSingleErrorHasNoRequests(): void
    {
        $error = Response::error(null, Error::parseError());
        $result = BatchResult::singleError($error);
        $this->assertFalse($result->hasRequests());
        $this->assertTrue($result->hasErrors());
        $this->assertFalse($result->isBatch);
    }

    public function testHasOnlyNotificationsTrueWhenAllNotifications(): void
    {
        $r1 = Request::fromArray(['jsonrpc' => '2.0', 'method' => 'a']);
        $r2 = Request::fromArray(['jsonrpc' => '2.0', 'method' => 'b']);
        $result = new BatchResult([$r1, $r2], [], true);
        $this->assertTrue($result->hasOnlyNotifications());
    }

    public function testHasOnlyNotificationsFalseWhenHasId(): void
    {
        $r1 = Request::fromArray(['jsonrpc' => '2.0', 'method' => 'a']);
        $r2 = Request::fromArray(['jsonrpc' => '2.0', 'method' => 'b', 'id' => 1]);
        $result = new BatchResult([$r1, $r2], [], true);
        $this->assertFalse($result->hasOnlyNotifications());
    }

    public function testHasOnlyNotificationsFalseWhenEmptyRequests(): void
    {
        $result = new BatchResult([], [], false);
        $this->assertFalse($result->hasOnlyNotifications());
    }

    public function testMixedErrorsAndRequests(): void
    {
        $request = Request::fromArray(['jsonrpc' => '2.0', 'method' => 'test', 'id' => 1]);
        $error = Response::error(null, Error::parseError());
        $result = new BatchResult([$request], [$error], true);
        $this->assertTrue($result->hasRequests());
        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->isBatch);
    }
}
