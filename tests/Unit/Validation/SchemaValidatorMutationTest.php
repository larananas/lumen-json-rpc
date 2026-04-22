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
}
