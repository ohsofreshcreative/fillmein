// Etap 14 — audyt wszystkich widoków HTML z planu tras przy 375, 768 i 1280 px (uruchamiany przez tests/bin/ui-audit.sh
// z /tmp/fmi-ui-audit-stage14). Dane: plan macierzy i manifesty testów. Tokenów i ciasteczek nie wypisujemy.
import { readFileSync, writeFileSync } from 'node:fs';
import { chromium } from '/tmp/fmi-ui-audit/node_modules/playwright/index.mjs';

const SITE = 'http://fillmein.local';
const axeSource = readFileSync('/tmp/fmi-ui-audit/node_modules/axe-core/axe.min.js', 'utf8');
const manifest = JSON.parse(readFileSync('/tmp/fmi-stage03-fixtures.json', 'utf8'));
const ids = JSON.parse(readFileSync('/tmp/fmi-stage14-ids.json', 'utf8'));
const token = ids.tokens.a;
const clean = (text) => String(text).replaceAll(token, '{token}');
const widths = [375, 768, 1280];

if (manifest.site !== SITE) {
  console.log(`BLOCKED: audyt działa tylko na ${SITE}.`);
  process.exit(2);
}

const skip = /(assets\/[^/]+\/original|\/exports\/[^/]+\/json|figma\/preview|figma\/callback|screens\/[^/]+\/image)/;
const plan = readFileSync('/tmp/fmi-stage14-plan.tsv', 'utf8').trim().split('\n').map((line) => line.split('\t'));
const views = plan
  // Strony gościa recenzji (strefa review) bez sesji to 403/404 — audyt z sesją gościa: tests/review-links (axe).
  .filter(([, method, zone, path]) => method === 'GET' && zone !== 'client' && zone !== 'review' && !skip.test(path))
  .map(([name, , zone, path]) => ({ name, path, staff: zone !== 'guest' }));

views.push(
  { name: 'fmi.client.editor', path: `/p/${token}`, staff: false },
  { name: 'fmi.client.editor (podstrona)', path: `/p/${token}?strona=${manifest.pages.a}`, staff: false },
  { name: 'historia pola klienta', path: `/p/${token}?historia=${ids.a.field}`, staff: false },
  { name: 'nieważny link klienta', path: '/p/nieprawidlowy', staff: false, expect: 404 },
  { name: 'strona 404 panelu', path: '/app/projects/999999999', staff: true, expect: 404 },
);

let browser;

try {
  browser = await chromium.launch();
} catch (error) {
  console.log(`BLOCKED: nie można uruchomić Chromium Playwrighta (${error.message.split('\n')[0]}).`);
  process.exit(2);
}

const [cookieName, cookieValue] = manifest.cookies.admin.split(/=(.*)/s);
const axeRun = () => window.axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] })
  .then((result) => result.violations.map((violation) => `${violation.id}×${violation.nodes.length}: ${violation.nodes[0].target.join(' ')}`));

let failed = 0;
let total = 0;
const rows = [];

for (const view of views) {
  const cells = [];

  for (const width of widths) {
    const context = await browser.newContext({ viewport: { width, height: 900 } });
    if (view.staff) await context.addCookies([{ name: cookieName, value: cookieValue, url: SITE }]);
    const page = await context.newPage();
    const problems = [];
    const expected = view.expect ?? 200;

    page.on('pageerror', (error) => problems.push(`pageerror: ${clean(error.message)}`));
    page.on('console', (message) => {
      if (message.type() === 'error' && !(view.expect && message.text().includes('404'))) problems.push(`console: ${clean(message.text())}`);
    });

    const response = await page.goto(SITE + view.path, { waitUntil: 'networkidle' });

    if (response.status() !== expected) problems.push(`HTTP ${response.status()}`);

    await page.addScriptTag({ content: axeSource });
    problems.push(...await page.evaluate(axeRun));

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

    if (overflow > 0) problems.push(`przepełnienie poziome ${overflow} px`);

    total++;
    failed += problems.length ? 1 : 0;
    cells.push(problems.length ? 'FAIL' : 'PASS');
    if (problems.length) console.log(`  FAIL  ${view.name} ${width}px — ${problems.join('; ')}`);
    await context.close();
  }

  rows.push(`| ${view.name} | ${cells.join(' | ')} |`);
}

await browser.close();
writeFileSync('/tmp/fmi-stage14-ui.md', `| Widok | 375 | 768 | 1280 |\n|---|---|---|---|\n${rows.join('\n')}\n`);
console.log(`ETAP 14 — audyt UI: ${views.length} widoków × ${widths.length} szerokości, ${total - failed}/${total} PASS, ${failed} FAIL`);
process.exit(failed ? 1 : 0);
