<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        $manager = $this->app?->bound('db') ? $this->app->make('db') : null;
        try {
            parent::tearDown();
        } finally {
            foreach ($manager?->getConnections() ?? [] as $connection) {
                $connection->disconnect();
            }
        }
    }
}
