<?php

namespace Tests\Unit\Support;

use App\Support\Contrast;
use PHPUnit\Framework\TestCase;

class ContrastTest extends TestCase
{
    public function test_ratio_matches_known_values(): void
    {
        $this->assertEqualsWithDelta(21.0, Contrast::ratio('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, Contrast::ratio('#777777', '#777777'), 0.001);
        // The light accent token of the design system: 5.7:1 on white.
        $this->assertEqualsWithDelta(5.7, Contrast::ratio('#2f5fd0', '#ffffff'), 0.1);
    }

    public function test_the_readable_text_colour_on_any_accent_meets_aa(): void
    {
        foreach (['#38bdf8', '#22d3ee', '#ffff00', '#2f5fd0', '#000000', '#ffffff', '#ff0000', '#00ff00', '#7c3aed', '#808080'] as $accent) {
            $text = Contrast::readableOn($accent);
            $this->assertGreaterThanOrEqual(4.5, Contrast::ratio($accent, $text), "{$text} on {$accent}");
        }
    }

    public function test_an_accent_with_too_little_contrast_falls_back(): void
    {
        $this->assertNull(Contrast::textOn('#38bdf8', Contrast::SURFACE_LIGHT));  // light blue on white
        $this->assertSame('#38bdf8', Contrast::textOn('#38BDF8', Contrast::SURFACE_DARK));
        $this->assertSame('#2f5fd0', Contrast::textOn('#2f5fd0', Contrast::SURFACE_LIGHT));
        $this->assertNull(Contrast::textOn('#2f5fd0', Contrast::SURFACE_DARK));
        $this->assertNull(Contrast::textOn('red', Contrast::SURFACE_LIGHT));
        $this->assertNull(Contrast::textOn(null, Contrast::SURFACE_LIGHT));
    }
}
