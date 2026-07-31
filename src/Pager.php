<?php

declare(strict_types=1);

namespace Mk\Framework;

class Pager
{

    public static function getPage(): ?string
    {
        return Main::captureGetString('page') ?? null;
    }

    public static function getCategory(): ?string
    {
        return Main::captureGetString('category') ?? null;
    }


    public static function homePage(): never
    {
        header('Location: /' . HOMEPAGE);
        exit();
    }

    public static function login(): never
    {
        header('Location: /' . LOGIN_PAGE);
        exit();
    }




}