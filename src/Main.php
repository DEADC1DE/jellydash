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

    public static function captureGetInt($get): ?int
    {
        // Filter $_GET int input and validates
        // If int is valid, it's returned
        return filter_input(INPUT_GET, $get, FILTER_VALIDATE_INT);

    }

    public static function capturePostString($post): ?string
    {
        // Captured raw. Validate where needed; Twig escapes on output.
        return isset($_POST[$post]) ? (string) $_POST[$post] : null;
    }

    public static function captureHTML($post): ?string
    {
        if (isset($_POST[$post]) && $_POST[$post] !== '') {
            // The specific item is not empty
            return $_POST[$post];
        } else {
            // The specific item is empty or not set
            // Handle the case accordingly
            return null;
        }
    }

    public static function capturePostEmail($post): ?string
    {
        // Filter $_POST string input and sanitize
        // Value can be checked with !$var / $var or empty($var)
        $sanitizedEmail = filter_input(INPUT_POST, $post, FILTER_SANITIZE_EMAIL);
        return self::validateEmail($sanitizedEmail) ? $sanitizedEmail : null;
    }

    public static function capturePostInt($post): ?int
    {
        // Filter $_POST int input
        // Value can be checked with !$var / $var or empty($var)
        // Min, max value optional
        return filter_input(INPUT_POST, $post, FILTER_VALIDATE_INT);
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