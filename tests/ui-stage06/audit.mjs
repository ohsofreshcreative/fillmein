// Etap 06 — audyt UI konfiguratora (uruchamiany przez tests/bin/ui-audit.sh z /tmp/fmi-ui-audit-stage06).
// Dane: /tmp/fmi-stage03-fixtures.json i /tmp/fmi-stage06-ids.json. Ciasteczek nie wypisujemy.
import { readFileSync } from 'node:fs';
import { chromium } from '/tmp/fmi-ui-audit/node_modules/playwright/index.mjs';

const SITE = 'http://fillmein.local';
const axeSource = readFileSync('/tmp/fmi-ui-audit/node_modules/axe-core/axe.min.js', 'utf8');
const manifest = JSON.parse(readFileSync('/tmp/fmi-stage03-fixtures.json', 'utf8'));
const ids = JSON.parse(readFileSync('/tmp/fmi-stage06-ids.json', 'utf8'));

if (manifest.site !== SITE) {
  console.log(`BLOCKED: audyt działa tylko na ${SITE}.`);
  process.exit(2);
}

const base = `/app/projects/${manifest.projects.a}/pages/${manifest.pages.a}`;
const routes = [
  { label: 'struktura podstrony', path: `${base}/structure` },
  { label: 'edycja sekcji', path: `${base}/sections/${ids.first}/edit` },
  { label: 'nowe pole', path: `${base}/sections/${ids.first}/fields/create` },
  { label: 'nowe pole podrzędne', path: `${base}/sections/${ids.first}/fields/create?parent=${ids.repeater}` },
  { label: 'edycja pola', path: `${base}/fields/${ids.text}/edit` },
  { label: 'design system', path: '/app/design-system' },
];
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
  page.on('pageerror', (error) => jsErrors.push(`pageerror: ${error.message}`));
  page.on('console', (message) => { if (message.type() === 'error') jsErrors.push(`console: ${message.text()}`); });

  return { context, page, jsErrors };
};

let failed = 0;
let total = 0;
const report = (name, problems) => {
  total++;
  failed += problems.length ? 1 : 0;
  console.log(`  ${problems.length ? 'FAIL' : 'PASS'}  ${name}${problems.length ? ` — ${problems.join('; ')}` : ''}`);
};

for (const route of routes) {
  for (const width of widths) {
    const { context, page, jsErrors } = await open(width);
    const response = await page.goto(SITE + route.path, { waitUntil: 'networkidle' });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);

    await page.addScriptTag({ content: axeSource });
    const violations = await page.evaluate(axeRun);

    const problems = [];
    if (response.status() !== 200) problems.push(`HTTP ${response.status()}`);
    if (violations.length) problems.push(`axe: ${violations.join(', ')}`);
    if (jsErrors.length) problems.push(...jsErrors);
    if (overflow) problems.push('przepełnienie poziome');

    report(`${route.label} | ${width}px | axe ${violations.length} | JS ${jsErrors.length}`, problems);

    if (process.env.TMPDIR && route.label === 'struktura podstrony' && width === 375) {
      await page.screenshot({ path: `${process.env.TMPDIR}/fmi-structure-375.png`, fullPage: true });
    }

    await context.close();
  }
}

const structure = SITE + `${base}/structure`;
const sectionOrder = (page) => page.locator('[data-sortable-item]').evaluateAll((items) => items
  .filter((item) => item.id.startsWith('sekcja-')).map((item) => item.dataset.sortableItem));
const fieldOrder = (page) => page.locator(`#sekcja-${ids.first} [data-sortable-list]`).first().evaluate((list) => [...list.children].map((item) => item.dataset.sortableItem));

// Okno potwierdzenia archiwizacji.
{
  const { context, page, jsErrors } = await open(1280);
  await page.goto(structure, { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Archiwizuj sekcję: Sekcja pierwsza' }).click();
  await page.waitForTimeout(500); // koniec animacji otwierania, inaczej axe mierzy kontrast przy częściowej przezroczystości
  await page.addScriptTag({ content: axeSource });
  const violations = await page.evaluate(axeRun);
  const problems = violations.length ? [`axe: ${violations.join(', ')}`] : [];
  if (!(await page.getByRole('heading', { name: 'Zarchiwizować sekcję?' }).isVisible())) problems.push('okno nie otwarte');
  report('okno potwierdzenia archiwizacji', [...problems, ...jsErrors]);
  await context.close();
}

// Klawiatura: Enter na „niżej” przesuwa pole, fokus zostaje, kolejność przeżywa przeładowanie.
{
  const { context, page, jsErrors } = await open(375);
  await page.goto(structure, { waitUntil: 'networkidle' });
  const before = await fieldOrder(page);
  const control = page.getByRole('button', { name: 'Przenieś niżej: Nagłówek' });
  await control.focus();
  const saved = page.waitForResponse((response) => response.url().endsWith('/fields/reorder'));
  await page.keyboard.press('Enter');
  const response = await saved;
  const focused = await control.evaluate((element) => element === document.activeElement);
  await page.reload({ waitUntil: 'networkidle' });
  const after = await fieldOrder(page);
  const problems = [...jsErrors];
  if (response.status() !== 200) problems.push(`HTTP ${response.status()}`);
  if (!focused) problems.push('fokus utracony');
  if (after[0] !== before[1] || after[1] !== before[0]) problems.push(`kolejność ${after.join()} (przed: ${before.join()})`);
  report('sortowanie klawiaturą (375 px)', problems);
  await context.close();
}

// Przeciąganie myszą: sekcja druga na pierwsze miejsce.
{
  const { context, page, jsErrors } = await open(1280);
  await page.goto(structure, { waitUntil: 'networkidle' });
  const before = await sectionOrder(page);
  const saved = page.waitForResponse((response) => response.url().endsWith('/sections/reorder'));
  await page.dragAndDrop(`#sekcja-${ids.second} [draggable="true"] >> nth=0`, `#sekcja-${ids.first} h2`, { targetPosition: { x: 5, y: 1 } });
  const response = await saved;
  await page.reload({ waitUntil: 'networkidle' });
  const after = await sectionOrder(page);
  const problems = [...jsErrors];
  if (response.status() !== 200) problems.push(`HTTP ${response.status()}`);
  if (after[0] !== String(ids.second)) problems.push(`kolejność ${after.join()} (przed: ${before.join()})`);
  report('przeciąganie sekcji (1280 px)', problems);
  await context.close();
}

// Formularz pola: ustawienia zależne od typu.
{
  const { context, page, jsErrors } = await open(1280);
  await page.goto(`${SITE}${base}/sections/${ids.first}/fields/create`, { waitUntil: 'networkidle' });
  const visible = (name) => page.locator(`[name="settings[${name}]"]`).isVisible();
  const problems = [...jsErrors];
  if (!(await visible('max_length')) || (await visible('max_items'))) problems.push('typ tekst: złe ustawienia');
  await page.selectOption('[name="type"]', 'repeater');
  // Alpine przełącza widoczność asynchronicznie.
  await page.locator('[name="settings[max_items]"]').waitFor({ state: 'visible', timeout: 3000 }).catch(() => problems.push('typ repeater: brak limitu elementów'));
  await page.locator('[name="settings[max_length]"]').waitFor({ state: 'hidden', timeout: 3000 }).catch(() => problems.push('typ repeater: widoczny limit znaków'));
  report('ustawienia zależne od typu pola', problems);
  await context.close();
}

await browser.close();

console.log(`\nAudyt UI etapu 06 (administrator): przypadków ${total}, PASS ${total - failed}, FAIL ${failed}`);
process.exit(failed ? 1 : 0);
