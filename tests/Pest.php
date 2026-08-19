<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Config reset between tests
|--------------------------------------------------------------------------
|
| RefreshDatabase resets the database between tests, but not the config
| repository — a test that does config(['attendance.x' => ...]) leaks
| that value into whichever test happens to run next in the same file,
| since Pest doesn't rebuild the whole application per test the way
| plain Laravel test suites sometimes do. Hit this three times while
| building Phase C before fixing it here once: re-read config/attendance.php
| fresh before every test rather than trusting whatever an earlier test
| left in memory. Extend this if another config file starts getting
| mutated in tests.
|
*/

beforeEach(function () {
    foreach (require base_path('config/attendance.php') as $key => $value) {
        config(["attendance.{$key}" => $value]);
    }
});

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
