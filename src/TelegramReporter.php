<?php

namespace Decadence;

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
     * Таймаут взаимодействия с Telegram в секундах
     * @var int
     */
    protected $timeout = 5;

    /**
     * Лимит на длину сообщения в Telegram
     * @var int
     */
    protected $messageLimit = 4096;

    protected $baseUrl = "https://api.telegram.org";

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
        return "{$this->baseUrl}/bot{$this->token}/{$method}";
    }

    /**
     * Отправка сообщения в Telegram из исключения
     * @param Throwable $exception
     */
    public function sendExceptionMessage(Throwable $exception)
    {
        // не отправляем исключения с локальной площадки
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

        $message = $exception->getMessage() ?: $emptyValue;

        $user = Auth::user();

        $class = get_class($exception);
        $file = $exception->getFile();
        $line = $exception->getLine();

        $url = App::runningInConsole() ? "CLI" : URL::full();
        $env = App::environment();
        $ip = request()->server("SERVER_ADDR", $emptyValue);

        // получение имени пользователя
        $user = method_exists($user, "getName") ?
            $user->getName() :
            data_get($user, "name", $emptyValue);

        return compact("message", "file", "line", "class", "url", "env", "user", "ip");
    }

    /**
     * Отправка сообщения
     * @param $text
     * @return bool
     */
    public function sendMessage($text, $markdown = false)
    {
        try {
            $startTime = microtime(true);

            $url = $this->getUrl("sendMessage");

            // сокращаем сообщение до лимита с учётом overflow-текста
            $overflowText = "...";
            $realLimit = $this->messageLimit - Str::length($overflowText);
            $text = Str::limit($text, $realLimit, $overflowText);

            $formParams = [
                "chat_id" => $this->chatId,
                "text" => $text,
            ];

            if ($markdown) {
                $formParams["parse_mode"] = "markdown";
            }

            $response = $this->client->post($url, [
                RequestOptions::FORM_PARAMS => $formParams,

                RequestOptions::TIMEOUT => $this->timeout,
            ]);

            $json = json_decode($response->getBody()->getContents());

            $result = data_get($json, "ok") === true;

            if ($result) {
                $sendTime = round(microtime(true) - $startTime, 4);
                Log::info("Сообщение успешно отправлено за {$sendTime} секунд");
            }

            return $result;

        } catch (Exception $exception) {
            Log::error("Ошибка при отправке сообщения: " . $exception->getMessage());
            return false;
        }
    }

}
