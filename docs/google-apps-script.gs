/**
 * Babymedics HMIS → Google Sheet invoice log.
 *
 * Paste this whole file into the Sheet's Apps Script editor
 * (Extensions → Apps Script), set SHARED_SECRET below, then deploy:
 *
 *   Deploy → New deployment → Web app
 *     Execute as:     Me
 *     Who has access: Anyone      <-- required; HMIS sends no Google login
 *
 * Copy the /exec URL it returns into config/sheets_config.php on the server.
 *
 * "Anyone" is safe here because the URL is unguessable AND every request must
 * carry the shared secret below. Requests without it are rejected.
 *
 * IMPORTANT: after ANY edit to this script you must redeploy
 * (Deploy → Manage deployments → edit → Version: New version). Saving alone
 * does not update the live web app.
 */

// Must match 'shared_secret' in config/sheets_config.php.
var SHARED_SECRET = 'CHANGE_ME';

function doPost(e) {
  try {
    if (!e || !e.postData || !e.postData.contents) {
      return json({ ok: false, error: 'empty request' });
    }

    var body = JSON.parse(e.postData.contents);

    if (!SHARED_SECRET || SHARED_SECRET === 'CHANGE_ME') {
      return json({ ok: false, error: 'script secret not set' });
    }
    if (body.secret !== SHARED_SECRET) {
      return json({ ok: false, error: 'bad secret' });
    }

    // Ping: the secret has been checked above, so answering here proves the
    // deployment is live AND the secret matches — without touching the sheet.
    // Used by the "Test connection" button on sheet_log.php.
    if (body.ping) {
      return json({ ok: true, pong: true });
    }

    var columns = body.columns || [];
    var rowData = body.row || {};
    var tabName = body.tab || 'HMIS Log';
    if (!columns.length) {
      return json({ ok: false, error: 'no columns' });
    }

    // A lock keeps two concurrent registrations from computing the same
    // last-row and overwriting each other. Sheets has no atomic append.
    var lock = LockService.getScriptLock();
    lock.waitLock(20000);
    try {
      var ss = SpreadsheetApp.getActiveSpreadsheet();
      var sheet = ss.getSheetByName(tabName);

      // A new year gets its own tab, created with the header row on first write,
      // so January needs no manual setup.
      if (!sheet) {
        sheet = ss.insertSheet(tabName);
        sheet.appendRow(columns);
        sheet.getRange(1, 1, 1, columns.length)
             .setFontWeight('bold')
             .setBackground('#D9EAD3');
        sheet.setFrozenRows(1);
      }

      // Values are placed by COLUMN NAME as it appears in THIS sheet's own header
      // row, never by the order HMIS sent. That way an existing year's tab whose
      // columns were reordered or renamed by hand still receives correct data.
      //
      // Read exactly the sheet's real width: padding this out to the length of the
      // list HMIS sent would invent blank trailing headers on an older tab (the
      // 2023-2025 sheets have 42 columns, HMIS now sends 43) and every value would
      // land one column short of where it belongs.
      var lastCol = sheet.getLastColumn();
      var header = lastCol > 0 ? sheet.getRange(1, 1, 1, lastCol).getValues()[0] : [];

      // Header names are matched EXACTLY, never trimmed. The clinic's sheet
      // distinguishes the "Rectal Enema" amount column from the "Rectal Enema "
      // count column by nothing but a trailing space — trimming would collapse the
      // two, write the count into the amount column and leave the count blank.
      // Falls back to a trimmed match only when the exact one finds nothing, so a
      // hand-retyped header with stray whitespace still resolves.
      var out = [];
      for (var i = 0; i < header.length; i++) {
        var raw = String(header[i]);
        var val = '';
        if (Object.prototype.hasOwnProperty.call(rowData, raw)) {
          val = rowData[raw];
        } else if (Object.prototype.hasOwnProperty.call(rowData, raw.trim())) {
          val = rowData[raw.trim()];
        }
        out.push(val === null || val === undefined ? '' : val);
      }

      // A column HMIS sent that this tab has no header for is APPENDED rather than
      // dropped, so an older tab automatically grows the new "Invoice Number"
      // column on its first write instead of silently discarding it.
      for (var k = 0; k < columns.length; k++) {
        var col = String(columns[k]);
        var found = false;
        for (var h = 0; h < header.length; h++) {
          var hraw = String(header[h]);
          if (hraw === col || hraw.trim() === col.trim()) { found = true; break; }
        }
        if (!found) {
          // out.length is the authoritative next free column: header and out grow
          // together, so this stays correct across several appended columns.
          sheet.getRange(1, out.length + 1).setValue(col).setFontWeight('bold');
          header.push(col);
          var extra = rowData[col];
          out.push(extra === undefined || extra === null ? '' : extra);
        }
      }

      if (!out.length) { return json({ ok: false, error: 'nothing to write' }); }

      var rowIndex = sheet.getLastRow() + 1;

      // Force the date cells to plain text BEFORE writing — the format has to be
      // in place at write time or Sheets parses "01/07/2026" as a US date (or a
      // serial number) on the way in and the cell is already wrong. Deliberately
      // only the date columns: text-formatting the whole row would turn
      // TotalAmount / Net Total into strings and break any SUM over them.
      for (var d = 0; d < header.length; d++) {
        var hn = String(header[d]).trim();
        if (hn === 'Date' || hn === 'Date Of Birth') {
          sheet.getRange(rowIndex, d + 1).setNumberFormat('@');
        }
      }

      sheet.getRange(rowIndex, 1, 1, out.length).setValues([out]);

      return json({ ok: true, row: rowIndex, tab: tabName });
    } finally {
      lock.releaseLock();
    }
  } catch (err) {
    return json({ ok: false, error: String(err) });
  }
}

/** Browser GET is a health check — confirms the deployment is live. */
function doGet() {
  return json({ ok: true, service: 'HMIS sheet log', ready: SHARED_SECRET !== 'CHANGE_ME' });
}

function json(obj) {
  return ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
