<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Notifications\DatabaseNotification;

abstract class TestCase extends BaseTestCase
{
    /** An admin bell notification (App\Support\AdminAlert) was stored whose title contains $text. */
    protected function assertAdminNotified(string $text): void
    {
        $titles = DatabaseNotification::all()->pluck('data.title');

        $this->assertTrue($titles->contains(fn ($t) => str_contains((string) $t, $text)), "No admin notification containing \"{$text}\". Got: ".$titles->implode(' | '));
    }
}
