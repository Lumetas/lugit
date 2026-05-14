Это git server написанный на php. Созданный с целью обеспечить максимально легковесный и нетребовательный сервер, который можно поднять на сервере.

Как правило, для разработки запускается сервер на порту 8080, через специальнй скрипт от лица пользователя git.

ПОЭТОМУ СЕРВЕР НЕЛЬЗЯ ПЕРЕЗАГРУЖАТЬ. НЕЛЬЗЯ УБИВАТЬ ТЕКУЩИЙ И ЗАПУСКАТЬ НОВЫЙ, У ТЕБЯ НЕТ НА ЭТО ПРАВ! НЕ НУЖНО ПЕРЕЗАПУСКАТЬ СЕРВЕР PHP В ЭТОМ НЕ НУЖДАЕТСЯ.

# Проект использует библиотеку bmnd для роутинга через аттрибуты классов, найти контроллеры можно в src/controllers. 
## ApiController 
Отвечает за REST API для клиента(консольный, написан на php bin/lugit). И web.

## WebController 
Отвечает за веб интерфейс

## GitController 
Отвечает за эндпоинты для самого git клиента.
Документация библиотеки находится в `vendor/bmnd/core/README.org`

По архитектуре добавление ключа обновляет файл ~/.ssh/authorized_keys на сервере, добавляя туда ключ с форсированной командой(Наш собственный обработчик)

# Тесты

## Структура
- `tests/Unit/` — модульные тесты (10 файлов, 170 тестов)
- `tests/Integration/` — интеграционные тесты (3 файла)
- `tests/bootstrap.php` — автозагрузка, хелперы (`initTestConfigFromArray`, `createTestBareRepo`, `cleanupTestDir`, `setPrivateProperty`, `getPrivateProperty`)
- `tests.php` — скрипт-раннер с опциями (--unit, --integration, --port, --ssh, --no-server)
- `phpunit.xml` — конфиг с двумя suite: unit и integration

## Запуск
```bash
# Все unit-тесты
php vendor/bin/phpunit --configuration phpunit.xml --testsuite unit

# Один тестовый класс
php vendor/bin/phpunit --configuration phpunit.xml --filter GitApiTest

# Один тест
php vendor/bin/phpunit --configuration phpunit.xml --filter testCicdListHooksEmpty

# Integration (требуется сервер на localhost:8080)
php vendor/bin/phpunit --configuration phpunit.xml --testsuite integration

# Через раннер (сам запускает сервер)
php tests.php --unit --integration
```

## Ключевые моменты
- `password_hash(PASSWORD_ARGON2ID)` медленный (~600ms/хеш). Хеши предвычисляются в bootstrap как `TEST_PASS_HASH`/`OTHER_PASS_HASH` — **не вызывать `password_hash` в setUp**
- `git init --bare` не удаляет лишние директории (lugit/hooks). `createTestBareRepo` не гарантирует чистоту — при переиспользовании имени репы удалять вручную `rm -rf` перед созданием
- `Config::load()` пытается читать файл из `configPath`. `initTestConfigFromArray` пишет его и ставит config через reflection — не вызывать `Config::reload()` без `Config::init()` с существующим файлом
- В моках `disableOriginalConstructor()` — GitApi/GitHttpServer конструктор дёргает `Config::init` и сбрасывает тестовый конфиг
- `sendJson` protected — мокать через `onlyMethods(['sendJson', 'sendError'])`, трекать вызовы через `willReturnCallback`
- `exit()` в исходном коде — PHPUnit 13 падает с "Premature end of process". Тестировать такие路径 через моки или `@runInSeparateProcess`
- `Utils::findRepoPath` использует `Config::getRepositoriesPath()`, а не `$this->basePath` на моке
- RepoCache статический — `RepoCache::init()` в setUp сбрасывает состояние

## Тестовые хелперы
| Функция | Назначение |
|---------|-----------|
| `initTestConfigFromArray($reposPath, $extra)` | Устанавливает Config через reflection + пишет конфиг-файл |
| `createTestBareRepo($username, $repoName)` | `git init --bare + RepoConfig::save` |
| `cleanupTestDir()` | `rm -rf tests/_temp/` |
| `setPrivateProperty($obj, $prop, $value)` | Установка private/protected свойства (traverses hierarchy) |
| `getPrivateProperty($obj, $prop)` | Чтение private/protected свойства |
| `SendErrorException` | Исключение для мока sendError |
