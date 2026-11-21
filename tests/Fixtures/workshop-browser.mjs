// Opt-in live browser verification. This is never run by the routine PHP suite.
// Supply a private account JSON file and an externally installed Playwright module.
import { createRequire } from 'node:module';
import { readFile, writeFile, unlink } from 'node:fs/promises';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.WORKSHOP_PLAYWRIGHT_MODULE);
const account = JSON.parse(await readFile(process.env.WORKSHOP_ACCOUNT_FILE, 'utf8'));
const base = process.env.WORKSHOP_URL ?? 'http://127.0.0.1:8000';
const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.WORKSHOP_CHROMIUM,
    args: ['--no-sandbox'],
});
const context = await browser.newContext({ viewport: { width: 1365, height: 1000 } });
const page = await context.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
page.on('console', (message) => {
    if (message.type() === 'error') errors.push(message.text());
});
let stateUrl;
async function state() {
    return (await context.request.get(stateUrl)).json();
}
async function waitFor(check, label, timeout = 120000) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        const saved = await state();
        if (await check(saved)) return saved;
        await new Promise((resolve) => setTimeout(resolve, 200));
    }
    throw new Error(`Timed out: ${label}`);
}
async function send(text) {
    await page.locator('#task').fill(text);
    await page.getByRole('button', { name: 'Send task', exact: true }).click();
    await waitFor((saved) => saved.agent.status !== 'idle', 'task begins');
}
try {
    await page.goto(`${base}/workshop`);
    await page.getByLabel('Email', { exact: true }).fill(account.email);
    await page.getByLabel('Password', { exact: true }).fill(account.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.waitForURL('**/workshop');
    let created;
    if (process.env.WORKSHOP_REUSE_AGENT === '1') {
        const previous = JSON.parse(await readFile(process.env.WORKSHOP_RESULT_FILE, 'utf8'));
        stateUrl = base + '/workshop/agents/' + previous.agentId;
        created = await state();
    } else {
        await page.getByLabel('Agent name').fill('Sage');
        await page.getByLabel('Working directory').selectOption('sandbox');
        const createdResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith('/workshop/agents') &&
                response.request().method() === 'POST',
        );
        await page.getByRole('button', { name: 'Create agent', exact: true }).click();
        created = await (await createdResponse).json();
        assert(created.agent, 'agent was created');
        stateUrl = base + created.agent.urls.state;
        await writeFile(
            process.env.WORKSHOP_RESULT_FILE,
            JSON.stringify({
                agentId: created.agent.id,
                workspace: created.agent.workspace,
                stateUrl,
            }),
            { mode: 0o600 },
        );
    }
    const baselineTurns = created.turns.length;
    const baselineReplies = created.messages.filter(
        (message) => message.role === 'assistant' && message.body === 'BROWSER_OK',
    ).length;
    const baselineSteering = created.messages.filter((message) => message.kind === 'steer').length;
    await waitFor((saved) => saved.agent.status === 'idle', 'worker ready');
    console.log(
        process.env.WORKSHOP_REUSE_AGENT === '1'
            ? 'PASS browser login, saved agent open, worker connection'
            : 'PASS browser login, workspace selection, agent creation, worker connection',
    );

    const streamEvents = [];
    const streamListener = (response) => {
        if (response.url().includes('/events')) streamEvents.push(response.status());
    };
    page.on('response', streamListener);
    await send('Reply with exactly BROWSER_OK. Do not use tools.');
    await waitFor(
        (saved) =>
            saved.turns.length === baselineTurns + 1 && saved.turns.at(-1).status === 'completed',
        'first completion',
    );
    await page.getByText('BROWSER_OK', { exact: true }).last().waitFor();
    assert(
        (await state()).messages.some(
            (message) => message.role === 'assistant' && message.body === 'BROWSER_OK',
        ),
    );
    await page.reload();
    await page.getByText('BROWSER_OK', { exact: true }).last().waitFor();
    console.log('PASS live task streamed in browser and recovered after refresh');

    const concurrentStart = Date.now();
    const otherPage = await context.newPage();
    await otherPage.goto(`${base}/workshop`);
    assert(Date.now() - concurrentStart < 8000, 'another page loads while SSE is open');
    await otherPage.close();
    await context.setOffline(true);
    await page.waitForTimeout(500);
    await context.setOffline(false);
    await page.getByText('Live updates connected', { exact: true }).waitFor({ timeout: 20000 });
    assert.equal(
        (await state()).turns.length,
        baselineTurns + 1,
        'reconnect did not create a turn',
    );
    console.log('PASS concurrent browser request and reconnect without duplicate work');

    await send(
        'What exact token did you reply with in the previous turn? Reply only with that token. Do not use tools.',
    );
    await waitFor(
        (saved) =>
            saved.turns.length === baselineTurns + 2 &&
            saved.turns.slice(-2).every((turn) => turn.status === 'completed'),
        'follow-up completion',
    );
    assert.equal(
        (await state()).messages.filter(
            (message) => message.role === 'assistant' && message.body === 'BROWSER_OK',
        ).length,
        baselineReplies + 2,
    );
    console.log('PASS live follow-up in the same saved conversation');

    await send(
        'Run python3 -c "print(73519)" exactly once using the shell tool in cwd. Ask for approval. If denied, stop and report DENIED; do not try alternatives.',
    );
    await page.getByRole('region', { name: 'Permission request' }).waitFor({ timeout: 120000 });
    const permission = await waitFor(
        (saved) => saved.approvals.some((approval) => approval.status === 'pending'),
        'permission request',
    );
    assert(permission.approvals[0].command.includes('python3'));
    assert.equal(permission.approvals[0].cwd, created.agent.workspace);
    await page.getByRole('button', { name: 'Deny', exact: true }).click();
    await waitFor(
        (saved) => saved.agent.status === 'idle' && saved.turns.length === baselineTurns + 3,
        'denied task ends',
    );
    console.log('PASS live permission displayed with command/workspace and denied');

    const marker = `${created.agent.workspace}/browser-running.txt`;
    await unlink(marker).catch((error) => {
        if (error.code !== 'ENOENT') throw error;
    });
    await send(
        'Using the shell tool, run python3 -u -c ' +
            JSON.stringify(
                'import os,time; open("browser-running.txt","w").write(str(os.getpid())); print("RUNNING", flush=True); time.sleep(300)',
            ) +
            ' exactly once in cwd. This is a cancellation check. Ask for approval. Wait for this Python command to exit naturally by polling its terminal if the tool returns early. Only after it exits, read browser-running.txt and reply DONE. Do not finish your turn while Python is running. Do not spawn agents.',
    );
    await page
        .getByRole('button', { name: 'Approve once', exact: true })
        .waitFor({ timeout: 120000 });
    const approve = await state();
    const requested = approve.approvals.find((approval) => approval.status === 'pending');
    assert(
        requested.command.includes('python3') &&
            requested.command.includes('browser-running.txt') &&
            requested.command.includes('time.sleep'),
        'approve only the specified sleeping Python test command',
    );
    assert.equal(requested.cwd, created.agent.workspace);
    await page.getByRole('button', { name: 'Approve once', exact: true }).click();
    const deadline = Date.now() + 30000;
    let markerText;
    while (Date.now() < deadline) {
        try {
            markerText = await readFile(marker, 'utf8');
            if (markerText) break;
        } catch {}
        await page.waitForTimeout(250);
    }
    assert(markerText, 'approved Python child actually started');
    if (process.env.WORKSHOP_PROCESS_CHECKPOINT) {
        await writeFile(
            process.env.WORKSHOP_PROCESS_CHECKPOINT,
            JSON.stringify({ agentId: created.agent.id, phase: 'child_running' }),
            { mode: 0o600 },
        );
        console.log('CHECKPOINT approved Python child is running; awaiting host process check');
        const pauseDeadline = Date.now() + 90000;
        while (true) {
            try {
                await readFile(process.env.WORKSHOP_PROCESS_CHECKPOINT + '.continue');
                break;
            } catch {}
            if (Date.now() >= pauseDeadline) throw new Error('Host process checkpoint timed out');
            await page.waitForTimeout(250);
        }
    }
    await page
        .locator('#steering')
        .fill('Keep waiting for my stop request; do not start any additional work.');
    await page.getByRole('button', { name: 'Send steering', exact: true }).click();
    await waitFor(
        (saved) =>
            saved.messages.filter((message) => message.kind === 'steer').length ===
            baselineSteering + 1,
        'steering acknowledged',
    );
    const controlsStarted = Date.now();
    await page.getByRole('button', { name: 'Stop task', exact: true }).click();
    await waitFor(
        (saved) => saved.agent.status === 'idle' && saved.turns.at(-1).status === 'interrupted',
        'running task interrupted',
    );
    assert(Date.now() - controlsStarted < 15000, 'Stop stays responsive while SSE is open');
    await page.getByText('Task interrupted.', { exact: true }).waitFor();
    console.log('PASS approved running Python child, explicit steering, and Stop confirmation');

    await page.getByRole('tab', { name: 'Activity', exact: true }).click();
    await page.getByRole('tabpanel').getByText('Running a command').first().waitFor();
    await page.getByRole('tab', { name: 'Activity', exact: true }).focus();
    await page.keyboard.press('ArrowLeft');
    assert.equal(
        await page
            .getByRole('tab', { name: 'Conversation', exact: true })
            .getAttribute('aria-selected'),
        'true',
    );
    await page.screenshot({
        path: '/tmp/ai-agents-browser-check/workshop-desktop.png',
        fullPage: true,
    });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload();
    await page.getByText('Task interrupted.', { exact: true }).waitFor();
    assert(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
        'mobile has no horizontal page overflow',
    );
    await page.screenshot({
        path: '/tmp/ai-agents-browser-check/workshop-mobile.png',
        fullPage: true,
    });
    const final = await state();
    assert.equal(final.turns.length, baselineTurns + 4);
    assert.equal(
        final.messages.filter((message) => message.kind === 'steer').length,
        baselineSteering + 1,
    );
    assert.equal(
        errors.filter((error) => !error.includes('ERR_INTERNET_DISCONNECTED')).length,
        0,
        `browser errors: ${errors.join('; ')}`,
    );
    console.log(
        'PASS activity panel, keyboard tabs, mobile layout, final refresh and no browser exceptions',
    );
    await writeFile(
        process.env.WORKSHOP_RESULT_FILE,
        JSON.stringify({
            agentId: created.agent.id,
            workspace: created.agent.workspace,
            stateUrl,
            turns: final.turns,
            errors,
            checks: 'passed',
        }),
        { mode: 0o600 },
    );
} catch (error) {
    console.error(error.message);
    await page
        .screenshot({ path: '/tmp/ai-agents-browser-check/workshop-failure.png', fullPage: true })
        .catch(() => {});
    process.exitCode = 1;
} finally {
    await browser.close();
}
