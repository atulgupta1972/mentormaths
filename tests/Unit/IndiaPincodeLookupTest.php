<?php

namespace Tests\Unit;

use App\Services\IndiaPincodeLookup;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IndiaPincodeLookupTest extends TestCase
{
    #[Test]
    public function it_maps_post_office_state_and_district(): void
    {
        Http::fake([
            'api.postalpincode.in/*' => Http::response([
                [
                    'Status' => 'Success',
                    'PostOffice' => [
                        [
                            'Name' => 'Gurgaon Sector 14',
                            'District' => 'Gurgaon',
                            'State' => 'Haryana',
                            'Block' => 'Gurgaon',
                        ],
                    ],
                ],
            ]),
        ]);

        $result = (new IndiaPincodeLookup)->lookup('122001');

        $this->assertTrue($result['ok']);
        $this->assertSame('Haryana', $result['state']);
        $this->assertSame('Gurgaon', $result['city']);
    }

    #[Test]
    public function it_rejects_short_pin(): void
    {
        $result = (new IndiaPincodeLookup)->lookup('1220');

        $this->assertFalse($result['ok']);
    }
}
