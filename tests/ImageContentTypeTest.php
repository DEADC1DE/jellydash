<?php

declare(strict_types=1);

use Mk\Framework\Http\ImageContentType;
use PHPUnit\Framework\TestCase;

final class ImageContentTypeTest extends TestCase
{
    public function testNormalizesAllowedImageTypes(): void
    {
        $this->assertSame('image/jpeg', ImageContentType::normalize('image/jpeg; charset=binary'));
        $this->assertSame('image/webp', ImageContentType::normalize('IMAGE/WEBP'));
    }

    public function testRejectsNonImageAndUnknownTypes(): void
    {
        $this->assertNull(ImageContentType::normalize('text/html'));
        $this->assertNull(ImageContentType::normalize('image/svg+xml'));
        $this->assertNull(ImageContentType::normalize(''));
    }
}
