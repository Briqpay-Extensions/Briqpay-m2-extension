<?php
/**
 * PATCH runs without a deprecation: Magento turns every deprecation into an exception,
 * in production too, so one would fail the request (curl_close() on PHP 8.5).
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Rest;

use Briqpay\Payments\Rest\BriqpayCurl;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Briqpay\Payments\Rest\BriqpayCurl
 */
class BriqpayCurlTest extends TestCase
{
    public function testPatchRaisesNoDeprecation(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'briqpay-curl');
        $this->assertNotFalse($file);
        file_put_contents($file, '{"ok":true}');
        $raised = [];
        set_error_handler(static function (int $level, string $message) use (&$raised): bool {
            $raised[] = $level . ': ' . $message;

            return true;
        });
        try {
            $curl = new BriqpayCurl();
            $curl->addHeader('Content-Type', 'application/json');
            $curl->patch('file://' . $file, '{}');
        } finally {
            restore_error_handler();
            unlink($file);
        }

        $this->assertSame([], $raised);
    }

    /**
     * A transfer that fails before any response records its error without a PHP 8.2
     * dynamic-property deprecation, which Magento would turn into an exception hiding it.
     */
    public function testFailedPatchRaisesNoDeprecation(): void
    {
        $raised = [];
        set_error_handler(static function (int $level, string $message) use (&$raised): bool {
            $raised[] = $level . ': ' . $message;

            return true;
        });
        try {
            (new BriqpayCurl())->patch('file:///nonexistent/briqpay-curl-test', '{}');
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $raised);
    }
}
