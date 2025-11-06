<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MonthlySumConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->clearEnv('MONTHLY_SUM_ACCOUNT');
        $this->clearEnv('MONTHLY_SUM_ACCOUNT_CASH');
        $this->clearEnv('MONTHLY_SUM_ACCOUNT_TIME_DEPOSIT');
    }

    protected function tearDown(): void
    {
        $this->clearEnv('MONTHLY_SUM_ACCOUNT');
        $this->clearEnv('MONTHLY_SUM_ACCOUNT_CASH');
        $this->clearEnv('MONTHLY_SUM_ACCOUNT_TIME_DEPOSIT');

        parent::tearDown();
    }

    public function test_combined_account_env_supports_comma_separated_quotes(): void
    {
        $this->setEnv('MONTHLY_SUM_ACCOUNT', '"現金/普通預金","定期預金"');

        $config = $this->loadConfig();

        $this->assertSame(['現金/普通預金', '定期預金'], $config['monthly_sum']['accounts']);
    }

    public function test_combined_account_env_supports_raw_csv_values(): void
    {
        $this->setEnv('MONTHLY_SUM_ACCOUNT', '現金/普通預金","定期預金');

        $config = $this->loadConfig();

        $this->assertSame(['現金/普通預金', '定期預金'], $config['monthly_sum']['accounts']);
    }

    public function test_falls_back_to_legacy_variables_when_combined_missing(): void
    {
        $this->setEnv('MONTHLY_SUM_ACCOUNT_CASH', "現金/普通預金\n手元現金");
        $this->setEnv('MONTHLY_SUM_ACCOUNT_TIME_DEPOSIT', '"定期預金"');

        $config = $this->loadConfig();

        $this->assertSame(['現金/普通預金', '手元現金', '定期預金'], $config['monthly_sum']['accounts']);
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function clearEnv(string $name): void
    {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    private function loadConfig(): array
    {
        return require __DIR__ . '/../../config/services.php';
    }
}
