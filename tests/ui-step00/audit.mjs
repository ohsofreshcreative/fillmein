// Krok 0 — audyt UI widoku importu z Figmy (uruchamiany przez tests/bin/ui-audit.sh z /tmp/fmi-ui-audit-step00).
// Dane: /tmp/fmi-stage03-fixtures.json. Ciasteczka trzymamy tylko w pamięci i nigdy ich nie wypisujemy.
import { readFileSync } from 'node:fs';
import { chromium } from '/tmp/fmi-ui-audit/node_modules/playwright/index.mjs';

const SITE = 'http://fillmein.local';
const axeSource = readFileSync('/tmp/fmi-ui-audit/node_modules/axe-core/axe.min.js', 'utf8');
const manifest = JSON.parse(readFileSync('/tmp/fmi-stage03-fixtures.json', 'utf8'));

if (manifest.site !== SITE) {
  console.log(`BLOCKED: audyt działa tylko na ${SITE}.`);
  process.exit(2);
}

const base = `/app/projects/${manifest.projects.a}/pages/${manifest.pages.a}`;
const routes = [
  { label: 'edycja podstrony', path: `${base}/edit`, texts: [] },
  {
    label: 'import z Figmy (truncated)',
    path: `${base}/figma/import`,
    texts: ['Frame pobrano tylko częściowo', "Warstwa poza pobraną częścią frame'u — zostaje."],
  },
];
const widths = [360, 768, 1280];

let browser;

try {
  browser = await chromium.launch();
} catch (error) {
  console.log(`BLOCKED: nie można uruchomić Chromium Playwrighta (${error.message.split('\n')[0]}).`);
  process.exit(2);
}

const [cookieName, cookieValue] = manifest.cookies.admin.split(/=(.*)/s);
const rows = [];

for (const route of routes) {
  for (const width of widths) {
    const context = await browser.newContext({ viewport: { width, height: 900 } });
    await context.addCookies([{ name: cookieName, value: cookieValue, url: SITE }]);
    const page = await context.newPage();
    const jsErrors = [];
    page.on('pageerror', (error) => jsErrors.push(`pageerror: ${error.message}`));
    page.on('console', (message) => { if (message.type() === 'error') jsErrors.push(`console: ${message.text()}`); });

    const response = await page.goto(SITE + route.path, { waitUntil: 'networkidle' });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);

    await page.addScriptTag({ content: axeSource });
    const violations = await page.evaluate(async () => (await window.axe.run(document, {
      runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'],
    })).violations.map((violation) => `${violation.id}×${violation.nodes.length}`));

    const missing = [];
    for (const text of route.texts) {
      if (!(await page.getByText(text, { exact: false }).first().isVisible())) missing.push(text);
    }

    const problems = [];
    if (response.status() !== 200) problems.push(`HTTP ${response.status()}`);
    if (violations.length) problems.push(`axe: ${violations.join(', ')}`);
    if (jsErrors.length) problems.push(...jsErrors);
    if (overflow) problems.push('przepełnienie poziome');
    if (missing.length) problems.push(`brak widocznego tekstu: ${missing.join(' | ')}`);

    rows.push({ route: route.label, width, axe: violations.length, js: jsErrors.length, overflow: overflow ? 1 : 0, ok: problems.length === 0 });
    console.log(`  ${problems.length ? 'FAIL' : 'PASS'}  ${route.label} | ${width}px | HTTP ${response.status()} | axe ${violations.length} | JS ${jsErrors.length} | overflow ${overflow ? 1 : 0}${route.texts.length ? ` | teksty ${route.texts.length - missing.length}/${route.texts.length}` : ''}${problems.length ? ` — ${problems.join('; ')}` : ''}`);
    await context.close();
  }
}

await browser.close();

const failed = rows.filter((row) => !row.ok).length;
console.log(`\nAudyt UI kroku 0 (administrator): przypadków ${rows.length}, PASS ${rows.length - failed}, FAIL ${failed}`);
process.exit(failed ? 1 : 0);
