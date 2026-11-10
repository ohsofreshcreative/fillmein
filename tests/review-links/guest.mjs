// Design Review, etap 09 — recenzja gościa w headless Chromium (Playwright z /tmp/fmi-ui-audit). Dane testowe,
// obraz ekranu generowany lokalnie, prototyp z niedostępnego pliku Figmy (odmowa Figmy) — bez zrzutów.
import { readFileSync } from 'node:fs';
import { chromium } from '/tmp/fmi-ui-audit/node_modules/playwright/index.mjs';

const SITE = 'http://fillmein.local';
const ids = JSON.parse(readFileSync('/tmp/fmi-review-links.json', 'utf8'));
const axeSource = readFileSync('/tmp/fmi-ui-audit/node_modules/axe-core/axe.min.js', 'utf8');

let pass = 0;
let fail = 0;
const check = (ok, label) => {
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${label}`);
};
const note = (text) => console.log(`  INFO  ${text}`);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

let browser;
try {
  browser = await chromium.launch();
} catch (error) {
  console.log(`BLOCKED: Chromium Playwrighta (${error.message.split('\n')[0]})`);
  process.exit(2);
}

console.log('== Gość z prawem komentarza ==');
{
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  const problems = [];
  const referers = [];
  page.on('pageerror', (error) => problems.push(error.message));
  page.on('request', (request) => {
    const referer = request.headers().referer;
    if (/figma\.com/.test(request.url()) && referer) referers.push(referer);
  });
  await page.goto(`${SITE}/r/${ids.tokens.noapprove}`, { waitUntil: 'domcontentloaded' });
  check(new URL(page.url()).pathname === '/review' && !page.url().includes(ids.tokens.noapprove), 'Po wejściu adres /review bez tokenu');
  check(await page.locator('[data-figma-notice]').isVisible(), 'Informacja o osobnym dostępie Figmy');
  check((await page.locator('[data-guest-abilities]').innerText()).trim() === 'Podgląd, Komentowanie', 'Możliwości linku: Podgląd, Komentowanie');
  check((await page.locator('[data-comment-form]:visible').count()) === 0, 'Przed podaniem nazwy: bez formularza uwag');
  await page.locator('[data-comment-whole]').click();
  await page.waitForTimeout(600);
  check(await page.evaluate(() => document.activeElement?.closest('[data-guest-name-form]') !== null) && (await page.locator('[data-comment-form]:visible').count()) === 0, 'Przed podaniem nazwy: „Dodaj uwagę” przewija do pola z podpisem i ustawia w nim kursor');

  await page.locator('[data-guest-name-form] input[name=name]').fill('Jan z firmy klienta');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('[data-guest-name-form] button[type=submit]').click()]);
  await sleep(400);
  check((await page.locator('[data-guest-name]').innerText()).includes('Jan z firmy klienta'), 'Nazwa w nagłówku z dopiskiem „gość”');
  check(await page.locator('[data-review-composer]').isVisible() && (await page.locator('[data-comment-whole]').count()) === 1, 'Po podaniu nazwy: komentowanie na ekranach');
  check((await page.locator('iframe').count()) === 0, 'Bez ramki Figmy (tryb Przeglądaj usunięty)');

  await page.locator(`[data-screen-tab="${ids.rs}"]`).click();
  await page.waitForFunction((id) => document.querySelector('[data-pin-surface]')?.dataset.screen === String(id) && document.querySelector('[data-screen-image]')?.complete, ids.rs, { timeout: 30000 });
  const box = await page.locator('[data-screen-image]').boundingBox();
  await page.mouse.click(box.x + box.width * 0.3, box.y + Math.min(box.height * 0.2, 300));
  await page.locator('[data-comment-body]').fill('Uwaga z przeglądarki gościa');
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }), page.locator('[data-comment-submit]').click()]);
  await sleep(500);
  const number = Number(new URL(page.url()).searchParams.get('nowa'));
  check(!(await page.locator('[data-thread-popover]').isVisible()) && (await page.locator(`[data-pin="${number}"]`).isVisible()), 'Po zapisie: dymek zamknięty, zostaje pinezka');
  const item = page.locator(`#uwaga-${number}`);
  check(number > 0 && (await item.locator('[data-comment-author]').first().innerText()) === 'Jan z firmy klienta', `Uwaga ${number} zapisana z nazwą gościa`);
  check((await item.locator('[data-guest-mark]').count()) === 1 && (await item.innerText()).includes('pinezka'), 'Uwaga: oznaczenie gościa, pinezka na ekranie');

  await item.locator('[data-reply-form] textarea').fill('Dopisek gościa');
  await Promise.all([page.waitForResponse((response) => response.request().resourceType() === 'document' && response.request().method() === 'GET'), item.locator('[data-reply-form] button[type=submit]').click()]);
  await sleep(400);
  check((await page.locator(`#uwaga-${number} [data-message-body]`).last().innerText()) === 'Dopisek gościa', 'Odpowiedź gościa w wątku');

  console.log('== Recenzja bez Figmy w przeglądarce gościa ==');
  await page.goto(`${SITE}/review`, { waitUntil: 'domcontentloaded' });
  await sleep(1500);
  check((await page.locator('[data-threads] [data-thread]').count()) >= 1 && (await page.locator('[data-figma-notice]').isVisible()), 'Recenzja w Fill Me In działa niezależnie od dostępu do Figmy');
  check(referers.every((referer) => !referer.includes('/r/')), `Żądania do Figmy bez tokenu w Referer (${referers.length ? referers.length + ' z Referer' : 'bez nagłówka Referer'})`);

  await page.addScriptTag({ content: axeSource });
  const violations = await page.evaluate(() => window.axe.run({ include: [['main']], exclude: [['[data-figma-frame]']] }, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] })
    .then((result) => result.violations.map((violation) => `${violation.id}×${violation.nodes.length}`)));
  check(violations.length === 0, `axe (strona gościa): ${violations.length ? violations.join(', ') : 'brak naruszeń'}`);
  await page.setViewportSize({ width: 375, height: 800 });
  await sleep(400);
  check((await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)) <= 0, '375 px: bez przewijania poziomego');
  check(problems.length === 0, `Bez błędów JS${problems.length ? ': ' + problems.join(' | ') : ''}`);
  await context.close();
}

console.log('== Gość tylko z podglądem ==');
{
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await page.goto(`${SITE}/r/${ids.tokens.readonly}`, { waitUntil: 'domcontentloaded' });
  check((await page.locator('[data-guest-abilities]').innerText()).trim() === 'Podgląd', 'Możliwości: Podgląd');
  check((await page.locator('[data-comment-form], [data-comment-whole], [data-reply-form], [data-popover-reply], [data-guest-name-form]').count()) === 0, 'Bez dodawania uwag, odpowiedzi i formularza nazwy');
  check((await page.locator('[data-review-composer]').count()) === 1 && (await page.locator('[data-threads] [data-thread]').count()) >= 1, 'Ekrany i uwagi do odczytu');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.getByRole('button', { name: 'Zakończ' }).click()]);
  const after = await page.goto(`${SITE}/review`, { waitUntil: 'domcontentloaded' });
  check(after.status() === 403, '„Zakończ” zamyka sesję: /review → 403');
  await context.close();
}

await browser.close();
console.log(`  LINKI (przeglądarka): ${pass} PASS, ${fail} FAIL`);
process.exit(fail === 0 ? 0 : 1);
