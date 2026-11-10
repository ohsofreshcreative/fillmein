// Design Review, etap 12 — macierz przeglądarek × widoków × ról (headless; Playwright z /tmp/fmi-ui-audit).
// Silniki: Chromium (Chrome/Edge), WebKit (Safari), Firefox. Widoki: desktop 1280, tablet 768, telefon 390 (pion),
// telefon 844×390 (poziom), „zoom 200%” = 640 px CSS przy DPR 2. Role: opiekun (panel), gość z linkiem recenzji,
// klient Treści (stary link). Sprawdzenia: 200, brak błędów JS, brak przewijania poziomego, fokus klawiatury,
// axe (WCAG 2.2 AA, poza ramką Figmy), pinezki na zapisanych pozycjach. Bez zrzutów ekranu.
import { readFileSync } from 'node:fs';
import { chromium, webkit, firefox } from '/tmp/fmi-ui-audit/node_modules/playwright/index.mjs';

const SITE = 'http://fillmein.local';
const manifest = JSON.parse(readFileSync('/tmp/fmi-stage03-fixtures.json', 'utf8'));
const ids = JSON.parse(readFileSync('/tmp/fmi-review-qa.json', 'utf8'));
const axeSource = readFileSync('/tmp/fmi-ui-audit/node_modules/axe-core/axe.min.js', 'utf8');
const [cookieName, cookieValue] = manifest.cookies.manager.split(/=(.*)/s);
const TOLERANCE = 0.003;
const ENGINES = { chromium, webkit, firefox };
const VIEWS = [
  { name: 'desktop 1280', viewport: { width: 1280, height: 900 } },
  { name: 'tablet 768', viewport: { width: 768, height: 1024 }, touch: true },
  { name: 'telefon 390 pion', viewport: { width: 390, height: 844 }, touch: true, mobile: true },
  { name: 'telefon 844 poziom', viewport: { width: 844, height: 390 }, touch: true, mobile: true },
  { name: 'zoom 200% (640 CSS, DPR 2)', viewport: { width: 640, height: 450 }, scale: 2 },
];

let pass = 0;
let fail = 0;
const rows = [];
const blocked = [];
const check = (ok, label) => {
  ok ? pass++ : fail++;
  if (!ok) console.log(`  FAIL  ${label}`);
};
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

let TAB = 'Tab';
const pageChecks = async (page, label, problems, { axe }) => {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  check(overflow <= 1, `${label}: bez przewijania poziomego (${overflow})`);
  // Safari domyślnie przechodzi Tabem tylko po polach formularzy; po linkach — Option+Tab.
  await page.keyboard.press(TAB);
  const focused = await page.evaluate(() => {
    const el = document.activeElement;
    return el && el !== document.body ? el.tagName : null;
  });
  check(Boolean(focused), `${label}: Tab przenosi fokus (${focused})`);
  if (axe) {
    await page.addScriptTag({ content: axeSource });
    const violations = await page.evaluate(() => window.axe.run({ include: [['body']], exclude: [] }, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] })
      .then((result) => result.violations.map((v) => `${v.id}×${v.nodes.length}`)));
    check(violations.length === 0, `${label}: axe ${violations.join(', ') || 'OK'}`);
  }
  check(problems.length === 0, `${label}: bez błędów JS ${problems.join(' | ')}`);
};

const pins = (page) => page.evaluate(() => {
  const image = document.querySelector('[data-screen-image]');
  const data = window.Alpine.$data(document.querySelector('[data-review-composer]'));
  if (!image) return [];
  const box = image.getBoundingClientRect();
  return [...document.querySelectorAll('[data-pin]')].map((element) => {
    const rect = element.getBoundingClientRect();
    const stored = data.screenPins.find((pin) => String(pin.number) === element.dataset.pin);
    // Dymek wskazuje miejsce lewym dolnym rogiem.
    return { x: (rect.left - box.left) / box.width, y: (rect.bottom - box.top) / box.height, sx: stored?.pin.x, sy: stored?.pin.y };
  });
});

for (const [engineName, engine] of Object.entries(ENGINES)) {
  let browser;
  try {
    // WebKit/Firefox: nazwy .local rozwiązuje mDNS (zawieszenia) — ruch do strony przez router Local jako proxy
    // (bez zmian w systemie); Figma z pominięciem proxy.
    browser = await engine.launch(engineName === 'chromium' ? {} : { proxy: { server: 'http://127.0.0.1:80', bypass: '*.figma.com,figma.com' } });
  } catch (error) {
    console.log(`  BLOCKED  ${engineName}: ${error.message.split('\n')[0]}`);
    // Niedostępny silnik to brak środowiska, nie błąd aplikacji — raport jako NIE WYKONANY.
    rows.push(`${engineName}\tBLOCKED (nie wykonano)`);
    blocked.push(engineName);
    continue;
  }
  const version = browser.version();
  TAB = engineName === 'webkit' ? 'Alt+Tab' : 'Tab';
  const before = { pass, fail };

  for (const view of VIEWS) {
    const options = { viewport: view.viewport, deviceScaleFactor: view.scale ?? 1, hasTouch: Boolean(view.touch) };
    if (view.mobile && engineName !== 'firefox') options.isMobile = true; // Firefox nie obsługuje isMobile.
    const tag = `${engineName} ${version} · ${view.name}`;
    const axe = engineName === 'chromium' || view.name === 'desktop 1280';

    // Opiekun: recenzja (ekrany, dymek pinezki), powiadomienia, historia.
    try {
      const context = await browser.newContext(options);
      await context.addCookies([{ name: cookieName, value: cookieValue, url: SITE }]);
      const page = await context.newPage();
      const problems = [];
      page.on('pageerror', (error) => problems.push(error.message));
      const response = await page.goto(`${SITE}/app/design-review/${ids.q}`, { waitUntil: 'domcontentloaded' });
      await sleep(600);
      check(response.status() === 200, `${tag} · panel recenzji: 200`);
      check((await page.locator('[data-review-composer]').count()) === 1 && (await page.locator('iframe').count()) === 0, `${tag} · panel: widok ekranów (bez ramki)`);
      await page.locator(`[data-screen-tab="${ids.screen}"]`).click();
      await page.waitForFunction((id) => document.querySelector('[data-pin-surface]')?.dataset.screen === String(id) && document.querySelector('[data-screen-image]')?.complete, ids.screen, { timeout: 60000 });
      await sleep(400);
      const placed = await pins(page);
      check(placed.length >= 1 && placed.every((p) => Math.abs(p.x - p.sx) < TOLERANCE && Math.abs(p.y - p.sy) < TOLERANCE),
        `${tag} · pinezka na zapisanej pozycji (${placed.map((p) => `${p.x.toFixed(3)},${p.y.toFixed(3)}`).join(' ')})`);
      await pageChecks(page, `${tag} · panel recenzji`, problems, { axe });

      for (const path of ['/app/notifications', `/app/projects/${ids.q}/activity?typ=recenzja`]) {
        const extra = [];
        const listener = (error) => extra.push(error.message);
        page.on('pageerror', listener);
        const res = await page.goto(`${SITE}${path}`, { waitUntil: 'domcontentloaded' });
        await sleep(300);
        check(res.status() === 200, `${tag} · ${path}: 200`);
        await pageChecks(page, `${tag} · ${path}`, extra, { axe });
        page.off('pageerror', listener);
      }
      await context.close();
    } catch (error) {
      check(false, `${tag} · panel: ${error.name}`); // bez adresu (tokenów) w komunikacie
    }

    // Gość z linkiem recenzji.
    try {
      const context = await browser.newContext(options);
      const page = await context.newPage();
      const problems = [];
      page.on('pageerror', (error) => problems.push(error.message));
      await page.goto(`${SITE}/r/${ids.review}`, { waitUntil: 'domcontentloaded' });
      await sleep(500);
      check(new URL(page.url()).pathname === '/review', `${tag} · gość: /review bez tokenu`);
      check(await page.locator('[data-figma-notice]').isVisible(), `${tag} · gość: informacja o dostępie Figmy`);
      await pageChecks(page, `${tag} · gość`, problems, { axe });
      const name = page.locator('[data-guest-name-form] input[name=name]');
      if (await name.count()) {
        await name.fill('Gość QA');
        await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('[data-guest-name-form] button[type=submit]').click()]);
        await sleep(400);
        check((await page.locator('[data-decision-form]').count()) === 1, `${tag} · gość: formularz decyzji po podaniu nazwy`);
      }
      await context.close();
    } catch (error) {
      check(false, `${tag} · gość: ${error.name}`);
    }

    // Klient Treści przez stary link.
    try {
      const context = await browser.newContext(options);
      const page = await context.newPage();
      const problems = [];
      page.on('pageerror', (error) => problems.push(error.message));
      const res = await page.goto(`${SITE}/p/${ids.old}`, { waitUntil: 'domcontentloaded' });
      await sleep(500);
      check(res.status() === 200, `${tag} · Treści (stary link): 200`);
      await pageChecks(page, `${tag} · Treści`, problems, { axe });
      await context.close();
    } catch (error) {
      check(false, `${tag} · Treści: ${error.name}`);
    }
  }

  rows.push(`${engineName} ${version}\t${pass - before.pass} PASS\t${fail - before.fail} FAIL`);
  await browser.close();
}

console.log('  MACIERZ (silnik → wynik):');
for (const row of rows) console.log(`    ${row}`);
console.log(`  MACIERZ PRZEGLĄDAREK: ${pass} PASS, ${fail} FAIL${blocked.length ? `, BLOCKED: ${blocked.join(', ')}` : ''}`);
process.exit(fail === 0 ? 0 : 1);
