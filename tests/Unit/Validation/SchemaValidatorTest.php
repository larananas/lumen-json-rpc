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

    public function testAdditionalPropertiesAsSchemaPasses(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'additionalProperties' => ['type' => 'integer'],
        ];
        $errors = $this->validator->validate(
            ['name' => 'test', 'age' => 25, 'count' => 10],
            $schema,
        );
        $this->assertEmpty($errors);
    }

    public function testAdditionalPropertiesAsSchemaFails(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'additionalProperties' => ['type' => 'integer'],
        ];
        $errors = $this->validator->validate(
            ['name' => 'test', 'age' => 'not-int'],
            $schema,
        );
        $this->assertNotEmpty($errors);
        $this->assertTrue(count(array_filter($errors, fn($e) => str_contains($e, 'age.'))) > 0);
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

    public function testObjectTypeDoesNotRunForStringType(): void
    {
        $schema = [
            'type' => 'string',
            'required' => ['email'],
        ];
        $errors = $this->validator->validate('hello', $schema);
        $this->assertEmpty($errors);
    }

    public function testObjectTypeRequiredOnlyRunsForObject(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['email'],
        ];
        $errors = $this->validator->validate(['name' => 'test'], $schema);
        $this->assertNotEmpty($errors);
        $this->assertTrue(count(array_filter($errors, fn($e) => str_contains($e, 'Missing required field: email'))) > 0);
    }

    public function testObjectTypeRequiredDoesNotRunForNonObjectArray(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['email'],
        ];
        $errors = $this->validator->validate([1, 2, 3], $schema);
        $this->assertNotEmpty($errors);
    }

    public function testConstValidationPasses(): void
    {
        $schema = ['const' => 42];
        $this->assertEmpty($this->validator->validate(42, $schema));
        $this->assertEmpty($this->validator->validate('hello', ['const' => 'hello']));
    }

    public function testConstValidationFails(): void
    {
        $schema = ['const' => 42];
        $errors = $this->validator->validate(43, $schema);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must be', $errors[0]);
    }

    public function testConstValidationTypeMismatch(): void
    {
        $schema = ['const' => '42'];
        $errors = $this->validator->validate(42, $schema);
        $this->assertNotEmpty($errors);
    }

    public function testOneOfValidationPasses(): void
    {
        $schema = [
            'oneOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ];
        $this->assertEmpty($this->validator->validate('hello', $schema));
        $this->assertEmpty($this->validator->validate(42, $schema));
    }

    public function testOneOfValidationFailsNoMatch(): void
    {
        $schema = [
            'oneOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ];
        $errors = $this->validator->validate(true, $schema);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('exactly one', $errors[0]);
    }

    public function testOneOfValidationFailsMultipleMatch(): void
    {
        $schema = [
            'oneOf' => [
                ['type' => 'number'],
                ['type' => 'integer'],
            ],
        ];
        $errors = $this->validator->validate(42, $schema);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('matched 2', $errors[0]);
    }

    public function testAnyOfValidationPasses(): void
    {
        $schema = [
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ];
        $this->assertEmpty($this->validator->validate('hello', $schema));
        $this->assertEmpty($this->validator->validate(42, $schema));
    }

    public function testAnyOfValidationFails(): void
    {
        $schema = [
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ];
        $errors = $this->validator->validate(true, $schema);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('at least one anyOf', $errors[0]);
    }

    public function testAllOfValidationPasses(): void
    {
        $schema = [
            'allOf' => [
                ['type' => 'object', 'required' => ['name']],
                ['type' => 'object', 'required' => ['email']],
            ],
        ];
        $this->assertEmpty($this->validator->validate(
            ['name' => 'test', 'email' => 'test@example.com'],
            $schema,
        ));
    }

    public function testAllOfValidationFails(): void
    {
        $schema = [
            'allOf' => [
                ['type' => 'object', 'required' => ['name']],
                ['type' => 'object', 'required' => ['email']],
            ],
        ];
        $errors = $this->validator->validate(['name' => 'test'], $schema);
        $this->assertNotEmpty($errors);
        $this->assertTrue(count(array_filter($errors, fn($e) => str_contains($e, 'allOf[1]'))) > 0);
    }

    public function testMinimumValidation(): void
    {
        $schema = ['type' => 'integer', 'minimum' => 10];
        $this->assertEmpty($this->validator->validate(10, $schema));
        $this->assertEmpty($this->validator->validate(15, $schema));
        $errors = $this->validator->validate(9, $schema);
        $this->assertNotEmpty($errors);
    }

    public function testMaximumValidation(): void
    {
        $schema = ['type' => 'integer', 'maximum' => 100];
        $this->assertEmpty($this->validator->validate(100, $schema));
        $this->assertEmpty($this->validator->validate(50, $schema));
        $errors = $this->validator->validate(101, $schema);
        $this->assertNotEmpty($errors);
    }

    public function testExclusiveMinimum(): void
    {
        $schema = ['type' => 'number', 'exclusiveMinimum' => 0];
        $this->assertEmpty($this->validator->validate(0.1, $schema));
        $errors = $this->validator->validate(0, $schema);
        $this->assertNotEmpty($errors);
    }

    public function testExclusiveMaximum(): void
    {
        $schema = ['type' => 'number', 'exclusiveMaximum' => 10];
        $this->assertEmpty($this->validator->validate(9.9, $schema));
        $errors = $this->validator->validate(10, $schema);
        $this->assertNotEmpty($errors);
    }

    public function testPatternValidation(): void
    {
        $schema = ['type' => 'string', 'pattern' => '^[a-z]+$'];
        $this->assertEmpty($this->validator->validate('hello', $schema));
        $errors = $this->validator->validate('Hello123', $schema);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('pattern', $errors[0]);
    }

    public function testPatternWithForwardSlash(): void
    {
        $schema = ['type' => 'string', 'pattern' => '^https?://'];
        $this->assertEmpty($this->validator->validate('http://example.com', $schema));
        $errors = $this->validator->validate('not-a-url', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testPatternDoesNotApplyToNonString(): void
    {
        $schema = ['pattern' => '^[a-z]+$'];
        $this->assertEmpty($this->validator->validate(42, $schema));
    }

    public function testNotValidationPasses(): void
    {
        $schema = [
            'not' => ['type' => 'string'],
        ];
        $this->assertEmpty($this->validator->validate(42, $schema));
    }

    public function testNotValidationFails(): void
    {
        $schema = [
            'not' => ['type' => 'string'],
        ];
        $errors = $this->validator->validate('hello', $schema);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('not', $errors[0]);
    }

    public function testMinProperties(): void
    {
        $schema = ['type' => 'object', 'minProperties' => 2];
        $this->assertEmpty($this->validator->validate(['a' => 1, 'b' => 2], $schema));
        $errors = $this->validator->validate(['a' => 1], $schema);
        $this->assertNotEmpty($errors);
    }

    public function testMaxProperties(): void
    {
        $schema = ['type' => 'object', 'maxProperties' => 2];
        $this->assertEmpty($this->validator->validate(['a' => 1, 'b' => 2], $schema));
        $errors = $this->validator->validate(['a' => 1, 'b' => 2, 'c' => 3], $schema);
        $this->assertNotEmpty($errors);
    }

    public function testUniqueItems(): void
    {
        $schema = ['type' => 'array', 'uniqueItems' => true];
        $this->assertEmpty($this->validator->validate([1, 2, 3], $schema));
        $errors = $this->validator->validate([1, 2, 1], $schema);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('unique', $errors[0]);
    }

    public function testUniqueItemsWithComplexValues(): void
    {
        $schema = ['type' => 'array', 'uniqueItems' => true];
        $this->assertEmpty($this->validator->validate(
            [['a' => 1], ['a' => 2]],
            $schema,
        ));
        $errors = $this->validator->validate(
            [['a' => 1], ['a' => 1]],
            $schema,
        );
        $this->assertNotEmpty($errors);
    }

    public function testDeeplyNestedValidation(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'user' => [
                    'type' => 'object',
                    'properties' => [
                        'profile' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string', 'minLength' => 1],
                            ],
                            'required' => ['name'],
                        ],
                    ],
                    'required' => ['profile'],
                ],
            ],
            'required' => ['user'],
        ];
        $errors = $this->validator->validate(
            ['user' => ['profile' => ['name' => '']]],
            $schema,
        );
        $this->assertNotEmpty($errors);
        $this->assertTrue(count(array_filter($errors, fn($e) => str_contains($e, 'user.profile.name.'))) > 0);
    }

    public function testOneOfWithNullableType(): void
    {
        $schema = [
            'oneOf' => [
                ['type' => 'string'],
                ['type' => 'null'],
            ],
        ];
        $this->assertEmpty($this->validator->validate('hello', $schema));
        $this->assertEmpty($this->validator->validate(null, $schema));
        $errors = $this->validator->validate(42, $schema);
        $this->assertNotEmpty($errors);
    }

    public function testAllOfWithPropertyConstraints(): void
    {
        $schema = [
            'allOf' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'age' => ['type' => 'integer', 'minimum' => 0],
                    ],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'age' => ['type' => 'integer', 'maximum' => 150],
                    ],
                ],
            ],
        ];
        $this->assertEmpty($this->validator->validate(['age' => 25], $schema));
        $errors = $this->validator->validate(['age' => 200], $schema);
        $this->assertNotEmpty($errors);
    }

    public function testNestedArrayValidation(): void
    {
        $schema = [
            'type' => 'array',
            'items' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
            ],
        ];
        $this->assertEmpty($this->validator->validate([[1, 2], [3, 4]], $schema));
        $errors = $this->validator->validate([[1, 'x'], [3, 4]], $schema);
        $this->assertNotEmpty($errors);
        $this->assertTrue(count(array_filter($errors, fn($e) => str_contains($e, '[0].[1].'))) > 0);
    }

    public function testNumericConstraintsDoNotApplyToNonNumeric(): void
    {
        $schema = ['type' => 'string', 'minimum' => 0, 'maximum' => 100];
        $this->assertEmpty($this->validator->validate('hello', $schema));
    }

    public function testFormatEmailValid(): void
    {
        $schema = ['type' => 'string', 'format' => 'email'];
        $this->assertEmpty($this->validator->validate('user@example.com', $schema));
        $this->assertEmpty($this->validator->validate('user.name+tag@sub.example.com', $schema));
    }

    public function testFormatEmailInvalid(): void
    {
        $schema = ['type' => 'string', 'format' => 'email'];
        $errors = $this->validator->validate('not-an-email', $schema);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('format', $errors[0]);
    }

    public function testFormatEmailEmptyInvalid(): void
    {
        $schema = ['type' => 'string', 'format' => 'email'];
        $errors = $this->validator->validate('', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatUriValid(): void
    {
        $schema = ['type' => 'string', 'format' => 'uri'];
        $this->assertEmpty($this->validator->validate('https://example.com/path', $schema));
        $this->assertEmpty($this->validator->validate('ftp://files.example.com', $schema));
        $this->assertEmpty($this->validator->validate('urn:isbn:0451450523', $schema));
    }

    public function testFormatUriInvalid(): void
    {
        $schema = ['type' => 'string', 'format' => 'uri'];
        $errors = $this->validator->validate('not a uri', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatUriMissingScheme(): void
    {
        $schema = ['type' => 'string', 'format' => 'uri'];
        $errors = $this->validator->validate('//example.com', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatUrlValid(): void
    {
        $schema = ['type' => 'string', 'format' => 'url'];
        $this->assertEmpty($this->validator->validate('https://example.com', $schema));
        $this->assertEmpty($this->validator->validate('http://localhost:8080/path?q=1', $schema));
    }

    public function testFormatUrlInvalid(): void
    {
        $schema = ['type' => 'string', 'format' => 'url'];
        $errors = $this->validator->validate('ftp://example.com', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatUrlNoScheme(): void
    {
        $schema = ['type' => 'string', 'format' => 'url'];
        $errors = $this->validator->validate('example.com', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatUuidValid(): void
    {
        $schema = ['type' => 'string', 'format' => 'uuid'];
        $this->assertEmpty($this->validator->validate('550e8400-e29b-41d4-a716-446655440000', $schema));
        $this->assertEmpty($this->validator->validate('00000000-0000-0000-0000-000000000000', $schema));
    }

    public function testFormatUuidCaseInsensitive(): void
    {
        $schema = ['type' => 'string', 'format' => 'uuid'];
        $this->assertEmpty($this->validator->validate('550E8400-E29B-41D4-A716-446655440000', $schema));
    }

    public function testFormatUuidInvalid(): void
    {
        $schema = ['type' => 'string', 'format' => 'uuid'];
        $errors = $this->validator->validate('not-a-uuid', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatUuidMissingDashes(): void
    {
        $schema = ['type' => 'string', 'format' => 'uuid'];
        $errors = $this->validator->validate('550e8400e29b41d4a716446655440000', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatIpv4Valid(): void
    {
        $schema = ['type' => 'string', 'format' => 'ipv4'];
        $this->assertEmpty($this->validator->validate('192.168.1.1', $schema));
        $this->assertEmpty($this->validator->validate('0.0.0.0', $schema));
        $this->assertEmpty($this->validator->validate('255.255.255.255', $schema));
    }

    public function testFormatIpv4Invalid(): void
    {
        $schema = ['type' => 'string', 'format' => 'ipv4'];
        $errors = $this->validator->validate('256.1.1.1', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatIpv4NotIpv6(): void
    {
        $schema = ['type' => 'string', 'format' => 'ipv4'];
        $errors = $this->validator->validate('::1', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatIpv6Valid(): void
    {
        $schema = ['type' => 'string', 'format' => 'ipv6'];
        $this->assertEmpty($this->validator->validate('::1', $schema));
        $this->assertEmpty($this->validator->validate('2001:0db8:85a3:0000:0000:8a2e:0370:7334', $schema));
        $this->assertEmpty($this->validator->validate('fe80::1', $schema));
    }

    public function testFormatIpv6Invalid(): void
    {
        $schema = ['type' => 'string', 'format' => 'ipv6'];
        $errors = $this->validator->validate('192.168.1.1', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatDateTimeValid(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $this->assertEmpty($this->validator->validate('2024-01-15T10:30:00Z', $schema));
        $this->assertEmpty($this->validator->validate('2024-01-15T10:30:00+02:00', $schema));
        $this->assertEmpty($this->validator->validate('2024-01-15T10:30:00.123456Z', $schema));
    }

    public function testFormatDateTimeInvalid(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $errors = $this->validator->validate('2024-01-15 10:30:00', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatDateTimeMissingTimezone(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $errors = $this->validator->validate('2024-01-15T10:30:00', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatDateValid(): void
    {
        $schema = ['type' => 'string', 'format' => 'date'];
        $this->assertEmpty($this->validator->validate('2024-01-15', $schema));
    }

    public function testFormatDateInvalid(): void
    {
        $schema = ['type' => 'string', 'format' => 'date'];
        $errors = $this->validator->validate('2024/01/15', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatTimeValid(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertEmpty($this->validator->validate('10:30:00', $schema));
        $this->assertEmpty($this->validator->validate('10:30:00.123Z', $schema));
    }

    public function testFormatTimeInvalid(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $errors = $this->validator->validate('not-a-time', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatUnknownIgnored(): void
    {
        $schema = ['type' => 'string', 'format' => 'custom-unknown'];
        $this->assertEmpty($this->validator->validate('anything', $schema));
    }

    public function testFormatOnlyRunsForStrings(): void
    {
        $schema = ['format' => 'email'];
        $this->assertEmpty($this->validator->validate(42, $schema));
    }

    public function testSupportedFormatsReturnsExpectedKeys(): void
    {
        $formats = SchemaValidator::supportedFormats();
        $this->assertContains('email', $formats);
        $this->assertContains('uri', $formats);
        $this->assertContains('url', $formats);
        $this->assertContains('uuid', $formats);
        $this->assertContains('ipv4', $formats);
        $this->assertContains('ipv6', $formats);
        $this->assertContains('date-time', $formats);
        $this->assertContains('date', $formats);
        $this->assertContains('time', $formats);
    }

    public function testFormatInsideObjectProperty(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'email' => ['type' => 'string', 'format' => 'email'],
            ],
        ];
        $this->assertEmpty($this->validator->validate(['email' => 'ok@test.com'], $schema));
        $errors = $this->validator->validate(['email' => 'bad'], $schema);
        $this->assertNotEmpty($errors);
    }

    public function testMultipleOfPassesForInteger(): void
    {
        $schema = ['type' => 'integer', 'multipleOf' => 5];
        $this->assertEmpty($this->validator->validate(10, $schema));
        $this->assertEmpty($this->validator->validate(0, $schema));
        $this->assertEmpty($this->validator->validate(25, $schema));
    }

    public function testMultipleOfFailsForInteger(): void
    {
        $schema = ['type' => 'integer', 'multipleOf' => 5];
        $errors = $this->validator->validate(7, $schema);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('multiple of', $errors[0]);
    }

    public function testMultipleOfPassesForFloat(): void
    {
        $schema = ['type' => 'number', 'multipleOf' => 0.5];
        $this->assertEmpty($this->validator->validate(1.0, $schema));
        $this->assertEmpty($this->validator->validate(2.5, $schema));
        $this->assertEmpty($this->validator->validate(0.0, $schema));
    }

    public function testMultipleOfFailsForFloat(): void
    {
        $schema = ['type' => 'number', 'multipleOf' => 0.5];
        $errors = $this->validator->validate(1.3, $schema);
        $this->assertNotEmpty($errors);
    }

    public function testMultipleOfDoesNotApplyToNonNumeric(): void
    {
        $schema = ['multipleOf' => 2];
        $this->assertEmpty($this->validator->validate('hello', $schema));
    }

    public function testMultipleOfWithZeroIgnored(): void
    {
        $schema = ['type' => 'integer', 'multipleOf' => 0];
        $this->assertEmpty($this->validator->validate(5, $schema));
    }

    public function testFormatDateInvalidMonth(): void
    {
        $schema = ['type' => 'string', 'format' => 'date'];
        $errors = $this->validator->validate('2024-13-01', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatDateInvalidDay(): void
    {
        $schema = ['type' => 'string', 'format' => 'date'];
        $errors = $this->validator->validate('2024-01-32', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatDateZeroMonth(): void
    {
        $schema = ['type' => 'string', 'format' => 'date'];
        $errors = $this->validator->validate('2024-00-15', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatDateTimeInvalidHour(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $errors = $this->validator->validate('2024-06-15T25:00:00Z', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatDateTimeInvalidMinute(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $errors = $this->validator->validate('2024-06-15T12:60:00Z', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatDateTimeInvalidMonth(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $errors = $this->validator->validate('2024-13-15T12:00:00Z', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatDateTimeBoundaryValid(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $this->assertEmpty($this->validator->validate('2024-12-31T23:59:59Z', $schema));
    }

    public function testFormatTimeInvalidHour(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $errors = $this->validator->validate('24:00:00', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatTimeInvalidSecond(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $errors = $this->validator->validate('12:30:60', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testFormatTimeBoundaryValid(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertEmpty($this->validator->validate('23:59:59', $schema));
        $this->assertEmpty($this->validator->validate('00:00:00', $schema));
    }
}
