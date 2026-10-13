<?php

namespace Tests;

use App\Scheduling\WorkCalendar;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // the working calendar is cached per process; every test reads it from its own configuration
        WorkCalendar::reset();
    }
}
