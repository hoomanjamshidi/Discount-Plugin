/**
 * Minimal, dependency-free Excel (.xlsx) writer.
 *
 * An .xlsx file is a ZIP of a few XML parts. We write the ZIP ourselves ("stored", no
 * compression: the files stay small and every Excel / LibreOffice / Google Sheets reads it),
 * with inline strings (no shared-string table), real numbers with a thousands format,
 * right-to-left sheets, a frozen + filterable header row and clickable links.
 */

export type CellType = "text" | "int" | "money" | "percent" | "link";

export interface Column {
  header: string;
  type: CellType;
  /** Column width in characters. */
  width?: number;
}

export interface LinkValue {
  text: string;
  url: string;
}

export type CellValue = string | number | null | LinkValue;

export interface Sheet {
  name: string;
  columns: Column[];
  rows: CellValue[][];
  rtl?: boolean;
  /** Header row is bold, frozen and gets an AutoFilter (default true). */
  table?: boolean;
}

export interface WorkbookOptions {
  /** Decimals used by the "money" format (shop setting). */
  decimals?: number;
}

// --------------------------------------------------------------------------- ZIP

const CRC_TABLE = (() => {
  const t = new Uint32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    t[n] = c >>> 0;
  }
  return t;
})();

export function crc32(data: Uint8Array): number {
  let c = 0xffffffff;
  for (let i = 0; i < data.length; i++) c = CRC_TABLE[(c ^ data[i]) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}

/** Store-only ZIP (method 0). Names are ASCII paths; data is already UTF-8. */
export function zip(files: { name: string; data: Uint8Array }[]): Uint8Array<ArrayBuffer> {
  const enc = new TextEncoder();
  const chunks: Uint8Array[] = [];
  const central: Uint8Array[] = [];
  let offset = 0;
  // Fixed DOS timestamp (1 Jan 2020 00:00): the file content, not the clock, defines the output.
  const dosTime = 0;
  const dosDate = ((2020 - 1980) << 9) | (1 << 5) | 1;

  for (const f of files) {
    const name = enc.encode(f.name);
    const crc = crc32(f.data);
    const size = f.data.length;

    const local = new DataView(new ArrayBuffer(30));
    local.setUint32(0, 0x04034b50, true);
    local.setUint16(4, 20, true); // version needed
    local.setUint16(6, 0x0800, true); // UTF-8 names
    local.setUint16(8, 0, true); // stored
    local.setUint16(10, dosTime, true);
    local.setUint16(12, dosDate, true);
    local.setUint32(14, crc, true);
    local.setUint32(18, size, true);
    local.setUint32(22, size, true);
    local.setUint16(26, name.length, true);
    local.setUint16(28, 0, true);
    chunks.push(new Uint8Array(local.buffer), name, f.data);

    const cd = new DataView(new ArrayBuffer(46));
    cd.setUint32(0, 0x02014b50, true);
    cd.setUint16(4, 20, true); // made by
    cd.setUint16(6, 20, true); // needed
    cd.setUint16(8, 0x0800, true);
    cd.setUint16(10, 0, true);
    cd.setUint16(12, dosTime, true);
    cd.setUint16(14, dosDate, true);
    cd.setUint32(16, crc, true);
    cd.setUint32(20, size, true);
    cd.setUint32(24, size, true);
    cd.setUint16(28, name.length, true);
    cd.setUint16(30, 0, true);
    cd.setUint16(32, 0, true);
    cd.setUint16(34, 0, true);
    cd.setUint16(36, 0, true);
    cd.setUint32(38, 0, true);
    cd.setUint32(42, offset, true);
    central.push(new Uint8Array(cd.buffer), name);

    offset += 30 + name.length + size;
  }

  const cdSize = central.reduce((n, c) => n + c.length, 0);
  const end = new DataView(new ArrayBuffer(22));
  end.setUint32(0, 0x06054b50, true);
  end.setUint16(8, files.length, true);
  end.setUint16(10, files.length, true);
  end.setUint32(12, cdSize, true);
  end.setUint32(16, offset, true);

  const all = [...chunks, ...central, new Uint8Array(end.buffer)];
  const out = new Uint8Array(all.reduce((n, c) => n + c.length, 0));
  let p = 0;
  for (const c of all) {
    out.set(c, p);
    p += c.length;
  }
  return out;
}

// --------------------------------------------------------------------------- XML

/** Escape text for XML and drop characters XML 1.0 forbids (they make Excel refuse the file). */
export function xmlText(s: string): string {
  let legal = "";
  for (const ch of s) {
    const c = ch.codePointAt(0) ?? 0;
    if (c === 0x9 || c === 0xa || c === 0xd || (c >= 0x20 && c <= 0xd7ff) || (c >= 0xe000 && c <= 0xfffd) || c >= 0x10000) legal += ch;
  }
  return legal
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

/** 0 → "A", 27 → "AB". */
export function colName(index: number): string {
  let n = index + 1;
  let s = "";
  while (n > 0) {
    const m = (n - 1) % 26;
    s = String.fromCharCode(65 + m) + s;
    n = Math.floor((n - 1) / 26);
  }
  return s;
}

/** Sheet names: max 31 chars, none of : \ / ? * [ ]. */
export function sheetName(name: string): string {
  const clean = name.replace(/[:\\/?*[\]]/g, " ").trim() || "Sheet";
  return clean.slice(0, 31);
}

// Style indexes in styles.xml (cellXfs order).
const S_TEXT = 0;
const S_HEADER = 1;
const S_INT = 2;
const S_MONEY = 3;
const S_PERCENT = 4;
const S_LINK = 5;
const S_LABEL = 6;

function stylesXml(decimals: number): string {
  const money = decimals > 0 ? `#,##0.${"0".repeat(Math.min(decimals, 4))}` : "#,##0";
  return (
    `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>` +
    `<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">` +
    `<numFmts count="2"><numFmt numFmtId="164" formatCode="${xmlText(money)}"/><numFmt numFmtId="165" formatCode="0.0%"/></numFmts>` +
    `<fonts count="3">` +
    `<font><sz val="11"/><name val="Tahoma"/></font>` +
    `<font><b/><sz val="11"/><name val="Tahoma"/></font>` +
    `<font><u/><sz val="11"/><color rgb="FF0563C1"/><name val="Tahoma"/></font>` +
    `</fonts>` +
    `<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>` +
    `<fill><patternFill patternType="solid"><fgColor rgb="FFE8F0FE"/><bgColor indexed="64"/></patternFill></fill></fills>` +
    `<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>` +
    `<border><left/><right/><top/><bottom style="thin"><color rgb="FF9AA5B1"/></bottom><diagonal/></border></borders>` +
    `<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>` +
    `<cellXfs count="7">` +
    `<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>` +
    `<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>` +
    `<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>` +
    `<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>` +
    `<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>` +
    `<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>` +
    `<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>` +
    `</cellXfs>` +
    `<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>` +
    `</styleSheet>`
  );
}

function isLink(v: CellValue): v is LinkValue {
  return typeof v === "object" && v !== null && "url" in v;
}

function sheetXml(sheet: Sheet): { xml: string; links: { ref: string; url: string }[] } {
  const table = sheet.table !== false;
  const links: { ref: string; url: string }[] = [];
  const lastCol = colName(Math.max(0, sheet.columns.length - 1));
  const rowsXml: string[] = [];

  const cell = (ref: string, v: CellValue, type: CellType): string => {
    if (v === null || v === "" || (typeof v === "number" && !Number.isFinite(v))) return "";
    if (isLink(v)) {
      if (/^https?:\/\//i.test(v.url) && v.url.length <= 2000) links.push({ ref, url: v.url });
      return `<c r="${ref}" t="inlineStr" s="${S_LINK}"><is><t xml:space="preserve">${xmlText(v.text)}</t></is></c>`;
    }
    if (typeof v === "number") {
      const s = type === "money" ? S_MONEY : type === "percent" ? S_PERCENT : type === "int" ? S_INT : S_TEXT;
      return `<c r="${ref}" s="${s}"><v>${v}</v></c>`;
    }
    return `<c r="${ref}" t="inlineStr" s="${S_TEXT}"><is><t xml:space="preserve">${xmlText(String(v))}</t></is></c>`;
  };

  let r = 1;
  if (table) {
    const head = sheet.columns.map((c, i) => `<c r="${colName(i)}1" t="inlineStr" s="${S_HEADER}"><is><t xml:space="preserve">${xmlText(c.header)}</t></is></c>`);
    rowsXml.push(`<row r="1" ht="24" customHeight="1">${head.join("")}</row>`);
    r = 2;
  }
  for (const row of sheet.rows) {
    const cells = sheet.columns.map((c, i) => {
      const v = row[i] ?? null;
      const ref = `${colName(i)}${r}`;
      // Label column of a key/value sheet: bold.
      if (!table && i === 0 && typeof v === "string" && v !== "") {
        return `<c r="${ref}" t="inlineStr" s="${S_LABEL}"><is><t xml:space="preserve">${xmlText(v)}</t></is></c>`;
      }
      return cell(ref, v, c.type);
    });
    rowsXml.push(`<row r="${r}">${cells.join("")}</row>`);
    r++;
  }

  const lastRow = Math.max(1, r - 1);
  const cols = sheet.columns.map((c, i) => `<col min="${i + 1}" max="${i + 1}" width="${c.width ?? 14}" customWidth="1"/>`).join("");
  const pane = table ? `<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>` : "";
  const filter = table && sheet.rows.length > 0 ? `<autoFilter ref="A1:${lastCol}${lastRow}"/>` : "";
  const hl = links.length ? `<hyperlinks>${links.map((l, i) => `<hyperlink ref="${l.ref}" r:id="rId${i + 1}"/>`).join("")}</hyperlinks>` : "";

  const xml =
    `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>` +
    `<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">` +
    `<dimension ref="A1:${lastCol}${lastRow}"/>` +
    `<sheetViews><sheetView workbookViewId="0"${sheet.rtl === false ? "" : ` rightToLeft="1"`}>${pane}</sheetView></sheetViews>` +
    `<sheetFormatPr defaultRowHeight="18"/>` +
    `<cols>${cols}</cols>` +
    `<sheetData>${rowsXml.join("")}</sheetData>` +
    filter +
    hl +
    `</worksheet>`;
  return { xml, links };
}

/** Build the .xlsx bytes. */
export function buildXlsx(sheets: Sheet[], opts: WorkbookOptions = {}): Uint8Array<ArrayBuffer> {
  const enc = new TextEncoder();
  const files: { name: string; data: Uint8Array }[] = [];
  const names = sheets.map((s) => sheetName(s.name));
  const n = sheets.length;

  files.push({
    name: "[Content_Types].xml",
    data: enc.encode(
      `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>` +
        `<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">` +
        `<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>` +
        `<Default Extension="xml" ContentType="application/xml"/>` +
        `<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>` +
        sheets.map((_, i) => `<Override PartName="/xl/worksheets/sheet${i + 1}.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>`).join("") +
        `<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>` +
        `<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>` +
        `<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>` +
        `</Types>`,
    ),
  });
  files.push({
    name: "_rels/.rels",
    data: enc.encode(
      `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>` +
        `<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">` +
        `<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>` +
        `<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>` +
        `<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>` +
        `</Relationships>`,
    ),
  });
  files.push({
    name: "docProps/core.xml",
    data: enc.encode(
      `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>` +
        `<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">` +
        `<dc:creator>Smart Bulk Discount</dc:creator></cp:coreProperties>`,
    ),
  });
  files.push({
    name: "docProps/app.xml",
    data: enc.encode(
      `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>` +
        `<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Smart Bulk Discount</Application></Properties>`,
    ),
  });
  files.push({
    name: "xl/workbook.xml",
    data: enc.encode(
      `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>` +
        `<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">` +
        `<bookViews><workbookView/></bookViews>` +
        `<sheets>${names.map((nm, i) => `<sheet name="${xmlText(nm)}" sheetId="${i + 1}" r:id="rId${i + 1}"/>`).join("")}</sheets>` +
        `<definedNames>${sheets
          .map((s, i) => (s.table !== false && s.rows.length > 0 ? `<definedName name="_xlnm._FilterDatabase" localSheetId="${i}" hidden="1">'${xmlText(names[i].replace(/'/g, "''"))}'!$A$1:$${colName(s.columns.length - 1)}$${s.rows.length + 1}</definedName>` : ""))
          .join("")}</definedNames>` +
        `</workbook>`,
    ),
  });
  files.push({
    name: "xl/_rels/workbook.xml.rels",
    data: enc.encode(
      `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>` +
        `<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">` +
        sheets.map((_, i) => `<Relationship Id="rId${i + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet${i + 1}.xml"/>`).join("") +
        `<Relationship Id="rId${n + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>` +
        `</Relationships>`,
    ),
  });
  files.push({ name: "xl/styles.xml", data: enc.encode(stylesXml(opts.decimals ?? 0)) });

  sheets.forEach((s, i) => {
    const { xml, links } = sheetXml(s);
    files.push({ name: `xl/worksheets/sheet${i + 1}.xml`, data: enc.encode(xml) });
    if (links.length) {
      files.push({
        name: `xl/worksheets/_rels/sheet${i + 1}.xml.rels`,
        data: enc.encode(
          `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>` +
            `<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">` +
            links.map((l, k) => `<Relationship Id="rId${k + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="${xmlText(l.url)}" TargetMode="External"/>`).join("") +
            `</Relationships>`,
        ),
      });
    }
  });

  return zip(files);
}
