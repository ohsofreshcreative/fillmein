/*--- FILL ME IN: ZAZNACZANIE PROJEKTÓW NA LIŚCIE ---*/
// `items`: ID projektu => nazwa, tylko projekty, które ta osoba może usunąć.

export const projectSelection = (items) => ({
  selected: [],

  get allSelected() {
    return this.selected.length === Object.keys(items).length;
  },

  get names() {
    return this.selected.map((id) => items[id]);
  },

  toggleAll() {
    this.selected = this.allSelected ? [] : Object.keys(items);
  },
});
