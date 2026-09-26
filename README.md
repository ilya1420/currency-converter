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

## Android release

NativePHP-команды выполняются из Windows PowerShell, не из WSL. Перед
сборкой перенеси значения из `.env.native-release.example` в локальный
`.env`. Пароли подписи и путь к keystore не хранятся в `.env` — передавай их
только через переменные текущей PowerShell-сессии.

`config/nativephp.php` удаляет из runtime bundle `APP_KEY` и Android signing
variables, а также исключает тестовые и локальные файлы. NativePHP создаёт
свой per-device application key при первом запуске.

После изменения версии или кода приложения собирай APK так:

```powershell
php artisan native:package android --output=.\builds
```

Перед распространением проверь, что в APK не находятся `APP_KEY`,
`ANDROID_KEYSTORE_*`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`, а в
manifest указаны ожидаемые название и версия.
