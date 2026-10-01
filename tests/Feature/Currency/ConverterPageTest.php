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
            ->assertSee('<span>Конвертер</span>', false)
            ->assertSee('<span>Графики</span>', false)
            ->assertSee('<span>Источники</span>', false)
            ->assertSee('Нажмите на значок валюты, чтобы заменить её.')
            ->assertSee('Понятно')
            ->assertSee('Показать калькулятор')
            ->assertDontSee('dailyChangeLabel')
            ->assertSee('<span>Скрыть калькулятор</span>', false)
            ->assertSee('Настройки провайдеров')
            ->assertSee('Добавить валюту')
            ->assertSee('Избранные валютные пары')
            ->assertSee('История конвертаций')
            ->assertSee('Сохранить текущий расчёт')
            ->assertSee('Вернуть в конвертер')
            ->assertSee('Очистить историю');
    }
}
