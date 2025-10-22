<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Strategy\RenameStrategy\QuickTime;

use DateTimeImmutable;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Exception\FileReadException;
use MagicSunday\Renamer\Service\SafeFileReader;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ContentIdentifier;
use MagicSunday\Renamer\Strategy\RenameStrategy\QuickTime\QuickTimeContentIdentifierExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Throwable;

use function pack;
use function strlen;
use function uniqid;

final class StubSafeFileReader extends SafeFileReader
{
    /**
     * @var array<string, string|Throwable>
     */
    private array $responses = [];

    public function withResponse(string $path, string|Throwable $response): void
    {
        $this->responses[$path] = $response;
    }

    public function read(SplFileInfo $file): string
    {
        $path = $file->getPathname();

        if (!array_key_exists($path, $this->responses)) {
            throw new FileReadException('No stubbed response available.');
        }

        $response = $this->responses[$path];

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}

#[CoversClass(QuickTimeContentIdentifierExtractor::class)]
final class QuickTimeContentIdentifierExtractorTest extends TestCase
{
    #[Test]
    public function itReturnsNullForUnsupportedExtensions(): void
    {
        $reader = new StubSafeFileReader();
        $extractor = new QuickTimeContentIdentifierExtractor($reader);

        $identifier = $extractor->extractContentIdentifier(new SplFileInfo('/tmp/example.jpg'));

        self::assertNull($identifier);
    }

    #[Test]
    public function itExtractsContentIdentifierFromQuickTimeAtoms(): void
    {
        $reader = new StubSafeFileReader();
        $path = '/tmp/' . uniqid('quicktime_', true) . '.mov';
        $reader->withResponse($path, $this->createQuickTimeSample('UUID-1234'));

        $extractor = new QuickTimeContentIdentifierExtractor($reader);

        $identifier = $extractor->extractContentIdentifier(new SplFileInfo($path));

        self::assertInstanceOf(ContentIdentifier::class, $identifier);
        self::assertSame('uuid-1234', $identifier->getValue());
    }

    #[Test]
    public function itTrimsWhitespaceFromContentIdentifier(): void
    {
        $reader = new StubSafeFileReader();
        $path = '/tmp/' . uniqid('quicktime_', true) . '.mov';
        $identifierWithWhitespace = 'UUID-5678   ' . "\0\0";
        $reader->withResponse($path, $this->createQuickTimeSample($identifierWithWhitespace));

        $extractor = new QuickTimeContentIdentifierExtractor($reader);

        $identifier = $extractor->extractContentIdentifier(new SplFileInfo($path));

        self::assertInstanceOf(ContentIdentifier::class, $identifier);
        self::assertSame('uuid-5678', $identifier->getValue());
    }

    #[Test]
    public function itExtractsCreationDateFromQuickTimeMetadata(): void
    {
        $reader = new StubSafeFileReader();
        $path = '/tmp/' . uniqid('quicktime_', true) . '.mov';
        $creationDate = '2024-05-05T12:34:56.123456+02:00';

        $reader->withResponse($path, $this->createQuickTimeSample(
            'UUID-0000',
            ['CreationDate' => $creationDate],
        ));

        $extractor = new QuickTimeContentIdentifierExtractor($reader);

        $timestamp = $extractor->extractCreationDate(new SplFileInfo($path));

        self::assertInstanceOf(DateTimeImmutable::class, $timestamp);
        self::assertSame($creationDate, $timestamp->format('Y-m-d\TH:i:s.uP'));
    }

    #[Test]
    public function itReturnsNullWhenCreationDateMissingOrInvalid(): void
    {
        $reader = new StubSafeFileReader();
        $path = '/tmp/' . uniqid('quicktime_', true) . '.mov';
        $reader->withResponse($path, $this->createQuickTimeSample('UUID-1111'));

        $extractor = new QuickTimeContentIdentifierExtractor($reader);

        self::assertNull($extractor->extractCreationDate(new SplFileInfo($path)));

        $invalidPath = '/tmp/' . uniqid('quicktime_', true) . '.mov';
        $reader->withResponse($invalidPath, $this->createQuickTimeSample(
            'UUID-2222',
            ['com.apple.quicktime.creationdate' => 'not-a-date'],
        ));

        self::assertNull($extractor->extractCreationDate(new SplFileInfo($invalidPath)));
    }

    #[Test]
    public function itWrapsReadErrorsInExifMetadataException(): void
    {
        $reader = new StubSafeFileReader();
        $path = '/tmp/' . uniqid('quicktime_', true) . '.mov';
        $reader->withResponse($path, new FileReadException('I/O failure'));

        $extractor = new QuickTimeContentIdentifierExtractor($reader);

        $this->expectException(ExifMetadataReadException::class);
        $this->expectExceptionMessage('Unable to read QuickTime metadata: I/O failure');

        $extractor->extractContentIdentifier(new SplFileInfo($path));
    }

    private function createQuickTimeSample(string $identifier, ?array $additionalMetadata = null): string
    {
        $metadata = ['com.apple.quicktime.content.identifier' => $identifier];

        if ($additionalMetadata !== null) {
            foreach ($additionalMetadata as $key => $value) {
                $metadata[$key] = $value;
            }
        }

        $keysPayload = "\0\0\0\0"
            . pack('N', count($metadata));

        $ilstEntries = '';
        $index = 1;

        foreach ($metadata as $key => $value) {
            $keyEntryPayload = pack('N', 8 + strlen($key))
                . "\0\0\0\0"
                . $key;

            $keysPayload .= $keyEntryPayload;

            $dataPayload = pack('N', 16 + strlen($value))
                . 'data'
                . "\0\0\0\1"
                . "\0\0\0\0"
                . $value;

            $ilstEntries .= pack('N', 8 + strlen($dataPayload))
                . pack('N', $index)
                . $dataPayload;

            ++$index;
        }

        $keysAtom = pack('N', 8 + strlen($keysPayload))
            . 'keys'
            . $keysPayload;

        $ilstAtom = pack('N', 8 + strlen($ilstEntries))
            . 'ilst'
            . $ilstEntries;

        $metaPayload = "\0\0\0\0"
            . $keysAtom
            . $ilstAtom;

        $metaAtom = pack('N', 8 + strlen($metaPayload))
            . 'meta'
            . $metaPayload;

        $udtaAtom = pack('N', 8 + strlen($metaAtom))
            . 'udta'
            . $metaAtom;

        return pack('N', 8 + strlen($udtaAtom))
            . 'moov'
            . $udtaAtom;
    }
}

