/**
 * Hateco AI Chatbot -> Google Sheets webhook.
 *
 * Cài đặt:
 * 1. Mở Google Sheet của tổ tư vấn > Tiện ích mở rộng > Apps Script, dán toàn bộ file này, Lưu.
 * 2. Triển khai > Tùy chọn triển khai mới > Ứng dụng web. Thực thi với tư cách: Tôi. Ai có quyền truy cập: Bất kỳ ai.
 * 3. Copy URL kết thúc bằng /exec, dán vào ô "Google Sheets Webhook" trong trang cài đặt plugin.
 * Sửa script sau này: Triển khai > Quản lý bản triển khai > sửa > Phiên bản mới (URL giữ nguyên).
 *
 * Sheet "Leads": mỗi phiên chat 1 dòng. 3 cột cuối dành cho nhân viên, script không bao giờ ghi đè.
 * Sheet "Tin nhắn": nhật ký toàn bộ tin nhắn.
 */

var LEAD_SHEET = 'Leads';
var MESSAGE_SHEET = 'Tin nhắn';
var LEAD_HEADERS = ['Thời gian ghi nhận', 'Cập nhật lần cuối', 'Session ID', 'Họ và tên', 'Số điện thoại', 'Lớp hiện tại',
  'Ngành quan tâm', 'Nhu cầu tuyển sinh', 'Lịch sử trò chuyện', 'Trạng thái', 'Nhân viên phụ trách', 'Ghi chú tư vấn'];
var STAFF_COLUMNS = 3;
var STATUS_OPTIONS = ['Chưa gọi', 'Đã gọi', 'Không liên lạc được', 'Hẹn gọi lại', 'Đã nộp hồ sơ'];
var MESSAGE_HEADERS = ['Thời gian', 'Session ID', 'Khách hàng', 'Người gửi', 'Chế độ', 'Nội dung'];
var MAX_CELL = 49000; // Sheets cell limit is 50,000 chars

function doPost(e) {
  var lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    var payload = JSON.parse(e.postData.contents);
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var now = new Date();
    if (payload.type === 'LEAD') {
      upsertLead(getSheet(ss, LEAD_SHEET, LEAD_HEADERS), payload.session_id || '', payload.data || {}, now);
    } else if (payload.type === 'MESSAGE') {
      getSheet(ss, MESSAGE_SHEET, MESSAGE_HEADERS).appendRow(messageRow(payload.session_id || '', payload.data || {}, now));
    }
    return json({ ok: true });
  } catch (err) {
    return json({ ok: false, error: String(err) });
  } finally {
    lock.releaseLock();
  }
}

function leadFields(data) {
  var name = data.name || '';
  var phone = data.phone || '';
  // Older plugin versions only send lead_name = "Tên - SĐT"
  if (!phone && data.lead_name) {
    var m = String(data.lead_name).match(/^(.*?)\s*-\s*(\+?\d[\d\s.]*)$/);
    if (m) {
      name = name || m[1];
      phone = m[2].replace(/[^\d+]/g, '');
    }
  }
  if (name === 'Học sinh (Qua Chat)') name = '';
  return {
    name: String(name).trim(),
    phone: String(phone).trim(),
    grade: String(data.grade || '').trim(),
    major: String(data.major || '').trim(),
    need: String(data.need || '').trim(),
    history: String(data.history || '').slice(-MAX_CELL),
  };
}

// Sheets stores the leading ' as a "literal text" marker: keeps leading zeros, blocks formulas.
function asText(v) { return typeof v === 'string' && v !== '' ? "'" + v : v; }

function upsertLead(sheet, sessionId, data, now) {
  var f = leadFields(data);
  var fresh = [f.name, f.phone, f.grade, f.major, f.need, f.history];
  var values = sheet.getDataRange().getValues();

  for (var i = 1; i < values.length; i++) {
    if (sessionId && values[i][2] === sessionId) {
      var old = values[i].slice(3, 9);
      var merged = fresh.map(function (v, k) { return v !== '' ? v : old[k]; });
      sheet.getRange(i + 1, 2, 1, 8).setValues([[now, asText(sessionId)].concat(merged.map(asText))]);
      return 'updated';
    }
  }

  sheet.appendRow([now, now, asText(sessionId)].concat(fresh.map(asText), [STATUS_OPTIONS[0], '', '']));
  return 'inserted';
}

function messageRow(sessionId, data, now) {
  var sender = data.role === 'user' ? 'Khách hàng' : (data.chat_type === 'human' ? 'Nhân viên' : 'Chatbot');
  var text = String(data.content || '').replace(/<[^>]+>/g, '').trim().slice(0, MAX_CELL);
  return [now, asText(sessionId), asText(data.lead_name || ''), sender, asText(data.chat_type || 'ai'), asText(text)];
}

function getSheet(ss, name, headers) {
  var sheet = ss.getSheetByName(name);
  if (sheet) return sheet;
  sheet = ss.insertSheet(name);
  sheet.appendRow(headers);
  sheet.setFrozenRows(1);
  sheet.getRange(1, 1, 1, headers.length).setFontWeight('bold');
  if (name === LEAD_SHEET) {
    var statusCol = headers.length - STAFF_COLUMNS + 1;
    sheet.getRange(2, statusCol, sheet.getMaxRows() - 1, 1).setDataValidation(
      SpreadsheetApp.newDataValidation().requireValueInList(STATUS_OPTIONS, true).build()
    );
  }
  return sheet;
}

function json(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}
