// Design Review, etap 08 — dyskusje w headless Chromium (Playwright z /tmp/fmi-ui-audit). Dane testowe, bez zrzutów.
// Stan wejściowy (po teście HTTP): uwaga 1 Nowy, 2 Nowy (ponownie otwarta), 3 Rozwiązany.
import { readFileSync } from 'node:fs';
import { chromium } from '/tmp/fmi-ui-audit/node_modules/playwright/index.mjs';

const SITE = 'http://fillmein.local';
const manifest = JSON.parse(readFileSync('/tmp/fmi-stage03-fixtures.json', 'utf8'));
const ids = JSON.parse(readFileSync('/tmp/fmi-review-discussions.json', 'utf8'));
const axeSource = readFileSync('/tmp/fmi-ui-audit/node_modules/axe-core/axe.min.js', 'utf8');
const URL = `${SITE}/app/design-review/${ids.d}`;

let pass = 0;
let fail = 0;
const check = (ok, label) => {
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${label}`);
};

let browser;
try {
  browser = await chromium.launch();
} catch (error) {
  console.log(`BLOCKED: Chromium Playwrighta (${error.message.split('\n')[0]})`);
  process.exit(2);
}

async function session(who, viewport = { width: 1280, height: 900 }) {
  const [name, value] = manifest.cookies[who].split(/=(.*)/s);
  const context = await browser.newContext({ viewport });
  await context.addCookies([{ name, value, url: SITE }]);
  const page = await context.newPage();
  const problems = [];
  page.on('pageerror', (error) => problems.push(error.message));
  return { context, page, problems };
}
const thread = (page, number) => page.locator(`#uwaga-${number}`);
const counts = (page) => page.evaluate(() => Object.fromEntries([...document.querySelectorAll('[data-filter]')].map((link) => [link.dataset.filter, Number(link.querySelector('[data-filter-count]').textContent)])));
const listed = (page) => page.evaluate(() => [...document.querySelectorAll('[data-thread]')].map((item) => Number(item.dataset.thread)));
// Formularz → POST → 302 → nowy dokument (adres może mieć ten sam #uwaga-N, więc czekamy na odpowiedź GET strony).
const submitAndWait = async (page, action) => {
  await Promise.all([
    page.waitForResponse((response) => response.request().method() === 'GET' && response.request().resourceType() === 'document', { timeout: 20000 }),
    action(),
  ]);
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(200);
};

console.log('== Odpowiedź z klawiatury (opiekun) ==');
const manager = await session('manager');
{
  const { page } = manager;
  await page.goto(URL, { waitUntil: 'domcontentloaded' });
  const before = await thread(page, 1).locator('[data-comment]').count();
  await thread(page, 1).locator('[data-reply-form] textarea').focus();
  await page.keyboard.type('Odpowiedź z przeglądarki');
  await page.keyboard.press('Tab');
  const focused = await page.evaluate(() => document.activeElement?.textContent?.trim());
  check(focused?.includes('Wyślij odpowiedź'), 'Tab z pola odpowiedzi → przycisk „Wyślij odpowiedź”');
  await submitAndWait(page, () => page.keyboard.press('Enter'));
  check(page.url().endsWith('#uwaga-1'), 'Po wysłaniu: powrót do #uwaga-1');
  const after = thread(page, 1).locator('[data-comment]');
  check((await after.count()) === before + 1 && (await after.last().locator('[data-message-body]').innerText()) === 'Odpowiedź z przeglądarki', 'Odpowiedź na końcu wątku 1');
  check((await after.last().locator('[data-comment-author]').innerText()).length > 0 && (await after.last().locator('time').getAttribute('datetime')) !== null, 'Odpowiedź: autor i data');

  console.log('== Podwójne kliknięcie „Wyślij odpowiedź” ==');
  const base = await thread(page, 2).locator('[data-comment]').count();
  await thread(page, 2).locator('[data-reply-form] textarea').fill('Podwójny klik');
  const button = thread(page, 2).locator('[data-reply-form] button[type=submit]');
  await submitAndWait(page, () => button.dblclick());
  check((await thread(page, 2).locator('[data-comment]').count()) === base + 1, 'Podwójne kliknięcie → jedna odpowiedź');

  console.log('== Statusy i filtry ==');
  let state = await counts(page);
  check(state.all === 3 && state.nowe === 2 && state.rozwiazane === 1, `Liczniki: wszystkie ${state.all}, nowe ${state.nowe}, w trakcie ${state['w-trakcie']}, rozwiązane ${state.rozwiazane}`);
  await submitAndWait(page, () => thread(page, 2).locator('[data-status-target=in_progress]').click());
  check((await thread(page, 2).locator('[data-thread-badge]').innerText()).trim() === 'W trakcie', 'Uwaga 2: „Oznacz: W trakcie” → W trakcie');
  state = await counts(page);
  check(state.nowe === 1 && state['w-trakcie'] === 1, 'Liczniki po zmianie: nowe 1, w trakcie 1');
  await submitAndWait(page, () => thread(page, 2).locator('[data-status-target=resolved]').click());
  check((await thread(page, 2).locator('[data-thread-badge]').innerText()).trim() === 'Rozwiązany' && (await thread(page, 2).locator('[data-thread-closed]').count()) === 1, 'Uwaga 2: Rozwiąż → Rozwiązany, bez formularza odpowiedzi');
  await page.locator('[data-filter=rozwiazane]').click();
  await page.waitForLoadState('domcontentloaded');
  check(JSON.stringify(await listed(page)) === '[2,3]' && (await page.locator('[data-filter=rozwiazane]').getAttribute('aria-current')) === 'page', 'Filtr „Rozwiązany”: uwagi 2 i 3, aria-current');
  await submitAndWait(page, () => thread(page, 2).locator('[data-status-target=open]').click());
  check(page.url().includes('status=rozwiazane'), 'Po zmianie statusu: zachowany filtr w adresie');
  check(JSON.stringify(await listed(page)) === '[3]', 'Ponownie otwarta uwaga 2 znika z filtra „Rozwiązany”');
  await page.goto(URL, { waitUntil: 'domcontentloaded' });
  await thread(page, 2).locator('[data-history] summary').click();
  const history = await thread(page, 2).locator('[data-history] [data-event]').evaluateAll((items) => items.map((item) => item.dataset.event));
  check(history.slice(-3).join(',') === 'status_changed,status_changed,reopened', `Historia uwagi 2: ${history.join(', ')}`);
  check(/ponownie otworzył\(a\) wątek \(Rozwiązany → Nowy\)/.test(await thread(page, 2).locator('[data-history]').innerText()), 'Historia: opis ponownego otwarcia');

  console.log('== Edycja własnej wiadomości ==');
  const own = thread(page, 1).locator('[data-comment]').first();
  await own.locator('details summary').click();
  await own.locator('[data-edit-form] textarea').fill('Uwaga opiekuna — poprawiona');
  await submitAndWait(page, () => own.locator('[data-edit-form] button[type=submit]').click());
  check((await thread(page, 1).locator('[data-comment]').first().locator('[data-message-body]').innerText()) === 'Uwaga opiekuna — poprawiona' && (await thread(page, 1).locator('[data-comment-edited]').count()) >= 1, 'Edycja zapisana, znacznik „edytowano”');
  check((await thread(page, 1).locator('[data-history]').textContent()).includes('Poprzednio: Uwaga opiekuna'), 'Poprzednia treść w historii');
  const others = await thread(page, 2).locator('[data-comment]').first().locator('details').count();
  check(others === 0, 'Cudza wiadomość (pracownika): brak „Edytuj”');

  console.log('== Dostępność i telefon ==');
  await page.addScriptTag({ content: axeSource });
  const violations = await page.evaluate(() => window.axe.run({ include: [['#uwagi']] }, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] })
    .then((result) => result.violations.map((violation) => `${violation.id}×${violation.nodes.length}`)));
  check(violations.length === 0, `axe (lista uwag): ${violations.length ? violations.join(', ') : 'brak naruszeń'}`);
  await page.setViewportSize({ width: 375, height: 800 });
  await page.waitForTimeout(400);
  check((await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)) <= 0, '375 px: bez przewijania poziomego');
  check(manager.problems.length === 0, `Bez błędów JS${manager.problems.length ? ': ' + manager.problems.join(' | ') : ''}`);
}

console.log('== Pracownik ==');
const member = await session('member');
{
  const { page } = member;
  await page.goto(URL, { waitUntil: 'domcontentloaded' });
  check((await page.locator('[data-status-target=in_progress], [data-status-target=resolved]').count()) === 0, 'Pracownik: brak przycisków W trakcie / Rozwiąż');
  check((await thread(page, 3).locator('[data-status-target]').count()) === 0, 'Pracownik: brak ponownego otwarcia cudzej rozwiązanej uwagi');
  check((await thread(page, 1).locator('[data-comment]').first().locator('details').count()) === 0, 'Pracownik: brak „Edytuj” przy wiadomości opiekuna');
  check((await thread(page, 1).locator('[data-reply-form]').count()) === 1, 'Pracownik: może odpowiadać');
}

await browser.close();
console.log(`  DYSKUSJE (przeglądarka): ${pass} PASS, ${fail} FAIL`);
process.exit(fail === 0 ? 0 : 1);
