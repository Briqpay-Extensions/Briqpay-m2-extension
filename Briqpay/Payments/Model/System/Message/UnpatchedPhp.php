<?php
/**
 * Admin system message when the server's PHP lacks the fixes PhpRuntime checks for.
 *
 * Magento builds system messages only in the admin, so storefront requests never run
 * this check.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Model\System\Message;

use Briqpay\Payments\Model\System\PhpRuntime;
use Magento\Framework\Notification\MessageInterface;

class UnpatchedPhp implements MessageInterface
{
    /**
     * @var PhpRuntime
     */
    private PhpRuntime $phpRuntime;

    /**
     * @param PhpRuntime $phpRuntime
     */
    public function __construct(PhpRuntime $phpRuntime)
    {
        $this->phpRuntime = $phpRuntime;
    }

    /**
     * @inheritdoc
     */
    public function getIdentity()
    {
        return 'briqpay_unpatched_php_' . PHP_VERSION;
    }

    /**
     * @inheritdoc
     */
    public function isDisplayed()
    {
        return $this->phpRuntime->needsWarning();
    }

    /**
     * @inheritdoc
     */
    public function getText()
    {
        return (string) __(
            'This server runs PHP %1, which is affected by %2 (remote code execution in PHP itself). '
            . 'Update PHP to %3 or later. If your hosting provider has backported the fix to this build, '
            . 'confirm it under Stores > Configuration > Sales > Payment Methods > Briqpay > Advanced.',
            PHP_VERSION,
            PhpRuntime::ADVISORY,
            (string) $this->phpRuntime->requiredUpdate(PHP_VERSION)
        );
    }

    /**
     * @inheritdoc
     */
    public function getSeverity()
    {
        return self::SEVERITY_CRITICAL;
    }
}
