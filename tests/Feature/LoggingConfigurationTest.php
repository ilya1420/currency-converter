<?php

namespace Tests\Feature;

use Tests\TestCase;

class LoggingConfigurationTest extends TestCase
{
    public function test_log_channel_can_be_selected_through_environment_configuration(): void
    {
        $this->assertSame('stderr', config('logging.default'));
    }
}
