# Конвертер валют

Автономный Laravel-интерфейс для фиатных и криптовалютных конвертаций.

- фиатные курсы — НБРБ;
- криптовалюты — публичный API Kraken;
- SQLite хранит только последние курсы в `exchange_rates` для работы офлайн;
- UI — Blade, Alpine.js и Tailwind CSS.

В приложении нет аккаунтов, сессий, очередей, почты, файлового хранилища и фоновых задач.

## Локальный запуск

```bash
composer install
npm install
php artisan migrate
npm run dev
php artisan serve
```

Проверка:

```bash
php artisan test
npm run build
```
