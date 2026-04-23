<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Http;

use Lumen\JsonRpc\Http\HttpResponse;
use PHPUnit\Framework\TestCase;

final class HttpResponseMutationKillTest extends TestCase
{
    public function testDefaultStatusCodeIs200(): void
    {
        $response = new HttpResponse('body');
        $this->assertSame(200, $response->statusCode);
    }

    public function testDefaultHeadersIsEmptyArray(): void
    {
        $response = new HttpResponse('body');
        $this->assertSame([], $response->headers);
    }

    public function testJsonFactorySetsContentType(): void
    {
        $response = HttpResponse::json('{}');
        $this->assertSame('application/json', $response->headers['Content-Type']);
    }

    public function testNoContentReturns204(): void
    {
        $response = HttpResponse::noContent();
        $this->assertSame(204, $response->statusCode);
        $this->assertSame('', $response->body);
        $this->assertSame([], $response->headers);
    }
}
