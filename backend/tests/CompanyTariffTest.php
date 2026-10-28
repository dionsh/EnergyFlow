<?php

declare(strict_types=1);

use EnergyFlow\Core\Database;
use EnergyFlow\Models\User;
use EnergyFlow\Services\Auth\AuthService;

// A real company picks its KESCO tariff group in Settings and gets its own copy of the
// published ERO template (company_id NULL), which prices its kWh, peak and reactive energy.
$tariffOwner = registeredOwner('tariff-owner@example.com', 'Tariff SME');
$tariffCompany = (int) Database::value("SELECT company_id FROM users WHERE email = 'tariff-owner@example.com'");
$templateId = static fn (string $category): int => (int) Database::value('SELECT id FROM tariff_plans WHERE company_id IS NULL AND category = ?', [$category]);
$tariffStep = static fn (Client $client): bool => array_column($client->get('/onboarding')['body']['data']['steps'], 'done', 'key')['tariff'];

test('a new company has no tariff yet and sees the published groups', function () use ($tariffOwner, $tariffStep): void {
    $state = $tariffOwner->get('/company/tariff');
    assertSame(200, $state['status']);
    assertSame(null, $state['body']['data']['current']);
    assertSame(['commercial_cat_1', 'commercial_cat_2'], array_column($state['body']['data']['templates'], 'category'));
    assertSame(false, $tariffStep($tariffOwner));
});

test('the owner picks Category I: the company gets its own copy, priced at the ERO rates', function () use ($tariffOwner, $tariffCompany, $templateId, $tariffStep): void {
    $result = $tariffOwner->patch('/company/tariff', ['template_id' => $templateId('commercial_cat_1')]);
    assertSame(200, $result['status']);
    $current = $result['body']['data']['current'];
    assertSame('commercial_cat_1', $current['category']);
    assertTrue($current['id'] !== $templateId('commercial_cat_1'), 'the company has its own copy of the template');
    assertSame($current['id'], (int) Database::value('SELECT tariff_plan_id FROM companies WHERE id = ?', [$tariffCompany]));
    assertSame(0.087, $current['rate_high']);
    assertSame(3.87, $current['demand_charge_eur_kw_month']);
    assertSame(0.95, $current['reactive_pf_threshold']);
    assertSame(true, $tariffStep($tariffOwner));
});

test('switching groups leaves one active plan; the earlier copy stays, inactive', function () use ($tariffOwner, $tariffCompany, $templateId): void {
    assertSame(200, $tariffOwner->patch('/company/tariff', ['template_id' => $templateId('commercial_cat_2')])['status']);
    assertSame('commercial_cat_2', $tariffOwner->get('/company/tariff')['body']['data']['current']['category']);
    assertSame(1, (int) Database::value('SELECT COUNT(*) FROM tariff_plans WHERE company_id = ? AND is_active = 1', [$tariffCompany]));
    assertSame(2, (int) Database::value('SELECT COUNT(*) FROM tariff_plans WHERE company_id = ?', [$tariffCompany]));
});

test('only admins pick a tariff, and only a published template', function () use ($tariffOwner, $tariffCompany, $templateId): void {
    User::create($tariffCompany, 'tariff-worker@example.com', AuthService::hash('correct-horse-battery'), 'Besa Worker', 'worker', 'sq');
    $worker = new Client('10.9.2.2');
    assertSame(200, $worker->post('/auth/login', ['email' => 'tariff-worker@example.com', 'password' => 'correct-horse-battery'])['status']);
    assertSame(200, $worker->get('/company/tariff')['status']);
    assertSame(403, $worker->patch('/company/tariff', ['template_id' => $templateId('commercial_cat_1')])['status']);

    $ownCopy = (int) Database::value('SELECT tariff_plan_id FROM companies WHERE id = ?', [$tariffCompany]);
    foreach ([$ownCopy, 999999] as $notATemplate) {
        $result = $tariffOwner->patch('/company/tariff', ['template_id' => $notATemplate]);
        assertSame(422, $result['status']);
        assertSame(['template_id' => 'invalid_choice'], $result['body']['error']['fields']);
    }
    assertSame(422, $tariffOwner->patch('/company/tariff', [])['status']);
    assertSame($ownCopy, (int) Database::value('SELECT tariff_plan_id FROM companies WHERE id = ?', [$tariffCompany]));
});
