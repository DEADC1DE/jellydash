<?php

declare(strict_types=1);

use Mk\Framework\Downloads\JsonRpcHistoryStream;
use Mk\Framework\Downloads\ProviderException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonRpcHistoryStreamTest extends TestCase
{
    public function testEveryChunkBoundaryPreservesEscapesUnicodeAndNestedRecordData(): void
    {
        $rows = [['NZBID' => 1, 'Name' => 'Snow 雪 "quoted" \\ slash', 'nested' => [['x' => '},]'], null, true, 1.5]],
            ['NZBID' => 2, 'Name' => 'Second']];
        $json = json_encode(['id' => 1, 'extra' => ['a' => false], 'result' => $rows, 'error' => null], JSON_THROW_ON_ERROR);
        foreach ([1, 2, 7, 16384] as $size) {
            $received = [];
            $reader = new JsonRpcHistoryStream(static function (array $row) use (&$received): void { $received[] = $row; });
            foreach (str_split($json, $size) as $chunk) {
                $reader->write($chunk);
            }
            self::assertSame($rows, $received);
            self::assertSame(['id' => 1, 'extra' => ['a' => false], 'result' => [], 'error' => null], json_decode($reader->finish(), true));
        }
    }

    #[DataProvider('malformedDocuments')]
    public function testRejectsMalformedOrAmbiguousDocuments(string $json): void
    {
        $reader = new JsonRpcHistoryStream(static function (array $row): void {});
        $this->expectException(ProviderException::class);
        $reader->write($json);
        $reader->finish();
    }

    public static function malformedDocuments(): iterable
    {
        foreach (['{}', '[]', '{"result":[{}]}', '{"result":[1]}', '{"result":[{"x":1},]}',
            '{"result":[{"x":1} {"x":2}]}', '{"result":[{"x":1}]}garbage',
            '{"result":[{"x":1}]}{}', '{"result":[{"x":1}],}', '{"result":[],"result":[]}',
            '{"id":1,"id":2,"result":[]}', '{"result":[{"x":"bad\\q"}]}',
            '{"result":[{"x":1', '{"result":[],"error":tru e}', '{"result":{}}',
            '{"result":[{"x":[1,]}]}', '{"result":[],"id":01}', '{"result":[],"x":NaN}',
        ] as $json) {
            yield [$json];
        }
    }

    public function testLargeHistoryUsesBoundedMemoryAndStillReadsTheLastRecord(): void
    {
        $count = 0;
        $last = null;
        $reader = new JsonRpcHistoryStream(static function (array $row) use (&$count, &$last): void {
            ++$count;
            $last = $row['NZBID'];
        });
        $baseline = memory_get_usage(true);
        $reader->write('{"id":1,"result":[');
        $padding = str_repeat('x', 1024);
        for ($i = 0; $i < 6000; ++$i) {
            $reader->write(($i ? ',' : '') . json_encode(['NZBID' => $i, 'padding' => $padding], JSON_THROW_ON_ERROR));
        }
        $reader->write('],"error":null}');
        self::assertSame(6000, $count);
        self::assertSame(5999, $last);
        self::assertGreaterThan(6 * 1024 * 1024, $reader->bytesReceived());
        self::assertLessThan(4 * 1024 * 1024, memory_get_usage(true) - $baseline);
        self::assertSame([], json_decode($reader->finish(), true)['result']);
    }

    public function testRecordAndTotalByteLimitsRemainEnforced(): void
    {
        foreach (['record', 'total'] as $kind) {
            $reader = new JsonRpcHistoryStream(static function (array $row): void {});
            try {
                if ($kind === 'record') {
                    $reader->write('{"result":[{"Name":"' . str_repeat('x', 262144));
                } else {
                    $reader->write(str_repeat(' ', JsonRpcHistoryStream::MAX_BYTES + 1));
                }
                self::fail('Expected a bounded reader failure.');
            } catch (ProviderException $error) {
                self::assertSame('response_too_large', $error->reason);
            }
        }
    }
}
