<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Validation;

use Lumen\JsonRpc\Validation\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class SchemaValidatorMutationKillTest extends TestCase
{
    private SchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SchemaValidator();
    }

    public function testDefaultDepthIsZero(): void
    {
        $errors = $this->validator->validate('data', ['type' => 'string']);
        $this->assertEmpty($errors);
    }

    public function testArrayMergeWithArrayTypeValidation(): void
    {
        $errors = $this->validator->validate(
            [1, 2, 3],
            ['type' => 'array', 'items' => ['type' => 'integer']],
        );
        $this->assertEmpty($errors);
    }

    public function testArrayMergeAccumulatesBothArrayAndItemErrors(): void
    {
        $errors = $this->validator->validate(
            ['not-int'],
            ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 2],
        );
        $this->assertNotEmpty($errors);
    }

    public function testAnyOfBreakStopsAtFirstMatch(): void
    {
        $schema = [
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ];
        $errors = $this->validator->validate('hello', $schema);
        $this->assertEmpty($errors);
    }

    public function testMinimumWithFloatBoundary(): void
    {
        $errors = $this->validator->validate(4.9, ['minimum' => 5.0]);
        $this->assertNotEmpty($errors);
    }

    public function testMinimumWithIntBoundary(): void
    {
        $errors = $this->validator->validate(4, ['minimum' => 5]);
        $this->assertNotEmpty($errors);
    }

    public function testMinimumPassesAtBoundary(): void
    {
        $errors = $this->validator->validate(5, ['minimum' => 5]);
        $this->assertEmpty($errors);
    }

    public function testMinimumWithFloatPassesAtBoundary(): void
    {
        $errors = $this->validator->validate(5.0, ['minimum' => 5.0]);
        $this->assertEmpty($errors);
    }

    public function testMultipleOfWithRoundBoundary(): void
    {
        $errors = $this->validator->validate(10, ['multipleOf' => 5]);
        $this->assertEmpty($errors);
    }

    public function testMultipleOfRejectsNonMultiple(): void
    {
        $errors = $this->validator->validate(7, ['multipleOf' => 3]);
        $this->assertNotEmpty($errors);
    }

    public function testRequiredFieldWithNonStringKeySkipped(): void
    {
        $errors = $this->validator->validate(
            ['name' => 'test'],
            ['required' => ['name', 123]],
        );
        $this->assertEmpty($errors);
    }

    public function testRequiredFieldWithEmptyStringSkipped(): void
    {
        $errors = $this->validator->validate(
            ['name' => 'test'],
            ['required' => ['name', '']],
        );
        $this->assertEmpty($errors);
    }

    public function testPropertiesWithNonStringKeySkipped(): void
    {
        $errors = $this->validator->validate(
            ['name' => 'test'],
            ['properties' => ['name' => ['type' => 'string'], 123 => ['type' => 'integer']]],
        );
        $this->assertEmpty($errors);
    }

    public function testPropertiesContinueNotBreakWithMixedKeys(): void
    {
        $errors = $this->validator->validate(
            ['name' => 'test', 'age' => 25],
            ['properties' => [
                'name' => ['type' => 'string'],
                123 => 'not-a-schema',
                'age' => ['type' => 'integer'],
            ]],
        );
        $this->assertEmpty($errors);
    }

    public function testAdditionalPropertiesFalseWithIntegerKeysInProperties(): void
    {
        $errors = $this->validator->validate(
            (object)['name' => 'test', 'extra' => 'value'],
            [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
        );
        $this->assertNotEmpty($errors);
    }

    public function testAdditionalPropertiesWithSchemaValidatesExtra(): void
    {
        $errors = $this->validator->validate(
            ['name' => 'test', 'extra' => 'value'],
            [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'additionalProperties' => ['type' => 'string'],
            ],
        );
        $this->assertEmpty($errors);
    }

    public function testAdditionalPropertiesWithSchemaRejectsInvalidExtra(): void
    {
        $errors = $this->validator->validate(
            ['name' => 'test', 'extra' => 123],
            [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'additionalProperties' => ['type' => 'string'],
            ],
        );
        $this->assertNotEmpty($errors);
    }

    public function testUniqueItemsFalseDefault(): void
    {
        $errors = $this->validator->validate(
            [1, 1, 2],
            ['type' => 'array', 'uniqueItems' => false],
        );
        $this->assertEmpty($errors);
    }

    public function testUniqueItemsTrueRejectsDuplicates(): void
    {
        $errors = $this->validator->validate(
            [1, 1, 2],
            ['type' => 'array', 'uniqueItems' => true],
        );
        $this->assertNotEmpty($errors);
    }

    public function testValidateTypeAssumesNonIndexedForObjects(): void
    {
        $errors = $this->validator->validate(
            ['a' => 1],
            ['type' => 'object'],
        );
        $this->assertEmpty($errors);
    }

    public function testIsObjectTypeReturnsFalseForNonArray(): void
    {
        $errors = $this->validator->validate(
            'string',
            ['type' => 'object'],
        );
        $this->assertNotEmpty($errors);
    }

    public function testDateTimeValidationWithValidDateTime(): void
    {
        $errors = $this->validator->validate(
            '2024-01-15T10:30:00Z',
            ['format' => 'date-time'],
        );
        $this->assertEmpty($errors);
    }

    public function testDateTimeValidationWithInvalidTimeComponent(): void
    {
        $errors = $this->validator->validate(
            '2024-01-15Tab:30:00Z',
            ['format' => 'date-time'],
        );
        $this->assertNotEmpty($errors);
    }

    public function testDateTimeValidationWithNegativeTzOffset(): void
    {
        $errors = $this->validator->validate(
            '2024-01-15T10:30:00-05:00',
            ['format' => 'date-time'],
        );
        $this->assertEmpty($errors);
    }

    public function testDateTimeValidationWithPositiveTzOffset(): void
    {
        $errors = $this->validator->validate(
            '2024-01-15T10:30:00+05:00',
            ['format' => 'date-time'],
        );
        $this->assertEmpty($errors);
    }
}
