// Etap 09 — audyt UI pól zdjęcia i galerii (uruchamiany przez tests/bin/ui-audit.sh z /tmp/fmi-ui-audit-stage09).
// Dane: /tmp/fmi-stage03-fixtures.json i /tmp/fmi-stage09-ids.json. Tokenów i ciasteczek nie wypisujemy.
import { readFileSync } from 'node:fs';
import { chromium } from '/tmp/fmi-ui-audit/node_modules/playwright/index.mjs';

const SITE = 'http://fillmein.local';
const axeSource = readFileSync('/tmp/fmi-ui-audit/node_modules/axe-core/axe.min.js', 'utf8');
const manifest = JSON.parse(readFileSync('/tmp/fmi-stage03-fixtures.json', 'utf8'));
const ids = JSON.parse(readFileSync('/tmp/fmi-stage09-ids.json', 'utf8'));

if (manifest.site !== SITE) {
  console.log(`BLOCKED: audyt działa tylko na ${SITE}.`);
  process.exit(2);
}

const token = ids.tokens.a;
const editor = `${SITE}/p/${token}`;
const content = `${SITE}/app/projects/${manifest.projects.a}/pages/${manifest.pages.a}/content`;
const clean = (text) => String(text).replaceAll(token, '[token]');
const widths = [360, 375, 768, 1280];
const fixture = (name) => `${ids.fixtures}/${name}`;

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

  return { context, page, jsErrors };
};

let failed = 0;
let total = 0;
const report = (name, problems) => {
  total++;
  failed += problems.length ? 1 : 0;
  console.log(`  ${problems.length ? 'FAIL' : 'PASS'}  ${name}${problems.length ? ` — ${problems.map(clean).join('; ')}` : ''}`);
};

const scan = async (page) => {
  await page.addScriptTag({ content: axeSource });
  return page.evaluate(axeRun);
};

const galleryBlock = (page) => page.locator('[x-data^="clientMedia"]').filter({ hasText: 'Galeria' }).first();
const order = (block) => block.locator('[data-sortable-item]').evaluateAll((items) => items.map((item) => item.dataset.sortableItem));

// Przebieg główny: przesłanie, postęp, alt, sortowanie z klawiatury, odpinanie.
{
  const { context, page, jsErrors } = await open(375);
  await page.goto(editor, { waitUntil: 'networkidle' });
  const gallery = galleryBlock(page);
  const input = gallery.locator('input[type="file"]');
  const problems = [...jsErrors];

  await input.setInputFiles([fixture('ok.png'), fixture('ok.jpg'), fixture('ok.webp')]);
  await gallery.locator('[data-sortable-item]').nth(2).waitFor({ timeout: 20000 });
  if (await gallery.locator('[data-sortable-item] img').count() !== 3) problems.push('brak trzech miniatur');
  report('Przesłanie wielu plików przy 375 px', problems);

  const loaded = await gallery.locator('img').evaluateAll((images) => Promise.all(images.map((image) => image.decode().then(() => image.naturalWidth > 0).catch(() => false))));
  report('Miniatury ładują się przez kontroler', loaded.every(Boolean) ? [] : ['miniatura nie załadowana']);
  report('Pasek postępu ma nazwę', await gallery.locator('[data-upload-progress] [role="progressbar"]').getAttribute('aria-label') || await gallery.locator('[data-upload-progress] [role="progressbar"]').getAttribute('aria-labelledby') ? [] : ['brak nazwy']);
  report('Limit galerii blokuje wybór pliku', await input.isDisabled() ? [] : ['pole aktywne po osiągnięciu limitu']);

  const alt = gallery.locator('input[name="alt"]').first();
  await alt.fill('Opis z audytu');
  await alt.blur();
  await gallery.getByText('Zapisano opis.').waitFor({ timeout: 10000 });
  report('Alt ustawiony na obrazie', await gallery.locator('img').first().getAttribute('alt') === 'Opis z audytu' ? [] : ['img.alt bez zmiany']);

  const before = await order(gallery);
  const down = gallery.locator('[data-sortable-item]').first().locator('[data-sortable-control="down"]');
  await down.focus();
  await page.keyboard.press('Enter');
  await gallery.getByText('Przeniesiono: pozycja 2 z 3.').waitFor({ timeout: 10000 });
  const after = await order(gallery);
  const focused = await page.evaluate(() => document.activeElement?.tagName);
  report('Sortowanie klawiaturą', after[0] === before[1] && after[1] === before[0] && focused === 'BUTTON' ? [] : [`kolejność lub fokus (${focused})`]);

  await page.reload({ waitUntil: 'networkidle' });
  report('Kolejność i alt po przeładowaniu', JSON.stringify(await order(gallery)) === JSON.stringify(after) && await page.getByLabel('Tekst alternatywny').nth(0).count() ? [] : ['kolejność nie przetrwała']);

  await gallery.locator('[data-sortable-item]').nth(2).getByRole('button', { name: /Odepnij/ }).click();
  await gallery.getByText('Odpięto zdjęcie.').waitFor({ timeout: 10000 });
  const unpinned = await gallery.locator('[data-sortable-item]').count();
  const inputFocused = await page.evaluate(() => document.activeElement?.getAttribute('type'));
  report('Odpinanie zostawia fokus na polu pliku', unpinned === 2 && inputFocused === 'file' ? [] : [`pozycje ${unpinned}, fokus ${inputFocused}`]);

  await input.setInputFiles(fixture('script.svg'));
  await gallery.getByText(/Dozwolone są pliki/).waitFor({ timeout: 10000 });
  report('Odrzucenie pliku ma komunikat', await input.isDisabled() ? ['pole zablokowane po błędzie'] : []);

  report('Bez błędów JS w przebiegu', jsErrors);
  await context.close();
}

// Pola ze zdjęciami we wszystkich szerokościach i oba konteksty (dane z przebiegu głównego).
for (const target of [{ label: 'edytor klienta', url: editor, staff: false }, { label: 'edytor panelu', url: content, staff: true }]) {
  for (const width of widths) {
    const { context, page, jsErrors } = await open(width, target.staff);
    const response = await page.goto(target.url, { waitUntil: 'networkidle' });
    await page.waitForTimeout(300);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    const violations = await scan(page);
    const problems = [...jsErrors];
    const images = await page.locator('[data-sortable-item] img').count();

    if (response.status() !== 200) problems.push(`HTTP ${response.status()}`);
    if (!images) problems.push('brak miniatur');
    if (violations.length) problems.push(`axe: ${violations.join(', ')}`);
    if (overflow) problems.push('przepełnienie poziome');
    report(`${target.label} @${width}px`, problems);
    await context.close();
  }
}

// Panel: przesłanie zdjęcia działa także poza edytorem klienta.
{
  const { context, page, jsErrors } = await open(1280, true);
  await page.goto(content, { waitUntil: 'networkidle' });
  const block = page.locator('[x-data^="clientMedia"]').filter({ hasText: 'Zdjęcie główne' }).first();

  await block.locator('input[type="file"]').setInputFiles(fixture('second.png'));
  await block.locator('[data-sortable-item]').first().waitFor({ timeout: 20000 });
  const src = await block.locator('img').first().getAttribute('src');
  report('Panel: przesłanie i adres miniatury', src?.includes('/assets/') && !jsErrors.length ? [] : ['zły adres lub błąd JS']);
  await context.close();
}

// Klient: w HTML zdjęcia nie ma tokenu.
{
  const { context, page } = await open(375);
  await page.goto(editor, { waitUntil: 'networkidle' });
  report('HTML bez tokenu', (await page.content()).includes(token) ? ['token w HTML'] : []);
  await context.close();
}

await browser.close();
console.log(`\nAudyt UI etapu 09: ${total - failed}/${total} PASS, ${failed} FAIL`);
process.exit(failed ? 1 : 0);
