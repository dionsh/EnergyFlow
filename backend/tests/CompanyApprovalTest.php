<?php
declare(strict_types=1);

use EnergyFlow\Core\Database;

test('company requests require platform approval and workers cannot manage users', function (): void {
    $guest = new Client('10.88.1.1');
    $input = ['company_name' => 'Approval Company', 'full_name' => 'Company Owner', 'email' => 'approval-owner@example.test', 'password' => '1234567890', 'locale' => 'en'];
    assertSame(201, $guest->post('/auth/register', $input)['status']);
    assertSame(401, $guest->get('/auth/me')['status']);
    assertSame(401, $guest->post('/auth/login', ['email' => $input['email'], 'password' => $input['password']])['status']);
    $admin = registeredOwner('approval-admin@example.test');
    Database::run('UPDATE users SET is_platform_admin = 1 WHERE email = ?', ['approval-admin@example.test']);
    $id = (int) Database::value('SELECT id FROM company_requests WHERE email = ?', [$input['email']]);
    assertSame(403, registeredOwner('approval-other@example.test')->post("/admin/requests/{$id}/approve")['status']);
    assertSame(200, $admin->post("/admin/requests/{$id}/approve")['status']);
    assertSame(404, $admin->post("/admin/requests/{$id}/approve")['status']);
    assertSame(200, $guest->post('/auth/login', ['email' => $input['email'], 'password' => $input['password']])['status']);
    $created = $guest->post('/company/workers', ['full_name' => 'Worker Name', 'email' => 'approval-worker@example.test', 'password' => '1234567890', 'role' => 'owner', 'is_platform_admin' => true]);
    assertSame(200, $created['status']);
    assertSame('manager', $created['body']['data']['role']);
    assertSame(false, $created['body']['data']['is_platform_admin']);
    $workerId = $created['body']['data']['id'];
    assertSame(200, $guest->patch("/company/workers/{$workerId}", ['full_name' => 'Updated Worker'])['status']);
    assertSame(404, $admin->patch("/company/workers/{$workerId}", ['full_name' => 'Wrong Company'])['status']);
    $worker = new Client('10.88.1.2');
    assertSame(200, $worker->post('/auth/login', ['email' => 'approval-worker@example.test', 'password' => '1234567890'])['status']);
    assertSame(403, $worker->get('/company/workers')['status']);
    assertSame(403, $worker->post('/company/workers', [])['status']);
    assertSame(403, $worker->patch("/company/workers/{$workerId}", ['full_name' => 'Self'])['status']);
});
