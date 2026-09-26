<?php

namespace Tests\Feature\Currency;

use Tests\TestCase;

class ConverterPageTest extends TestCase
{
    public function test_converter_page_is_available_with_supported_currencies(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Конвертер')
            ->assertSee('Добавить валюту');
    }
}
