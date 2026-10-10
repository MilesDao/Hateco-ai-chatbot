// Run: node tests/test-sheets-webhook.js
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

const ctx = {};
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(__dirname + '/../google-sheets/hateco-webhook.gs', 'utf8'), ctx);

// Minimal fake of the Apps Script Sheet API used by upsertLead.
function fakeSheet(rows) {
  return {
    rows,
    getDataRange() { return { getValues: () => rows.map(r => r.slice()) }; },
    getRange(row, col, numRows, numCols) {
      return { setValues(values) { for (let c = 0; c < numCols; c++) rows[row - 1][col - 1 + c] = values[0][c]; } };
    },
    // Array.from: rows built inside the vm realm have a different Array prototype, which deepStrictEqual rejects.
    appendRow(r) { rows.push(Array.from(r)); },
  };
}

const t1 = new Date('2026-10-10T08:00:00Z');
const t2 = new Date('2026-10-10T09:00:00Z');
const sheet = fakeSheet([ctx.LEAD_HEADERS.slice()]);

// New lead -> appended with staff defaults, phone kept as text
assert.strictEqual(ctx.upsertLead(sheet, 's1', { name: 'Lan', phone: '0912345678', grade: 'Lớp 12', major: 'Kế toán', need: 'Muốn xét tuyển', history: '[Khách hàng] hi' }, t1), 'inserted');
let row = sheet.rows[1];
assert.strictEqual(row.length, ctx.LEAD_HEADERS.length);
assert.deepStrictEqual(row.slice(0, 9), [t1, t1, 's1', 'Lan', "'0912345678", 'Lớp 12', 'Kế toán', 'Muốn xét tuyển', '[Khách hàng] hi']);
assert.deepStrictEqual(row.slice(9), ['Chưa gọi', '', '']);
console.log('ok - new lead inserted');

// Staff edits status, then same session sends an update -> same row, created time + staff columns kept, empty values don't wipe
row[9] = 'Đã gọi';
row[11] = 'Hẹn thứ 2';
assert.strictEqual(ctx.upsertLead(sheet, 's1', { name: '', phone: '0987654321', grade: '', major: 'Kế toán', need: '', history: 'longer' }, t2), 'updated');
assert.strictEqual(sheet.rows.length, 2);
row = sheet.rows[1];
assert.deepStrictEqual(row, [t1, t2, 's1', 'Lan', "'0987654321", 'Lớp 12', 'Kế toán', 'Muốn xét tuyển', 'longer', 'Đã gọi', '', 'Hẹn thứ 2']);
console.log('ok - same session updated in place');

// Different session -> new row
assert.strictEqual(ctx.upsertLead(sheet, 's2', { phone: '0911111111' }, t2), 'inserted');
assert.strictEqual(sheet.rows.length, 3);
console.log('ok - other session inserted');

// Legacy payload (only lead_name "Tên - SĐT")
assert.deepStrictEqual(JSON.parse(JSON.stringify(ctx.leadFields({ lead_name: 'Trung - 0985282393', history: 'h' }))),
  { name: 'Trung', phone: '0985282393', grade: '', major: '', need: '', history: 'h' });
assert.strictEqual(ctx.leadFields({ lead_name: 'Học sinh (Qua Chat) - 0985282393' }).name, '');
console.log('ok - legacy lead_name parsed');

// Huge history is truncated under the 50,000-char cell limit
assert.ok(ctx.leadFields({ phone: '0912345678', history: 'x'.repeat(60000) }).history.length <= 49000);
console.log('ok - history truncated');

// messageRow
assert.deepStrictEqual(Array.from(ctx.messageRow('s1', { role: 'bot', content: '<p>Chào <b>bạn</b></p>', chat_type: 'ai', lead_name: 'Lan - 0912345678' }, t1)),
  [t1, 's1', 'Lan - 0912345678', 'Chatbot', 'ai', 'Chào bạn']);
assert.strictEqual(ctx.messageRow('s1', { role: 'user', content: 'hi', chat_type: 'ai' }, t1)[3], 'Khách hàng');
assert.strictEqual(ctx.messageRow('s1', { role: 'bot', content: 'hi', chat_type: 'human' }, t1)[3], 'Nhân viên');
console.log('ok - message rows');

console.log('ALL PASSED');
