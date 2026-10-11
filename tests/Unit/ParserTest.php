<?php

declare(strict_types=1);

namespace Lsm\Tests\Unit;

use Lsm\Exception\MalformedOperationException;
use Lsm\Exception\UnreadableSourceException;
use Lsm\Model\OperationType;
use Lsm\Parser\CsvLineParser;
use Lsm\Parser\JsonLineParser;
use Lsm\Parser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    #[Test]
    public function it_reads_a_json_line(): void
    {
        $operation = (new JsonLineParser)->parse('{"type":"put","key":"a","value":"1"}', 1);

        self::assertNotNull($operation);
        self::assertSame(OperationType::Put, $operation->type);
        self::assertSame('a', $operation->key);
        self::assertSame('1', $operation->value);
    }

    #[Test]
    public function a_blank_line_is_skipped_rather_than_rejected(): void
    {
        self::assertNull((new JsonLineParser)->parse('   ', 4));
        self::assertNull((new CsvLineParser)->parse('', 4));
    }

    #[Test]
    public function a_csv_header_row_is_skipped(): void
    {
        self::assertNull((new CsvLineParser)->parse('type,key,value', 1));
    }

    /**
     * A rejected import line is only actionable if the error names it. The
     * model raises these, and it has no idea which line it was handed.
     */
    #[Test]
    public function an_unknown_csv_type_is_reported_with_its_line(): void
    {
        $this->expectException(MalformedOperationException::class);
        $this->expectExceptionMessage('csv line 7');

        (new CsvLineParser)->parse('bogus,a,1', 7);
    }

    #[Test]
    public function an_empty_csv_type_is_reported_with_its_line(): void
    {
        $this->expectException(MalformedOperationException::class);
        $this->expectExceptionMessage('csv line 3');

        (new CsvLineParser)->parse(',a,1', 3);
    }

    #[Test]
    public function a_csv_put_without_a_value_is_reported_with_its_line(): void
    {
        $this->expectException(MalformedOperationException::class);
        $this->expectExceptionMessage('csv line 9');

        (new CsvLineParser)->parse('put,a', 9);
    }

    #[Test]
    public function an_unknown_json_type_is_reported_with_its_line(): void
    {
        $this->expectException(MalformedOperationException::class);
        $this->expectExceptionMessage('json line 5');

        (new JsonLineParser)->parse('{"type":"bogus","key":"a"}', 5);
    }

    #[Test]
    public function direct_callers_of_the_model_still_get_the_plain_message(): void
    {
        $this->expectException(MalformedOperationException::class);
        $this->expectExceptionMessage('Unknown operation type "bogus"');

        OperationType::fromInput('bogus');
    }

    #[Test]
    public function a_csv_delete_needs_no_value(): void
    {
        $operation = (new CsvLineParser)->parse('delete,a,', 2);

        self::assertNotNull($operation);
        self::assertSame(OperationType::Delete, $operation->type);
        self::assertNull($operation->value);
    }

    #[Test]
    public function broken_json_names_the_line_it_failed_on(): void
    {
        $this->expectException(MalformedOperationException::class);
        $this->expectExceptionMessageMatches('/line 7/');

        (new JsonLineParser('workload.jsonl'))->parse('{not json', 7);
    }

    #[Test]
    public function an_unknown_operation_type_is_rejected(): void
    {
        $this->expectException(MalformedOperationException::class);

        (new JsonLineParser)->parse('{"type":"upsert","key":"a"}', 1);
    }

    #[Test]
    public function the_factory_picks_a_parser_from_the_extension(): void
    {
        $factory = new ParserFactory;

        self::assertInstanceOf(JsonLineParser::class, $factory->forPath('/tmp/data.jsonl'));
        self::assertInstanceOf(JsonLineParser::class, $factory->forPath('/tmp/data.ndjson'));
        self::assertInstanceOf(CsvLineParser::class, $factory->forPath('/tmp/data.csv'));
        self::assertInstanceOf(CsvLineParser::class, $factory->forPath('/tmp/data.tsv'));
    }

    #[Test]
    public function an_unknown_extension_is_rejected_with_the_supported_list(): void
    {
        $this->expectException(UnreadableSourceException::class);
        $this->expectExceptionMessageMatches('/jsonl/');

        (new ParserFactory)->forPath('/tmp/data.xlsx');
    }
}
