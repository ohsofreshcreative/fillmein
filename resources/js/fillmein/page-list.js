/**
 * Lista podstron wklejona jako tekst: „nazwa link” w jednej linii (spacje, tabulator, myślnik między nimi)
 * albo nazwa w jednej linii, a link w następnej. Linie bez linku i bez nazwy są pomijane.
 * Gwiazdka przy nazwie („* Produkt” albo „Produkt *”) oznacza podstronę, którą klient może duplikować.
 *
 * @returns {{ rows: {name: string, url: string, duplicate: boolean}[], skipped: string[] }}
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

    const label = line.slice(0, match.index).replace(/[\s:;|–—-]+$/, '').trim() || pendingName;
    const duplicate = /^\*|\*$/.test(label);
    const name = label.replace(/^\*+\s*|\s*\*+$/g, '').trim();
    rows.push({ name, url: match[0].replace(/[),.;]+$/, ''), duplicate });
    pendingName = '';
  });

  if (pendingName) skipped.push(pendingName);

  return { rows, skipped };
}
