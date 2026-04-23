<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Validation;

use Lumen\JsonRpc\Validation\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class SchemaValidatorMutationTest extends TestCase
{
    private SchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SchemaValidator();
    }

    public function testDateTimeWithFractionalSecondsIsValid(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $this->assertEmpty($this->validator->validate('2024-06-15T12:30:45.123Z', $schema));
        $this->assertEmpty($this->validator->validate('2024-06-15T12:30:45.999999Z', $schema));
        $this->assertEmpty($this->validator->validate('2024-06-15T12:30:45.1+02:00', $schema));
    }

    public function testDateTimeFractionalSecondsAreTruncatedForTimeValidation(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $this->assertEmpty($this->validator->validate('2024-06-15T23:59:59.999Z', $schema));
        $this->assertEmpty($this->validator->validate('2024-06-15T00:00:00.000Z', $schema));
    }

    public function testDateTimeInvalidHourWithFractionalSeconds(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $errors = $this->validator->validate('2024-06-15T24:00:00.000Z', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testDateTimeInvalidMinuteWithFractionalSeconds(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $errors = $this->validator->validate('2024-06-15T12:60:00.000Z', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testDateTimeInvalidSecondWithFractionalSeconds(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $errors = $this->validator->validate('2024-06-15T12:30:60.000Z', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testTimeWithFractionalSecondsIsValid(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertEmpty($this->validator->validate('12:30:45.123Z', $schema));
        $this->assertEmpty($this->validator->validate('12:30:45.999', $schema));
    }

    public function testTimeFractionalSecondsTruncatedForValidation(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertEmpty($this->validator->validate('23:59:59.999', $schema));
        $this->assertEmpty($this->validator->validate('00:00:00.000Z', $schema));
    }

    public function testTimeInvalidHourWithFractionalSeconds(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $errors = $this->validator->validate('24:00:00.000Z', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testTimeInvalidMinuteWithFractionalSeconds(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $errors = $this->validator->validate('12:60:00.000', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testTimeInvalidSecondWithFractionalSeconds(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $errors = $this->validator->validate('12:30:60.000Z', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testTimeComponentsExtractedFromCorrectIndices(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertEmpty($this->validator->validate('01:02:03', $schema));
        $errors = $this->validator->validate('24:00:03', $schema);
        $this->assertNotEmpty($errors);
        $errors = $this->validator->validate('01:60:03', $schema);
        $this->assertNotEmpty($errors);
        $errors = $this->validator->validate('01:02:60', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testDateTimeComponentsExtractedFromCorrectIndices(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $this->assertEmpty($this->validator->validate('2024-01-15T01:02:03Z', $schema));
        $errors = $this->validator->validate('2024-01-15T24:00:03Z', $schema);
        $this->assertNotEmpty($errors);
        $errors = $this->validator->validate('2024-01-15T01:60:03Z', $schema);
        $this->assertNotEmpty($errors);
        $errors = $this->validator->validate('2024-01-15T01:02:60Z', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testDateBoundaryDayOneIsValid(): void
    {
        $schema = ['type' => 'string', 'format' => 'date'];
        $this->assertEmpty($this->validator->validate('2024-06-01', $schema));
    }

    public function testDateBoundaryDayZeroIsInvalid(): void
    {
        $schema = ['type' => 'string', 'format' => 'date'];
        $errors = $this->validator->validate('2024-06-00', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testDateBoundaryMonthOneIsValid(): void
    {
        $schema = ['type' => 'string', 'format' => 'date'];
        $this->assertEmpty($this->validator->validate('2024-01-15', $schema));
    }

    public function testDateBoundaryMonthTwelveIsValid(): void
    {
        $schema = ['type' => 'string', 'format' => 'date'];
        $this->assertEmpty($this->validator->validate('2024-12-15', $schema));
    }

    public function testDateBoundaryDayThirtyOneIsValid(): void
    {
        $schema = ['type' => 'string', 'format' => 'date'];
        $this->assertEmpty($this->validator->validate('2024-01-31', $schema));
    }

    public function testDateBoundaryDayThirtyTwoIsInvalid(): void
    {
        $schema = ['type' => 'string', 'format' => 'date'];
        $errors = $this->validator->validate('2024-01-32', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testDateTimeBoundaryMonthZeroIsInvalid(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $errors = $this->validator->validate('2024-00-15T12:00:00Z', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testDateTimeBoundaryMonthThirteenIsInvalid(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $errors = $this->validator->validate('2024-13-15T12:00:00Z', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testDateTimeBoundaryDayZeroIsInvalid(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $errors = $this->validator->validate('2024-06-00T12:00:00Z', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testDateTimeWithPositiveTimezone(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $this->assertEmpty($this->validator->validate('2024-06-15T12:30:00+05:30', $schema));
    }

    public function testDateTimeWithNegativeTimezone(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $this->assertEmpty($this->validator->validate('2024-06-15T12:30:00-08:00', $schema));
    }

    public function testTimeWithPositiveTimezone(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertEmpty($this->validator->validate('12:30:00+02:00', $schema));
    }

    public function testTimeWithNegativeTimezone(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertEmpty($this->validator->validate('12:30:00-05:00', $schema));
    }

    public function testTimeExactBoundaryValues(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertEmpty($this->validator->validate('00:00:00', $schema));
        $this->assertEmpty($this->validator->validate('23:59:59', $schema));
        $this->assertEmpty($this->validator->validate('00:00:00Z', $schema));
        $this->assertEmpty($this->validator->validate('23:59:59Z', $schema));
    }

    public function testDateTimeExactBoundaryValues(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $this->assertEmpty($this->validator->validate('2024-01-01T00:00:00Z', $schema));
        $this->assertEmpty($this->validator->validate('2024-12-31T23:59:59Z', $schema));
    }

    public function testIntCastOnTimeComponents(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $errors = $this->validator->validate('12:59:59', $schema);
        $this->assertEmpty($errors);
        $errors = $this->validator->validate('12:59:60', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testIntCastOnDateTimeComponents(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $errors = $this->validator->validate('2024-06-15T12:59:59Z', $schema);
        $this->assertEmpty($errors);
        $errors = $this->validator->validate('2024-06-15T12:59:60Z', $schema);
        $this->assertNotEmpty($errors);
    }

    public function testTimeStrictNumericHourRejectsNonDigits(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertNotEmpty($this->validator->validate('08abc:10:10', $schema));
        $this->assertNotEmpty($this->validator->validate("08\n:10:10", $schema));
        $this->assertNotEmpty($this->validator->validate('08 :10:10', $schema));
    }

    public function testTimeStrictNumericSecondRejectsTrailingGarbage(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertNotEmpty($this->validator->validate('08:10:10abc', $schema));
    }

    public function testTimeBoundaryZeroZeroZero(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertEmpty($this->validator->validate('00:00:00', $schema));
    }

    public function testTimeBoundaryMaxValid(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertEmpty($this->validator->validate('23:59:59', $schema));
    }

    public function testTimeRejectsSingleDigitHour(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertNotEmpty($this->validator->validate('8:10:10', $schema));
    }

    public function testTimeRejectsMissingSeconds(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertNotEmpty($this->validator->validate('08:10', $schema));
    }

    public function testTimeRejectsExtraSegment(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertNotEmpty($this->validator->validate('08:10:10:12', $schema));
    }

    public function testTimeEachComponentEnforcesIntegerCast(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertEmpty($this->validator->validate('00:00:00', $schema));
        $this->assertEmpty($this->validator->validate('01:00:00', $schema));
        $this->assertEmpty($this->validator->validate('00:01:00', $schema));
        $this->assertEmpty($this->validator->validate('00:00:01', $schema));
        $this->assertEmpty($this->validator->validate('23:00:00', $schema));
        $this->assertEmpty($this->validator->validate('00:59:00', $schema));
        $this->assertEmpty($this->validator->validate('00:00:59', $schema));
    }

    public function testDateTimeEachComponentEnforcesIntegerCast(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $this->assertEmpty($this->validator->validate('2024-01-01T00:00:00Z', $schema));
        $this->assertEmpty($this->validator->validate('2024-01-01T23:00:00Z', $schema));
        $this->assertEmpty($this->validator->validate('2024-01-01T00:59:00Z', $schema));
        $this->assertEmpty($this->validator->validate('2024-01-01T00:00:59Z', $schema));
    }

    public function testTimeHourOverflowDetected(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertNotEmpty($this->validator->validate('24:00:00', $schema));
    }

    public function testTimeMinuteOverflowDetected(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertNotEmpty($this->validator->validate('00:60:00', $schema));
    }

    public function testTimeSecondOverflowDetected(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertNotEmpty($this->validator->validate('00:00:60', $schema));
    }

    public function testTimeValidWithTimezoneZ(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertEmpty($this->validator->validate('12:30:45Z', $schema));
    }

    public function testTimeValidWithOffsetTimezone(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertEmpty($this->validator->validate('12:30:45+02:00', $schema));
        $this->assertEmpty($this->validator->validate('12:30:45-05:00', $schema));
    }

    public function testTimeValidWithFractionalSeconds(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertEmpty($this->validator->validate('12:30:45.123Z', $schema));
        $this->assertEmpty($this->validator->validate('12:30:45.999', $schema));
    }

    public function testDateTimeRejectsNonNumericTimeComponents(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $this->assertNotEmpty($this->validator->validate('2024-06-15T08abc:10:10Z', $schema));
        $this->assertNotEmpty($this->validator->validate('2024-06-15T08:10:10abcZ', $schema));
    }

    public function testMinLengthExactBoundaryIsAccepted(): void
    {
        $schema = ['type' => 'string', 'minLength' => 3];
        $this->assertEmpty($this->validator->validate('abc', $schema));
        $this->assertNotEmpty($this->validator->validate('ab', $schema));
    }

    public function testMaxLengthExactBoundaryIsAccepted(): void
    {
        $schema = ['type' => 'string', 'maxLength' => 3];
        $this->assertEmpty($this->validator->validate('abc', $schema));
        $this->assertNotEmpty($this->validator->validate('abcd', $schema));
    }

    public function testMaxItemsExactBoundaryIsAccepted(): void
    {
        $schema = ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'string']];
        $this->assertEmpty($this->validator->validate(['a', 'b'], $schema));
        $this->assertNotEmpty($this->validator->validate(['a', 'b', 'c'], $schema));
    }

    public function testEnumErrorUsesVarExportFormat(): void
    {
        $schema = ['type' => 'string', 'enum' => ['foo', 'bar']];
        $errors = $this->validator->validate('baz', $schema);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("'foo'", $errors[0]);
        $this->assertStringContainsString("'bar'", $errors[0]);
    }

    public function testValidateAccumulatesAllErrors(): void
    {
        $schema = ['type' => 'string', 'minLength' => 10, 'maxLength' => 2];
        $errors = $this->validator->validate('hello', $schema);
        $this->assertGreaterThanOrEqual(2, count($errors));
    }

    public function testMultipleOfRequiresRoundNotFloor(): void
    {
        $schema = ['type' => 'number', 'multipleOf' => 0.1];
        $this->assertEmpty($this->validator->validate(0.3, $schema));
        $this->assertEmpty($this->validator->validate(0.7, $schema));
        $this->assertNotEmpty($this->validator->validate(0.35, $schema));
    }

    public function testUniqueItemsBreaksOnFirstDuplicate(): void
    {
        $schema = ['type' => 'array', 'uniqueItems' => true];
        $errors = $this->validator->validate(['a', 'b', 'a', 'b'], $schema);
        $this->assertNotEmpty($errors);
        $this->assertCount(1, $errors);
    }

    public function testTypeValidationInteger(): void
    {
        $schema = ['type' => 'integer'];
        $this->assertEmpty($this->validator->validate(42, $schema));
        $this->assertNotEmpty($this->validator->validate('42', $schema));
    }

    public function testTypeValidationIntAlias(): void
    {
        $schema = ['type' => 'int'];
        $this->assertEmpty($this->validator->validate(42, $schema));
        $this->assertNotEmpty($this->validator->validate('42', $schema));
    }

    public function testTypeValidationNumber(): void
    {
        $schema = ['type' => 'number'];
        $this->assertEmpty($this->validator->validate(42, $schema));
        $this->assertEmpty($this->validator->validate(3.14, $schema));
        $this->assertNotEmpty($this->validator->validate('42', $schema));
    }

    public function testTypeValidationBoolean(): void
    {
        $schema = ['type' => 'boolean'];
        $this->assertEmpty($this->validator->validate(true, $schema));
        $this->assertEmpty($this->validator->validate(false, $schema));
        $this->assertNotEmpty($this->validator->validate(1, $schema));
    }

    public function testTypeValidationBoolAlias(): void
    {
        $schema = ['type' => 'bool'];
        $this->assertEmpty($this->validator->validate(true, $schema));
        $this->assertNotEmpty($this->validator->validate(1, $schema));
    }

    public function testTypeValidationArray(): void
    {
        $schema = ['type' => 'array'];
        $this->assertEmpty($this->validator->validate([1, 2], $schema));
        $this->assertNotEmpty($this->validator->validate('not_array', $schema));
    }

    public function testTypeValidationObject(): void
    {
        $schema = ['type' => 'object'];
        $this->assertEmpty($this->validator->validate(['a' => 1], $schema));
        $this->assertNotEmpty($this->validator->validate([1, 2], $schema));
    }

    public function testTypeValidationNull(): void
    {
        $schema = ['type' => 'null'];
        $this->assertEmpty($this->validator->validate(null, $schema));
        $this->assertNotEmpty($this->validator->validate(0, $schema));
    }

    public function testObjectValidationRequiresAllErrors(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['a', 'b'],
        ];
        $errors = $this->validator->validate(['x' => 1], $schema);
        $this->assertGreaterThanOrEqual(2, count($errors));
    }

    public function testMinimumBoundaryIsAccepted(): void
    {
        $schema = ['type' => 'number', 'minimum' => 5];
        $this->assertEmpty($this->validator->validate(5, $schema));
        $this->assertEmpty($this->validator->validate(6, $schema));
        $this->assertNotEmpty($this->validator->validate(4, $schema));
    }

    public function testMaximumBoundaryIsAccepted(): void
    {
        $schema = ['type' => 'number', 'maximum' => 5];
        $this->assertEmpty($this->validator->validate(5, $schema));
        $this->assertEmpty($this->validator->validate(4, $schema));
        $this->assertNotEmpty($this->validator->validate(6, $schema));
    }

    public function testDefaultTypeAcceptsAnything(): void
    {
        $schema = ['type' => 'unknown_type'];
        $this->assertEmpty($this->validator->validate('anything', $schema));
    }

    public function testValidatePreservesTypeErrorsBeforeObject(): void
    {
        $schema = ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'integer']]];
        $errors = $this->validator->validate(['name' => 'not_int'], $schema);
        $this->assertNotEmpty($errors);
    }

    public function testValidatePreservesTypeErrorsBeforeArray(): void
    {
        $schema = ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 1];
        $errors = $this->validator->validate([1, 2], $schema);
        $this->assertGreaterThanOrEqual(2, count($errors));
    }

    public function testValidatePreservesFormatErrors(): void
    {
        $schema = ['type' => 'string', 'format' => 'email', 'minLength' => 100];
        $errors = $this->validator->validate('not-email', $schema);
        $this->assertGreaterThanOrEqual(2, count($errors));
    }

    public function testValidateArrayReturnsMultipleErrors(): void
    {
        $schema = ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 1, 'minItems' => 5];
        $errors = $this->validator->validate([1, 2, 3], $schema);
        $this->assertGreaterThanOrEqual(2, count($errors));
    }

    public function testValidateDateTimeReturnsMultipleErrors(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $errors = $this->validator->validate('2024-13-32T25:60:60Z', $schema);
        $this->assertGreaterThanOrEqual(2, count($errors));
    }

    public function testRequiredSkipsNonStringFields(): void
    {
        $schema = ['type' => 'object', 'required' => [123, 'name']];
        $errors = $this->validator->validate(['x' => 1], $schema);
        $this->assertNotEmpty($errors);
        $hasNameError = false;
        foreach ($errors as $err) {
            if (str_contains($err, 'name')) {
                $hasNameError = true;
                break;
            }
        }
        $this->assertTrue($hasNameError);
    }

    public function testPropertiesSkipsInvalidEntries(): void
    {
        $schema = ['type' => 'object', 'properties' => [123 => ['type' => 'string'], 'name' => ['type' => 'string']]];
        $errors = $this->validator->validate(['name' => 'test'], $schema);
        $this->assertEmpty($errors);
    }

    public function testAdditionalPropertiesWithMixedKeys(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'additionalProperties' => false,
        ];
        $this->assertEmpty($this->validator->validate(['name' => 'test'], $schema));
        $this->assertNotEmpty($this->validator->validate(['name' => 'test', 'extra' => 1], $schema));
    }

    public function testAnyOfBreaksOnFirstMatch(): void
    {
        $schema = ['anyOf' => [['type' => 'string'], ['type' => 'integer']]];
        $errors = $this->validator->validate('hello', $schema);
        $this->assertEmpty($errors);
    }

    public function testUniqueItemsDetectsDuplicateJsonSerialization(): void
    {
        $schema = ['type' => 'array', 'uniqueItems' => true];
        $errors = $this->validator->validate([['a'], ['a']], $schema);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('unique', $errors[0]);
    }

    public function testUniqueItemsAcceptsDistinctItems(): void
    {
        $schema = ['type' => 'array', 'uniqueItems' => true];
        $this->assertEmpty($this->validator->validate([['a'], ['b']], $schema));
    }

    public function testAllOfDepthBoundary(): void
    {
        $schema = ['allOf' => [['type' => 'string']]];
        $errors = $this->validator->validate('test', $schema, 31);
        $this->assertEmpty($errors);
        $errors = $this->validator->validate('test', $schema, 32);
        $this->assertNotEmpty($errors);
    }

    public function testAnyOfDepthBoundary(): void
    {
        $schema = ['anyOf' => [['type' => 'string']]];
        $this->assertEmpty($this->validator->validate('test', $schema, 31));
        $this->assertNotEmpty($this->validator->validate('test', $schema, 32));
    }

    public function testOneOfDepthBoundary(): void
    {
        $schema = ['oneOf' => [['type' => 'string']]];
        $this->assertEmpty($this->validator->validate('test', $schema, 31));
        $this->assertNotEmpty($this->validator->validate('test', $schema, 32));
    }

    public function testNotDepthBoundary(): void
    {
        $schema = ['not' => ['type' => 'string']];
        $errors31 = $this->validator->validate('test', $schema, 31);
        $this->assertNotEmpty($errors31);
        $errors32 = $this->validator->validate('test', $schema, 32);
        $this->assertEmpty($errors32);
    }

    public function testPropertiesDepthBoundary(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]];
        $this->assertEmpty($this->validator->validate(['a' => 'x'], $schema, 31));
        $this->assertNotEmpty($this->validator->validate(['a' => 'x'], $schema, 32));
    }

    public function testAdditionalPropertiesDepthBoundary(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'string']],
            'additionalProperties' => ['type' => 'string'],
        ];
        $this->assertEmpty($this->validator->validate(['a' => 'x', 'b' => 'y'], $schema, 31));
        $this->assertNotEmpty($this->validator->validate(['a' => 'x', 'b' => 'y'], $schema, 32));
    }

    public function testItemsDepthBoundary(): void
    {
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];
        $this->assertEmpty($this->validator->validate(['a'], $schema, 31));
        $this->assertNotEmpty($this->validator->validate(['a'], $schema, 32));
    }

    public function testDepthDefaultBoundary(): void
    {
        $inner = ['type' => 'string'];
        for ($i = 0; $i < 33; $i++) {
            $inner = ['allOf' => [$inner]];
        }
        $errors = $this->validator->validate('test', $inner);
        $this->assertNotEmpty($errors);
    }

    public function testDepthGreaterThanOrEquals(): void
    {
        $inner = ['type' => 'string'];
        for ($i = 0; $i < 31; $i++) {
            $inner = ['allOf' => [$inner]];
        }
        $errors = $this->validator->validate('test', $inner);
        $this->assertEmpty($errors);
    }

    public function testObjectWithIndexedArrayRejectsAdditional(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'additionalProperties' => false,
        ];
        $errors = $this->validator->validate(['name' => 'valid', 0 => 'extra'], $schema);
        $this->assertNotEmpty($errors);
    }

    public function testNotSchemaRejectsMatch(): void
    {
        $schema = ['not' => ['type' => 'string']];
        $this->assertNotEmpty($this->validator->validate('hello', $schema));
        $this->assertEmpty($this->validator->validate(42, $schema));
    }

    public function testTimeWithNegativeTimezoneStripsCorrectly(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertEmpty($this->validator->validate('12:30:45-05:00', $schema));
    }

    public function testTimeWithPositiveTimezoneStripsCorrectly(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertEmpty($this->validator->validate('12:30:45+02:00', $schema));
    }

    public function testTimeFractionalSecondsStrippedCorrectly(): void
    {
        $schema = ['type' => 'string', 'format' => 'time'];
        $this->assertEmpty($this->validator->validate('23:59:59.999Z', $schema));
        $this->assertEmpty($this->validator->validate('00:00:00.001', $schema));
    }

    public function testDateTimeNegativeTimezoneStripsCorrectly(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $this->assertEmpty($this->validator->validate('2024-06-15T12:30:45-05:00', $schema));
    }

    public function testDateTimeFractionalSecondsStrippedCorrectly(): void
    {
        $schema = ['type' => 'string', 'format' => 'date-time'];
        $this->assertEmpty($this->validator->validate('2024-06-15T23:59:59.999Z', $schema));
    }

    public function testFalseValueOnDepthParameter(): void
    {
        $this->assertEmpty($this->validator->validate('test', ['type' => 'string'], 0));
    }

    public function testMultipleOfWithIntegerValue(): void
    {
        $schema = ['type' => 'integer', 'multipleOf' => 3];
        $this->assertEmpty($this->validator->validate(9, $schema));
        $this->assertNotEmpty($this->validator->validate(10, $schema));
    }

    public function testMinimumWithFloatValue(): void
    {
        $schema = ['type' => 'number', 'minimum' => 1.5];
        $this->assertEmpty($this->validator->validate(1.5, $schema));
        $this->assertNotEmpty($this->validator->validate(1.4, $schema));
    }

    public function testMaximumWithFloatValue(): void
    {
        $schema = ['type' => 'number', 'maximum' => 10.5];
        $this->assertEmpty($this->validator->validate(10.5, $schema));
        $this->assertNotEmpty($this->validator->validate(10.6, $schema));
    }

    public function testExclusiveMinimumBoundary(): void
    {
        $schema = ['type' => 'number', 'exclusiveMinimum' => 5];
        $this->assertNotEmpty($this->validator->validate(5, $schema));
        $this->assertEmpty($this->validator->validate(6, $schema));
    }

    public function testExclusiveMaximumBoundary(): void
    {
        $schema = ['type' => 'number', 'exclusiveMaximum' => 10];
        $this->assertNotEmpty($this->validator->validate(10, $schema));
        $this->assertEmpty($this->validator->validate(9, $schema));
    }

    public function testMinItemsExactBoundary(): void
    {
        $schema = ['type' => 'array', 'minItems' => 2, 'items' => ['type' => 'string']];
        $this->assertEmpty($this->validator->validate(['a', 'b'], $schema));
        $this->assertNotEmpty($this->validator->validate(['a'], $schema));
    }
}
