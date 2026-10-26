/**
 * Full editor-panel E2E on the REAL editor page (post.php?post=716):
 * D1 panel renders with all four controls,
 * D2 venue edit -> QA value sticks into editor state,
 * D3 capacity NumberControl accepts 99,
 * D4 end-before-start advisory appears and clears,
 * D5 Save + DB persistence (meta via wp-cli),
 * D6 restore originals via the panel itself, save, DB verify.
 * Run: node scripts/verify-editor-ux.mjs
 */
import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';

const base = 'http://localhost:10078';
const POST = 716;
const results = [];
const report = (ok, label, extra = '') =>
  results.push(`${ok ? 'PASS' : 'FAIL' } ${label}${extra ? ' — ' + extra : ''}`);

// wp-cli without a shell: argument array + explicit env/cwd.
const wp = (args) =>
  execFileSync('wp', args, {
    encoding: 'utf8',
    timeout: 60000,
    cwd: '/Users/anisurrahman/Local Sites/playground/app/public',
    env: {
      ...process.env,
      PATH:
        process.env.PATH +
        ':/Users/anisurrahman/Library/Application Support/Local/lightning-services/mysql-8.4.0+2/bin/darwin-arm64/bin',
    },
  }).trim();

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1600, height: 1100 }, ignoreHTTPSErrors: true });
const issues = [];
page.on('pageerror', (e) => issues.push('pageerror: ' + e.message.slice(0, 200)));
page.on('console', (m) => { if (m.type() === 'error') issues.push('console: ' + m.text().slice(0, 200)); });

const login = async () => {
  await page.goto(`${base}/wp-login.php`, { waitUntil: 'load', timeout: 30000 });
  await page.fill('#user_login', 'eh-test-admin');
  await page.fill('#user_pass', 'eh-test-pass-2026');
  await page.click('#wp-submit');
  await page.waitForSelector('#wpadminbar', { timeout: 20000 });
};
try { await login(); } catch { await login(); }

await page.goto(`${base}/wp-admin/post.php?post=${POST}&action=edit`, { waitUntil: 'load' });
await page.waitForTimeout(5000);

// The "Event details" panel body — the only one with that text.
const body = page.locator('.components-panel__body', { hasText: 'Event details' }).first();

// D1 — panel + controls render.
await body.waitFor({ state: 'visible', timeout: 10000 });
const d1 = await body.evaluate((el) => ({
  text: el.textContent ?? '',
  starts: (el.textContent ?? '').includes('Starts'),
  venue: (el.textContent ?? '').includes('Venue'),
  toggle: (el.textContent ?? '').includes('Limit the number of spots'),
}));
report(d1.starts && d1.venue && d1.toggle, 'D1: panel renders Starts/Venue/Capacity controls', JSON.stringify(d1));
await page.screenshot({ path: '/tmp/eh-panel-live.png' });

// D2 — venue: clear, type QA value, read back.
const venueInput = body.locator('input[id*="inspector-text-control"]').first();
await venueInput.click();
await venueInput.fill('');
await venueInput.type('QA Temporary Hall');
report((await venueInput.inputValue()) === 'QA Temporary Hall', 'D2: venue input accepts QA value');

// D3 — capacity: toggle state; NumberControl accepts 99. ToggleControl in
// components 43 renders as a bare checkbox (no switch role — DOM-verified).
const toggle = body.locator('input[type="checkbox"]').first();
const wasChecked = await toggle.isChecked();
if (!wasChecked) {
  await toggle.click();
  await page.waitForTimeout(500);
}
const capInput = body.locator('input[type="number"]').last();
await capInput.click();
await capInput.fill('');
await capInput.type('99');
await capInput.blur();
await page.waitForTimeout(400);
report((await capInput.inputValue()) === '99', 'D3: capacity NumberControl accepts 99', `wasChecked=${wasChecked}`);

// D4 — reversed-dates advisory: pull the END picker's hour back so the end
// (01 PM = 13:00) lands before start 19:00; expect the warning; restore.
// DOM-verified: end hour = #inspector-input-control-4; meridiem stays PM.
const endHour = body.locator('#inspector-input-control-4');
const prevEndHour = await endHour.inputValue();
await endHour.fill('01');
await endHour.blur();
await page.waitForTimeout(600);
const notice = await page.locator('.components-notice', { hasText: 'before the start time' }).count();
report(notice >= 1, 'D4a: reversed-dates advisory appears', `prevEndHour=${prevEndHour} notices=${notice}`);
await endHour.fill(prevEndHour);
await endHour.blur();
await page.waitForTimeout(600);
const noticeGone = await page.locator('.components-notice', { hasText: 'before the start time' }).count();
report(noticeGone === 0, 'D4b: advisory clears when dates restored');

// D5 — save and verify persistence.
const save = page.locator('button.editor-post-save-button, button:has-text("Save")').first();
await save.click();
await page.waitForSelector('button:has-text("Saved")', { timeout: 15000 }).catch(() => {});
await page.waitForTimeout(1500);
report(wp(['post', 'meta', 'get', String(POST), 'eh_venue']) === 'QA Temporary Hall', 'D5a: eh_venue persisted', 'db');
report(wp(['post', 'meta', 'get', String(POST), 'eh_capacity']) === '99', 'D5b: eh_capacity persisted as 99', 'db');
await page.screenshot({ path: '/tmp/eh-panel-saved.png' });

// D6 — restore originals through the panel, save, verify.
await venueInput.click();
await venueInput.fill('Hall B, 12 Mercer St');
await capInput.click();
await capInput.fill('5');
await capInput.blur();
await page.waitForTimeout(400);
await page.locator('button.editor-post-save-button, button:has-text("Save")').first().click();
await page.waitForSelector('button:has-text("Saved")', { timeout: 15000 }).catch(() => {});
await page.waitForTimeout(1500);
report(wp(['post', 'meta', 'get', String(POST), 'eh_venue']) === 'Hall B, 12 Mercer St', 'D6a: venue restored', 'db');
report(wp(['post', 'meta', 'get', String(POST), 'eh_capacity']) === '5', 'D6b: capacity restored', 'db');

report(issues.length === 0, 'Z1: zero JS errors across the flow', issues.join(' | '));

console.log(results.join('\n'));
const fails = results.filter((r) => r.startsWith('FAIL')).length;
console.log(`\nSUMMARY: ${results.length - fails} PASS, ${fails} FAIL`);
await browser.close();
process.exit(fails > 0 ? 1 : 0);
