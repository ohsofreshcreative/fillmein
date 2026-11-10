// Etap 11 — audyt UI mapowania i eksportu (uruchamiany przez tests/bin/ui-audit.sh z katalogu /tmp).
// Dane: /tmp/fmi-stage03-fixtures.json i /tmp/fmi-stage11-ids.json. Tokenów i ciasteczek nie wypisujemy.
import { readFileSync } from 'node:fs';
import { chromium } from '/tmp/fmi-ui-audit/node_modules/playwright/index.mjs';

const SITE = 'http://fillmein.local';
const axeSource = readFileSync('/tmp/fmi-ui-audit/node_modules/axe-core/axe.min.js', 'utf8');
const manifest = JSON.parse(readFileSync('/tmp/fmi-stage03-fixtures.json', 'utf8'));
const ids = JSON.parse(readFileSync('/tmp/fmi-stage11-ids.json', 'utf8'));

if (manifest.site !== SITE) {
  console.log(`BLOCKED: audyt działa tylko na ${SITE}.`);
  process.exit(2);
}

const project = `${SITE}/app/projects/${manifest.projects.a}`;
const mapping = `${project}/mapping?strona=${manifest.pages.a}`;
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
  .then((result) => result.violations.map((violation) => `${violation.id}×${violation.nodes.length}: ${violation.nodes[0]?.target}`));

const open = async (width) => {
  const context = await browser.newContext({ viewport: { width, height: 900 }, acceptDownloads: true });
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

const audit = async (page, jsErrors, name, width) => {
  await page.addScriptTag({ content: axeSource });
  const violations = await page.evaluate(axeRun);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

  report(`${name} @${width}: axe`, violations);
  report(`${name} @${width}: bez przepełnienia`, overflow > 0 ? [`${overflow} px`] : []);
  report(`${name} @${width}: bez błędów JS`, jsErrors);
};

// Zapis mapowania i wygenerowanie eksportu klawiaturą, żeby historia i raport braków trafiły do audytu.
{
  const { context, page, jsErrors } = await open(375);

  await page.goto(mapping, { waitUntil: 'networkidle' });
  await page.locator('#page-template').fill('template-custom.blade.php');
  await page.locator(`#field-${ids.fields.title}-name`).fill('header');
  await page.getByRole('button', { name: 'Zapisz mapowanie' }).focus();
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.keyboard.press('Enter')]);

  const body = await page.content();

  report('Mapowanie: zapis klawiaturą', body.includes('Zapisano mapowanie') && body.includes('value="header"') ? [] : ['brak zapisu']);

  await page.goto(`${project}/exports`, { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Wygeneruj eksport' }).focus();
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.keyboard.press('Enter')]);

  const history = await page.content();

  report('Eksport: wygenerowany', history.includes('Eksport gotowy') ? [] : ['brak komunikatu']);

  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: /^JSON/ }).first().click()]);

  report('Eksport: pobranie pliku', /^fillmein-.*\.json$/.test(download.suggestedFilename()) ? [] : [download.suggestedFilename()]);
  report('Przepływ: bez błędów JS', jsErrors);
  await context.close();
}

for (const width of widths) {
  const { context, page, jsErrors } = await open(width);

  await page.goto(mapping, { waitUntil: 'networkidle' });
  await audit(page, jsErrors, 'Mapowanie', width);
  await page.goto(`${project}/exports`, { waitUntil: 'networkidle' });
  await audit(page, jsErrors, 'Eksporty', width);
  await page.goto(project, { waitUntil: 'networkidle' });
  await audit(page, jsErrors, 'Projekt z kartą eksportu', width);
  await context.close();
}

// Błąd walidacji jest widoczny i powiązany z polem.
{
  const { context, page, jsErrors } = await open(1280);

  await page.goto(mapping, { waitUntil: 'networkidle' });
  await page.locator('#page-post-type').fill('ZŁY TYP');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.getByRole('button', { name: 'Zapisz mapowanie' }).click()]);

  report('Mapowanie: błąd widoczny', (await page.content()).includes('Typ wpisu: małe litery') ? [] : ['brak komunikatu']);
  await audit(page, jsErrors, 'Mapowanie z błędem', 1280);
  await context.close();
}

await browser.close();
console.log(`\nAudyt UI etapu 11: ${total - failed}/${total} PASS, ${failed} FAIL`);
process.exit(failed ? 1 : 0);
