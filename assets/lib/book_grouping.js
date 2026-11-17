/** Canonical edition identifier; never identifies a physical copy on its own. */
export function normalizeIsbn(input) {
  let value = String(input).trim().toUpperCase().replace(/[ -]/g, '');
  if (/^\d{9}[\dX]$/.test(value)) {
    const sum = [...value].reduce((n, c, i) => n + (10 - i) * (c === 'X' ? 10 : Number(c)), 0);
    if (sum % 11) return null;
    value = `978${value.slice(0, 9)}`;
    const total = [...value].reduce((n, c, i) => n + Number(c) * (i % 2 ? 3 : 1), 0);
    return value + ((10 - total % 10) % 10);
  }
  if (!/^97[89]\d{10}$/.test(value)) return null;
  return [...value].reduce((n, c, i) => n + Number(c) * (i % 2 ? 3 : 1), 0) % 10 === 0 ? value : null;
}

export function decideBookGroup(current, input, newCopy = false) {
  const isbn = normalizeIsbn(input);
  if (!isbn) return { action: 'reject', reason: 'Not a valid ISBN' };
  if (!newCopy && current?.isbn === isbn) return { action: 'repeat', isbn, copyId: current.id };
  return { action: 'new', isbn, reason: newCopy ? 'explicit-new-copy' : 'different-isbn' };
}
