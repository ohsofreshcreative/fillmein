// Etap 07 — audyt UI edytora klienta i panelu linków (uruchamiany przez tests/bin/ui-audit.sh z /tmp/fmi-ui-audit-stage07).
// Dane: /tmp/fmi-stage03-fixtures.json i /tmp/fmi-stage07-ids.json. Tokenów i ciasteczek nie wypisujemy.
import { readFileSync } from 'node:fs';
import { chromium } from '/tmp/fmi-ui-audit/node_modules/playwright/index.mjs';

const SITE = 'http://fillmein.local';
const axeSource = readFileSync('/tmp/fmi-ui-audit/node_modules/axe-core/axe.min.js', 'utf8');
const manifest = JSON.parse(readFileSync('/tmp/fmi-stage03-fixtures.json', 'utf8'));
const ids = JSON.parse(readFileSync('/tmp/fmi-stage07-ids.json', 'utf8'));

if (manifest.site !== SITE) {
  console.log(`BLOCKED: audyt działa tylko na ${SITE}.`);
  process.exit(2);
}

const token = ids.tokens.a;
const editor = `${SITE}/p/${token}`;
const clean = (text) => String(text).replaceAll(token, '[token]');
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

const open = async (width, staff = false) => {
  const context = await browser.newContext({ viewport: { width, height: 900 } });
  if (staff) await context.addCookies([{ name: cookieName, value: cookieValue, url: SITE }]);
  const page = await context.newPage();
  const jsErrors = [];
  page.on('pageerror', (error) => jsErrors.push(`pageerror: ${clean(error.message)}`));
  page.on('console', (message) => { if (message.type() === 'error') jsErrors.push(`console: ${clean(message.text())}`); });

  return { context, page, jsErrors };
};

let failed = 0;
let total = 0;
const report = (name, problems) => {
  total++;
  failed += problems.length ? 1 : 0;
  console.log(`  ${problems.length ? 'FAIL' : 'PASS'}  ${name}${problems.length ? ` — ${problems.map(clean).join('; ')}` : ''}`);
};

const pages = [
  { label: 'edytor klienta', url: editor, staff: false, status: 200 },
  { label: 'edytor: podstrona podrzędna', url: `${editor}?strona=${ids.pages.child}`, staff: false, status: 200 },
  { label: 'nieaktywny link', url: `${SITE}/p/${ids.tokens.revoked}`, staff: false, status: 404 },
  { label: 'panel projektu', url: `${SITE}/app/projects/${manifest.projects.a}`, staff: true, status: 200 },
];

for (const target of pages) {
  for (const width of widths) {
    const { context, page, jsErrors } = await open(width, target.staff);
    const response = await page.goto(target.url, { waitUntil: 'networkidle' });
    await page.waitForTimeout(400);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    await page.addScriptTag({ content: axeSource });
    const violations = await page.evaluate(axeRun);

    const problems = [];
    if (response.status() !== target.status) problems.push(`HTTP ${response.status()}`);
    if (violations.length) problems.push(`axe: ${violations.join(', ')}`);
    problems.push(...jsErrors.filter((message) => !(target.status === 404 && message.includes('404'))));
    if (overflow) problems.push('przepełnienie poziome');

    report(`${target.label} | ${width}px | axe ${violations.length}`, problems);

    await context.close();
  }
}

// Zapis pola z klawiatury, brak nagłówka Referer przy wyjściu na zewnątrz i po reloadzie ta sama wartość.
{
  const { context, page, jsErrors } = await open(375);
  const referers = [];
  page.on('request', (request) => { if (request.url().includes('/fields/')) referers.push(request.headers().referer ?? ''); });
  await page.goto(editor, { waitUntil: 'networkidle' });
  const input = page.locator(`#p-${ids.fields.text}`);
  await input.fill('Z przeglądarki');
  const saved = page.waitForResponse((response) => response.url().includes(`/fields/${ids.fields.text}`));
  await input.press('Enter');
  const response = await saved;
  const status = page.locator(`#p-${ids.fields.text}-status`);
  await status.getByText('Zapisano.').waitFor({ timeout: 3000 }).catch(() => {});
  const problems = [...jsErrors];
  if (response.status() !== 200) problems.push(`HTTP ${response.status()}`);
  if ((await status.textContent()) !== 'Zapisano.') problems.push('brak komunikatu');
  if (referers.some(Boolean)) problems.push('żądanie z nagłówkiem Referer');
  await page.reload({ waitUntil: 'networkidle' });
  if ((await page.locator(`#p-${ids.fields.text}`).inputValue()) !== 'Z przeglądarki') problems.push('wartość po przeładowaniu');
  report('zapis pola (375 px)', problems);
  await context.close();
}

// Błąd walidacji: komunikat w regionie aria-live i aria-invalid.
{
  const { context, page, jsErrors } = await open(1280);
  await page.goto(editor, { waitUntil: 'networkidle' });
  const link = page.locator(`#p-${ids.fields.link}-url`);
  await link.fill('zly adres');
  await link.press('Tab');
  const status = page.locator(`#p-${ids.fields.link}-status`);
  await status.getByText('Podaj pełny adres').waitFor({ timeout: 3000 }).catch(() => {});
  const problems = jsErrors.filter((message) => !message.includes('422'));
  if (!(await status.textContent()).includes('Podaj pełny adres')) problems.push('brak komunikatu błędu');
  if ((await link.getAttribute('aria-invalid')) !== 'true') problems.push('brak aria-invalid');
  await page.addScriptTag({ content: axeSource });
  const violations = await page.evaluate(axeRun);
  if (violations.length) problems.push(`axe: ${violations.join(', ')}`);
  report('błąd walidacji (aria-live, aria-invalid, axe)', problems);
  await context.close();
}

// WYSIWYG: pogrubienie i zapis HTML.
{
  const { context, page, jsErrors } = await open(1280);
  await page.goto(editor, { waitUntil: 'networkidle' });
  const box = page.getByRole('textbox', { name: 'Treść sformatowana' });
  await box.click();
  await page.keyboard.type('Ważny tekst');
  await page.keyboard.press('ControlOrMeta+A');
  await page.getByRole('button', { name: 'Pogrubienie' }).click();
  const saved = page.waitForResponse((response) => response.url().includes(`/fields/${ids.fields.wysiwyg}`));
  await page.getByRole('heading', { level: 1 }).click();
  const response = await saved;
  await page.reload({ waitUntil: 'networkidle' });
  const html = await page.getByRole('textbox', { name: 'Treść sformatowana' }).innerHTML();
  const problems = [...jsErrors];
  if (response.status() !== 200) problems.push(`HTTP ${response.status()}`);
  if (!/<(b|strong)>Ważny tekst/.test(html)) problems.push(`HTML: ${html}`);
  report('WYSIWYG: formatowanie i zapis', problems);
  await context.close();
}

// Repeater: dodanie, zapis w nowym wierszu, sortowanie klawiaturą, usunięcie.
{
  const { context, page, jsErrors } = await open(375);
  page.on('dialog', (dialog) => dialog.accept());
  await page.goto(editor, { waitUntil: 'networkidle' });
  const problems = [...jsErrors];
  const list = page.locator(`[data-sortable-list]:has(> li [id^="p-${ids.fields.row_field}-"])`).first();
  const rowIds = () => list.evaluate((element) => [...element.children].map((item) => item.dataset.sortableItem));
  const before = await rowIds();

  const added = page.waitForResponse((response) => response.url().endsWith(`/fields/${ids.fields.repeater}/rows`));
  await page.getByRole('button', { name: 'Dodaj element: Karty' }).click();
  if ((await added).status() !== 200) problems.push('dodanie');
  await list.locator(':scope > li').nth(before.length).waitFor();
  const afterAdd = await rowIds();
  if (afterAdd.length !== before.length + 1) problems.push(`po dodaniu ${afterAdd.length}`);

  const newRow = list.locator(':scope > li').last();
  await page.waitForTimeout(200);
  if (!(await newRow.locator('[data-row-number]').textContent()).includes(String(afterAdd.length))) problems.push('numer nowego wiersza');
  await newRow.locator(`input[id^="p-${ids.fields.row_field}-"]`).fill('Nowa karta');
  const saved = page.waitForResponse((response) => response.url().includes(`/fields/${ids.fields.row_field}`));
  await page.getByRole('heading', { level: 1 }).click();
  if ((await saved).status() !== 200) problems.push('zapis w wierszu');

  const moved = page.waitForResponse((response) => response.url().endsWith('/rows/reorder'));
  const control = newRow.locator('[data-sortable-control="up"]').first();
  await control.focus();
  await page.keyboard.press('Enter');
  if ((await moved).status() !== 200) problems.push('sortowanie');
  await page.waitForTimeout(200);
  await page.reload({ waitUntil: 'networkidle' });
  const afterMove = await rowIds();
  if (afterMove[afterMove.length - 2] !== afterAdd.at(-1)) problems.push('kolejność po przeładowaniu');

  const removed = page.waitForResponse((response) => response.url().endsWith('/delete'));
  // Kosz jest widoczny dopiero w rozwiniętym elemencie (akordeon).
  const firstRow = list.locator(':scope > li').first();
  if ((await firstRow.locator('[data-row-toggle]').first().getAttribute('aria-expanded')) !== 'true') await firstRow.locator('[data-row-toggle]').first().click();
  await firstRow.getByRole('button', { name: 'Usuń element listy Karty' }).first().click();
  if ((await removed).status() !== 200) problems.push('usunięcie');
  await page.waitForTimeout(200);
  if ((await rowIds()).length !== afterAdd.length - 1) problems.push('po usunięciu');
  report('repeater: dodanie, zapis, sortowanie, usunięcie (375 px)', problems.concat(jsErrors));
  await context.close();
}

// Panel: jednorazowy adres po wygenerowaniu.
{
  const { context, page, jsErrors } = await open(375, true);
  await page.goto(`${SITE}/app/projects/${manifest.projects.a}`, { waitUntil: 'networkidle' });
  // Przy aktywnym linku jest tylko „Wygeneruj od nowa” (link generujemy raz, bez terminu).
  const generate = page.getByRole('button', { name: 'Wygeneruj link', exact: true });
  if (await generate.count()) {
    await generate.click();
  } else {
    await page.getByRole('button', { name: 'Wygeneruj od nowa', exact: true }).click();
    await page.getByRole('button', { name: 'Unieważnij i wygeneruj', exact: true }).click();
  }
  await page.locator('[data-client-access-url]').first().waitFor();
  await page.waitForTimeout(400);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
  await page.addScriptTag({ content: axeSource });
  const violations = await page.evaluate(axeRun);
  const problems = [...jsErrors];
  if (violations.length) problems.push(`axe: ${violations.join(', ')}`);
  if (overflow) problems.push('przepełnienie poziome');
  report('strona jednorazowego linku (375 px)', problems);
  await context.close();
}

await browser.close();

console.log(`\nAudyt UI etapu 07: przypadków ${total}, PASS ${total - failed}, FAIL ${failed}`);
process.exit(failed ? 1 : 0);
