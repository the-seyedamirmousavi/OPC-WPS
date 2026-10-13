<?php

namespace Tests\Unit\Support;

use App\Support\HttpTls;
use PHPUnit\Framework\TestCase;

/** A call that waits for a remote answer must not be stopped by PHP's execution limit (30 s in php.ini). */
class HttpWaitTest extends TestCase
{
    public function test_the_limit_is_raised_to_cover_the_wait_when_it_is_too_short(): void
    {
        $before = ini_get('max_execution_time');
        ini_set('max_execution_time', '30');
        try {
            HttpTls::allowWait(300);
            $this->assertSame('315', ini_get('max_execution_time'));
        } finally {
            ini_set('max_execution_time', $before);
        }
    }

    public function test_an_unlimited_or_already_long_enough_limit_is_left_alone(): void
    {
        $before = ini_get('max_execution_time');
        try {
            ini_set('max_execution_time', '0');
            HttpTls::allowWait(300);
            $this->assertSame('0', ini_get('max_execution_time'));

            ini_set('max_execution_time', '600');
            HttpTls::allowWait(300);
            $this->assertSame('600', ini_get('max_execution_time'));
        } finally {
            ini_set('max_execution_time', $before);
        }
    }
}
