// Design Review — strona recenzji bez osadzonej ramki prototypu (headless Chromium, Playwright z /tmp/fmi-ui-audit).
// Tryb Przeglądaj (ramka Figmy) został usunięty: recenzja działa na obrazach ekranów, a prototyp otwiera się w Figmie
// przez link. Adresu pliku `live` ani ciasteczek nie wypisujemy. Bez zrzutów ekranu.
import { readFileSync } from 'node:fs';
import { chromium } from '/tmp/fmi-ui-audit/node_modules/playwright/index.mjs';

const SITE = 'http://fillmein.local';
const manifest = JSON.parse(readFileSync('/tmp/fmi-stage03-fixtures.json', 'utf8'));
const ids = JSON.parse(readFileSync('/tmp/fmi-review-embed.json', 'utf8'));
const [cookieName, cookieValue] = manifest.cookies.admin.split(/=(.*)/s);

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

async function open(id, options = {}) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, ...options });
  await context.addCookies([{ name: cookieName, value: cookieValue, url: SITE }]);
  const page = await context.newPage();
  const problems = [];
  page.on('pageerror', (error) => problems.push(error.message));
  const response = await page.goto(`${SITE}/app/design-review/${id}`, { waitUntil: 'domcontentloaded' });
  return { context, page, response, problems };
}

console.log('== Prototyp publiczny: strona recenzji ==');
{
  const { context, page, response, problems } = await open(ids.pub);
  check(response.status() === 200, `Strona recenzji 200 (${response.status()})`);
  check((await page.locator('iframe').count()) === 0, 'Brak ramki Figmy na stronie');
  check((await page.locator('[data-mode-button]').count()) === 0, 'Brak przełącznika Przeglądaj / Komentuj');
  check(await page.locator('[data-review-canvas] [data-review-composer]').isVisible(), 'Widok recenzji na ekranach');
  const link = page.locator('[data-embed-open]');
  const href = (await link.getAttribute('href')) ?? '';
  check(/^https:\/\/www\.figma\.com\/proto\/nrPSsILSYjesyc5UHjYYa4/.test(href), 'Link „Otwórz prototyp w Figmie” prowadzi do zapisanego prototypu');
  check((await link.getAttribute('target')) === '_blank' && (await link.getAttribute('rel')) === 'noopener noreferrer' && (await link.getAttribute('referrerpolicy')) === 'no-referrer', 'Link: nowa karta, noopener noreferrer, bez Referer');
  check(await page.locator('[data-screens-empty-surface]').isVisible(), 'Bez ekranów: informacja o dodaniu ekranów rewizji');
  await page.locator('[data-comment-whole]').click();
  check(await page.locator('[data-comment-context]').isVisible(), 'Bez ekranów: uwaga z opisanym kontekstem');
  check((await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)) <= 0, 'Bez przewijania poziomego');
  check(problems.length === 0, `Bez błędów JS${problems.length ? ': ' + problems.join(' | ') : ''}`);
  await context.close();
}

console.log('== Telefon 375 px ==');
{
  const { context, page, problems } = await open(ids.pub, { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true });
  check((await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)) <= 0, 'Telefon: bez przewijania poziomego');
  check(problems.length === 0, 'Telefon: bez błędów JS');
  await context.close();
}

console.log('== Niepoprawny i brakujący link ==');
{
  const { context, page } = await open(ids.bad);
  check(await page.locator('[data-embed-invalid]').isVisible() && (await page.locator('[data-embed-open]').count()) === 0, 'Niepoprawny zapisany link: komunikat, bez linku do Figmy');
  await page.goto(`${SITE}/app/design-review/${ids.none}`);
  check((await page.locator('[data-embed-open]').count()) === 0 && (await page.locator('[data-subpages-form]').isVisible()), 'Brak linku: bez przycisku Figmy, formularz Podstrony');
  await context.close();
}

await browser.close();
console.log(`\nOSADZENIE (przeglądarka): ${pass} PASS, ${fail} FAIL`);
process.exit(fail === 0 ? 0 : 1);
