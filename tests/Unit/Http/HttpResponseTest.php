<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Http;

use Lumen\JsonRpc\Http\HttpResponse;
use PHPUnit\Framework\TestCase;

final class HttpResponseTest extends TestCase
{
    public function testJsonFactoryCreatesResponseWithContentType(): void
    {
        $response = HttpResponse::json('{"ok":true}');
        $this->assertEquals('{"ok":true}', $response->body);
        $this->assertEquals(200, $response->statusCode);
        $this->assertEquals('application/json', $response->headers['Content-Type']);
    }

    public function testJsonFactoryMergesExtraHeaders(): void
    {
        $response = HttpResponse::json('{}', 201, ['X-Custom' => 'test']);
        $this->assertEquals(201, $response->statusCode);
        $this->assertEquals('test', $response->headers['X-Custom']);
        $this->assertEquals('application/json', $response->headers['Content-Type']);
    }

    public function testNoContentFactoryReturns204(): void
    {
        $response = HttpResponse::noContent();
        $this->assertEquals('', $response->body);
        $this->assertEquals(204, $response->statusCode);
        $this->assertEquals([], $response->headers);
    }
}
