<?php

namespace Pterodactyl\Tests\Unit\Services\Resellers;

use PHPUnit\Framework\TestCase;
use Pterodactyl\Services\Resellers\ColorRampGenerator;

class ColorRampGeneratorTest extends TestCase
{
    private ColorRampGenerator $generator;

    public function setUp(): void
    {
        parent::setUp();

        $this->generator = new ColorRampGenerator();
    }

    public function testRampContainsEveryStop(): void
    {
        $ramp = $this->generator->ramp('#a855f7');

        $this->assertSame(
            [50, 100, 200, 300, 400, 500, 600, 700, 800, 900],
            array_keys($ramp)
        );
    }

    /**
     * The 500 stop is the base color untouched — that's what makes a reseller
     * who picks the stock purple get the stock look at the shade that matters.
     */
    public function testFiveHundredStopIsTheBaseColourExactly(): void
    {
        $this->assertSame('168 85 247', $this->generator->ramp('#a855f7')[500]);
        $this->assertSame('255 138 61', $this->generator->ramp('#ff8a3d')[500]);
    }

    public function testRampGetsMonotonicallyDarker(): void
    {
        $ramp = $this->generator->ramp('#a855f7');

        $previous = null;
        foreach ($ramp as $stop => $triplet) {
            $luminance = array_sum(array_map('intval', explode(' ', $triplet)));

            if (!is_null($previous)) {
                $this->assertLessThan($previous, $luminance, "Stop $stop is not darker than the one before it.");
            }

            $previous = $luminance;
        }
    }

    public function testRampStaysWithinChannelBounds(): void
    {
        foreach (['#000000', '#ffffff', '#a855f7'] as $hex) {
            foreach ($this->generator->ramp($hex) as $stop => $triplet) {
                foreach (explode(' ', $triplet) as $channel) {
                    $this->assertGreaterThanOrEqual(0, (int) $channel, "$hex stop $stop underflowed.");
                    $this->assertLessThanOrEqual(255, (int) $channel, "$hex stop $stop overflowed.");
                }
            }
        }
    }

    public function testTripletsAreThreeIntegers(): void
    {
        foreach ($this->generator->ramp('#ff8a3d') as $triplet) {
            $this->assertMatchesRegularExpression('/^\d{1,3} \d{1,3} \d{1,3}$/', $triplet);
        }
    }

    /**
     * @dataProvider hexProvider
     */
    public function testNormalizeHex(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, $this->generator->normalizeHex($input));
    }

    public static function hexProvider(): array
    {
        return [
            'null passes through' => [null, null],
            'lowercases' => ['#A855F7', '#a855f7'],
            'adds the missing hash' => ['a855f7', '#a855f7'],
            'expands shorthand' => ['#a5f', '#aa55ff'],
            'trims whitespace' => ['  #a855f7 ', '#a855f7'],
            'rejects a non-colour' => ['rgb(1,2,3)', null],
            'rejects a bad length' => ['#a855f', null],
            'rejects non-hex characters' => ['#zzzzzz', null],
        ];
    }
}
