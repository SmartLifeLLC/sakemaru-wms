import assert from 'node:assert/strict';
import '../../public/js/hq-order-inputs.js';

const { parseDate, totals, dateInput } = globalThis.HqOrderInputs;
const basis = new Date(2026, 9, 24);
for (const [input, output] of [['25', '2026-10-25'], ['１', '2026-10-01'], ['1105', '2026-11-05'], ['20261105', '2026-11-05'], ['2028/02/29', '2028-02-29']]) assert.equal(parseDate(input, basis), output);
for (const input of ['20260229', '2026/04/31', '202610/20/日', '2026', '12345', '00']) assert.throws(() => parseDate(input, basis));
const lines = [
    { contractor_id: 1, contractor_code: 'A', contractor_name: '問屋A', case_qty: 3, piece_qty: 0 },
    { contractor_id: 2, contractor_code: 'B', contractor_name: '問屋B', case_qty: 0, piece_qty: 5 },
    { contractor_id: 1, contractor_code: 'A', contractor_name: '問屋A', case_qty: 2, piece_qty: 0 },
];
assert.deepEqual(totals(lines), { cases: 5, pieces: 5, groups: [
    { id: '1', label: '[A]問屋A', cases: 5, pieces: 0 },
    { id: '2', label: '[B]問屋B', cases: 0, pieces: 5 },
] });
lines[0].case_qty = '';
assert.equal(totals(lines).cases, 2);
assert.deepEqual(totals([]), { cases: 0, pieces: 0, groups: [] });
const input = dateInput('2026-11-05');
let valueWatcher;
input.$watch = (name, callback) => { valueWatcher = callback; };
input.$dispatch = () => {};
input.init();
input.display = '20260229';
assert.equal(input.commit(), false);
valueWatcher('');
assert.match(input.error, /存在しない日付/);
valueWatcher('2026-11-07');
assert.equal(input.error, '');
assert.equal(input.display, '2026/11/07');
input.error = 'エラー';
input.syncExternal('2026-11-07', 1);
assert.equal(input.error, '');
const limited = dateInput('2026-11-05', '2026-10-24', '2027-04-23');
let limitedWatcher;
const events = [];
limited.$watch = (name, callback) => { limitedWatcher = callback; };
limited.$dispatch = (name, detail) => events.push({name, detail});
limited.init();
limited.display = '20261012';
assert.equal(limited.commit(), false);
assert.match(limited.error, /過去日/);
limited.display = '20270424';
limited.edit();
limitedWatcher('');
assert.equal(limited.error, '');
assert.equal(limited.value, '');
assert.equal(limited.display, '20270424');
assert.equal(events.at(-1).name, 'hq-date-invalid');
assert.equal(limited.commit(), false);
assert.match(limited.error, /6か月以上/);
limited.display = '20270423';
limited.edit();
assert.equal(limited.error, '');
assert.equal(limited.commit(), true);
assert.equal(limited.value, '2027-04-23');
assert.equal(limited.display, '2027/04/23');
console.log('日付の短縮入力・実在日検証・単位別合計・発注先別小計を確認しました。');
