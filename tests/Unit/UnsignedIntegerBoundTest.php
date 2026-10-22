<?php

namespace Tests\Unit;

use App\Domain\UnsignedIntegerBound;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class UnsignedIntegerBoundTest extends TestCase
{
    public function test_bound_matches_the_unsigned_integer_column_range(): void
    {
        $this->assertSame(4294967295, UnsignedIntegerBound::MAX);
        $this->assertTrue(UnsignedIntegerBound::contains(0));
        $this->assertTrue(UnsignedIntegerBound::contains(UnsignedIntegerBound::MAX));
        $this->assertFalse(UnsignedIntegerBound::contains(-1));
        $this->assertFalse(UnsignedIntegerBound::contains(UnsignedIntegerBound::MAX + 1));
    }

    public function test_addition_accepts_the_exact_maximum_and_rejects_overflow(): void
    {
        $this->assertSame(
            UnsignedIntegerBound::MAX,
            UnsignedIntegerBound::add(UnsignedIntegerBound::MAX - 2, 1, 2),
        );

        $this->expectException(InvalidArgumentException::class);
        UnsignedIntegerBound::add(UnsignedIntegerBound::MAX - 1, 1, 2);
    }

    public function test_addition_rejects_line_multiplication_overflow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UnsignedIntegerBound::add(0, 2, intdiv(UnsignedIntegerBound::MAX, 2) + 1);
    }

    public function test_difference_accepts_zero_through_the_maximum(): void
    {
        $this->assertSame(0, UnsignedIntegerBound::difference(UnsignedIntegerBound::MAX, UnsignedIntegerBound::MAX));
        $this->assertSame(UnsignedIntegerBound::MAX, UnsignedIntegerBound::difference(UnsignedIntegerBound::MAX, 0));

        $this->expectException(InvalidArgumentException::class);
        UnsignedIntegerBound::difference(0, 1);
    }
}
