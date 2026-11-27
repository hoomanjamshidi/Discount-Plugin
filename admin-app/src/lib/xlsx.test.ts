import { describe, expect, it } from "vitest";
import { buildXlsx, colName, crc32, sheetName, xmlText, zip } from "./xlsx";

/** Read a store-only ZIP (what zip() writes) back into name → bytes, verifying every CRC. */
function unzip(bytes: Uint8Array): Map<string, Uint8Array> {
  const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  const dec = new TextDecoder();
  // End of central directory is the last 22 bytes (no comment).
  const eocd = bytes.length - 22;
  expect(dv.getUint32(eocd, true)).toBe(0x06054b50);
  const count = dv.getUint16(eocd + 10, true);
  let p = dv.getUint32(eocd + 16, true);
  const out = new Map<string, Uint8Array>();
  for (let i = 0; i < count; i++) {
    expect(dv.getUint32(p, true)).toBe(0x02014b50);
    const crc = dv.getUint32(p + 16, true);
    const size = dv.getUint32(p + 20, true);
    const nameLen = dv.getUint16(p + 28, true);
    const local = dv.getUint32(p + 42, true);
    const name = dec.decode(bytes.subarray(p + 46, p + 46 + nameLen));
    expect(dv.getUint32(local, true)).toBe(0x04034b50);
    expect(dv.getUint16(local + 8, true)).toBe(0); // stored
    const lnameLen = dv.getUint16(local + 26, true);
    const start = local + 30 + lnameLen + dv.getUint16(local + 28, true);
    const data = bytes.subarray(start, start + size);
    expect(crc32(data)).toBe(crc);
    out.set(name, data);
    p += 46 + nameLen;
  }
  return out;
}

const xml = (files: Map<string, Uint8Array>, name: string) => {
  const raw = files.get(name);
  expect(raw, name).toBeDefined();
  const text = new TextDecoder().decode(raw);
  const doc = new DOMParser().parseFromString(text, "application/xml");
  expect(doc.getElementsByTagName("parsererror").length, `${name} is not well-formed XML`).toBe(0);
  return { text, doc };
};

describe("crc32 / zip", () => {
  it("matches the standard CRC-32 check value", () => {
    expect(crc32(new TextEncoder().encode("123456789"))).toBe(0xcbf43926);
    expect(crc32(new Uint8Array())).toBe(0);
  });

  it("round-trips files with UTF-8 content", () => {
    const enc = new TextEncoder();
    const files = unzip(zip([{ name: "a.txt", data: enc.encode("سلام") }, { name: "b/c.xml", data: enc.encode("<x/>") }]));
    expect(new TextDecoder().decode(files.get("a.txt"))).toBe("سلام");
    expect(new TextDecoder().decode(files.get("b/c.xml"))).toBe("<x/>");
  });
});

describe("helpers", () => {
  it("names columns like Excel", () => {
    expect([0, 1, 25, 26, 27, 51, 52, 701, 702].map(colName)).toEqual(["A", "B", "Z", "AA", "AB", "AZ", "BA", "ZZ", "AAA"]);
  });

  it("escapes XML and drops characters Excel rejects", () => {
    expect(xmlText(`a & b <c> "d"`)).toBe("a &amp; b &lt;c&gt; &quot;d&quot;");
    expect(xmlText("x\u0001\u0008y\u000Bz")).toBe("xyz");
    expect(xmlText("خط\tاول\nدوم")).toBe("خط\tاول\nدوم");
  });

  it("makes legal sheet names", () => {
    expect(sheetName("a/b:c?d*e[f]")).toBe("a b c d e f");
    expect(sheetName("x".repeat(40))).toHaveLength(31);
    expect(sheetName("  ")).toBe("Sheet");
  });
});

describe("buildXlsx", () => {
  const book = buildXlsx(
    [
      {
        name: "محصولات",
        columns: [
          { header: "ردیف", type: "int" },
          { header: "نام <محصول>", type: "text" },
          { header: "لینک", type: "link" },
          { header: "قیمت", type: "money" },
          { header: "درصد", type: "percent" },
        ],
        rows: [
          [1, "کتاب & دفتر", { text: "https://shop.test/p/کتاب", url: "https://shop.test/p/%DA%A9" }, 1000000, 0.15],
          [2, "بدون لینک", "", null, null],
          [3, "لینک ناامن", { text: "javascript:alert(1)", url: "javascript:alert(1)" }, 850000.5, NaN],
        ],
      },
      { name: "خلاصه", columns: [{ header: "", type: "text" }, { header: "", type: "text" }], rows: [["نام کمپین", "تست"]], table: false },
    ],
    { decimals: 0 },
  );
  const files = unzip(book);

  it("contains every part Excel needs, all well-formed", () => {
    for (const name of [
      "[Content_Types].xml",
      "_rels/.rels",
      "xl/workbook.xml",
      "xl/_rels/workbook.xml.rels",
      "xl/styles.xml",
      "xl/worksheets/sheet1.xml",
      "xl/worksheets/sheet2.xml",
      "xl/worksheets/_rels/sheet1.xml.rels",
      "docProps/core.xml",
      "docProps/app.xml",
    ]) {
      xml(files, name);
    }
    expect(files.has("xl/worksheets/_rels/sheet2.xml.rels")).toBe(false);
  });

  it("is right-to-left with a frozen, filterable header", () => {
    const { doc, text } = xml(files, "xl/worksheets/sheet1.xml");
    expect(doc.getElementsByTagName("sheetView")[0].getAttribute("rightToLeft")).toBe("1");
    expect(doc.getElementsByTagName("pane")[0].getAttribute("state")).toBe("frozen");
    expect(doc.getElementsByTagName("autoFilter")[0].getAttribute("ref")).toBe("A1:E4");
    expect(text).toContain("نام &lt;محصول&gt;");
    expect(text).toContain("کتاب &amp; دفتر");
  });

  it("writes prices as real numbers and leaves empty / non-finite cells out", () => {
    const { doc } = xml(files, "xl/worksheets/sheet1.xml");
    const cell = (ref: string) => Array.from(doc.getElementsByTagName("c")).find((c) => c.getAttribute("r") === ref);
    expect(cell("D2")?.getAttribute("t")).toBeNull();
    expect(cell("D2")?.textContent).toBe("1000000");
    expect(cell("E2")?.textContent).toBe("0.15");
    expect(cell("D4")?.textContent).toBe("850000.5");
    expect(cell("D3")).toBeUndefined();
    expect(cell("E4")).toBeUndefined();
    expect(cell("C3")).toBeUndefined();
  });

  it("links only http(s) URLs, through external relationships", () => {
    const { doc } = xml(files, "xl/worksheets/sheet1.xml");
    const links = Array.from(doc.getElementsByTagName("hyperlink"));
    expect(links.map((l) => l.getAttribute("ref"))).toEqual(["C2"]);
    const rels = xml(files, "xl/worksheets/_rels/sheet1.xml.rels").doc.getElementsByTagName("Relationship");
    expect(rels).toHaveLength(1);
    expect(rels[0].getAttribute("Target")).toBe("https://shop.test/p/%DA%A9");
    expect(rels[0].getAttribute("TargetMode")).toBe("External");
  });

  it("names the sheets in Persian and registers them all", () => {
    const { doc } = xml(files, "xl/workbook.xml");
    expect(Array.from(doc.getElementsByTagName("sheet")).map((s) => s.getAttribute("name"))).toEqual(["محصولات", "خلاصه"]);
    // rightToLeft belongs on sheetView only; on workbookView it breaks strict readers (found with openpyxl).
    expect(doc.getElementsByTagName("workbookView")[0].hasAttribute("rightToLeft")).toBe(false);
    const types = xml(files, "[Content_Types].xml").text;
    expect(types).toContain("/xl/worksheets/sheet1.xml");
    expect(types).toContain("/xl/worksheets/sheet2.xml");
  });

  it("uses the shop's decimals for prices", () => {
    expect(xml(files, "xl/styles.xml").text).toContain('formatCode="#,##0"');
    const two = unzip(buildXlsx([{ name: "x", columns: [{ header: "p", type: "money" }], rows: [[1.5]] }], { decimals: 2 }));
    expect(xml(two, "xl/styles.xml").text).toContain('formatCode="#,##0.00"');
  });
});
