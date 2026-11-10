// Etap 10 — audyt UI podglądu zmian, przeglądu treści i dziennika (uruchamiany przez tests/bin/ui-audit.sh z /tmp/fmi-ui-audit-stage10).
// Dane: /tmp/fmi-stage03-fixtures.json i /tmp/fmi-stage10-ids.json. Tokenów i ciasteczek nie wypisujemy.
import { readFileSync } from 'node:fs';
import { chromium } from '/tmp/fmi-ui-audit/node_modules/playwright/index.mjs';

const SITE = 'http://fillmein.local';
const axeSource = readFileSync('/tmp/fmi-ui-audit/node_modules/axe-core/axe.min.js', 'utf8');
const manifest = JSON.parse(readFileSync('/tmp/fmi-stage03-fixtures.json', 'utf8'));
const ids = JSON.parse(readFileSync('/tmp/fmi-stage10-ids.json', 'utf8'));

if (manifest.site !== SITE) {
  console.log(`BLOCKED: audyt działa tylko na ${SITE}.`);
  process.exit(2);
}

const project = `${SITE}/app/projects/${manifest.projects.a}`;
const importUrl = (page) => `${project}/pages/${page}/figma/import`;
const clean = (text) => String(text).replaceAll(ids.token, '[token]');
const widths = [360, 375, 768, 1280];

let browser;

try {
  browser = await chromium.launch();
} catch (error) {
  console.log(`BLOCKED: nie można uruchomić Chromium Playwrighta (${error.message.split('\n')[0]}).`);
  process.exit(2);
}

const [cookieName, cookieValue] = manifest.cookies.admin.split(/=(.*)/s);
const axeRun = () => window.axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] })
  .then((result) => result.violations.map((violation) => `${violation.id}×${violation.nodes.length}: ${violation.nodes[0].target}`));

const open = async (width) => {
  const context = await browser.newContext({ viewport: { width, height: 900 } });
  await context.addCookies([{ name: cookieName, value: cookieValue, url: SITE }]);
  const page = await context.newPage();
  const jsErrors = [];
  page.on('pageerror', (error) => jsErrors.push(`pageerror: ${clean(error.message)}`));

  return { context, page, jsErrors };
};

let failed = 0;
let total = 0;
const report = (name, problems) => {
  total++;
  failed += problems.length ? 1 : 0;
  console.log(`  ${problems.length ? 'FAIL' : 'PASS'}  ${name}${problems.length ? ` — ${problems.map(clean).join('; ')}` : ''}`);
};

const targets = [
  { label: 'podgląd zmian', url: importUrl(manifest.pages.a) },
  { label: 'podgląd: frame przycięty', url: importUrl(ids.pages.cut) },
  { label: 'podgląd: zmiana ryzykowna', url: importUrl(ids.pages.risk) },
  { label: 'przegląd treści', url: `${project}/review` },
  { label: 'dziennik', url: `${project}/activity` },
  { label: 'dziennik z filtrem', url: `${project}/activity?typ=figma` },
];

for (const target of targets) {
  for (const width of widths) {
    const { context, page, jsErrors } = await open(width);
    const response = await page.goto(target.url, { waitUntil: 'networkidle' });
    await page.waitForTimeout(200);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    await page.addScriptTag({ content: axeSource });
    const violations = await page.evaluate(axeRun);
    const problems = [...jsErrors];

    if (response.status() !== 200) problems.push(`HTTP ${response.status()}`);
    if (violations.length) problems.push(`axe: ${violations.join(', ')}`);
    if (overflow) problems.push('przepełnienie poziome');
    report(`${target.label} @${width}px`, problems);
    await context.close();
  }
}

// Podgląd: wybór z klawiatury, zastosowanie tylko wybranej zmiany.
{
  const { context, page, jsErrors } = await open(375);
  await page.goto(importUrl(manifest.pages.a), { waitUntil: 'networkidle' });
  const boxes = page.locator('input[name="selected[]"]');
  const count = await boxes.count();
  const label = page.locator('input[value="f:10:301"]');

  report('Podgląd: wszystkie zmiany zaznaczone', count >= 5 && await boxes.evaluateAll((all) => all.every((box) => box.checked)) ? [] : [`pól ${count}`]);

  await page.locator('input[value="f:10:306"]').focus();
  await page.keyboard.press('Space');
  report('Podgląd: odznaczenie klawiaturą', await page.locator('input[value="f:10:306"]').isChecked() ? ['pole zaznaczone'] : []);
  report('Podgląd: etykieta dostępna', await label.getAttribute('aria-label') ? [] : ['brak nazwy']);

  await page.getByRole('button', { name: 'Zastosuj wybrane zmiany' }).click();
  await page.waitForLoadState('networkidle');
  const body = await page.content();
  report('Podgląd: odznaczona zmiana czeka', body.includes('value="f:10:306"') && !body.includes('value="f:10:301"') ? [] : ['zły stan po zastosowaniu']);
  report('Podgląd: bez błędów JS', jsErrors);
  await context.close();
}

// Przegląd: oznaczenie i zdjęcie z komentarzem.
{
  const { context, page, jsErrors } = await open(375);
  await page.goto(`${project}/review?status=filled`, { waitUntil: 'networkidle' });
  const item = page.locator('li').filter({ hasText: 'Oznacz do poprawy' }).first();

  await item.locator('summary').click();
  await item.locator('textarea').fill('Skróć tekst.');
  await item.getByRole('button', { name: 'Zapisz oznaczenie' }).click();
  await page.waitForLoadState('networkidle');
  await page.goto(`${project}/review?status=review`, { waitUntil: 'networkidle' });
  await page.addScriptTag({ content: axeSource });
  const violations = await page.evaluate(axeRun);

  report('Przegląd: komentarz widoczny', (await page.content()).includes('Skróć tekst.') ? [] : ['brak komentarza']);
  report('Przegląd z oznaczeniem: axe', violations.length ? [violations.join(', ')] : []);
  report('Przegląd: bez błędów JS', jsErrors);
  await context.close();
}

await browser.close();
console.log(`\nAudyt UI etapu 10: ${total - failed}/${total} PASS, ${failed} FAIL`);
process.exit(failed ? 1 : 0);
