# Lugit - Git HTTP Server

lugit - простой и лёгкий git http сервер написанный на php.

## Установка
```bash
git clone https://github.com/lumetas/lugit.git
cd lugit
composer install
```

## Запуск
```bash
php -S localhost:8080 -t public
```

## Настройка 
скопируйте файл config.json.example в config.json и замените все поля на свои.

В поле repositoriesPath укажите путь к папке с bare репозиториями.

excludeFolders - список файлов и папок которые должны быть исключены из списка репозиториев.

enabledRegister - включить регистрацию пользователей через консольный клиент.

Зарегестрировать первого клиента:
```bash
php register.php
```
Скрипт спросит username и password и зарегистрирует пользователя в системе.

## Создание первого репозитория
Авторизуемся в системе через консольный клиент:
```bash
./bin/lugit login http://localhost:8080 alice secret123
``` 

Создаём новый репозиторий:
```bash
./bin/lugit create my-project
```

После создания репозитория вы можете получить его git адрес через консольный клиент:
```bash
./bin/lugit info my-project
```

Пользователи могут добавляться в репозитории через консольный клиент:
```bash
./bin/lugit user-add my-project bob
```
## WEB интерфейс
Сервер имеет небольшой веб интерфейс. Если перейти на `/repos/{repoName}` вы увидите информацию о репозитории и его README.md, но только если репозиторий публичный.

## Права доступа
Репозитории могут быть публичными или приватными, по умолчанию репозитории считаются приватными. Чтобы это изменить можно сделать так:
```bash
./bin/lugit set-public my-project
```

И наоборот:
```bash
./bin/lugit set-private my-project
```
После этого даже не авторизованные пользователи смогут склонировать репозиторий через git. А вы можете отправить им ссылку.


Скриншот веб интерфейса:
![lugit-screenshot](img/lugit.png)
