# Lugit — Git HTTP Server

lugit — простой и лёгкий Git HTTP сервер на PHP. Реализует smart HTTP protocol, управление репозиториями через CLI и API, встроенную CI/CD систему и базовый WEB интерфейс.

---

## Зависимости

- **PHP 8.1+** (проверено на 8.4)
- **Composer**
- **Git**
- **fastvolt/markdown** ^0.2.5 — рендеринг README (composer-зависимость)

---

## Установка

```bash
git clone https://github.com/lumetas/lugit.git
cd lugit
composer install
chmod +x bin/lugit
```

---

## Запуск

**Для разработки** (встроенный сервер PHP):
```bash
php -S localhost:8080 -t public
```
Либо настройте любой веб-сервер (Apache/Nginx) на `public/index.php`.

---

## Конфигурация

Скопируйте `config.json.example` в `config.json` и настройте под себя:

```json
{
    "users": [],
    "repositoriesPath": "/path/to/bare/repos",
    "cacheFile": "/path/to/cache/file",
    "excludedFolders": ["test3"],
    "enableRegister": false
}
```

### Поля конфигурации

| Поле | Описание |
|------|----------|
| `users` | Массив пользователей: `{ "username", "password" (argon2id hash), "allow_cicd" (bool) }` |
| `repositoriesPath` | Путь к папке с bare-репозиториями |
| `cacheFile` | Путь к файлу кэша репозиториев (по умолчанию: `repo.cache` в корне проекта) |
| `excludedFolders` | Папки, исключённые из списка репозиториев |
| `enableRegister` | Разрешить регистрацию через CLI (`lugit register`) |

---

## Первый пользователь

```bash
php register.php
```
Скрипт создаст пользователя в `config.json`. Пароль хранится в виде Argon2id хэша.

---

## Структура репозиториев

Репозитории хранятся в структуре `<repositoriesPath>/<username>/<repoName>`:

```
repos/
├── alice/
│   ├── my-project/
│   │   ├── config
│   │   ├── hooks
│   │   ├── objects/
│   │   ├── refs/
│   │   └── lugit.json
│   └── another-repo/
└── bob/
    └── shared-project/
```

### Миграция старых репозиториев

Если у вас уже есть репозитории в старой структуре (без разделения по пользователям), выполните миграцию:

```bash
php migrateToUserBased.php
```

Скрипт автоматически определит владельца репозитория по первому пользователю из `allowedUsers` и переместит репозиторий в правильную папку.

---

## Кэширование

lugit использует файл кэша (`repo.cache`) для быстрого поиска репозиториев. Кэш хранит информацию о существовании репозиториев и их настройках (public/allowedUsers).

### Обновление кэша

```bash
php updateCache.php
```

### Как работает кэш

1. При запросе сначала проверяется кэш
2. Если репозитория нет в кэше — поиск на диске
3. При изменении настроек репозитория через API кэш обновляется автоматически
4. Кэш можно перестроить вручную через `updateCache.php`

---

## Команды CLI

### Аутентификация

```bash
lugit login http://localhost:8080 <username> <password>
lugit logout
lugit whoami
lugit set-server http://localhost:8080
lugit changepass <new-password>
lugit register http://localhost:8080 <username> <password>   # если enableRegister: true
```

### Репозитории

```bash
lugit list                              # список репозиториев
lugit create <username> <name>           # создать bare-репозиторий
lugit info <username> <name>             # информация о репозитории
lugit delete <username> <name>           # удалить репозиторий
lugit set-public <username> <name>       # сделать публичным
lugit set-private <username> <name>      # сделать приватным
```

### Управление доступом

```bash
lugit user-add <username> <repo> <user>           # добавить пользователя
lugit user-remove <username> <repo> <user>        # удалить пользователя
lugit user-list <username> <repo>                 # список пользователей
```

---

## Права доступа

- **Приватные репозитории** (по умолчанию) — доступ только у авторизованных пользователей из `allowedUsers`
- **Публичные репозитории** — clone доступен без аутентификации, **push требует аутентификации и прав доступа**
- `/repos/<username>/<name>` на WEB странице отображается только для публичных репозиториев

---

## CI/CD

lugit имеет встроенную CI/CD. Хуки привязываются к ветке **по точному совпадению имени** (без wildcard-ов).

### Команды

```bash
lugit cicd-set <user> <repo> <branch> <script>        # установить хук
lugit cicd-del <user> <repo> <branch>                  # удалить хук
lugit cicd-list <user> <repo>                         # список хуков
lugit cicd-run <user> <repo> <branch>                 # ручной запуск
lugit cicd-logs <user> <repo> <branch>                # просмотр логов
lugit cicd-logs-clean <user> <repo> <branch>          # очистка логов
```

### Как работает

1. Хук сохраняется в `<repo>/lugit/hooks/<branch>` и делается исполнимым
2. В bare-репозиторий устанавливается `hooks/post-receive`
3. При пуше post-receive читает `refname`, извлекает имя ветки, ищет файл `<repo>/lugit/hooks/<branch>`
4. **Важно:** хук на `main` НЕ сработает при пуше в `feature/xyz`. Только exact-match по имени ветки
5. Выполнение асинхронное, через `nohup`
6. Вывод сохраняется в `<repo>/lugit/logs/<branch>`

### Права CI/CD

- `allow_cicd: true` — полное управление хуками (set, del, run, list) + просмотр логов
- `allow_cicd: false` — только просмотр логов (`cicd-logs`)
- При регистрации через CLI (`lugit register`) новый пользователь получает `allow_cicd: false`

---

## WEB интерфейс

### Dashboard (`/`)

Главная страница (`/`) — полноценный веб-интерфейс для управления сервером, аналог консольной утилиты `lugit`.

**Возможности:**
- Авторизация (данные хранятся в `localStorage`)
- Управление репозиториями: список, создание, удаление, смена видимости
- Управление доступом: добавление/удаление пользователей к репозиторию
- CI/CD: просмотр хуков, ручной запуск, просмотр и очистка логов
- Использует тот же сервер, с которого открыт

**Дизайн:** тёмная тема в едином стиле со страницей репозитория.

### Страница репозитория (`/repos/<username>/<name>`)

Реализована поддержка маршрутизации с именем пользователя и именем репозитория.

- **Публичный** — страница с README, ссылкой для клонирования, списком участников
- **Приватный** — страница с сообщением "Private Repository"
- README парсится из `README.md` в корне репозитория

---

## API endpoints

Все API (`/api/v1/*`) возвращают JSON. Аутентификация — Basic Auth.

| Метод | Путь | Описание |
|-------|------|----------|
| GET | `/api/v1/repos` | Список репозиториев |
| GET | `/api/v1/repos/{username}/{name}` | Информация о репозитории |
| POST | `/api/v1/repos/{username}/{name}` | Создать репозиторий |
| DELETE | `/api/v1/repos/{username}/{name}` | Удалить репозиторий |
| GET | `/api/v1/repos/{username}/{name}/users` | Список пользователей |
| POST | `/api/v1/repos/{username}/{name}/users/{user}` | Добавить пользователя |
| DELETE | `/api/v1/repos/{username}/{name}/users/{user}` | Удалить пользователя |
| PUT | `/api/v1/repos/{username}/{name}/public` | Сделать публичным |
| PUT | `/api/v1/repos/{username}/{name}/private` | Сделать приватным |
| POST | `/api/v1/login` | Логин |
| GET | `/api/v1/user` | Текущий пользователь |
| POST | `/api/v1/register` | Регистрация |
| POST | `/api/v1/changepass` | Смена пароля |
| GET | `/api/v1/repos/{username}/{name}/cicd` | Список CI/CD хуков |
| POST | `/api/v1/repos/{username}/{name}/cicd/{branch}` | Установить хук |
| DELETE | `/api/v1/repos/{username}/{name}/cicd/{branch}` | Удалить хук |
| POST | `/api/v1/repos/{username}/{name}/cicd/{branch}/run` | Запустить хук |
| GET | `/api/v1/repos/{username}/{name}/cicd/logs/{branch}` | Логи хука |
| DELETE | `/api/v1/repos/{username}/{name}/cicd/logs/{branch}` | Очистить логи |

---

## Git clone/push

Git URL теперь имеет формат `<server>/<username>/<repoName>`:

```bash
# Clone
git clone http://localhost:8080/alice/my-project

# Push
git push http://localhost:8080/alice/my-project
```

---

## Безопасность

Пароли пользователей хешируются с использованием **Argon2id** — современного алгоритма хеширования паролей. Для обратной совместимости поддерживается также SHA256 (старые хэши в конфиге продолжат работать).

---

## Утилиты

### updateCache.php
Перестраивает кэш репозиториев. Полезно после ручного изменения файловой системы.

```bash
php updateCache.php
```

### migrateToUserBased.php
Мигрирует репозитории из старой структуры (без разделения по пользователям) в новую.

```bash
php migrateToUserBased.php
```