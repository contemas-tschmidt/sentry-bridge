<?php

declare(strict_types=1);

namespace AUS\SentryBridge\Tests;

use AUS\SentryBridge\Tests\Helper\MockApi;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pluswerk\SentryTestExtension\UserFunction\ErrorTrigger;
use Sentry\Breadcrumb;
use TYPO3\CMS\Core\Information\Typo3Version;

class InitialTest extends TestCase
{
    #[Test]
    public function httpWithoutException(): void
    {
        $mockApi = new MockApi();
        $mockApi->executeScript('vendor/bin/typo3 cache:flush -vvv')->assertOk();
        $response = $mockApi->client()->get('http://localhost:1180/');
        self::assertTrue($response->getStatusCode() === 200, 'HTTP request did not return 200');

        $body = $response->getBody()->getContents();
        self::assertStringContainsString('<h1>Content ~auto~</h1>', $body, 'Expected response body to contain "Content ~auto~"');

        $content = $mockApi->getAndEraseSentryEvents();

        self::assertEmpty($content, 'Expected 0 Sentry events to be captured');
    }

    #[Test]
    public function httpContentObjectOops(): void
    {
        $mockApi = new MockApi();
        $mockApi->executeScript('vendor/bin/typo3 cache:flush -vvv')->assertOk();
        $response = $mockApi->client()->get('http://localhost:1180/?type=500');
        self::assertTrue($response->getStatusCode() === 200, 'HTTP request did not return 200');

        $body = $response->getBody()->getContents();
        self::assertStringContainsString('<a target="_blank" rel="noopener noreferrer" href="https://sentry.example.com/organizations/sentry/issues/?project=1&query=oops_code', $body, 'Expected response body to contain link to sentry instance');
        self::assertStringContainsString('" style="text-decoration: none !important;color: initial !important;">Oops, an error occurred!', $body, 'Expected response body to contain link to sentry instance');

        $content = $mockApi->getAndEraseSentryEvents();

        self::assertCount(1, $content, 'Expected Sentry events to be captured');

        // every exception is send 2 times, once with handled = false and once with handled = true
        foreach ($content as $event) {
            $event->assertSingleException('TypeError', ErrorTrigger::class . '::trigger(): Argument #1 ($errorTrigger) must be of type ' . ErrorTrigger::class . ', string given');
            $event->assertExceptionFileAndLine('test_extension/Classes/UserFunction/ErrorTrigger.php', 16);

            self::assertEquals(['ipAddress' => '127.0.0.0'], $event->user, 'Expected no user data in the event');

            $event->assertMetaData(requestType: 'frontend');

            $categoryFUA = 'TYPO3.CMS.Frontend.Authentication.FrontendUserAuthentication';
            $categoryPTPM = 'TYPO3.CMS.Core.PageTitle.PageTitleProviderManager';
            $breadcrumbs = [];
            if ((new Typo3Version())->getMajorVersion() >= 14) {
                $breadcrumbs[] = new Breadcrumb('debug', 'default', $categoryPTPM, 'Page title provider {provider} skipped on page {title}', ['title' => 0, 'provider' => 0, 'providerUsed' => 2]);
            }

            $breadcrumbs[] = new Breadcrumb('debug', 'default', $categoryPTPM, 'Page title provider {provider} used on page {title}', ['title' => 0, 'provider' => 0]);
            $event->assertBreadCrumbs(
                new Breadcrumb('debug', 'default', $categoryFUA, '## Beginning of auth logging.'),
                new Breadcrumb('debug', 'default', $categoryFUA, 'Login type: {type}', ['type' => 0]),
                new Breadcrumb('debug', 'default', $categoryFUA, 'Login data', ['status' => 0, 'uname' => 0, 'uident' => 0, 'permanent' => 0]),
                new Breadcrumb('debug', 'default', $categoryFUA, 'No user session found'),
                new Breadcrumb('debug', 'default', $categoryFUA, 'No usergroups found'),
                new Breadcrumb('debug', 'default', $categoryFUA, 'Valid frontend usergroups: {groups}', ['groups' => 0]),
                new Breadcrumb('debug', 'default', $categoryPTPM, 'Page title providers ordered', ['orderedTitleProviders' => 0]),
                ...$breadcrumbs,
            );
        }
    }

    #[Test]
    public function middlewareThrow(): void
    {
        $mockApi = new MockApi();
        $mockApi->executeScript('vendor/bin/typo3 cache:flush -vvv')->assertOk();
        $response = $mockApi->client()->get('http://localhost:1180/?throw');
        self::assertTrue($response->getStatusCode() === 500, 'HTTP request did not return 500');

        $content = $mockApi->getAndEraseSentryEvents();

        self::assertCount(1, $content, 'Expected Sentry events to be captured');

        // every exception is send 2 times, once with handled = false and once with handled = true
        foreach ($content as $event) {
            $event->assertSingleException('InvalidArgumentException', 'This is a test exception from the last middleware.');
            $event->assertExceptionFileAndLine('Classes/Middleware/LastMiddlewareTestExceptionMiddleware.php', 22);

            self::assertEquals(['ipAddress' => '127.0.0.0'], $event->user, 'Expected no user data in the event');

            $requestType = 'frontend';
            if ((new Typo3Version())->getMajorVersion() >= 14) {
                // after 14 the middleware stack does not have the TYPO3_REQUEST object and can not find out what type the request is.
                // if https://github.com/networkteam/sentry_client/pull/126 is merged we need to use: $requestType = 'request';
                $requestType = null;
            }

            $event->assertMetaData(requestType: $requestType);

            $categoryFUA = 'TYPO3.CMS.Frontend.Authentication.FrontendUserAuthentication';
            $event->assertBreadCrumbs(
                new Breadcrumb('debug', 'default', $categoryFUA, '## Beginning of auth logging.', [], 0),
                new Breadcrumb('debug', 'default', $categoryFUA, 'Login type: {type}', ['type' => 0], 0),
                new Breadcrumb('debug', 'default', $categoryFUA, 'Login data', ['status' => 0,'uname' => 0, 'uident' => 0,'permanent' => 0], 0),
                new Breadcrumb('debug', 'default', $categoryFUA, 'No user session found', [], 0),
                new Breadcrumb('debug', 'default', $categoryFUA, 'No usergroups found', [], 0),
                new Breadcrumb('debug', 'default', $categoryFUA, 'Valid frontend usergroups: {groups}', ['groups' => 0], 0),
            );
        }
    }

    #[Test]
    public function cli(): void
    {
        $mockApi = new MockApi();
        $result = $mockApi->executeScript('vendor/bin/typo3 test-exception -vvv');
        $result->assertError();
        $result->assertOutput('throws a test exception');
        $result->assertOutput('test_extension/Classes/Command/TestException.php');

        $content = $mockApi->getAndEraseSentryEvents();
        self::assertCount(1, $content, 'Expected Sentry events to be captured');

        foreach ($content as $event) {
            $event->assertSingleException('RuntimeException', 'throws a test exception');
            $event->assertExceptionFileAndLine('test_extension/Classes/Command/TestException.php', 22);

            self::assertEquals([], $event->user, 'Expected no user data in the event');

            $event->assertMetaData(requestType: 'cli');

            $event->assertBreadCrumbs(...[]);
        }
    }

    #[Test]
    public function canExecuteSentryAsyncFlush(): void
    {
        $mockApi = new MockApi();
        $result = $mockApi->executeScript('vendor/bin/typo3 andersundsehr:sentry-async:flush -vvv');
        $result->assertOk();
        $result->assertOutput('running with limit-items=60');
        $result->assertOutput('to do: 0 queued entries');
        $result->assertOutput('done');
    }
}
