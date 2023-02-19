<?php

namespace Telegram;

use App;
use Log;
use URL;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Класс для отправки сообщений об исключениях
 */
class TelegramReporter
{
    /**
     * Токен бота для отправки
     * @var
     */
    protected $token;

    /**
     * Список игнорируемых исключений
     * @var array
     */
    protected $ignoreExceptions = [
        AuthenticationException::class,
        AuthorizationException::class,
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        SuspiciousOperationException::class,
        TokenMismatchException::class,
        ValidationException::class,
    ];

    /**
     * Guzzle-экземпляр
     * @var Client
     */
    protected $client;

    /**
     * ID чата для отправки сообщения
     * @var
     */
    protected $chatId;

    public function __construct($token, $chatId)
    {
        $this->token = $token;

        $this->chatId = $chatId;

        $this->client = new Client();
    }

    /**
     * Нужный URL API для метода
     * @param $method
     * @return string
     */
    protected function getUrl($method)
    {
        return "https://api.telegram.org/bot{$this->token}/{$method}";
    }

    /**
     * Отправка сообщения в Telegram из исключения
     * @param Throwable $exception
     */
    public function sendExceptionMessage(Throwable $exception)
    {
        // не отправляем исключения от локальной админки
        if (App::isLocal()) {
            return false;
        }

        // если исключение в списке игнорируемых, ничего не делаем
        foreach ($this->ignoreExceptions as $ignoreException) {
            if ($exception instanceof $ignoreException) {
                return false;
            }
        }

        $message = $exception->getMessage();
        $message = $message ? $message : "Отсутствует";

        $class = get_class($exception);

        $url = URL::full();

        $file = $exception->getFile();
        $line = $exception->getLine();
        $env = App::environment();

        $replace = compact("message", "file", "line", "class", "url", "env");

        $text = trans("telegram.exception", $replace);

        return $this->sendMessage($text);
    }

    /**
     * Отправка сообщения
     * @param $text
     * @return bool
     */
    public function sendMessage($text)
    {
        try {
            $url = $this->getUrl("sendMessage");

            $messageLimit = 4090;

            // сокращаем text до лимита
            $text = Str::limit($text, $messageLimit, "...");

            $response = $this->client->post($url, [
                "form_params" => [
                    "chat_id" => $this->chatId,
                    "text" => $text,
                ],
                "timeout" => 5,
            ]);

            $json = json_decode($response->getBody()->getContents());

            return data_get($json, "ok") === true;

        } catch (Exception $exception) {
            Log::error("Ошибка при отправке сообщения: " . $exception->getMessage());
            return false;
        }
    }

}
