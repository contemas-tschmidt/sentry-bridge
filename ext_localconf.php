<?php

declare(strict_types=1);

use AUS\SentryBridge\Logger\BreadcrumbLogger;
use Psr\Log\LogLevel;
use TYPO3\CMS\Frontend\ContentObject\Exception\ProductionExceptionHandler;

$logLevel = ($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['sentry_bridge']['breadcrumb_log_level'] ?? '') ?: LogLevel::DEBUG;
$GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'][$logLevel][BreadcrumbLogger::class] = [];

// Reset XCLass as we overwrite the ProductionExceptionHandler via Configuration/Services.php
unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][ProductionExceptionHandler::class]);
