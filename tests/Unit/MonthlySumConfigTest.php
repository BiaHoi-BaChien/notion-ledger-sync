<?php

namespace Tests\Unit;

use Tests\TestCase;

class MonthlySumConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->clearEnv('MONTHLY_SUM_ACCOUNT');
        $this->clearEnv('MONTHLY_SUM_ACCOUNT_CASH');
        $this->clearEnv('MONTHLY_SUM_ACCOUNT_TIME_DEPOSIT');
        $this->clearEnv('CASH_OR_SAVING');
        $this->clearEnv('TIME_DEPOSIT');
        $this->clearEnv('OTHER_ACCOUNT');
    }

    protected function tearDown(): void
    {
        $this->clearEnv('MONTHLY_SUM_ACCOUNT');
        $this->clearEnv('MONTHLY_SUM_ACCOUNT_CASH');
        $this->clearEnv('MONTHLY_SUM_ACCOUNT_TIME_DEPOSIT');
        $this->clearEnv('CASH_OR_SAVING');
        $this->clearEnv('TIME_DEPOSIT');
        $this->clearEnv('OTHER_ACCOUNT');

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

    public function test_combined_account_env_supports_plus_separated_env_variables(): void
    {
        $this->setEnv('MONTHLY_SUM_ACCOUNT', 'CASH_OR_SAVING+TIME_DEPOSIT+OTHER_ACCOUNT');
        $this->setEnv('CASH_OR_SAVING', "現金/普通預金\n手元現金");
        $this->setEnv('TIME_DEPOSIT', '定期預金');
        $this->setEnv('OTHER_ACCOUNT', '海外口座');

        $config = $this->loadConfig();

        $this->assertSame(['現金/普通預金', '手元現金', '定期預金', '海外口座'], $config['monthly_sum']['accounts']);
    }

    public function test_plus_separated_env_variables_ignores_empty_values(): void
    {
        $this->setEnv('MONTHLY_SUM_ACCOUNT', 'CASH_OR_SAVING+TIME_DEPOSIT+OTHER_ACCOUNT');
        $this->setEnv('CASH_OR_SAVING', '現金/普通預金');
        $this->setEnv('TIME_DEPOSIT', '定期預金');
        $this->setEnv('OTHER_ACCOUNT', '');

        $config = $this->loadConfig();

        $this->assertSame(['現金/普通預金', '定期預金'], $config['monthly_sum']['accounts']);
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
