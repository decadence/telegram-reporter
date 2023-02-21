# Telegram Reporter
Простой класс для отправки сообщений и информации об исключениях в Telegram

```
composer require decadence/telegram-reporter
```

Пример подключения в Laravel

```php
public function register()
{
    $this->reportable(function (Throwable $e) {
        $reporter = new Decadence\TelegramReporter(
            $token, $chatId
        );
        
        $reporter->sendExceptionMessage($e);
    });
}
```

Пример сообщения об ошибке, оно берётся из `trans("telegram.exception")`.

```
⚠️ Ошибка в проекте

Сообщение: :message

Файл и строка: :file, :line

Тип: :class

URL: :url
Пользователь: :user
Площадка: :env
```
