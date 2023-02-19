<?php

namespace TelegramReporter;

use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
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
     * Таймаут взаимодействия с Telegram
     * @var int
     */
    protected $timeout = 5;

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

        $text = trans("telegram.exception", $this->getExceptionDetails($exception));

        return $this->sendMessage($text);
    }

    /**
     * Получение информации об исключении
     * @param Throwable $exception
     */
    protected function getExceptionDetails(Throwable $exception)
    {
        $emptyValue = "N/A";

        $message = $exception->getMessage();
        $message = $message ?: $emptyValue;

        $class = get_class($exception);

        $url = URL::full();

        $user = Auth::user();

        $file = $exception->getFile();
        $line = $exception->getLine();
        $env = App::environment();
        $user = data_get($user, "full_name", $emptyValue);
        $ip = request()->server("SERVER_ADDR", $emptyValue);

        return compact("message", "file", "line", "class", "url", "env", "user", "ip");
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
                RequestOptions::FORM_PARAMS => [
                    "chat_id" => $this->chatId,
                    "text" => $text,
                ],

                RequestOptions::TIMEOUT => $this->timeout,
            ]);

            $json = json_decode($response->getBody()->getContents());

            return data_get($json, "ok") === true;

        } catch (Exception $exception) {
            Log::error("Ошибка при отправке сообщения: " . $exception->getMessage());
            return false;
        }
    }

}
