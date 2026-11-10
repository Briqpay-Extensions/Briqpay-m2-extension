<?php
/**
 * Contract test: the public PHP surface a third-party Hyva Checkout integration calls
 * directly. That extension is released on its own schedule and is not part of this
 * repository, so these classes and method signatures are a public API.
 *
 * Each class must exist, be concrete and not final (the extension may extend or plugin
 * it), and each listed method must be public, non-static and have exactly the parameter
 * names, types, defaults and return type the module has always had. Constructors are deliberately not
 * checked: the extension gets these classes from the object manager, so constructor
 * arguments may change.
 *
 * A failure here is a backward-compatibility break for that extension. Keep the old
 * signature (add a shim or adapter that forwards to the new code); do not update this
 * test to match the new signature.
 */
declare(strict_types=1);

namespace Briqpay\Payments\Test\Unit\Compat;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class HyvaCheckoutContractTest extends TestCase
{
    /**
     * Class => method => signature, written as PHP would declare it.
     */
    private const CONTRACT = [
        \Briqpay\Payments\Model\PaymentMethod::class => [
            'getTitle' => 'getTitle()',
        ],
        \Briqpay\Payments\Model\Utility\GenerateCart::class => [
            'getCart' => 'getCart()',
            'getCartData' => 'getCartData(): array',
        ],
        \Briqpay\Payments\Model\PaymentModule\CreateSession::class => [
            'getPaymentModule' => 'getPaymentModule($fallbackEmail = null)',
        ],
        \Briqpay\Payments\Model\PaymentModule\ReadSession::class => [
            'getSession' => 'getSession(string $sessionId)',
        ],
        \Briqpay\Payments\Model\PaymentModule\UpdateSession::class => [
            'updateSession' => 'updateSession(string $sessionId, $fallbackEmail = null): array',
        ],
        \Briqpay\Payments\Model\Utility\ReinitialiseSession::class => [
            'reinitialiseSession' => 'reinitialiseSession(string $sessionId, $fallbackEmail = null): array',
        ],
        \Briqpay\Payments\Model\Utility\MakeDecisionLogic::class => [
            'makeDecision' => 'makeDecision(string $sessionId, $fallbackEmail = null): bool',
        ],
        \Briqpay\Payments\Model\Utility\ScopeHelper::class => [
            'getScopedConfigValue' => 'getScopedConfigValue(string $path): ?string',
        ],
        \Briqpay\Payments\Logger\Logger::class => [
            'info' => 'info($message, array $context = []): void',
        ],
        \Briqpay\Payments\Controller\Order\Success::class => [
            'execute' => 'execute()',
        ],
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function classProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::CONTRACT) as $class) {
            $cases[$class] = [$class];
        }

        return $cases;
    }

    /**
     * @dataProvider classProvider
     */
    public function testClassExistsIsConcreteAndNotFinal(string $class): void
    {
        $this->assertTrue(class_exists($class), "$class must exist");
        $reflection = new \ReflectionClass($class);

        $this->assertFalse($reflection->isInterface(), "$class must be a class");
        $this->assertFalse($reflection->isAbstract(), "$class must not be abstract");
        $this->assertFalse($reflection->isFinal(), "$class must not be final");
        $this->assertTrue($reflection->isInstantiable(), "$class must be instantiable");
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function methodProvider(): array
    {
        $cases = [];
        foreach (self::CONTRACT as $class => $methods) {
            foreach ($methods as $method => $signature) {
                $cases["$class::$method"] = [$class, $method, $signature];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider methodProvider
     */
    public function testMethodIsPublicWithTheExactSignature(string $class, string $method, string $signature): void
    {
        $this->assertTrue(method_exists($class, $method), "$class::$method must exist");
        $reflection = new \ReflectionMethod($class, $method);

        $this->assertTrue($reflection->isPublic(), "$class::$method must be public");
        $this->assertFalse($reflection->isStatic(), "$class::$method must not be static");
        $this->assertFalse($reflection->isAbstract(), "$class::$method must not be abstract");
        $this->assertSame($signature, $this->signature($reflection), "$class::$method signature changed");
    }

    private function signature(\ReflectionMethod $method): string
    {
        $params = [];
        foreach ($method->getParameters() as $param) {
            $params[] = $this->parameter($param);
        }
        $signature = $method->getName() . '(' . implode(', ', $params) . ')';
        $returnType = $method->getReturnType();

        return $returnType === null ? $signature : $signature . ': ' . $returnType;
    }

    private function parameter(\ReflectionParameter $param): string
    {
        $type = $param->getType();
        $text = ($type === null ? '' : $type . ' ')
            . ($param->isPassedByReference() ? '&' : '')
            . ($param->isVariadic() ? '...' : '')
            . '$' . $param->getName();
        if ($param->isDefaultValueAvailable()) {
            $text .= ' = ' . $this->literal($param->getDefaultValue());
        }

        return $text;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function literal($value): string
    {
        if ($value === null) {
            return 'null';
        }
        if ($value === []) {
            return '[]';
        }

        return var_export($value, true);
    }
}
