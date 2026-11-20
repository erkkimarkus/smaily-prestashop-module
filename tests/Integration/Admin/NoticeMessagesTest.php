<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use PrestaShop\Module\SmailyForPrestaShop\Admin\NoticeMessages;
use PrestaShop\Module\SmailyForPrestaShop\Config\MigrationNotices;
use PrestaShop\Module\SmailyForPrestaShop\Migration\MigrationPlan;
use Tests\Integration\IntegrationTestCase;

/**
 * Every notice code that the migration writes reads as an English sentence with its parameters
 * filled in, and every reason why a feature stays off has its own words.
 */
final class NoticeMessagesTest extends IntegrationTestCase
{
    /**
     * @return array<string, array{string, array<string, int|string>}>
     */
    public function notices(): array
    {
        $notices = [
            MigrationPlan::NOTICE_SETTINGS_MIGRATED => [],
            MigrationPlan::NOTICE_FIELD_LIST_UNREADABLE => ['key' => 'SMAILY_SYNCRONIZE_ADDITIONAL'],
            MigrationPlan::NOTICE_CRON_TOKEN_REPLACED => ['key' => 'SMAILY_CART_CRON_TOKEN', 'id_shop_group' => 0, 'id_shop' => 0],
            MigrationPlan::NOTICE_CRON_SHOP_ASSUMED => ['id_shop' => 1],
            MigrationPlan::NOTICE_NEWSLETTER_BLOCK_REMOVED => [],
            MigrationPlan::NOTICE_EMAILSUBSCRIPTION_EMAILS_CHANGED => [],
            MigrationNotices::OVERRIDE_LEFT_IN_PLACE => ['path' => 'override/modules/ps_emailsubscription/ps_emailsubscription.php'],
        ];
        $cases = [];
        foreach ($notices as $code => $params) {
            $cases[$code] = [$code, $params];
        }
        $features = [MigrationPlan::NOTICE_CONTACT_SYNC_LEFT_OFF, MigrationPlan::NOTICE_WELCOME_LEFT_OFF, MigrationPlan::NOTICE_ABANDONED_CART_LEFT_OFF];
        foreach ($features as $code) {
            foreach (self::reasons() as $reason) {
                $cases[$code . ' ' . $reason] = [$code, ['id_shop' => 1, 'reason' => $reason]];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider notices
     *
     * @param array<string, int|string> $params
     */
    public function testEachNoticeReadsAsASentence(string $code, array $params): void
    {
        $text = self::messages()->text(['id' => 'x', 'code' => $code, 'params' => $params]);

        self::assertStringNotContainsString('%', $text);
        self::assertStringNotContainsString('left a notice (', $text, 'the code has words of its own');
        self::assertStringNotContainsString('did not send it.', $text, 'the reason has words of its own');
        self::assertMatchesRegularExpression('/^[A-Z].*\.$/', $text);
        if (isset($params['id_shop']) && $code !== MigrationPlan::NOTICE_CRON_TOKEN_REPLACED) {
            self::assertStringContainsString('Shop One', $text);
        }
    }

    public function testEveryReasonOfThePlanIsCovered(): void
    {
        $constants = (new \ReflectionClass(MigrationPlan::class))->getConstants();
        $reasons = array_values(array_filter($constants, static fn ($name): bool => strpos($name, 'REASON_') === 0, ARRAY_FILTER_USE_KEY));
        $codes = array_values(array_filter($constants, static fn ($name): bool => strpos($name, 'NOTICE_') === 0, ARRAY_FILTER_USE_KEY));

        sort($reasons);
        $covered = self::reasons();
        sort($covered);
        self::assertSame($reasons, $covered);
        foreach ($codes as $code) {
            self::assertStringNotContainsString('left a notice (', self::messages()->text(['id' => 'x', 'code' => $code, 'params' => ['id_shop' => 1, 'reason' => MigrationPlan::REASON_NO_ACCOUNT]]));
        }
    }

    /**
     * @return list<string>
     */
    private static function reasons(): array
    {
        return [
            MigrationPlan::REASON_SWITCH_NOT_READ,
            MigrationPlan::REASON_NO_ACCOUNT,
            MigrationPlan::REASON_NO_WORKFLOW,
            MigrationPlan::REASON_FIELD_LIST_NOT_JSON,
            MigrationPlan::REASON_NOT_DEFAULT_SHOP,
            MigrationPlan::REASON_CARTS_NOT_REACHED,
            MigrationPlan::REASON_OTHER_ACCOUNT,
        ];
    }

    private static function messages(): NoticeMessages
    {
        return new NoticeMessages(\Context::getContext()->getTranslator(), static fn (int $idShop): string => $idShop === 1 ? 'Shop One' : 'Shop ' . $idShop);
    }
}
