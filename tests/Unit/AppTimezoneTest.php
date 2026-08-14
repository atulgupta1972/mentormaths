<?php

namespace Tests\Unit;

use Tests\TestCase;

class AppTimezoneTest extends TestCase
{
    public function test_app_config_defaults_to_india_timezone(): void
    {
        $config = file_get_contents(config_path('app.php'));

        $this->assertStringContainsString("env('APP_TIMEZONE', 'Asia/Kolkata')", $config);
    }

    public function test_env_example_documents_india_timezone(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('APP_TIMEZONE=Asia/Kolkata', $example);
        $this->assertStringContainsString('DB_TIMEZONE=+05:30', $example);
    }
}
