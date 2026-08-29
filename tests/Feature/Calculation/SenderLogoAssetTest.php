<?php

namespace Tests\Feature\Calculation;

use Tests\TestCase;

class SenderLogoAssetTest extends TestCase
{
    /**
     * @return array{width: int, height: int, visible: int, transparent: int, dark: int}
     */
    private function analyzePng(string $path): array
    {
        $image = imagecreatefrompng($path);
        $this->assertNotFalse($image, "Invalid PNG: {$path}");

        $width = imagesx($image);
        $height = imagesy($image);
        $visible = 0;
        $transparent = 0;
        $dark = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($image, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;

                if ($alpha >= 127) {
                    $transparent++;

                    continue;
                }

                $visible++;
                $red = ($rgba >> 16) & 0xFF;
                $green = ($rgba >> 8) & 0xFF;
                $blue = $rgba & 0xFF;

                if ($red < 64 && $green < 64 && $blue < 64) {
                    $dark++;
                }
            }
        }

        return compact('width', 'height', 'visible', 'transparent', 'dark');
    }

    public function test_sender_logos_sind_gueltige_pngs_mit_transparenz(): void
    {
        foreach ([
            'radio-hamburg.png',
            '80er-90er-oldie-antenne-hamburg.png',
        ] as $file) {
            $path = public_path("images/senders/{$file}");
            $this->assertFileExists($path);

            $stats = $this->analyzePng($path);

            $this->assertGreaterThan(0, $stats['width']);
            $this->assertGreaterThan(0, $stats['height']);
            $this->assertGreaterThan(0, $stats['visible'], "{$file} has no visible pixels");
            $this->assertGreaterThan(0, $stats['transparent'], "{$file} has no transparent pixels");
        }
    }

    public function test_oldie_logo_hat_dunkle_pixel_und_quadratisches_seitenverhaeltnis(): void
    {
        $path = public_path('images/senders/80er-90er-oldie-antenne-hamburg.png');
        $stats = $this->analyzePng($path);

        $this->assertGreaterThan(
            100,
            $stats['dark'],
            'OLDIE logo should retain visible dark artwork pixels',
        );

        $aspect = $stats['width'] / $stats['height'];

        $this->assertGreaterThan(
            0.75,
            $aspect,
            'OLDIE logo must not be cropped to a narrow horizontal format',
        );
        $this->assertLessThan(
            1.35,
            $aspect,
            'OLDIE logo must remain roughly square or tall',
        );
    }
}
