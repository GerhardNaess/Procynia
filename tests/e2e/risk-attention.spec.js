import { exec } from 'node:child_process';
import { promisify } from 'node:util';
import { expect, test } from '@playwright/test';
import { SYSTEM_OWNER, loginAs } from './helpers/auth.js';

const execAsync = promisify(exec);

/**
 * «Trenger oppmerksomhet» on the register, end to end. A fresh user with one fagområde, so the
 * totals are not disturbed by risks earlier specs left behind; a second fagområde the user cannot
 * see holds a risk that would hit every rule. The rules themselves are owned by RiskAttentionTest.
 */
test('the register shows unique risks needing attention, by category, and ignores hidden areas', async ({ page }) => {
    const suffix = Math.random().toString(36).slice(2, 8).toUpperCase();
    const email = `e2e.attention.${suffix.toLowerCase()}@procynia.test`;
    const password = 'E2eUser123!';

    const seed = `
        $customerId = App\\Models\\User::where('email', '${SYSTEM_OWNER.email}')->value('customer_id');
        $visible = App\\Models\\BusinessArea::create(['customer_id' => $customerId, 'name' => 'E2E Oppmerksomhet ${suffix}']);
        $hidden = App\\Models\\BusinessArea::create(['customer_id' => $customerId, 'name' => 'E2E Skjult område ${suffix}']);
        $user = App\\Models\\User::create(['name' => 'E2E Oppmerksomhet ${suffix}', 'email' => '${email}', 'password' => bcrypt('${password}'),
            'role' => 'user', 'bid_role' => 'contributor', 'customer_id' => $customerId, 'is_active' => true]);
        $role = App\\Models\\CustomerRole::create(['customer_id' => $customerId, 'name' => 'E2E Oppmerksomhet ${suffix}', 'is_active' => true]);
        $role->syncPermissions(['risk.view']);
        $role->syncBusinessAreas(false, [$visible->id]);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customerId]);
        $risk = fn ($area, $title, $interval = null) => App\\Models\\Risk::create(['customer_id' => $customerId, 'business_area_id' => $area->id,
            'title' => $title.' ${suffix}', 'cause' => 'manglende rutiner', 'event' => 'en hendelse', 'consequence' => 'tap', 'status' => 'identified',
            'review_interval_months' => $interval]);
        $assess = fn ($r, $at, $residual) => App\\Models\\RiskAssessment::create(['customer_id' => $customerId, 'risk_id' => $r->id, 'assessed_at' => $at,
            'rationale' => 'E2E', 'criteria_key' => 'standard_5x5_v1', 'inherent_likelihood' => 4, 'inherent_consequence' => 4,
            'residual_likelihood' => $residual[0] ?? null, 'residual_consequence' => $residual[1] ?? null]);
        $action = fn ($r, $due) => App\\Models\\RiskTreatmentAction::create(['customer_id' => $customerId, 'risk_id' => $r->id, 'title' => 'E2E tiltak',
            'due_at' => $due, 'status' => 'open']);
        $assess($risk($visible, 'E2E Høy restrisiko'), now(), [4, 4]);
        $assess($risk($visible, 'E2E Uten restrisiko'), now(), null);
        $assess($risk($visible, 'E2E Forfalt vurdering', 1), now()->subMonths(3), [1, 1]);
        $withAction = $risk($visible, 'E2E Forfalt tiltak');
        $assess($withAction, now(), [1, 1]);
        $action($withAction, now()->subDays(10)->toDateString());
        $assess($risk($visible, 'E2E Rolig'), now(), [1, 1]);
        $secret = $risk($hidden, 'E2E Skjult risiko', 1);
        $assess($secret, now()->subYear(), [5, 5]);
        $action($secret, now()->subDays(30)->toDateString());
        $risk($hidden, 'E2E Skjult uten vurdering');
    `;
    const encoded = Buffer.from(seed).toString('base64');
    await execAsync(`docker compose exec -T app php artisan tinker --execute="eval(base64_decode('${encoded}'));"`);

    await loginAs(page, email, password);
    await page.goto('/app/risk');

    const section = page.locator('section', { has: page.getByRole('heading', { name: 'Trenger oppmerksomhet' }) });
    await expect(section.getByText('Risikobildet for dine fagområder')).toBeVisible();
    await expect(section.getByTestId('risk-attention-total')).toHaveText('4 risikoer krever oppmerksomhet');

    const category = (key) => section.getByTestId(`risk-attention-category-${key}`);
    const expected = {
        high_residual: ['Høy eller svært høy restrisiko', `E2E Høy restrisiko ${suffix}`, 'Restrisiko i siste vurdering er høy (score 16).'],
        residual_not_assessed: ['Restrisiko ikke vurdert', `E2E Uten restrisiko ${suffix}`, 'har ikke restrisiko.'],
        review_overdue: ['Vurdering forfalt', `E2E Forfalt vurdering ${suffix}`, 'Ny vurdering skulle vært gjort innen'],
        actions_overdue: ['Tiltak forfalt', `E2E Forfalt tiltak ${suffix}`, 'Ett åpent tiltak har passert fristen'],
    };

    for (const [key, [label, title, reason]] of Object.entries(expected)) {
        const box = category(key);
        await expect(box.getByRole('heading', { name: label })).toBeVisible();
        await expect(box.getByTestId('risk-attention-count')).toHaveText('1');
        await box.getByRole('button', { name: 'Vis risikoer' }).click();
        await expect(box.getByRole('link', { name: title })).toBeVisible();
        await expect(box.getByText(reason)).toBeVisible();
    }

    // Only the categories with hits are shown, and nothing from the hidden area appears anywhere.
    await expect(section.locator('[data-testid^="risk-attention-category-"]')).toHaveCount(4);
    await expect(page.getByText('Skjult')).toHaveCount(0);

    await page.screenshot({ path: 'test-results/risk-attention.png', fullPage: true });
});
