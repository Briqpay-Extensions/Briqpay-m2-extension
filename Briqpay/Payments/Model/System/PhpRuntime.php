<?php
/**
 * Whether the running PHP has the fixes for CVE-2026-109618 and CVE-2026-109619
 * (unauthenticated remote code execution in PHP itself, fixed on 21 October 2026).
 *
 * The check reads only the version number. Hosting providers and distributions often
 * ship the fix in a build that keeps its old number, so a merchant can confirm a
 * backported build in the module settings (payment/briqpay/advanced/php_backport_confirmed)
 * or with the environment variable BRIQPAY_PHP_BACKPORT_CONFIRMED=1. Nothing is ever
 * blocked: the module keeps working and the admin is told.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Model\System;

use Magento\Framework\App\Config\ScopeConfigInterface;

class PhpRuntime
{
    public const BACKPORT_CONFIG_PATH = 'payment/briqpay/advanced/php_backport_confirmed';
    public const BACKPORT_ENV = 'BRIQPAY_PHP_BACKPORT_CONFIRMED';
    public const ADVISORY = 'CVE-2026-109618, CVE-2026-109619';

    /**
     * The first fixed release per minor version.
     */
    private const FIXED = [
        '8.2' => '8.2.35',
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * The release to update to, or null when the version has the fixes.
     *
     * Versions before 8.2 never got them; a minor version after 8.5 released later has them.
     *
     * @param string $version a PHP version, such as PHP_VERSION
     * @return string|null
     */
    public function requiredUpdate(string $version): ?string
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $parts)) {
            return null;
        }
        $minor = $parts[1] . '.' . $parts[2];
        $plain = $minor . '.' . $parts[3];
        if (version_compare($plain, '8.2.0', '<')) {
            return self::FIXED['8.2'];
        }
        $fixed = self::FIXED[$minor] ?? null;

        return $fixed !== null && version_compare($plain, $fixed, '<') ? $fixed : null;
    }

    /**
     * Whether the admin should be warned about the PHP this process runs on.
     *
     * @return bool
     */
    public function needsWarning(): bool
    {
        return $this->requiredUpdate(PHP_VERSION) !== null && !$this->backportConfirmed();
    }

    /**
     * Whether the merchant confirmed that their PHP build carries the fixes.
     *
     * @return bool
     */
    public function backportConfirmed(): bool
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- an operator's switch, read like Magento's own MAGE_ env settings
        if (getenv(self::BACKPORT_ENV) === '1') {
            return true;
        }

        return $this->scopeConfig->isSetFlag(self::BACKPORT_CONFIG_PATH);
    }
}
