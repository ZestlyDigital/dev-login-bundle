<?php

declare(strict_types=1);

namespace Zestly\DevLoginBundle\Tests\Fixtures;

/**
 * Booting a kernel with debug on installs Symfony's global error and exception handlers and
 * never removes them. PHPUnit correctly reports that as a leak, which would make every
 * functional test risky.
 *
 * Unwind back to the handlers that were installed when the test started — not all the way to
 * an empty stack, which would also discard PHPUnit's own handlers and trade one risky-test
 * warning for another. Doing this rather than lowering failOnRisky means a genuine handler
 * leak introduced by this bundle would still be caught.
 */
trait RestoresErrorHandlers
{
    private mixed $baselineExceptionHandler = null;
    private mixed $baselineErrorHandler = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baselineExceptionHandler = set_exception_handler(null);
        restore_exception_handler();

        $this->baselineErrorHandler = set_error_handler(null);
        restore_error_handler();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // The bound is a safety net against an unbalanced stack spinning forever; no test
        // here boots anything like 32 kernels.
        for ($i = 0; $i < 32; ++$i) {
            $current = set_exception_handler(null);
            restore_exception_handler();

            if ($current === $this->baselineExceptionHandler) {
                break;
            }

            restore_exception_handler();
        }

        for ($i = 0; $i < 32; ++$i) {
            $current = set_error_handler(null);
            restore_error_handler();

            if ($current === $this->baselineErrorHandler) {
                break;
            }

            restore_error_handler();
        }
    }
}
