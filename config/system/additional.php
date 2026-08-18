<?php

declare(strict_types=1);

use Networkteam\SentryClient\ProductionExceptionHandler;
use Networkteam\SentryClient\DebugExceptionHandler;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use AUS\SentryBridge\Tests\Helper\MockTransportFactory;

// Auto create DB file:
$GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['path'] = dirname(__DIR__, 2) . '/var/sqlite/cms.sqlite';

if (!is_dir(dirname($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['path']))) {
    // Create the directory if it does not exist
    mkdir(dirname($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['path']), recursive: true);
}

if (!file_exists($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['path'])) {
    touch($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['path']);
}

// Configure networkteam/sentry-client
$GLOBALS['TYPO3_CONF_VARS']['SYS']['productionExceptionHandler'] = ProductionExceptionHandler::class;
$GLOBALS['TYPO3_CONF_VARS']['SYS']['debugExceptionHandler'] = DebugExceptionHandler::class;
// add Release version to Sentry events
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['sentry_client']['release'] = trim((string)exec('git rev-parse --verify HEAD'));
// allow throw URL parameter to be used in tests
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'throw';
// register Sentry transport for tests
MockTransportFactory::register();
