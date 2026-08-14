<?php

namespace Tests\Unit;

use App\Support\GenderFromFirstName;
use PHPUnit\Framework\TestCase;

class GenderFromFirstNameTest extends TestCase
{
    public function test_infers_common_kenyan_and_english_names(): void
    {
        $inferrer = new GenderFromFirstName;

        $this->assertSame('Female', $inferrer->infer('Faith Wambui'));
        $this->assertSame('Female', $inferrer->infer('Wambui Nganga'));
        $this->assertSame('Female', $inferrer->infer('Carolyne Kerubo'));
        $this->assertSame('Male', $inferrer->infer('Brian Otieno'));
        $this->assertSame('Male', $inferrer->infer('samuel mutisya'));
        $this->assertSame('Male', $inferrer->infer('Collins Oruma'));
        $this->assertSame('Female', $inferrer->infer('Mrs. Jane Doe'));
    }

    public function test_returns_null_for_unknown_or_garbage(): void
    {
        $inferrer = new GenderFromFirstName;

        $this->assertNull($inferrer->infer('Transformation Team'));
        $this->assertNull($inferrer->infer('Director HR'));
        $this->assertNull($inferrer->infer(''));
    }
}
