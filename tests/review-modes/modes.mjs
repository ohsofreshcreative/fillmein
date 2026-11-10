// Design Review, etap 06 — tryby Przeglądaj / Komentuj w headless Chromium (Playwright z /tmp/fmi-ui-audit).
// Stan prototypu (node-id w URL ramki) odczytuje wyłącznie test. Zapis uwag na serwerze to etap 07 — błędy zapisu
// sprawdzamy na podstawionym adresie (route Playwrighta), bez prawdziwego endpointu.
import { mkdirSync, readFileSync } from 'node:fs';
import { chromium } from '/tmp/fmi-ui-audit/node_modules/playwright/index.mjs';

const SITE = 'http://fillmein.local';
const SHOTS = '/tmp/fmi-review-modes-shots';
const manifest = JSON.parse(readFileSync('/tmp/fmi-stage03-fixtures.json', 'utf8'));
const ids = JSON.parse(readFileSync('/tmp/fmi-review-embed.json', 'utf8'));
const axeSource = readFileSync('/tmp/fmi-ui-audit/node_modules/axe-core/axe.min.js', 'utf8');
const [cookieName, cookieValue] = manifest.cookies.admin.split(/=(.*)/s);
const FAKE = `${SITE}/app/__fmi-test-save`;
mkdirSync(SHOTS, { recursive: true });

let pass = 0;
let fail = 0;
const check = (ok, label) => {
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${label}`);
};
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

let browser;
try {
  browser = await chromium.launch();
} catch (error) {
  console.log(`BLOCKED: Chromium Playwrighta (${error.message.split('\n')[0]})`);
  process.exit(2);
}

async function open(options = {}) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, ...options });
  await context.addCookies([{ name: cookieName, value: cookieValue, url: SITE }]);
  const page = await context.newPage();
  const problems = [];
  page.on('pageerror', (error) => problems.push(error.message));
  await page.goto(`${SITE}/app/design-review/${ids.pub}`, { waitUntil: 'domcontentloaded' });
  await waitCanvas(page);

  return { context, page, problems };
}
const figmaFrame = (page) => page.frames().find((frame) => /^https:\/\/www\.figma\.com\/proto\//.test(frame.url()));
const nodeOf = (page) => figmaFrame(page)?.url().match(/[?&]node-id=([^&]+)/)?.[1] ?? null;
async function waitCanvas(page, timeout = 40000) {
  const end = Date.now() + timeout;
  while (Date.now() < end) {
    const frame = figmaFrame(page);
    if (frame && (await frame.locator('canvas').count().catch(() => 0)) > 0) {
      await sleep(4000);
      return true;
    }
    await sleep(500);
  }
  return false;
}
async function clickPhone(page) {
  await page.evaluate(() => document.querySelector('[data-comment-surface]').scrollIntoView({ block: 'center' }));
  await sleep(300);
  const point = await phonePoint(page);
  await page.mouse.click(point.x, point.y);
}
async function phonePoint(page) {
  const box = await page.locator('[data-figma-frame]').boundingBox();
  const phoneHeight = (box.height - 50) * 0.81;
  return { x: box.x + box.width / 2 - 60 * (phoneHeight / 530), y: box.y + (box.height - 50) * 0.32 };
}
const ui = async (page) => { await sleep(250); return page.evaluate(() => {
  const root = document.querySelector('[data-review-composer]');
  const data = window.Alpine.$data(root);
  const visible = (selector) => {
    const element = document.querySelector(selector);
    return Boolean(element) && getComputedStyle(element).display !== 'none' && element.getClientRects().length > 0;
  };
  return {
    mode: root.dataset.mode,
    browsePressed: document.querySelector('[data-mode-button=browse]').getAttribute('aria-pressed'),
    commentPressed: document.querySelector('[data-mode-button=comment]').getAttribute('aria-pressed'),
    status: document.querySelector('[data-mode-status]').innerText.trim(),
    form: visible('[data-comment-form]'),
    layer: visible('[data-comment-layer]'),
    marker: visible('[data-comment-marker]'),
    badge: visible('[data-draft-badge]'),
    inert: document.querySelector('[data-figma-frame]').inert,
    active: document.activeElement?.id || document.activeElement?.dataset?.modeButton || document.activeElement?.tagName,
    error: visible('[data-comment-error]') ? document.querySelector('[data-comment-error]').innerText.trim() : '',
    notice: visible('[data-comment-notice]') ? document.querySelector('[data-comment-notice]').innerText.trim() : '',
    kind: document.querySelector('[data-comment-kind]').innerText.trim(),
    body: data.body,
    screen: data.context,
    point: data.point,
    overflow: document.documentElement.scrollWidth - window.innerWidth,
  };
}); };
const setAction = (page, action) => page.evaluate((value) => { window.Alpine.$data(document.querySelector('[data-review-composer]')).action = value; }, action);

console.log('== Ten sam punkt w obu trybach (1280 px) ==');
{
  const { context, page, problems } = await open();
  let state = await ui(page);
  check(state.mode === 'browse' && state.browsePressed === 'true' && state.commentPressed === 'false' && /Przeglądaj/.test(state.status), 'Domyślnie Przeglądaj; aria-pressed i opis trybu');
  check(!state.layer && !state.inert, 'Przeglądaj: brak warstwy, ramka nie jest inert');
  check(nodeOf(page) === '5-3', `Ekran startowy 5-3 (${nodeOf(page)})`);

  await clickPhone(page);
  await sleep(3000);
  state = await ui(page);
  const navigated = nodeOf(page);
  check(navigated !== '5-3' && !state.form && !state.marker, `Przeglądaj: kliknięcie → nawigacja prototypu (${navigated}), bez formularza uwagi`);
  await page.keyboard.press('ArrowLeft');
  await sleep(2500);
  check(nodeOf(page) === '5-3', 'Powrót do 5-3');

  await page.locator('[data-mode-button=comment]').click();
  state = await ui(page);
  check(state.mode === 'comment' && state.commentPressed === 'true' && /prototyp jest zatrzymany/.test(state.status), 'Komentuj: aria-pressed, opis „prototyp jest zatrzymany”');
  check(state.layer && state.inert, 'Komentuj: warstwa nad prototypem, ramka inert');
  await clickPhone(page);
  await sleep(3000);
  state = await ui(page);
  check(nodeOf(page) === '5-3', `Komentuj: to samo kliknięcie NIE nawiguje prototypu (${nodeOf(page)})`);
  check(state.form && state.marker && state.point && state.point.x > 0 && state.point.x < 1, 'Komentuj: znacznik i formularz uwagi');
  check(state.active === 'comment-body', `Fokus w treści uwagi (${state.active})`);
  check(/orientacyjnie, nie zapisywane jako pinezka/.test(state.kind), 'Wskazanie opisane jako orientacyjne, bez pinezki');
  await page.screenshot({ path: `${SHOTS}/01-komentuj-formularz.png` });

  console.log('== Escape, anulowanie, szkic ==');
  await page.keyboard.type('Za mały kontrast przycisku <script>x</script>');
  await page.keyboard.press('Escape');
  state = await ui(page);
  check(!state.form && !state.marker && state.mode === 'comment' && state.active === 'comment', `Escape: formularz zamknięty, fokus na „Komentuj” (${state.active})`);
  check(state.badge && state.body.includes('Za mały kontrast'), 'Escape: szkic zachowany, widoczna informacja o szkicu');
  await page.keyboard.press('Escape');
  state = await ui(page);
  check(state.mode === 'browse' && !state.layer && !state.inert, 'Drugi Escape: powrót do Przeglądaj');

  console.log('== Przełączenie podczas pisania ==');
  await page.locator('[data-mode-button=comment]').click();
  await page.locator('[data-comment-whole]').click();
  state = await ui(page);
  check(state.form && !state.marker && state.body.includes('Za mały kontrast') && /bez miejsca/.test(state.kind), 'Uwaga do ekranu: formularz ze szkicem, bez znacznika');
  await page.locator('[data-comment-body]').press('End');
  await page.keyboard.type(' — dopisek');
  await page.locator('[data-mode-button=browse]').click();
  state = await ui(page);
  check(state.mode === 'browse' && !state.form && !state.layer && !state.inert && state.badge, 'Przełączenie na Przeglądaj w trakcie pisania: formularz ukryty, szkic zachowany');
  await clickPhone(page);
  await sleep(3000);
  check(nodeOf(page) !== '5-3' && !(await ui(page)).form, 'Po przełączeniu: kliknięcie znów nawiguje prototyp, bez uwagi');
  await page.keyboard.press('ArrowLeft');
  await sleep(2000);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await waitCanvas(page);
  state = await ui(page);
  check(state.badge && state.body.endsWith('— dopisek'), 'Po przeładowaniu strony szkic przywrócony (sessionStorage)');
  await page.getByRole('button', { name: 'Wróć do szkicu' }).click();
  state = await ui(page);
  check(state.mode === 'comment' && state.form && state.active === 'comment-body', '„Wróć do szkicu” otwiera formularz w trybie Komentuj');

  console.log('== Walidacja i błędy zapisu ==');
  await page.locator('[data-comment-submit]').click();
  check((await ui(page)).error === 'Wybierz ekran albo opisz, którego miejsca dotyczy uwaga (np. „Koszyk”).', 'Brak ekranu i opisu kontekstu → komunikat');
  await page.locator('[data-comment-context]').fill('Sklep — lista produktów');
  await setAction(page, null);
  await page.locator('[data-comment-submit]').click();
  state = await ui(page);
  check(/nie jest włączone/.test(state.error) && state.body.endsWith('— dopisek'), 'Bez adresu zapisu: jawny komunikat, szkic zachowany');

  await setAction(page, FAKE);
  await context.route(FAKE, (route) => route.fulfill({ status: 500, body: '{}' }));
  await page.locator('[data-comment-submit]').click();
  await sleep(500);
  state = await ui(page);
  check(/Nie udało się zapisać uwagi/.test(state.error) && state.form && state.body.endsWith('— dopisek'), 'Błąd 500: komunikat, formularz i szkic zostają');
  await context.unroute(FAKE);
  await context.route(FAKE, (route) => route.fulfill({ status: 403, body: '{}' }));
  await page.locator('[data-comment-submit]').click();
  await sleep(500);
  check(/Nie masz uprawnień/.test((await ui(page)).error), 'Odmowa 403: komunikat o uprawnieniach, szkic zostaje');
  await context.unroute(FAKE);
  await context.route(FAKE, (route) => route.abort('internetdisconnected'));
  await page.locator('[data-comment-submit]').click();
  await sleep(500);
  state = await ui(page);
  check(/Brak połączenia/.test(state.error) && state.body.endsWith('— dopisek'), 'Brak sieci: komunikat, szkic zostaje');
  await context.unroute(FAKE);
  let sent = null;
  await context.route(FAKE, async (route) => {
    sent = { csrf: route.request().headers()['x-csrf-token'] ?? '', body: route.request().postDataJSON() };
    await route.fulfill({ status: 201, contentType: 'application/json', body: '{"number":99,"pin":false}' });
  });
  await page.evaluate(() => { window.Alpine.$data(document.querySelector('[data-review-composer]')).csrf = document.querySelector('meta[name=csrf-token]').content; });
  await Promise.all([page.waitForURL(/[?&]uwaga=99/, { timeout: 20000 }), page.locator('[data-comment-submit]').click()]);
  await waitCanvas(page);
  state = await ui(page);
  check(sent && sent.csrf.length > 10 && sent.body.context === 'Sklep — lista produktów' && Object.keys(sent.body).join(',') === 'body,request_id,context' && /^[0-9a-f-]{36}$/.test(sent.body.request_id), 'Wysyłka: nagłówek CSRF, treść, kontekst i identyfikator żądania (bez współrzędnych z prototypu)');
  check(/#uwaga-99$/.test(page.url()) && !state.form && state.body === '' && !state.badge, 'Sukces: przeładowanie do #uwaga-99, szkic wyczyszczony');
  check((await page.evaluate(() => Object.keys(sessionStorage).filter((key) => key.startsWith('fmi-review-draft')).length)) === 0, 'Sukces: szkic usunięty z sessionStorage');
  await context.unroute(FAKE);

  console.log('== Klawiatura i fokus ==');
  await page.locator('[data-mode-button=browse]').click();
  await page.locator('[data-mode-button=browse]').focus();
  await page.keyboard.press('Tab');
  check((await ui(page)).active === 'comment', 'Tab: z „Przeglądaj” na „Komentuj”');
  const ring = await page.evaluate(() => getComputedStyle(document.activeElement).boxShadow);
  check(ring && ring !== 'none', 'Widoczny fokus przełącznika (focus-visible ring)');
  await page.keyboard.press('Enter');
  check((await ui(page)).mode === 'comment', 'Enter: włącza Komentuj');
  await page.locator('[data-comment-layer]').focus();
  const layerRing = await page.evaluate(() => getComputedStyle(document.activeElement).boxShadow);
  check(layerRing && layerRing !== 'none', 'Warstwa wskazania ma widoczny fokus');
  await page.keyboard.press('Enter');
  state = await ui(page);
  check(state.form && !state.marker && state.active === 'comment-body', 'Enter na warstwie: uwaga do ekranu, fokus w treści');
  await page.keyboard.press('Escape');
  state = await ui(page);
  check(!state.form && state.mode === 'comment', `Escape z formularza (klawiatura): zamknięty (form=${state.form}, active=${state.active})`);

  console.log('== Utrata pewności: resize i zmiana ekranu ==');
  await clickPhone(page);
  state = await ui(page);
  await page.keyboard.type('Uwaga przy resize');
  state = await ui(page);
  check(state.marker, `Znacznik przed zmianą rozmiaru (point=${JSON.stringify(state.point)}, form=${state.form}, notice=${state.notice}, body=${state.body})`);
  await page.setViewportSize({ width: 900, height: 900 });
  await sleep(1500);
  state = await ui(page);
  check(!state.marker && state.point === null && /Rozmiar widoku się zmienił/.test(state.notice) && state.body === 'Uwaga przy resize', 'Resize: znacznik usunięty, komunikat, szkic zostaje');
  check(state.overflow <= 0, 'Resize 900 px: bez przewijania poziomego');
  await page.setViewportSize({ width: 1280, height: 900 });
  await sleep(1500);
  await clickPhone(page);
  // Symulacja zdarzenia z komponentu figmaEmbed (prawdziwe zdarzenia Figmy nie docierają — etap 05).
  await page.evaluate(() => document.querySelector('[data-figma-embed]').dispatchEvent(new CustomEvent('figma-embed', { bubbles: true, detail: { type: 'PRESENTED_NODE_CHANGED', presentedNodeId: '3:1075' } })));
  state = await ui(page);
  check(!state.marker && /inny ekran/.test(state.notice) && state.screen === 'Ekran 3:1075', 'Zmiana ekranu (zdarzenie): znacznik usunięty, ekran uzupełniony ze zdarzenia');
  await page.locator('[data-comment-discard]').click();
  state = await ui(page);
  check(state.body === '' && state.screen === '' && !state.form && !state.badge, 'Odrzuć szkic: treść i ekran wyczyszczone');

  console.log('== Dostępność (axe, Komentuj + formularz) ==');
  await page.locator('[data-comment-whole]').click();
  await page.addScriptTag({ content: axeSource });
  const violations = await page.evaluate(() => window.axe.run({ include: [['[data-review-composer]']], exclude: [['[data-figma-frame]']] }, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] })
    .then((result) => result.violations.map((violation) => `${violation.id}×${violation.nodes.length}`)));
  check(violations.length === 0, `axe: ${violations.length ? violations.join(', ') : 'brak naruszeń'}`);
  check(problems.length === 0, `Bez błędów JS${problems.length ? ': ' + problems.join(' | ') : ''}`);
  await context.close();
}

console.log('== Telefon (390×844, dotyk) ==');
{
  const { context, page, problems } = await open({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  await page.locator('[data-mode-button=comment]').tap();
  await page.evaluate(() => document.querySelector('[data-comment-surface]').scrollIntoView({ block: 'center' }));
  await sleep(300);
  await sleep(500);
  const point = await phonePoint(page);
  await page.touchscreen.tap(point.x, point.y);
  await sleep(2500);
  let state = await ui(page);
  check(nodeOf(page) === '5-3' && state.form && state.marker, 'Komentuj + tap: znacznik i formularz, prototyp bez nawigacji');
  check(state.overflow <= 0, 'Telefon: bez przewijania poziomego z otwartym formularzem');
  await page.locator('[data-comment-body]').tap();
  await page.keyboard.type('Uwaga z telefonu');
  await page.locator('[data-comment-cancel]').tap();
  state = await ui(page);
  check(!state.form && state.badge, 'Anuluj (tap): formularz zamknięty, szkic zachowany');
  await page.screenshot({ path: `${SHOTS}/02-telefon.png`, fullPage: false });
  await page.locator('[data-mode-button=browse]').tap();
  await page.evaluate(() => document.querySelector('[data-comment-surface]').scrollIntoView({ block: 'center' }));
  await sleep(300);
  await sleep(500);
  const browsePoint = await phonePoint(page);
  await page.touchscreen.tap(browsePoint.x, browsePoint.y);
  await sleep(3000);
  check(nodeOf(page) !== '5-3' && !(await ui(page)).form, `Przeglądaj + tap: nawigacja prototypu (${nodeOf(page)})`);
  check(problems.length === 0, 'Telefon: bez błędów JS');
  await context.close();
}

console.log('== Projekt bez linku ==');
{
  const context = await browser.newContext();
  await context.addCookies([{ name: cookieName, value: cookieValue, url: SITE }]);
  const page = await context.newPage();
  await page.goto(`${SITE}/app/design-review/${ids.none}`);
  check((await page.locator('[data-review-composer]').count()) === 0, 'Bez prototypu: brak przełącznika trybów');
  await context.close();
}

await browser.close();
console.log(`\nTRYBY (przeglądarka): ${pass} PASS, ${fail} FAIL`);
process.exit(fail === 0 ? 0 : 1);
