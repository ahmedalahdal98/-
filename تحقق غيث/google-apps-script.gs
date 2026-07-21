const RECORDS_SHEET = 'السجلات';
const ALERTS_SHEET = 'التنبيهات';

const RECORD_HEADERS = [
  'id', 'اسم الطالب', 'رقم الجوال', 'الرقم الجامعي', 'الكلية',
  'طريقة التحقق', 'الموظف', 'التاريخ', 'تحذير', 'سبب التحذير'
];

const ALERT_HEADERS = [
  'id', 'recordId', 'اسم الطالب', 'رقم الجوال', 'الرقم الجامعي',
  'الكلية', 'طريقة التحقق', 'الموظف', 'السبب', 'التاريخ', 'مقروء'
];

function doGet() {
  return jsonResponse({ ok: true, service: 'بوابة التحقق' });
}

function doPost(e) {
  const lock = LockService.getScriptLock();
  lock.waitLock(10000);
  try {
    const request = JSON.parse(e.postData.contents || '{}');
    const data = request.data || {};

    if (request.entity === 'record') {
      handleRecord(request.action, data);
    } else if (request.entity === 'alert') {
      handleAlert(request.action, data);
    } else if (request.entity === 'alerts' && request.action === 'clear') {
      clearData(ALERTS_SHEET, ALERT_HEADERS);
    } else {
      throw new Error('طلب غير معروف');
    }

    return jsonResponse({ ok: true });
  } catch (error) {
    return jsonResponse({ ok: false, error: error.message });
  } finally {
    lock.releaseLock();
  }
}

function handleRecord(action, record) {
  const row = [
    record.id, record.studentName, record.phone, record.universityId,
    record.college, record.method, record.employeeName, record.createdAt,
    record.warning ? 'نعم' : 'لا', record.warningReason || ''
  ];
  mutateRow(RECORDS_SHEET, RECORD_HEADERS, action, record.id, row);
}

function handleAlert(action, alert) {
  const row = [
    alert.id, alert.recordId, alert.studentName, alert.phone,
    alert.universityId, alert.college, alert.method, alert.employeeName,
    alert.reason, alert.createdAt, alert.read ? 'نعم' : 'لا'
  ];
  mutateRow(ALERTS_SHEET, ALERT_HEADERS, action, alert.id, row);
}

function mutateRow(sheetName, headers, action, id, row) {
  const sheet = getSheet(sheetName, headers);
  const rowNumber = findRow(sheet, id);

  if (action === 'delete') {
    if (rowNumber) sheet.deleteRow(rowNumber);
    return;
  }

  if (action !== 'upsert') throw new Error('إجراء غير معروف');
  if (rowNumber) sheet.getRange(rowNumber, 1, 1, row.length).setValues([row]);
  else sheet.appendRow(row);
}

function getSheet(name, headers) {
  const spreadsheet = SpreadsheetApp.getActiveSpreadsheet();
  let sheet = spreadsheet.getSheetByName(name);
  if (!sheet) sheet = spreadsheet.insertSheet(name);
  if (sheet.getLastRow() === 0) {
    sheet.appendRow(headers);
    sheet.setFrozenRows(1);
  }
  return sheet;
}

function findRow(sheet, id) {
  if (!id || sheet.getLastRow() < 2) return 0;
  const match = sheet.getRange(2, 1, sheet.getLastRow() - 1, 1)
    .createTextFinder(String(id)).matchEntireCell(true).findNext();
  return match ? match.getRow() : 0;
}

function clearData(sheetName, headers) {
  const sheet = getSheet(sheetName, headers);
  if (sheet.getLastRow() > 1) {
    sheet.getRange(2, 1, sheet.getLastRow() - 1, sheet.getLastColumn()).clearContent();
  }
}

function jsonResponse(value) {
  return ContentService.createTextOutput(JSON.stringify(value))
    .setMimeType(ContentService.MimeType.JSON);
}
