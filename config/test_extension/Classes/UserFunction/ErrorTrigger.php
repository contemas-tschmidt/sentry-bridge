<?php

declare(strict_types=1);

namespace Pluswerk\SentryTestExtension\UserFunction;

use Exception;
use TYPO3\CMS\Core\Attribute\AsAllowedCallable;

final readonly class ErrorTrigger
{
    #[AsAllowedCallable]
    public function trigger(ErrorTrigger $errorTrigger): never
    {
        throw new Exception('This triggers an Exception' . $errorTrigger::class, 7342931057);
    }
}
