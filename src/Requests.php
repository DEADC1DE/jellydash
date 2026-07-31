<?php

declare(strict_types=1);

namespace Mk\Framework;

class Requests
{

    private const GET_AUTH_ENDPOINT = "auth";
    private const GET_REQUEST_ENDPOINT = "req";
    private const SESSION_KEY = 'session_data';

    public ?string $auth;
    public ?string $request;

    public function __construct()
    {
        // Set GET points
        $this->request = Main::captureGetString(self::GET_REQUEST_ENDPOINT);
        $this->auth = Main::captureGetString(self::GET_AUTH_ENDPOINT);
    }

    // Compare request
    public function requestIs($request): bool
    {
        return $this->request == $request;
    }

    // Compare Auth request
    public function authIs($request): bool
    {
        return $this->auth == $request;
    }

    public function setSessionData(string $target, $value): void
    {
        $_SESSION[self::SESSION_KEY][$target] = $value;
    }

    public function getSessionData($target = null)
    {
        if ($target) {
            return $_SESSION[self::SESSION_KEY][$target] ?? null;
        }

        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    public function clearSessionData($target = null): void
    {
        if ($target) {
            unset($_SESSION[self::SESSION_KEY][$target]);
        } else {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }

    public function setSessionAnswer($msg): void
    {
        $_SESSION[self::SESSION_KEY]["request_answer"] = $msg;
    }

    public function getSessionAnswer(): ?string
    {
        return $_SESSION[self::SESSION_KEY]["request_answer"] ?? null;
    }

    public function clearSessionAnswer(): void
    {
        unset($_SESSION[self::SESSION_KEY]["request_answer"]);
    }

    public function clearTwigSessionData(): void
    {
        $this->clearSessionData("error_message");
        $this->clearSessionData("message");
        $this->clearSessionData("post_data");
    }

    public function successSessionMessage($msg, $url): never
    {
        $this->clearTwigSessionData();
        $this->setSessionData("message", $msg);

        // Relative redirect: avoids Host-header injection from $_SERVER['HTTP_HOST'].
        header("Location: " . $url);
        exit();
    }

    public function errorSessionPostMessage($msg, $post, $url): never
    {
        $this->clearTwigSessionData();

        $this->setSessionData("error_message", $msg);
        $this->setSessionData("post_data", $post);

        // Relative redirect: avoids Host-header injection from $_SERVER['HTTP_HOST'].
        header("Location: " . $url);
        exit();

    }

    public function errorSessionMessage($msg, $url): never
    {
        $this->clearTwigSessionData();

        $this->setSessionData("error_message", $msg);

        // Relative redirect: avoids Host-header injection from $_SERVER['HTTP_HOST'].
        header("Location: " . $url);
        exit();

    }

}