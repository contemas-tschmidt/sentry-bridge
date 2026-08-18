<?php

declare(strict_types=1);

namespace AUS\SentryBridge\Tests\Helper;

use PHPUnit\Framework\Assert;
use Sentry\Breadcrumb;
use Sentry\Context\RuntimeContext;
use Sentry\ExceptionDataBag;
use Sentry\ExceptionMechanism;
use Sentry\Frame;
use Sentry\Stacktrace;

use function array_flip;
use function array_key_last;
use function array_keys;
use function time;

use const PHP_VERSION;

final readonly class SentryEvent
{
    public const UNSERIALIZE_ALLOWED_CLASSES = [
        SentryEvent::class,
        ExceptionDataBag::class,
        Stacktrace::class,
        Frame::class,
        ExceptionMechanism::class,
        Breadcrumb::class,
        RuntimeContext::class,
    ];

    public function __construct(
        public string $id,
        public ?string $environment = null,
        public ?string $message = null,
        public ?string $level = null,
        /**
         * @var ExceptionDataBag[]
         */
        public array $exceptions = [],
        /**
         * @var array<string, mixed>
         */
        public ?array $user = null,
        /**
         * @var array<string, mixed>
         */
        public array $tags = [],
        /**
         * @var array<string, mixed>
         */
        public array $extra = [],
        /**
         * @var Breadcrumb[]
         */
        public array $breadcrumbs = [],
        public ?RuntimeContext $runtimeContext = null,
    ) {
    }

    /**
     * @return Breadcrumb[]
     */
    public function getBreadCrumbsWithoutTimestamp(): array
    {
        $breadcrumbs = [];
        foreach ($this->breadcrumbs as $breadcrumb) {
            Assert::assertGreaterThan(time() - 10, $breadcrumb->getTimestamp(), 'Expected breadcrumb timestamp to be within the last 10 seconds');
            $breadcrumbs[] = $breadcrumb->withTimestamp(0); // Set timestamp to 0 for easier comparison
        }

        return $breadcrumbs;
    }

    public function getException(): ExceptionDataBag
    {
        Assert::assertCount(1, $this->exceptions, 'Expected exactly one exception in the event');
        $exception = $this->exceptions[0];
        Assert::assertInstanceOf(ExceptionDataBag::class, $exception);
        return $exception;
    }

    public function assertSingleException(string $throwableClass, string $messageContains): void
    {
        $exception = $this->getException();
        Assert::assertStringContainsString($messageContains, $exception->getValue(), 'Exception message does not contain expected value');
        Assert::assertEquals($throwableClass, $exception->getType(), 'Exception type does not match expected value');
    }

    public function getLastStackTraceFrame(): Frame
    {
        $exception = $this->getException();
        $stacktrace = $exception->getStacktrace();
        Assert::assertInstanceOf(Stacktrace::class, $stacktrace);
        $frames = $stacktrace->getFrames();
        return $frames[(int)array_key_last($frames)];
    }

    /**
     * @param non-empty-string $fileName
     */
    public function assertExceptionFileAndLine(string $fileName, int $lineNumber, int $plusMinus = 5): void
    {
        $lastFrame = $this->getLastStackTraceFrame();
        Assert::assertStringEndsWith($fileName, $lastFrame->getFile(), 'Exception file does not match expected value');
        Assert::assertGreaterThanOrEqual($lineNumber - $plusMinus, $lastFrame->getLine(), 'Exception line does not match expected value');
        Assert::assertLessThanOrEqual($lineNumber + $plusMinus, $lastFrame->getLine(), 'Exception line does not match expected value');
    }

    public function assertMetaData(?string $requestType): void
    {
        Assert::assertNotEmpty($this->tags['typo3_version'], 'Expected tags "typo3_version" to not be empty');
        Assert::assertEquals($requestType, $this->tags['request_type'], 'Expected tags "typo3_mode" to be "frontend"');

        Assert::assertInstanceOf(RuntimeContext::class, $this->runtimeContext);
        Assert::assertEquals(PHP_VERSION, $this->runtimeContext->getVersion(), 'Expected runtime context "version" to match PHP_VERSION');
        Assert::assertEquals('Production', $this->environment, 'Expected tags "application_context" to be "Production"'); // only in environment?
    }

    public function assertBreadCrumbs(Breadcrumb ...$breadcrumbs): void
    {
        foreach ($breadcrumbs as $index => $breadcrumb) {
            $breadcrumbs[$index] = new Breadcrumb(
                level: $breadcrumb->getLevel(),
                type: $breadcrumb->getType(),
                category: $breadcrumb->getCategory(),
                message: $breadcrumb->getMessage(),
                metadata: array_flip(array_keys($breadcrumb->getMetadata())),
                timestamp: 0, // Set timestamp to 0 for easier comparison
            );
        }

        $recordedBreadCrumbs = [];
        foreach ($this->breadcrumbs as $breadcrumb) {
            $recordedBreadCrumbs[] = new Breadcrumb(
                level: $breadcrumb->getLevel(),
                type: $breadcrumb->getType(),
                category: $breadcrumb->getCategory(),
                message: $breadcrumb->getMessage(),
                metadata: array_flip(array_keys($breadcrumb->getMetadata())),
                timestamp: 0, // Set timestamp to 0 for easier comparison
            );
        }

        Assert::assertEquals($breadcrumbs, $recordedBreadCrumbs);
    }
}
