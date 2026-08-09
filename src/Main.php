<?php

declare(strict_types=1);

namespace Mk\Framework;

class Main
{

    public static function isPostSet($post): bool
    {
        return isset($_POST[$post]);
    }

    // GET + POST CAPTURING
    // Captured raw. Validate where needed, and rely on Twig to escape on output.
    public static function captureGetString($get): ?string
    {
        return isset($_GET[$get]) ? (string) $_GET[$get] : null;
    }

    public static function capturePostString($post): ?string
    {
        // Captured raw. Validate where needed; Twig escapes on output.
        return isset($_POST[$post]) ? (string) $_POST[$post] : null;
    }

    // Validate NON $_POST Email
    public static function validateEmail($email): ?string
    {
        $sanitizedEmail = filter_var($email, FILTER_SANITIZE_EMAIL);
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $sanitizedEmail : null;
    }

    // Check if array items are not empty -> primarily for POSTs
    public static function arrayItemsNotEmpty(array $array): bool {
        foreach ($array as $item) {
            if (empty(trim($item))) {
                return false;
            }
        }
        return true;
    }

    public static function isSessionValueSet($valueName = null): bool
    {
        return !empty($_SESSION[$valueName]);
    }

    public static function getSessionValue($valueName)
    {
        // Returned raw; escaping happens on output (Twig).
        return $_SESSION[$valueName] ?? null;
    }

    public static function setSessionValue($valueName, $value): bool
    {
        // A value name is required to store anything.
        // Store the value as-is; escaping happens on output (Twig) / read.
        if (empty($valueName)) {
            return false;
        }

        $_SESSION[$valueName] = $value;
        return true;
    }




}
