// Opt-in browser smoke check using an EXISTING account, project and agent.
// Routine verification stays offline. This never creates accounts or agents.
// WORKSHOP_LIVE_TASK=1 permits exactly one tiny model task; default is read-only.
import { createRequire } from 'node:module';
import { readFile } from 'node:fs/promises';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.WORKSHOP_PLAYWRIGHT_MODULE);
const account = JSON.parse(await readFile(process.env.WORKSHOP_ACCOUNT_FILE, 'utf8'));
const base = process.env.WORKSHOP_URL ?? 'http://127.0.0.1:8000';
const projectId = process.env.WORKSHOP_PROJECT_ID;
const agentId = process.env.WORKSHOP_AGENT_ID;
assert.match(projectId ?? '', /^\d+$/, 'Provide an existing WORKSHOP_PROJECT_ID.');
assert.match(agentId ?? '', /^\d+$/, 'Provide an existing WORKSHOP_AGENT_ID.');
const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.WORKSHOP_CHROMIUM,
    args: ['--no-sandbox'],
});
const context = await browser.newContext({ viewport: { width: 1365, height: 1000 } });
const page = await context.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
const workshopPath = `/projects/${projectId}/workshop`;
const agentPath = `${workshopPath}?agent=${agentId}`;
const stateUrl = `${base}/workshop/agents/${agentId}`;
async function state() {
    const response = await context.request.get(stateUrl);
    assert.equal(response.status(), 200);
    return response.json();
}
try {
    await page.goto(`${base}/login`);
    await page.getByLabel('Email', { exact: true }).fill(account.email);
    await page.getByLabel('Password', { exact: true }).fill(account.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.waitForURL('**/projects');
    await page.locator(`a[href="${workshopPath}"]`).click();
    await page.getByRole('heading', { name: 'Workshop', exact: true }).waitFor();
    await page
        .getByRole('navigation', { name: 'Project agents' })
        .locator(`a[href="${agentPath}"]`)
        .click();
    await page.getByText('Live updates connected', { exact: true }).waitFor();
    const before = await state();
    assert.equal(before.agent.id, Number(agentId));
    assert.equal(before.agent.project_id, Number(projectId));
    await page.reload();
    await page.getByText('Live updates connected', { exact: true }).waitFor();
    assert.equal((await state()).messages.length, before.messages.length);
    console.log('PASS login → Projects → selected Workshop/agent, SSE and refresh');

    if (process.env.WORKSHOP_LIVE_TASK === '1') {
        assert.equal(before.agent.status, 'idle', 'Start this selected agent’s worker first.');
        await page.locator('#task').fill('Reply with exactly PROJECT_OK. Do not use tools.');
        await page.getByRole('button', { name: 'Send task', exact: true }).click();
        await page
            .getByLabel(`${before.agent.name} message`, { exact: true })
            .getByText('PROJECT_OK', { exact: true })
            .last()
            .waitFor({ timeout: 120000 });
        const after = await state();
        assert.equal(after.turns.length, before.turns.length + 1);
        console.log('PASS one tiny live model response in the selected agent');
    }

    await page.getByRole('tab', { name: 'Activity', exact: true }).click();
    await page.getByRole('tab', { name: 'Activity', exact: true }).focus();
    await page.keyboard.press('ArrowLeft');
    assert.equal(
        await page
            .getByRole('tab', { name: 'Conversation', exact: true })
            .getAttribute('aria-selected'),
        'true',
    );
    await page.setViewportSize({ width: 390, height: 844 });
    assert(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
        'Mobile has no horizontal overflow.',
    );
    await page.getByRole('link', { name: 'Back to Projects' }).click();
    await page.waitForURL('**/projects');
    assert.deepEqual(errors, []);
    console.log(
        'PASS activity tabs, keyboard, narrow viewport, Projects return and no browser exceptions',
    );
} catch (error) {
    console.error(error.message);
    process.exitCode = 1;
} finally {
    await browser.close();
}
