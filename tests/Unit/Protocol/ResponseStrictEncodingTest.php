<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Protocol;

use Lumen\JsonRpc\Protocol\Error;
use Lumen\JsonRpc\Protocol\Response;
use PHPUnit\Framework\TestCase;

final class ResponseStrictEncodingTest extends TestCase
{
    public function testToJsonUsesStrictEncodingForNormalData(): void
    {
        $response = Response::success(1, ['key' => 'value']);
        $json = $response->toJson();
        $decoded = json_decode($json, true);

        $this->assertNotNull($decoded);
        $this->assertEquals(['key' => 'value'], $decoded['result']);
    }

    public function testToJsonHandlesUtf8Correctly(): void
    {
        $response = Response::success(1, ['name' => 'Ünïcödé 日本語']);
        $json = $response->toJson();
        $decoded = json_decode($json, true);

        $this->assertEquals('Ünïcödé 日本語', $decoded['result']['name']);
    }

    public function testToJsonProducesValidErrorOnUnencodableResult(): void
    {
        $resource = fopen('php://memory', 'r');
        try {
            $response = Response::success(1, ['resource' => $resource]);
            $json = $response->toJson();
            $decoded = json_decode($json, true);

            $this->assertNotNull($decoded);
            $this->assertEquals(-32603, $decoded['error']['code']);
            $this->assertStringContainsString('JSON encoding failed', $decoded['error']['data']);
        } finally {
            fclose($resource);
        }
    }

    public function testToArrayAndToJsonAreConsistent(): void
    {
        $response = Response::success(42, ['status' => 'ok']);
        $arr = $response->toArray();
        $fromJson = json_decode($response->toJson(), true);

        $this->assertEquals($arr, $fromJson);
    }

    public function testErrorWithDataField(): void
    {
        $error = new Error(-32000, 'Custom error', ['detail' => 'extra info']);
        $response = Response::error(1, $error);
        $decoded = json_decode($response->toJson(), true);

        $this->assertEquals(-32000, $decoded['error']['code']);
        $this->assertEquals('Custom error', $decoded['error']['message']);
        $this->assertEquals(['detail' => 'extra info'], $decoded['error']['data']);
    }

    public function testErrorWithoutDataOmitsKey(): void
    {
        $response = Response::error(1, new Error(-32600, 'Bad request'));
        $decoded = json_decode($response->toJson(), true);

        $this->assertArrayNotHasKey('data', $decoded['error']);
    }
}
