import { chromium } from '@playwright/test';

const base = 'http://localhost:10078';
const [user, pass] = ['eh-test-admin', 'eh-test-pass-2026'];
const results = [];
const report = (ok, label, extra = '') =>
  results.push(`${ok ? 'PASS' : 'FAIL'} ${label}${extra ? ' — ' + extra : ''}`);

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, ignoreHTTPSErrors: true });
const issues = [];
page.on('pageerror', (e) => issues.push(`pageerror: ${e.message.slice(0, 140)}`));

// --- Part A: admin RSVP screen ---
const rsvpReqs = [];
page.on('request', (r) => {
  const u = r.url();
  if (u.includes('/rsvps?')) rsvpReqs.push({ url: u, eventId: new URL(u).searchParams.get('eventId') });
});

await page.goto(`${base}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', user);
await page.fill('#user_pass', pass);
await page.click('#wp-submit');
try {
  await page.waitForSelector('#wpadminbar', { timeout: 20000 });
} catch {
  // One retry on transient local-site latency.
  await page.goto(`${base}/wp-login.php`, { waitUntil: 'load' });
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await page.click('#wp-submit');
  await page.waitForSelector('#wpadminbar', { timeout: 20000 });
}

await page.goto(`${base}/wp-admin/admin.php?page=event-hub-rsvps`, { waitUntil: 'networkidle' });
await page.waitForTimeout(1200);

// Server boots the screen on the first event (Admin_Page: $initial =
// $event_ids[0], eh_start ASC — currently 718), so load fires ONE /rsvps
// request with that event's id. Read the actual boot value from the select.
const bootVal = await page.locator('select.components-select-control__input').first().inputValue();
report(rsvpReqs.length === 1 && rsvpReqs[0].eventId === bootVal && bootVal !== '0', 'A1: load fires exactly one /rsvps for the booted event', `reqs=${rsvpReqs.length} ids=${rsvpReqs.map((r) => r.eventId).join(',')} boot=${bootVal}`);
const errNotice = await page.locator('.components-notice').count();
report(errNotice === 0, 'A2: no error notice after load', `notices=${errNotice}`);
const rowsLoaded = await page.locator('tbody tr').count();
report(rowsLoaded >= 1, 'A3: booted event rows render', `rows=${rowsLoaded}`);

// Selecting the placeholder must fire NO request and show the prompt.
await page.locator('select.components-select-control__input').first().selectOption('0');
await page.waitForTimeout(1200);
const reqsAfterPlaceholder = rsvpReqs.length;
report(reqsAfterPlaceholder === 1, 'A4: choosing "Select an event" fires no request (the eventId=0 fix)', `reqs=${reqsAfterPlaceholder}`);
const prompt = await page.locator('text=Select an event to see its RSVPs').count();
report(prompt === 1, 'A5: prompt renders after choosing placeholder', `count=${prompt}`);
const errNotice2 = await page.locator('.components-notice').count();
report(errNotice2 === 0, 'A6: no error notice in placeholder state', `notices=${errNotice2}`);

// Selecting a real event loads its rows.
await page.locator('select.components-select-control__input').first().selectOption('719');
await page.waitForTimeout(1500);
const rows = await page.locator('tbody tr').count();
report(rows >= 1 && rsvpReqs.length === 2, 'A7: reselecting event loads rows (second request)', `rows=${rows} reqs=${rsvpReqs.length}`);

// --- Part B: single event page ---
const shots = async (w) => {
  await page.setViewportSize({ width: w, height: 900 });
  await page.goto(`${base}/events/first-press-social/`, { waitUntil: 'load' });
  await page.waitForSelector('.event-hub-single', { timeout: 10000 });
  await page.screenshot({ path: `/tmp/eh-single-${w}.png`, fullPage: true });
  return {
    single: await page.locator('.event-hub-single').count(),
    pill: (await page.locator('.event-hub-single__pill').textContent())?.trim(),
    chips: await page.locator('.event-hub-single__categories a').count(),
    bar: await page.locator('.event-hub-single__capacity .event-hub-card__progress').count(),
    inputs: await page.locator('.event-hub-rsvp__input').count(),
    btn: await page.locator('.event-hub-rsvp__button').count(),
  };
};

const d = await shots(1280);
const t = await shots(768);
const m = await shots(360);
report(d.single === 1 && d.pill !== null, 'B1: single renders at 1280 with pill', `pill="${d.pill}"`);
report(d.chips >= 1, 'B2: category chip renders', `chips=${d.chips}`);
report(d.bar === 1, 'B3: capacity bar renders');
report(d.inputs === 2 && d.btn === 1, 'B4: RSVP form renders', `inputs=${d.inputs} btn=${d.btn}`);
report(t.single === 1 && m.single === 1, 'B5: renders at 768 + 360');

const chipHref = await page.locator('.event-hub-single__categories a').first().getAttribute('href');
report((chipHref ?? '').includes('eh_category=new'), 'B6: chip links to ?eh_category filter', chipHref ?? '');

// Bar ARIA must agree with the page's own rendered count text (data-driven,
// not hardcoded — counts change when RSVPs are added).
const bar = await page.evaluate(() => {
  const el = document.querySelector('.event-hub-single__capacity .event-hub-card__progress');
  return el ? { max: el.getAttribute('aria-valuemax'), now: el.getAttribute('aria-valuenow'), text: el.getAttribute('aria-valuetext') } : null;
});
report(bar && bar.max === '60' && bar.now !== null && (bar.text ?? '').includes(`${bar.now} of 60 spots filled`), 'B7: progressbar ARIA self-consistent (N of 60)', JSON.stringify(bar));

// Tab through the page until focus enters the single-event region (theme
// header links come first); then a visible outline is required.
let focusOk = false;
let focusSel = '';
for (let i = 0; i < 20 && !focusOk; i++) {
  await page.keyboard.press('Tab');
  focusSel = await page.evaluate(() => {
    const el = document.activeElement;
    return el && el.closest('.event-hub-single')
      ? el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).split(' ').join('.') : '')
      : '';
  });
  if (focusSel) focusOk = true;
}
const outline = focusOk
  ? await page.evaluate(() => {
      const st = getComputedStyle(document.activeElement);
      return `${st.outlineStyle}/${st.outlineWidth}`;
    })
  : 'never-entered';
report(focusOk && outline !== 'none/0px', 'B8: Tab reaches single view with visible outline', `${focusSel} outline=${outline}`);

await page.waitForTimeout(2000);
const countText = (await page.locator('.event-hub-rsvp__count').textContent())?.trim() ?? '';
report(countText && !/%[sd]/.test(countText) && countText.includes('going'), 'B9: count interpolated after hydration', `"${countText}"`);

// Client-side validation with EMPTY fields (clear the logged-in pre-fill so
// the click cannot submit a real RSVP — this run must not write data).
const beforeUrl = page.url();
await page.locator('.event-hub-rsvp__input').first().fill('');
await page.locator('.event-hub-rsvp__input').nth(1).fill('');
await page.click('.event-hub-rsvp__button');
await page.waitForTimeout(1000);
const feedback = (await page.locator('.event-hub-rsvp__feedback').textContent())?.trim() ?? '';
report(page.url() === beforeUrl && feedback !== '', 'B10: empty-field click only triggers client validation', `"${feedback}"`);

const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
report(overflow <= 0, 'B11: no horizontal overflow at 360', `overflowPx=${overflow}`);

report(issues.length === 0, 'B12: zero JS page errors', issues.join(' | '));

// Log out (leave session clean).
await page.goto(`${base}/wp-admin/`, { waitUntil: 'load' });
const account = page.locator('#wp-admin-bar-my-account');
if (await account.count()) await account.hover();
await page.waitForTimeout(400);
const logout = page.locator('a[href*="action=logout"]');
if (await logout.count()) { await page.goto((await logout.first().getAttribute('href')) ?? base); }

console.log(results.join('\n'));
const fails = results.filter((r) => r.startsWith('FAIL')).length;
console.log(`\nSUMMARY: ${results.length - fails} PASS, ${fails} FAIL`);
await browser.close();
process.exit(fails > 0 ? 1 : 0);
