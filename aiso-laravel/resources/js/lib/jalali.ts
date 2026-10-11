/**
 * Solar Hijri (Jalali) helpers for date INPUT. Display uses Intl ("fa-IR-u-ca-persian").
 * The conversion is the usual arithmetic algorithm; it is checked against known dates in the backend tests.
 */

const G_D_M = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

export function toJalali(gy: number, gm: number, gd: number): [number, number, number] {
  const gy2 = gm > 2 ? gy + 1 : gy;
  let days =
    355666 + 365 * gy + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) + gd + G_D_M[gm - 1];
  let jy = -1595 + 33 * Math.floor(days / 12053);
  days %= 12053;
  jy += 4 * Math.floor(days / 1461);
  days %= 1461;
  if (days > 365) {
    jy += Math.floor((days - 1) / 365);
    days = (days - 1) % 365;
  }
  const jm = days < 186 ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
  const jd = 1 + (days < 186 ? days % 31 : (days - 186) % 30);
  return [jy, jm, jd];
}

export function toGregorian(jy: number, jm: number, jd: number): [number, number, number] {
  const y = jy + 1595;
  let days =
    -355668 + 365 * y + Math.floor(y / 33) * 8 + Math.floor(((y % 33) + 3) / 4) + jd + (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);
  let gy = 400 * Math.floor(days / 146097);
  days %= 146097;
  if (days > 36524) {
    days--;
    gy += 100 * Math.floor(days / 36524);
    days %= 36524;
    if (days >= 365) days++;
  }
  gy += 4 * Math.floor(days / 1461);
  days %= 1461;
  if (days > 365) {
    gy += Math.floor((days - 1) / 365);
    days = (days - 1) % 365;
  }
  let gd = days + 1;
  const leap = (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0;
  const sal = [0, 31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
  let gm = 0;
  while (gm < 13 && gd > sal[gm]) {
    gd -= sal[gm];
    gm++;
  }
  return [gy, gm, gd];
}

/** Persian/Arabic-Indic digits to Latin digits. */
export function latinDigits(s: string): string {
  return s.replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0)).replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660));
}

/** "1405/07/25" or "۱۴۰۵/۰۷/۲۵" -> [1405, 7, 25], or null when it is not a valid Jalali date. */
export function parseJalali(text: string): [number, number, number] | null {
  const m = /^(1[2-9]\d\d)[/\-.](\d{1,2})[/\-.](\d{1,2})$/.exec(latinDigits(text.trim()));
  if (!m) return null;
  const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])];
  if (mo < 1 || mo > 12 || d < 1 || d > (mo <= 6 ? 31 : 30)) return null;
  return [y, mo, d];
}

/** Jalali text -> "YYYY-MM-DD" (Gregorian), or null. */
export function jalaliToIsoDate(text: string): string | null {
  const j = parseJalali(text);
  if (!j) return null;
  const [gy, gm, gd] = toGregorian(j[0], j[1], j[2]);
  return `${gy}-${String(gm).padStart(2, "0")}-${String(gd).padStart(2, "0")}`;
}

/** An ISO instant or date -> "1405/07/25" (Latin digits) using the Tehran calendar day. */
export function isoToJalaliText(iso: string | null | undefined): string {
  if (!iso) return "";
  const parts = new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Tehran", year: "numeric", month: "2-digit", day: "2-digit" })
    .format(new Date(iso))
    .split("-")
    .map(Number);
  const [jy, jm, jd] = toJalali(parts[0], parts[1], parts[2]);
  return `${jy}/${String(jm).padStart(2, "0")}/${String(jd).padStart(2, "0")}`;
}
