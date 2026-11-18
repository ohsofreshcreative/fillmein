/**
 * Lista podstron wklejona jako tekst: „nazwa link” w jednej linii (spacje, tabulator, myślnik między nimi)
 * albo nazwa w jednej linii, a link w następnej. Linie bez linku i bez nazwy są pomijane.
 *
 * @returns {{ rows: {name: string, url: string}[], skipped: string[] }}
 */
export function parsePageList(text) {
  const rows = [];
  const skipped = [];
  let pendingName = '';

  String(text ?? '').split(/\r?\n/).forEach((raw) => {
    const line = raw.trim();
    if (line === '') return;

    const match = line.match(/https?:\/\/\S+/);

    if (!match) {
      // Sama nazwa — czeka na link w następnej linii. Dwie nazwy pod rząd: poprzednia nie miała linku.
      if (pendingName) skipped.push(pendingName);
      pendingName = line;
      return;
    }

    const name = line.slice(0, match.index).replace(/[\s:;|–—-]+$/, '').trim() || pendingName;
    rows.push({ name, url: match[0].replace(/[),.;]+$/, '') });
    pendingName = '';
  });

  if (pendingName) skipped.push(pendingName);

  return { rows, skipped };
}
