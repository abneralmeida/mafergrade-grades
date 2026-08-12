var ALLOWED_FORMS = {
  lead_gate: "Página1",
  full_form: "Página1",
  mafergrade_lead_gate: "Mafer Grade",
  mafergrade_full_form: "Mafer Grade",
  mafergrade_short_quote_v2: "Mafer Grade"
};

var HEADERS = [
  "Timestamp", "Formulário", "Nome", "WhatsApp", "Email", "Empresa/Órgão",
  "Produto", "Etapa da demanda", "UF", "Quantidade", "Detalhes",
  "CTA origem", "gclid", "Página"
];

function doPost(e) {
  try {
    if (!e || !e.postData || !e.postData.contents) {
      return jsonOutput({ ok: false, error: "empty_request" });
    }

    var data = JSON.parse(e.postData.contents);
    var formType = clean(data.formulario, 40);
    var sheetName = ALLOWED_FORMS[formType];

    if (!sheetName || data.website) {
      return jsonOutput({ ok: false, error: "invalid_form" });
    }

    var page = clean(data.pagina, 120);
    var expectedPage = formType.indexOf("mafergrade_") === 0
      ? /^\/(?:mafergrade-grades|gradis-e-cercamentos)\/?$/
      : /^\/(?:defensas-metalicas|jmafer-defensas)\/?$/;

    if (!expectedPage.test(page)) {
      return jsonOutput({ ok: false, error: "invalid_page" });
    }

    var name = clean(data.nome, 120);
    var phone = clean(data.whatsapp, 30);
    var email = clean(data.email, 160);
    var phoneDigits = phone.replace(/\D/g, "");
    var emailRequired = formType !== "mafergrade_short_quote_v2";
    var emailValid = /^\S+@\S+\.\S+$/.test(email);

    if (
      !name ||
      phoneDigits.length < 10 ||
      phoneDigits.length > 13 ||
      (emailRequired && !emailValid) ||
      (email && !emailValid)
    ) {
      return jsonOutput({ ok: false, error: "invalid_contact" });
    }

    var signature = [formType, phoneDigits, email.toLowerCase(), page].join("|");
    var digest = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, signature);
    var cacheKey = Utilities.base64EncodeWebSafe(digest).slice(0, 80);
    var cache = CacheService.getScriptCache();

    if (cache.get(cacheKey)) {
      return jsonOutput({ ok: true, duplicate: true });
    }

    var lock = LockService.getScriptLock();
    lock.waitLock(10000);

    try {
      var spreadsheet = SpreadsheetApp.getActiveSpreadsheet();
      var sheet = spreadsheet.getSheetByName(sheetName) || spreadsheet.insertSheet(sheetName);

      if (sheet.getLastRow() === 0) {
        sheet.appendRow(HEADERS);
        sheet.getRange(1, 1, 1, HEADERS.length)
          .setFontWeight("bold")
          .setBackground("#071e36")
          .setFontColor("#ffffff");
        sheet.setFrozenRows(1);
      }

      sheet.appendRow([
        new Date(),
        formType,
        name,
        phone,
        email,
        clean(data.empresa, 160),
        clean(data.produto, 180),
        clean(data.etapa, 120),
        clean(data.uf, 10),
        clean(data.quantidade, 80),
        clean(data.detalhes, 2000),
        clean(data.cta, 120),
        clean(data.gclid, 200),
        page
      ]);

      cache.put(cacheKey, "1", 30);
    } finally {
      lock.releaseLock();
    }

    return jsonOutput({ ok: true });
  } catch (err) {
    return jsonOutput({ ok: false, error: String(err) });
  }
}

function clean(value, maxLength) {
  return String(value == null ? "" : value).trim().slice(0, maxLength);
}

function jsonOutput(payload) {
  return ContentService
    .createTextOutput(JSON.stringify(payload))
    .setMimeType(ContentService.MimeType.JSON);
}
