// Design Review — pinezki i dymki na ekranach rewizji w headless Chromium (Playwright z /tmp/fmi-ui-audit).
// Widok bez trybu Przeglądaj: zakładki ekranów, pełny obraz frame'u, uwagi jako dymki (wątek, odpowiedź, Rozwiąż).
// Pozycję pinezki porównujemy z wartością zapisaną w bazie (dane strony) względem prostokąta obrazu ekranu.
// Zrzutów ekranów projektu klienta nie robimy (plik z FMI_FIGMA_LIVE_URL) — dowody liczbowe.
import { readFileSync } from 'node:fs';
import { chromium } from '/tmp/fmi-ui-audit/node_modules/playwright/index.mjs';

const SITE = 'http://fillmein.local';
const manifest = JSON.parse(readFileSync('/tmp/fmi-stage03-fixtures.json', 'utf8'));
const ids = JSON.parse(readFileSync('/tmp/fmi-review-comments.json', 'utf8'));
const [cookieName, cookieValue] = manifest.cookies.manager.split(/=(.*)/s);
const TOLERANCE = 0.002;

let pass = 0;
let fail = 0;
const check = (ok, label) => {
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${label}`);
};
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const fmt = (value) => (value === null || value === undefined ? '—' : Number(value).toFixed(4));

let browser;
try {
  browser = await chromium.launch();
} catch (error) {
  console.log(`BLOCKED: Chromium Playwrighta (${error.message.split('\n')[0]})`);
  process.exit(2);
}

async function open(options = {}, query = '') {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, ...options });
  await context.addCookies([{ name: cookieName, value: cookieValue, url: SITE }]);
  const page = await context.newPage();
  const problems = [];
  page.on('pageerror', (error) => problems.push(error.message));
  await page.goto(`${SITE}/app/design-review/${ids.l}${query}`, { waitUntil: 'domcontentloaded' });
  await sleep(500);
  return { context, page, problems };
}
async function selectScreen(page, id) {
  await closePopover(page);
  await page.locator(`[data-screen-tab="${id}"]`).click();
  await page.waitForFunction((wanted) => {
    const image = document.querySelector('[data-screen-image]');
    return image && image.closest('[data-pin-surface]')?.dataset.screen === String(wanted) && image.complete;
  }, id, { timeout: 60000 });
  await sleep(300);
}
async function closePopover(page) {
  if (await page.locator('[data-thread-popover]').isVisible()) {
    await page.locator('[data-popover-close]').click();
    await sleep(200);
  }
}
// Pozycje pinezek względem prostokąta obrazu i wartości zapisane (dane z serwera).
const pins = (page) => page.evaluate(() => {
  const image = document.querySelector('[data-screen-image]');
  const data = window.Alpine.$data(document.querySelector('[data-review-composer]'));
  if (!image) return { screen: data.screenId, items: [] };
  const box = image.getBoundingClientRect();
  return {
    screen: data.screenId,
    natural: [image.naturalWidth, image.naturalHeight],
    items: [...document.querySelectorAll('[data-pin]')].map((element) => {
      const rect = element.getBoundingClientRect();
      const stored = data.screenPins.find((pin) => String(pin.number) === element.dataset.pin);
      // Dymek wskazuje miejsce lewym dolnym rogiem (jak w Figmie).
      return {
        number: Number(element.dataset.pin),
        x: (rect.left - box.left) / box.width,
        y: (rect.bottom - box.top) / box.height,
        storedX: stored?.pin.x,
        storedY: stored?.pin.y,
      };
    }),
  };
});
const aligned = (items) => items.length > 0 && items.every((pin) => Math.abs(pin.x - pin.storedX) < TOLERANCE && Math.abs(pin.y - pin.storedY) < TOLERANCE);
async function save(page, body) {
  await page.locator('[data-comment-body]').fill(body);
  await Promise.all([reloaded(page), page.locator('[data-comment-submit]').click()]);
  await sleep(500);
  return Number(new URL(page.url()).searchParams.get('nowa') ?? new URL(page.url()).searchParams.get('uwaga'));
}
// Zapis przeładowuje stronę (location.replace), także pod ten sam adres — czekamy na nawigację, nie na URL.
async function reloaded(page) {
  await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 });
  await page.waitForFunction(() => window.Alpine && document.querySelector('[data-review-composer]')?._x_dataStack, null, { timeout: 20000 });
}
async function clickImage(page, fx, fy) {
  await closePopover(page);
  const box = await page.locator('[data-screen-image]').boundingBox();
  await page.mouse.click(box.x + box.width * fx, box.y + box.height * fy);
}

let newPin;
console.log('== Postawienie pinezki i zapis (1280 px) ==');
{
  const { context, page, problems } = await open();
  await selectScreen(page, ids.s1);
  const before = await pins(page);
  check(before.natural[0] === ids.s1_size[0] && before.natural[1] === ids.s1_size[1], `Obraz ekranu = wymiary frame'u (${before.natural.join('×')})`);
  check(before.items.length === 1 && before.items[0].number === 1, 'Na s1 widoczna tylko pinezka 1 (z testu HTTP)');
  check(aligned(before.items), `Pinezka 1 na zapisanej pozycji (${fmt(before.items[0]?.x)}, ${fmt(before.items[0]?.y)} vs ${fmt(before.items[0]?.storedX)}, ${fmt(before.items[0]?.storedY)})`);

  const sent = [];
  page.on('request', (request) => { if (request.method() === 'POST' && request.url().endsWith('/comments')) sent.push(request.postDataJSON()); });
  await clickImage(page, 0.6, 0.04);
  check(await page.locator('[data-new-pin]').isVisible(), 'Kliknięcie obrazu: nowa pinezka');
  check(/Pinezka w tym miejscu/.test(await page.locator('[data-comment-kind]').innerText()), 'Dymek nowej uwagi: „Pinezka w tym miejscu”');
  check(await page.locator('[data-comment-form]').evaluate((form) => {
    const pin = document.querySelector('[data-new-pin]').getBoundingClientRect();
    const rect = form.getBoundingClientRect();
    return Math.abs(rect.left - pin.right) < 40 || Math.abs(rect.right - pin.left) < 40;
  }), 'Dymek nowej uwagi przy pinezce');
  newPin = await save(page, 'Przesuń przycisk w prawo');
  check(newPin > 1, `Zapisano uwagę ${newPin}, strona przeładowana z ?nowa=${newPin}`);
  check(!(await page.locator('[data-thread-popover]').isVisible()) && (await page.locator(`[data-pin="${newPin}"]`).isVisible()), 'Po zapisie: dymek zamknięty, widać samą pinezkę');
  check(await page.locator(`[data-pin="${newPin}"]`).evaluate((pin) => getComputedStyle(pin).backgroundColor !== getComputedStyle(document.querySelector('[data-screen-image]')).backgroundColor && pin.getBoundingClientRect().width >= 36), 'Pinezka: kolorowa, 36 px');
  await page.locator(`[data-pin="${newPin}"]`).click();
  check((await page.locator('[data-thread-popover]').innerText()).includes('Przesuń przycisk w prawo'), 'Kliknięcie nowej pinezki otwiera wątek z treścią');
  check((await page.locator(`[data-screen-tab="${ids.s1}"]`).getAttribute('aria-current')) === 'page', 'Po zapisie: ten sam ekran wybrany');
  check(sent.length === 1 && sent[0].screen_id === ids.s1 && Math.abs(sent[0].x - 0.6) < TOLERANCE && Math.abs(sent[0].y - 0.04) < TOLERANCE, `Wysłano screen_id i x, y względem obrazu (${fmt(sent[0]?.x)}, ${fmt(sent[0]?.y)})`);
  check((await page.locator(`#uwaga-${newPin} [data-thread-context]`).innerText()).includes('pinezka'), 'Lista: uwaga z pinezką na ekranie');

  console.log('== Rozmiary okna ==');
  await selectScreen(page, ids.s1);
  for (const width of [1280, 768, 375, 1600]) {
    await page.setViewportSize({ width, height: 900 });
    await sleep(600);
    const state = await pins(page);
    const item = state.items.find((pin) => pin.number === newPin);
    check(state.items.length === 2 && aligned(state.items), `${width} px: pinezki ${state.items.map((pin) => pin.number).join(', ')} na zapisanych pozycjach (nowa: ${fmt(item?.x)}, ${fmt(item?.y)})`);
  }

  console.log('== Zoom strony ==');
  await page.setViewportSize({ width: 1280, height: 900 });
  for (const zoom of ['1.5', '0.67']) {
    await page.evaluate((value) => { document.documentElement.style.zoom = value; }, zoom);
    await sleep(600);
    check(aligned((await pins(page)).items), `Zoom ${zoom}: pinezki na zapisanych pozycjach`);
  }
  await page.evaluate(() => { document.documentElement.style.zoom = ''; });

  console.log('== Przewijanie strony i marginesy ==');
  await closePopover(page);
  await page.locator('[data-screen-image]').evaluate((element) => element.scrollIntoView({ block: 'center' }));
  await sleep(400);
  check(aligned((await pins(page)).items), 'Po przewinięciu strony: pinezki na pozycjach');
  const image = await page.locator('[data-screen-image]').boundingBox();
  const clickY = Math.max(image.y + 5, Math.min(image.y + image.height - 5, 450));
  const expectedY = (clickY - image.y) / image.height;
  await page.mouse.click(image.x + image.width * 0.5, clickY);
  check(await page.locator('[data-new-pin]').isVisible(), 'Pinezka po przewinięciu');
  const scrolledPin = await save(page, 'Uwaga w połowie ekranu');
  await selectScreen(page, ids.s1);
  const afterScroll = (await pins(page)).items.find((pin) => pin.number === scrolledPin);
  check(afterScroll && Math.abs(afterScroll.storedY - expectedY) < TOLERANCE && Math.abs(afterScroll.storedX - 0.5) < TOLERANCE, `Zapis po przewinięciu: y = ${fmt(afterScroll?.storedY)} (oczekiwane ${fmt(expectedY)}) — liczone względem obrazu, nie okna`);

  if (await page.locator('[data-comment-form]').isVisible()) await page.locator('[data-comment-cancel]').click();
  await closePopover(page);
  await page.locator('[data-screen-viewport]').evaluate((element) => element.scrollIntoView({ block: 'start' }));
  await sleep(300);
  const outer = await page.locator('[data-screen-viewport]').boundingBox();
  await page.mouse.click(outer.x + 6, outer.y + 6);
  await sleep(300);
  check(!(await page.locator('[data-new-pin]').isVisible()) && !(await page.locator('[data-comment-form]').isVisible()), 'Kliknięcie w margines poza obrazem: brak pinezki');

  console.log('== Dymek wątku: otwarcie, odpowiedź, Rozwiąż ==');
  await page.locator(`[data-pin="${newPin}"]`).click();
  await sleep(300);
  check(await page.locator('[data-thread-popover]').isVisible(), 'Kliknięcie dymka otwiera wątek');
  await page.keyboard.press('Escape');
  await sleep(200);
  check(!(await page.locator('[data-thread-popover]').isVisible()), 'Esc zamyka wątek');
  await page.locator(`[data-pin="${newPin}"]`).click();
  await page.locator('[data-popover-reply] textarea').fill('Odpowiedź z dymka');
  await Promise.all([reloaded(page), page.locator('[data-popover-reply] button[type=submit]').click()]);
  await sleep(600);
  check((await page.locator('[data-thread-popover]').innerText()).includes('Odpowiedź z dymka'), 'Odpowiedź z dymka zapisana i widoczna w wątku');
  check((await page.locator(`#uwaga-${newPin} [data-comments]`).innerText()).includes('Odpowiedź z dymka'), 'Odpowiedź widoczna też na liście');
  await Promise.all([reloaded(page), page.locator('[data-popover-resolve]').click()]);
  await sleep(600);
  check((await page.locator(`#uwaga-${newPin}`).getAttribute('data-thread-status')) === 'resolved', 'Rozwiąż z dymka: status Rozwiązany');
  check((await page.locator(`[data-pin="${newPin}"]`).getAttribute('data-pin-status')) === 'resolved', 'Rozwiązana uwaga widoczna po wejściu z ?uwaga= (Pokaż rozwiązane)');
  await closePopover(page);
  await page.locator('[data-show-resolved]').uncheck();
  await sleep(200);
  check((await page.locator(`[data-pin="${newPin}"]`).count()) === 0, 'Bez „Pokaż rozwiązane”: rozwiązany dymek ukryty');

  console.log('== Zmiana ekranu ==');
  const other = ids.s2 ?? ids.s3;
  await selectScreen(page, other);
  const otherPins = await pins(page);
  check(otherPins.items.every((pin) => ![1, newPin, scrolledPin].includes(pin.number)), `Inny ekran: brak pinezek z s1 (${otherPins.items.length} pinezek)`);
  await selectScreen(page, ids.s1);
  await clickImage(page, 0.2, 0.01);
  await page.locator('[data-comment-body]').fill('Szkic przed zmianą ekranu');
  await page.locator(`[data-screen-tab="${other}"]`).click();
  await sleep(300);
  check(!(await page.locator('[data-new-pin]').count()) || !(await page.locator('[data-new-pin]').isVisible()), 'Zmiana ekranu w trakcie pisania: pinezka nie przechodzi na inny ekran');
  check((await page.locator('[data-comment-notice]').innerText()).includes('pinezka usunięta'), 'Komunikat: pinezka usunięta');
  check((await page.locator('[data-comment-body]').inputValue()) === 'Szkic przed zmianą ekranu', 'Szkic treści zostaje po zmianie ekranu');
  await page.locator('[data-comment-discard]').click();

  console.log('== Klawiatura: strzałki zmieniają ekran ==');
  await selectScreen(page, ids.s1);
  await page.locator('h1').click();
  const startIndex = await page.evaluate(() => window.Alpine.$data(document.querySelector('[data-review-composer]')).screenIndex);
  await page.keyboard.press('ArrowRight');
  await sleep(300);
  const nextIndex = await page.evaluate(() => window.Alpine.$data(document.querySelector('[data-review-composer]')).screenIndex);
  await page.keyboard.press('ArrowLeft');
  await sleep(300);
  const backIndex = await page.evaluate(() => window.Alpine.$data(document.querySelector('[data-review-composer]')).screenIndex);
  check(nextIndex !== startIndex && backIndex === startIndex, `→ i ← przełączają ekran (${startIndex} → ${nextIndex} → ${backIndex})`);
  await page.locator('[data-screen-next]').click();
  await sleep(300);
  check(new URL(page.url()).searchParams.get('ekran') !== null, 'Wybrany ekran w adresie (?ekran=)');

  console.log('== Ekran bez potwierdzonej powierzchni ==');
  await selectScreen(page, ids.s3);
  await clickImage(page, 0.5, 0.01);
  await sleep(300);
  check(!(await page.locator('[data-new-pin]').isVisible()) && /całego ekranu/.test(await page.locator('[data-comment-kind]').innerText()), 'Niepotwierdzony ekran: brak pinezki, uwaga do ekranu');
  const unconfirmed = await save(page, 'Uwaga do ekranu bez pinezki');
  check((await page.locator(`#uwaga-${unconfirmed} [data-thread-context]`).innerText()).includes('bez pinezki'), 'Lista: „bez pinezki”');

  console.log('== Bez ramki prototypu ==');
  check((await page.locator('iframe').count()) === 0 && (await page.locator('[data-mode-button]').count()) === 0, 'Brak ramki Figmy i trybu Przeglądaj');
  check(await page.locator('[data-embed-open]').isVisible(), 'Link „Otwórz prototyp w Figmie”');
  check(problems.length === 0, `Bez błędów JS${problems.length ? ': ' + problems.join(' | ') : ''}`);
  await context.close();
}

console.log('== Telefon: orientacja i gęstość pikseli ==');
{
  const { context, page, problems } = await open({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 3 });
  await selectScreen(page, ids.s1);
  check(aligned((await pins(page)).items), 'Pion 390×844 (DPR 3): pinezki na pozycjach');
  await page.setViewportSize({ width: 844, height: 390 });
  await sleep(800);
  check(aligned((await pins(page)).items), 'Poziom 844×390: pinezki na pozycjach');
  check((await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)) <= 0, 'Poziom: bez przewijania poziomego');
  await page.setViewportSize({ width: 390, height: 844 });
  await sleep(500);
  const box = await page.locator('[data-screen-image]').boundingBox();
  await page.locator('[data-screen-viewport]').evaluate((element) => element.scrollIntoView({ block: 'start' }));
  await sleep(300);
  const tapBox = await page.locator('[data-screen-image]').boundingBox();
  await closePopover(page);
  await page.touchscreen.tap(tapBox.x + tapBox.width * 0.4, tapBox.y + tapBox.height * 0.03);
  await sleep(300);
  check(await page.locator('[data-comment-form]').isVisible() && (await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)) <= 0, 'Telefon: dymek nowej uwagi mieści się na ekranie');
  const data = await page.evaluate(() => window.Alpine.$data(document.querySelector('[data-review-composer]')).pin);
  check(data && Math.abs(data.x - 0.4) < 0.01 && Math.abs(data.y - 0.03) < 0.01 && box.width > 0, `Tap stawia pinezkę względem obrazu (${fmt(data?.x)}, ${fmt(data?.y)})`);
  check(problems.length === 0, 'Telefon: bez błędów JS');
  await context.close();
}

await browser.close();
console.log(`  PINEZKI (przeglądarka): ${pass} PASS, ${fail} FAIL`);
process.exit(fail === 0 ? 0 : 1);
