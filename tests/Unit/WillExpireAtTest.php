<?php

namespace Tests\Unit;

use Tests\TestCase;
use DTApi\Helpers\TeHelper;

class WillExpireAtTest extends TestCase
{
    /**
     * Test willExpireAt method with different scenarios.
     *
     * @dataProvider expirationDataProvider
     */
    public function testWillExpireAt($due_time, $created_at, $expected)
    {
        $result = TeHelper::willExpireAt($due_time, $created_at);
        $this->assertEquals($expected, $result);
    }

    /**
     * Data provider for expiration scenarios.
     *
     * @return array
     */
    public function expirationDataProvider()
    {
        return [
            // Scenario 1: Difference <= 90 minutes
            [
                '2024-01-01 12:00:00',
                '2024-01-01 10:00:00',
                '2024-01-01 12:00:00', // due time is returned
            ],
            // Scenario 2: Difference <= 24 hours
            [
                '2024-01-02 12:00:00',
                '2024-01-02 11:00:00',
                '2024-01-02 12:30:00', // created_at + 90 minutes
            ],
            // Scenario 3: Difference > 24 hours and <= 72 hours
            [
                '2024-01-03 12:00:00',
                '2024-01-02 10:00:00',
                '2024-01-02 26:00:00', // created_at + 16 hours
            ],
            // Scenario 4: Difference > 72 hours
            [
                '2024-01-05 12:00:00',
                '2024-01-02 10:00:00',
                '2024-01-03 12:00:00', // due time - 48 hours
            ],
        ];
    }
}
