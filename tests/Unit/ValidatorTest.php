<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Validator;

final class ValidatorTest extends TestCase
{
    public function testRequiredPassesWhenPresent(): void
    {
        $v = new Validator(['name' => 'Acme']);
        $v->required('name');
        $this->assertFalse($v->fails());
        $this->assertSame([], $v->errors());
    }

    public function testRequiredFailsWhenMissing(): void
    {
        $v = new Validator([]);
        $v->required('name');
        $this->assertTrue($v->fails());
        $this->assertSame(["Field 'name' is required."], $v->errors());
    }

    public function testRequiredFailsWhenNull(): void
    {
        $v = new Validator(['name' => null]);
        $v->required('name');
        $this->assertTrue($v->fails());
    }

    public function testRequiredFailsForEmptyAndWhitespaceStrings(): void
    {
        $v = new Validator(['a' => '', 'b' => '   ']);
        $v->required('a')->required('b');
        $this->assertTrue($v->fails());
        $this->assertCount(2, $v->errors());
    }

    public function testRequiredAllowsZeroAndFalse(): void
    {
        $v = new Validator(['qty' => 0, 'flag' => false]);
        $v->required('qty')->required('flag');
        $this->assertFalse($v->fails());
    }

    public function testStringSkipsWhenAbsentOrNull(): void
    {
        $v = new Validator(['b' => null]);
        $v->string('a')->string('b');
        $this->assertFalse($v->fails());
        $this->assertSame([], $v->validated());
    }

    public function testStringTrimsAndKeepsValue(): void
    {
        $v = new Validator(['name' => '  Acme  ']);
        $v->string('name');
        $this->assertFalse($v->fails());
        $this->assertSame(['name' => 'Acme'], $v->validated());
    }

    public function testStringEmptyAfterTrimBecomesNull(): void
    {
        $v = new Validator(['name' => '   ']);
        $v->string('name');
        $this->assertFalse($v->fails());
        $this->assertSame(['name' => null], $v->validated());
    }

    public function testStringRejectsNonScalar(): void
    {
        $v = new Validator(['name' => ['x']]);
        $v->string('name');
        $this->assertTrue($v->fails());
        $this->assertSame(["Field 'name' must be a string."], $v->errors());
    }

    public function testStringEnforcesMaxLength(): void
    {
        $v = new Validator(['name' => str_repeat('a', 6)]);
        $v->string('name', 5);
        $this->assertTrue($v->fails());
        $this->assertSame(["Field 'name' must be at most 5 characters."], $v->errors());
    }

    public function testStringMaxLengthUsesMultibyteCount(): void
    {
        // 3 multibyte chars should count as 3, not their byte length.
        $v = new Validator(['name' => 'ünç']);
        $v->string('name', 3);
        $this->assertFalse($v->fails());
        $this->assertSame(['name' => 'ünç'], $v->validated());
    }

    public function testStringCastsScalarToString(): void
    {
        $v = new Validator(['name' => 123]);
        $v->string('name');
        $this->assertFalse($v->fails());
        $this->assertSame(['name' => '123'], $v->validated());
    }

    public function testIntegerSkipsWhenAbsentOrNull(): void
    {
        $v = new Validator(['b' => null]);
        $v->integer('a')->integer('b');
        $this->assertFalse($v->fails());
        $this->assertSame([], $v->validated());
    }

    public function testIntegerAcceptsNumericStringAndCasts(): void
    {
        $v = new Validator(['qty' => '42']);
        $v->integer('qty');
        $this->assertFalse($v->fails());
        $this->assertSame(['qty' => 42], $v->validated());
    }

    public function testIntegerRejectsNonNumeric(): void
    {
        $v = new Validator(['qty' => 'abc']);
        $v->integer('qty');
        $this->assertTrue($v->fails());
        $this->assertSame(["Field 'qty' must be an integer."], $v->errors());
    }

    public function testIntegerRejectsFloatWithFraction(): void
    {
        $v = new Validator(['qty' => 1.5]);
        $v->integer('qty');
        $this->assertTrue($v->fails());
        $this->assertSame(["Field 'qty' must be an integer."], $v->errors());
    }

    public function testIntegerRejectsBelowMinimum(): void
    {
        $v = new Validator(['qty' => -1]);
        $v->integer('qty');
        $this->assertTrue($v->fails());
        $this->assertSame(["Field 'qty' must be >= 0."], $v->errors());
    }

    public function testIntegerHonoursCustomMinimum(): void
    {
        $v = new Validator(['qty' => 5]);
        $v->integer('qty', 10);
        $this->assertTrue($v->fails());
        $this->assertSame(["Field 'qty' must be >= 10."], $v->errors());
    }

    public function testDateSkipsWhenAbsentNullOrEmpty(): void
    {
        $v = new Validator(['b' => null, 'c' => '']);
        $v->date('a')->date('b')->date('c');
        $this->assertFalse($v->fails());
        $this->assertSame([], $v->validated());
    }

    public function testDateAcceptsIsoDate(): void
    {
        $v = new Validator(['order_date' => '2026-07-04']);
        $v->date('order_date');
        $this->assertFalse($v->fails());
        $this->assertSame(['order_date' => '2026-07-04'], $v->validated());
    }

    public function testDateRejectsWrongFormat(): void
    {
        $v = new Validator(['order_date' => '07/04/2026']);
        $v->date('order_date');
        $this->assertTrue($v->fails());
        $this->assertSame(["Field 'order_date' must be a valid date (YYYY-MM-DD)."], $v->errors());
    }

    public function testDateRejectsImpossibleCalendarDate(): void
    {
        $v = new Validator(['order_date' => '2026-02-30']);
        $v->date('order_date');
        $this->assertTrue($v->fails());
    }

    /**
     * @dataProvider booleanProvider
     */
    public function testBooleanCoercesToZeroOrOne(mixed $input, int $expected): void
    {
        $v = new Validator(['flag' => $input]);
        $v->boolean('flag');
        $this->assertFalse($v->fails());
        $this->assertSame(['flag' => $expected], $v->validated());
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function booleanProvider(): array
    {
        return [
            'bool true'     => [true, 1],
            'bool false'    => [false, 0],
            'string true'   => ['true', 1],
            'string yes'    => ['yes', 1],
            'string on'     => ['on', 1],
            'int one'       => [1, 1],
            'string false'  => ['false', 0],
            'string no'     => ['no', 0],
            'int zero'      => [0, 0],
            'garbage'       => ['banana', 0],
        ];
    }

    public function testBooleanSkipsWhenAbsentOrNull(): void
    {
        $v = new Validator(['b' => null]);
        $v->boolean('a')->boolean('b');
        $this->assertFalse($v->fails());
        $this->assertSame([], $v->validated());
    }

    public function testValidatedMergesDefaultsUnderCleanValues(): void
    {
        $v = new Validator(['name' => 'Acme', 'qty' => '7']);
        $v->string('name')->integer('qty');
        $result = $v->validated(['name' => 'fallback', 'is_sample' => 0]);
        $this->assertSame(
            ['name' => 'Acme', 'is_sample' => 0, 'qty' => 7],
            $result
        );
    }

    public function testFluentChainingCollectsMultipleErrors(): void
    {
        $v = new Validator(['qty' => 'x', 'order_date' => 'nope']);
        $v->required('name')->integer('qty')->date('order_date');
        $this->assertTrue($v->fails());
        $this->assertCount(3, $v->errors());
    }
}
