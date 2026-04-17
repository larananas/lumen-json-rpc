<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Validation;

use Lumen\JsonRpc\Validation\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class SchemaValidatorTest extends TestCase
{
    private SchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SchemaValidator();
    }

    public function testValidObjectPasses(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['email', 'roles'],
            'properties' => [
                'email' => ['type' => 'string'],
                'roles' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'minItems' => 1,
                ],
            ],
            'additionalProperties' => false,
        ];
        $errors = $this->validator->validate(
            ['email' => 'test@example.com', 'roles' => ['admin']],
            $schema,
        );
        $this->assertEmpty($errors);
    }

    public function testMissingRequiredField(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['email'],
            'properties' => [
                'email' => ['type' => 'string'],
            ],
        ];
        $errors = $this->validator->validate(['name' => 'test'], $schema);
        $this->assertNotEmpty($errors);
        $this->assertTrue(count(array_filter($errors, fn($e) => str_contains($e, 'Missing required field: email'))) > 0);
    }

    public function testInvalidType(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'email' => ['type' => 'string'],
            ],
        ];
        $errors = $this->validator->validate(['email' => 123], $schema);
        $this->assertNotEmpty($errors);
        $this->assertTrue(count(array_filter($errors, fn($e) => str_contains($e, 'Expected type string'))) > 0);
    }

    public function testUnexpectedFieldWhenAdditionalPropertiesFalse(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'email' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];
        $errors = $this->validator->validate(
            ['email' => 'test@example.com', 'extra' => 'value'],
            $schema,
        );
        $this->assertNotEmpty($errors);
        $this->assertTrue(count(array_filter($errors, fn($e) => str_contains($e, 'Unexpected field: extra'))) > 0);
    }

    public function testArrayMinItems(): void
    {
        $schema = [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'minItems' => 2,
        ];
        $errors = $this->validator->validate(['one'], $schema);
        $this->assertNotEmpty($errors);
        $this->assertTrue(count(array_filter($errors, fn($e) => str_contains($e, 'at least 2'))) > 0);
    }

    public function testArrayMaxItems(): void
    {
        $schema = [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'maxItems' => 2,
        ];
        $errors = $this->validator->validate(['a', 'b', 'c'], $schema);
        $this->assertNotEmpty($errors);
        $this->assertTrue(count(array_filter($errors, fn($e) => str_contains($e, 'at most 2'))) > 0);
    }

    public function testEnumValidation(): void
    {
        $schema = [
            'type' => 'string',
            'enum' => ['active', 'inactive'],
        ];
        $errors = $this->validator->validate('active', $schema);
        $this->assertEmpty($errors);

        $errors = $this->validator->validate('unknown', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testMinLength(): void
    {
        $schema = ['type' => 'string', 'minLength' => 3];
        $errors = $this->validator->validate('ab', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testMaxLength(): void
    {
        $schema = ['type' => 'string', 'maxLength' => 5];
        $errors = $this->validator->validate('abcdef', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testNestedPropertyValidation(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1],
            ],
        ];
        $errors = $this->validator->validate(['name' => ''], $schema);
        $this->assertNotEmpty($errors);
        $this->assertTrue(count(array_filter($errors, fn($e) => str_contains($e, 'name.'))) > 0);
    }

    public function testEmptyDataWithNoSchemaReturnsEmpty(): void
    {
        $errors = $this->validator->validate([], []);
        $this->assertEmpty($errors);
    }

    public function testIntegerTypeValidation(): void
    {
        $schema = ['type' => 'integer'];
        $this->assertEmpty($this->validator->validate(42, $schema));
        $errors = $this->validator->validate('not-int', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testNumberTypeAcceptsFloat(): void
    {
        $schema = ['type' => 'number'];
        $this->assertEmpty($this->validator->validate(3.14, $schema));
        $this->assertEmpty($this->validator->validate(42, $schema));
    }

    public function testBooleanTypeValidation(): void
    {
        $schema = ['type' => 'boolean'];
        $this->assertEmpty($this->validator->validate(true, $schema));
        $errors = $this->validator->validate('true', $schema);
        $this->assertNotEmpty($errors);
    }
}
