<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every suite boots the Laravel app. Database and Feature tests run against the
| meridian_testing MariaDB database (phpunit.xml), rebuilt by RefreshDatabase.
|
*/

pest()->extend(TestCase::class)->in('Unit');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Database', 'Feature');
