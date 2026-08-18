<?php

declare(strict_types=1);

namespace AUS\SentryBridge\Tests\Helper;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Sentry\Breadcrumb;
use Sentry\Event;
use Throwable;

use function array_filter;
use function array_flip;
use function array_keys;
use function dirname;
use function file_put_contents;
use function function_exists;
use function is_dir;
use function serialize;

use const PHP_EOL;

final readonly class MockApi
{
    private const SERIALIZATION_LINE_SEPARATOR = PHP_EOL . '__NEXT__' . PHP_EOL;

    private string $randomSeed;

    private string $projectPath;

    public function __construct(?string $randomSeed = null, ?string $projectPath = null)
    {
        $this->projectPath = $projectPath ?? getcwd() ?: throw new Exception('Project path is not set. Please provide a valid project path.', 7229732020);
        $this->randomSeed = $randomSeed ?? md5(microtime(true) . random_int(0, 1000000));
    }

    public function executeScript(string $script): ScriptResult
    {
        // redirect stderr to stdout
        $command = 'TYPO3_CONTEXT=Production SENTRY_MOCK_SEED=' . $this->randomSeed . ' ' . $script . ' 2>&1';
        $output = [];
        exec($command, $output, $exitcode);
        return new ScriptResult(
            command: $command,
            output: implode("\n", $output),
            exitCode: $exitcode,
        );
    }

    public function client(): Client
    {
        return new Client([
            RequestOptions::HEADERS => [
                // add Seed so the file is created with the same name
                'X-Sentry-Mock-Seed' => $this->randomSeed,
            ],
            // disable Exceptions on HTTP errors
            RequestOptions::HTTP_ERRORS => false,
        ]);
    }

    /**
     * @return SentryEvent[]
     */
    public function getAndEraseSentryEvents(): array
    {
        $fileName = $this->getFileName();
        if (!file_exists($fileName)) {
            return [];
        }

        $content = file_get_contents($fileName);
        if ($content === false) {
            throw new Exception('File ' . $fileName . ' could not be read. Did sentry not catch your exception?', 8874737994);
        }

        unlink($fileName);

        $result = [];
        $lines = explode(self::SERIALIZATION_LINE_SEPARATOR, trim($content));
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $result[] = unserialize($line, ['allowed_classes' => SentryEvent::UNSERIALIZE_ALLOWED_CLASSES]);
        }

        return $result;
    }

    public function save(Event $eventObject): void
    {
        try {
            $user = array_filter([
                'id' => $eventObject->getUser()?->getId(),
                'username' => $eventObject->getUser()?->getEmail(),
                'email' => $eventObject->getUser()?->getUsername(),
                'ipAddress' => $eventObject->getUser()?->getIpAddress(),
                'metadata' => $eventObject->getUser()?->getMetadata(),
            ]);


            $breadcrumbs = [];
            foreach ($eventObject->getBreadcrumbs() as $breadcrumb) {
                $breadcrumbs[] = new Breadcrumb(
                    level: $breadcrumb->getLevel(),
                    type: $breadcrumb->getType(),
                    category: $breadcrumb->getCategory(),
                    message: $breadcrumb->getMessage(),
                    metadata: array_flip(array_keys($breadcrumb->getMetadata())),
                    timestamp: $breadcrumb->getTimestamp(),
                );
            }

            $sentryEvent = new SentryEvent(
                id: $eventObject->getId()->__toString(),
                environment: $eventObject->getEnvironment(),
                message: $eventObject->getMessage(),
                level: $eventObject->getLevel()?->__toString(),
                exceptions: $eventObject->getExceptions(),
                user: $user,
                tags: $eventObject->getTags(),
                extra: $eventObject->getExtra(),
                breadcrumbs: $breadcrumbs,
                runtimeContext: $eventObject->getRuntimeContext(),
            );
            $fileName = $this->getFileName();
            if (!is_dir(dirname($fileName))) {
                mkdir(dirname($fileName), recursive: true);
            }

            file_put_contents($fileName, serialize($sentryEvent) . self::SERIALIZATION_LINE_SEPARATOR, FILE_APPEND | LOCK_EX);
//            file_put_contents($fileName . '.txt', new Exception()->getTraceAsString() . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable $throwable) {
            if (function_exists('dump')) {
                dump($eventObject, $throwable);
            } else {
                var_dump($eventObject, $throwable);
            }

            die(__METHOD__ . ':' . __LINE__);
        }
    }

    private function getFileName(): string
    {
        return $this->projectPath . '/var/sentry-mock-log/' . $this->randomSeed . '.ser';
    }
}
