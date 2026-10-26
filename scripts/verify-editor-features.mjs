/**
 * Integrated verification of the editor-UX features (post-wiring):
 * A. Events list table: When/Venue/Capacity columns, no default Date column,
 *    RSVPs + Export CSV row actions, When sortable.
 * B. RSVP screen deep link ?event=<id> boots the select on that event.
 * C. Editor panel exists on eh_event edit screen (rendered by the bundle).
 *
 * Run: node scripts/verify-editor-features.mjs
 */
import { chromium } from '@playwright/test';

const base = 'http://localhost:10078';
const [user, pass] = ['eh-test-admin', 'eh-test-pass-2026'];
const results = [];
const report = (ok, label, extra = '') =>
  results.push(`${ok ? 'PASS' : 'FAIL'} ${label}${extra ? ' — ' + extra : ''}`);

const browser = await chromium.launch();
const page = await browser.newPage({
  viewport: { width: 1440, height: 900 },
  ignoreHTTPSErrors: true,
});
const issues = [];
page.on('pageerror', (e) => issues.push(`pageerror: ${e.message.slice(0, 140)}`));

// Login (retry once — local site has flaky latency).
await page.goto(`${base}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', user);
await page.fill('#user_pass', pass);
await page.click('#wp-submit');
try {
  await page.waitForSelector('#wpadminbar', { timeout: 20000 });
} catch {
  await page.goto(`${base}/wp-login.php`, { waitUntil: 'load' });
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await page.click('#wp-submit');
  await page.waitForSelector('#wpadminbar', { timeout: 20000 });
}

// --- A. Events list table ---
await page.goto(`${base}/wp-admin/edit.php?post_type=eh_event`, {
  waitUntil: 'networkidle',
});
await page.screenshot({ path: '/tmp/eh-list-table.png', fullPage: true });

const headers = await page.locator('table.wp-list-table thead th').allTextContents();
report(headers.some((h) => h.includes('When')), 'A1: "When" column present', headers.join('|'));
report(headers.some((h) => h.includes('Venue')), 'A2: "Venue" column present');
report(headers.some((h) => h.includes('Capacity')), 'A3: "Capacity" column present');
report(!headers.some((h) => h.trim() === 'Date'), 'A4: default Date column removed', headers.join('|'));

const sortLink = await page.locator('th.column-eh_when a').first().getAttribute('href');
report((sortLink ?? '').includes('orderby=eh_when'), 'A5: "When" header is sortable', sortLink ?? '');
await page.locator('th.column-eh_when a').first().click();
await page.waitForLoadState('networkidle');
const ordered = await page.evaluate(() =>
  [...document.querySelectorAll('table.wp-list-table tbody tr td.column-eh_when')]
    .map((td) => td.textContent.trim())
    .filter(Boolean)
);
report(ordered.length >= 2, 'A6: sorting by When yields rows', ordered.slice(0, 4).join(' | '));

const bodyHasActions = await page.evaluate(() => {
  const row = document.querySelector('table.wp-list-table tbody tr');
  return row ? row.textContent : '';
});
report(bodyHasActions.includes('RSVPs'), 'A7: RSVPs row action present');
report(bodyHasActions.includes('Export CSV'), 'A8: Export CSV row action present');

// --- B. RSVP deep link ---
await page.goto(`${base}/wp-admin/admin.php?page=event-hub-rsvps&event=716`, {
  waitUntil: 'networkidle',
});
await page.waitForTimeout(1500);
const bootSel = await page
  .locator('select.components-select-control__input')
  .first()
  .inputValue();
report(bootSel === '716', 'B1: ?event=716 boots the select on 716', `value=${bootSel}`);
const bRows = await page.locator('tbody tr').count();
report(bRows >= 1, 'B2: deep-linked event rows render', `rows=${bRows}`);
const notices = await page.locator('.components-notice').count();
report(notices === 0, 'B3: no error notice after deep link', `notices=${notices}`);

// --- C. Editor panel on eh_event ---
await page.goto(`${base}/wp-admin/post.php?post=716&action=edit`, {
  waitUntil: 'load',
});
await page.waitForTimeout(4000);
// Open the settings sidebar if collapsed.
const sidebar = page.locator('.interface-complementary-area');
if ((await sidebar.count()) === 0) {
  await page.getByRole('button', { name: 'Settings', exact: true }).first().click().catch(() => {});
  await page.waitForTimeout(1200);
}
const panel = page.locator('.components-panel__body', { hasText: 'Event details' });
report((await panel.count()) >= 1, 'C1: "Event details" panel renders in the sidebar');
await page.screenshot({ path: '/tmp/eh-editor-panel.png', fullPage: false });

report(issues.length === 0, 'Z1: zero JS page errors', issues.join(' | '));

console.log(results.join('\n'));
const fails = results.filter((r) => r.startsWith('FAIL')).length;
console.log(`\nSUMMARY: ${results.length - fails} PASS, ${fails} FAIL`);
await browser.close();
process.exit(fails > 0 ? 1 : 0);
