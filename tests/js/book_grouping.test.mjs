import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normalizeIsbn, decideBookGroup } from '../../assets/lib/book_grouping.js';

test('ISBN-10 and ISBN-13 identify the same edition', () => {
  assert.equal(normalizeIsbn('0-14-032872-6'), '9780140328721');
  assert.equal(normalizeIsbn('0-8044-2957-X'), '9780804429573');
  assert.equal(normalizeIsbn('9780140328721'), '9780140328721');
});
test('reject bad checksums and non-book EANs', () => {
  for (const value of ['9780140328722', '0140328727', '4006381333931', 'garbage']) assert.equal(normalizeIsbn(value), null);
});
test('duplicate video frames stay on the copy, explicit copies get separate records', () => {
  const current = { id: 'copy-1', isbn: '9780140328721' };
  assert.equal(decideBookGroup(current, '0140328726').action, 'repeat');
  assert.equal(decideBookGroup(current, '0140328726', true).action, 'new');
  assert.equal(decideBookGroup(current, '9780804429573').action, 'new');
  assert.equal(decideBookGroup(null, '9780140328721').action, 'new');
});
