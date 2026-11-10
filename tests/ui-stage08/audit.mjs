// Etap 08 — audyt UI autozapisu, konfliktów, historii i postępu (uruchamiany przez tests/bin/ui-audit.sh z /tmp/fmi-ui-audit-stage08).
// Dane: /tmp/fmi-stage03-fixtures.json i /tmp/fmi-stage08-ids.json. Tokenów i ciasteczek nie wypisujemy.
import { readFileSync } from 'node:fs';
import { chromium } from '/tmp/fmi-ui-audit/node_modules/playwright/index.mjs';

const SITE = 'http://fillmein.local';
const axeSource = readFileSync('/tmp/fmi-ui-audit/node_modules/axe-core/axe.min.js', 'utf8');
const manifest = JSON.parse(readFileSync('/tmp/fmi-stage03-fixtures.json', 'utf8'));
const ids = JSON.parse(readFileSync('/tmp/fmi-stage08-ids.json', 'utf8'));

if (manifest.site !== SITE) {
  console.log(`BLOCKED: audyt działa tylko na ${SITE}.`);
  process.exit(2);
}

const token = ids.tokens.a;
const editor = `${SITE}/p/${token}`;
const content = `${SITE}/app/projects/${manifest.projects.a}/pages/${manifest.pages.a}/content`;
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

const pages = [
  { label: 'edytor klienta', url: editor, staff: false },
  { label: 'historia (klient)', url: `${editor}?historia=${ids.fields.text}`, staff: false },
  { label: 'edytor panelu', url: content, staff: true },
  { label: 'historia (panel)', url: `${content}/fields/${ids.fields.text}/history`, staff: true },
];

for (const target of pages) {
  for (const width of widths) {
    const { context, page, jsErrors } = await open(width, target.staff);
    const response = await page.goto(target.url, { waitUntil: 'networkidle' });
    await page.waitForTimeout(300);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    const violations = await scan(page);
    const problems = [...jsErrors];
    if (response.status() !== 200) problems.push(`HTTP ${response.status()}`);
    if (violations.length) problems.push(`axe: ${violations.join(', ')}`);
    if (overflow) problems.push('przepełnienie poziome');
    report(`${target.label} | ${width}px | axe ${violations.length}`, problems);
    await context.close();
  }
}

const field = (page, name) => page.locator(`#p-${ids.fields[name]}`);
const status = (page, name) => page.locator(`#p-${ids.fields[name]}-status`);
const globalStatus = (page) => page.locator('[role="status"]').first();
const waitText = (locator, text) => locator.getByText(text).or(locator.filter({ hasText: text })).first().waitFor({ timeout: 6000 }).catch(() => {});

// Autozapis: bez przycisku, po opóźnieniu; status pola, status globalny, postęp.
{
  const { context, page, jsErrors } = await open(375);
  await page.goto(editor, { waitUntil: 'networkidle' });
  const problems = [...jsErrors];
  const before = await page.locator('[data-progress-key="page"] [role="progressbar"]').getAttribute('aria-valuenow');
  const requests = [];
  page.on('request', (request) => { if (request.method() === 'POST') requests.push(request.url()); });
  await field(page, 'other').pressSequentially('Autozapis z audytu', { delay: 15 });
  await waitText(status(page, 'other'), 'Zapisano.');
  if ((await status(page, 'other').textContent()) !== 'Zapisano.') problems.push('brak statusu pola');
  if (!/^Zapisano o \d\d:\d\d$/.test((await globalStatus(page).textContent()).trim())) problems.push(`status globalny: ${await globalStatus(page).textContent()}`);
  if (requests.length !== 1) problems.push(`żądań: ${requests.length}`);
  if (await page.locator(`form:has(#p-${ids.fields.other}) [data-field-filled]`).textContent() !== 'Uzupełnione') problems.push('brak stanu pola');
  await page.waitForTimeout(100);
  const after = await page.locator('[data-progress-key="page"] [role="progressbar"]').getAttribute('aria-valuenow');
  if (Number(after) <= Number(before)) problems.push(`postęp ${before} → ${after}`);
  await page.reload({ waitUntil: 'networkidle' });
  if ((await field(page, 'other').inputValue()) !== 'Autozapis z audytu') problems.push('wartość po przeładowaniu');
  report('autozapis z opóźnieniem, status, postęp (375 px)', problems);
  await context.close();
}

// Zapis przy opuszczeniu pola, przed upływem opóźnienia.
{
  const { context, page, jsErrors } = await open(1280);
  await page.goto(editor, { waitUntil: 'networkidle' });
  const problems = [...jsErrors];
  await field(page, 'other').fill('Zapis przy wyjściu');
  const saved = page.waitForResponse((response) => response.url().endsWith(`/fields/${ids.fields.other}`));
  await field(page, 'other').blur();
  const started = Date.now();
  if ((await saved).status() !== 200) problems.push('zapis');
  if (Date.now() - started > 700) problems.push('zapis dopiero po opóźnieniu');
  report('zapis przy opuszczeniu pola', problems);
  await context.close();
}

// Offline: tekst czeka w szkicu, ostrzeżenie przed wyjściem, zapis po powrocie sieci, bez tokenu w localStorage.
{
  const { context, page, jsErrors } = await open(375);
  await page.goto(editor, { waitUntil: 'networkidle' });
  const problems = [...jsErrors];
  await context.setOffline(true);
  await field(page, 'other').fill('Tekst wpisany bez sieci');
  await waitText(status(page, 'other'), 'Brak połączenia');
  if (!(await status(page, 'other').textContent()).includes('Brak połączenia')) problems.push('brak statusu offline');
  if (!(await globalStatus(page).textContent()).includes('Brak połączenia')) problems.push('brak statusu globalnego');
  const stored = await page.evaluate(() => JSON.stringify(Object.entries(window.localStorage)));
  if (!stored.includes('Tekst+wpisany+bez+sieci')) problems.push('brak szkicu w localStorage');
  if (stored.includes(token)) problems.push('token w localStorage');
  const guarded = await page.evaluate(() => { const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented; });
  if (!guarded) problems.push('brak ostrzeżenia beforeunload');
  const saved = page.waitForResponse((response) => response.url().endsWith(`/fields/${ids.fields.other}`));
  await context.setOffline(false);
  if ((await saved).status() !== 200) problems.push('zapis po powrocie sieci');
  await waitText(status(page, 'other'), 'Zapisano.');
  const left = await page.evaluate(() => Object.keys(window.localStorage).filter((key) => key.startsWith('fmi-draft')).length);
  if (left !== 0) problems.push(`szkice po zapisie: ${left}`);
  const clear = await page.evaluate(() => { const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented; });
  if (clear) problems.push('ostrzeżenie mimo zapisu');
  report('offline: kolejka, szkic, beforeunload, ponowienie (375 px)', problems);
  await context.close();
}

// Offline i zamknięcie strony: szkic wraca po ponownym otwarciu i zapisuje się sam.
{
  const { context, page, jsErrors } = await open(1280);
  await page.goto(editor, { waitUntil: 'networkidle' });
  const problems = [...jsErrors];
  await context.setOffline(true);
  await field(page, 'other').fill('Tekst sprzed zamknięcia');
  await waitText(status(page, 'other'), 'Brak połączenia');
  await page.close({ runBeforeUnload: false });
  await context.setOffline(false);
  const second = await context.newPage();
  const saved = second.waitForResponse((response) => response.url().endsWith(`/fields/${ids.fields.other}`));
  await second.goto(editor);
  if ((await saved).status() !== 200) problems.push('zapis szkicu');
  if ((await field(second, 'other').inputValue()) !== 'Tekst sprzed zamknięcia') problems.push('tekst nie wrócił');
  await second.reload({ waitUntil: 'networkidle' });
  if ((await field(second, 'other').inputValue()) !== 'Tekst sprzed zamknięcia') problems.push('tekst po przeładowaniu');
  report('zamknięcie strony offline: szkic wraca i się zapisuje', problems);
  await context.close();
}

// Konflikt: dwie karty na tej samej wersji i trzy sposoby rozstrzygnięcia.
const conflict = async (name, resolve, expected) => {
  const first = await open(375);
  const second = await open(375, true);
  await first.page.goto(editor, { waitUntil: 'networkidle' });
  await second.page.goto(content, { waitUntil: 'networkidle' });
  const problems = [...first.jsErrors];

  await second.page.locator(`#p-${ids.fields.other}`).fill(`Panel ${name}`);
  await second.page.locator(`#p-${ids.fields.other}`).blur();
  await waitText(second.page.locator(`#p-${ids.fields.other}-status`), 'Zapisano.');

  await field(first.page, 'other').fill(`Klient ${name}`);
  await field(first.page, 'other').blur();
  const panel = first.page.getByRole('group', { name: 'Konflikt zapisu: Drugie pole' });
  await panel.waitFor({ timeout: 6000 });
  const text = await panel.textContent();
  if (!text.includes(`Klient ${name}`) || !text.includes(`Panel ${name}`)) problems.push('panel bez obu wersji');
  if (!(await globalStatus(first.page).textContent()).includes('Konflikt')) problems.push('brak statusu globalnego');

  if (name === 'moja') {
    const violations = await scan(first.page);
    const overflow = await first.page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    if (violations.length) problems.push(`axe: ${violations.join(', ')}`);
    if (overflow) problems.push('przepełnienie poziome');
  }

  await resolve(first.page);
  await waitText(status(first.page, 'other'), expected.status);
  if (await panel.isVisible()) problems.push('panel nadal widoczny');
  await first.page.waitForTimeout(100);
  await first.page.reload({ waitUntil: 'networkidle' });
  if ((await field(first.page, 'other').inputValue()) !== expected.value) problems.push(`wartość: ${await field(first.page, 'other').inputValue()}`);
  report(`konflikt: ${name}`, problems);
  await first.context.close();
  await second.context.close();
};

await conflict('moja', (page) => page.getByRole('button', { name: 'Zachowaj moją' }).click(), { status: 'Zapisano.', value: 'Klient moja' });
await conflict('zapisana', (page) => page.getByRole('button', { name: 'Przyjmij zapisaną' }).click(), { status: 'Przyjęto', value: 'Panel zapisana' });
await conflict('scalona', async (page) => {
  await page.getByRole('button', { name: 'Scal ręcznie' }).click();
  await field(page, 'other').fill('Scalona ręcznie');
  await field(page, 'other').blur();
}, { status: 'Zapisano.', value: 'Scalona ręcznie' });

// Link w konflikcie: przyjęcie wersji zapisanej wypełnia oba pola.
{
  const first = await open(1280);
  const second = await open(1280, true);
  await first.page.goto(editor, { waitUntil: 'networkidle' });
  await second.page.goto(content, { waitUntil: 'networkidle' });
  const problems = [...first.jsErrors];
  await second.page.locator(`#p-${ids.fields.link}-label`).fill('Z panelu');
  await second.page.locator(`#p-${ids.fields.link}-url`).fill('https://example.com/panel');
  await second.page.locator(`#p-${ids.fields.link}-url`).blur();
  await waitText(second.page.locator(`#p-${ids.fields.link}-status`), 'Zapisano.');
  await first.page.locator(`#p-${ids.fields.link}-label`).fill('Od klienta');
  await first.page.locator(`#p-${ids.fields.link}-label`).blur();
  await first.page.getByRole('group', { name: 'Konflikt zapisu: Przycisk' }).getByRole('button', { name: 'Przyjmij zapisaną' }).click();
  if ((await first.page.locator(`#p-${ids.fields.link}-url`).inputValue()) !== 'https://example.com/panel') problems.push('adres linku');
  if ((await first.page.locator(`#p-${ids.fields.link}-label`).inputValue()) !== 'Z panelu') problems.push('tekst linku');
  report('konflikt linku: przyjęcie wersji zapisanej', problems);
  await first.context.close();
  await second.context.close();
}

// Historia: przywrócenie z klawiatury dodaje wersję.
{
  const { context, page, jsErrors } = await open(375);
  await page.goto(`${editor}?historia=${ids.fields.text}`, { waitUntil: 'networkidle' });
  const problems = [...jsErrors];
  const before = await page.locator('ol > li').count();
  const button = page.getByRole('button', { name: 'Przywróć wersję 1' });
  await button.focus();
  await Promise.all([page.waitForEvent('load'), page.keyboard.press('Enter')]);
  await page.waitForLoadState('networkidle');
  const after = await page.locator('ol > li').count();
  if (after !== before + 1) problems.push(`wersji ${before} → ${after}`);
  if (!(await page.locator('ol > li').first().textContent()).includes('Aktualna')) problems.push('brak oznaczenia aktualnej');
  const violations = await scan(page);
  if (violations.length) problems.push(`axe: ${violations.join(', ')}`);
  await page.goto(editor, { waitUntil: 'networkidle' });
  if ((await field(page, 'text').inputValue()) !== 'Pierwsza wersja') problems.push('wartość w edytorze');
  report('historia: przywrócenie (375 px)', problems);
  await context.close();
}

await browser.close();

console.log(`\nAudyt UI etapu 08: przypadków ${total}, PASS ${total - failed}, FAIL ${failed}`);
process.exit(failed ? 1 : 0);
