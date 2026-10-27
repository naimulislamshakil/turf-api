<?php

namespace Tests\Unit;

use App\Services\OwnerTemporaryPasswordGenerator;
use PHPUnit\Framework\TestCase;

class OwnerTemporaryPasswordGeneratorTest extends TestCase
{
    public function test_generated_passwords_are_exactly_eight_digits(): void
    {
        $generator = new OwnerTemporaryPasswordGenerator();

        foreach (range(1, 100) as $_) {
            $password = $generator->generate();
            $this->assertMatchesRegularExpression('/^[0-9]{8}$/', $password);
            $this->assertSame(8, strlen($password));
        }
    }

    public function test_leading_zeroes_are_preserved(): void
    {
        $this->assertSame('00000007', (new OwnerTemporaryPasswordGenerator())->format(7));
    }

    public function test_values_outside_the_eight_digit_range_are_rejected(): void
    {
        $generator = new OwnerTemporaryPasswordGenerator();

        $this->expectException(\InvalidArgumentException::class);
        $generator->format(100000000);
    }
}
