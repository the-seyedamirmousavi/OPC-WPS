"use client";

import { useT } from "@/components/Providers";
import { inputClass } from "@/components/ui";
import { jalaliToIsoDate, latinDigits, parseJalali } from "@/lib/jalali";

/**
 * Date input. In Persian the user types a Solar Hijri date (1405/07/25, Persian digits also fine) and sees the
 * resolved date in words; in English a normal date picker is used. The value is sent to the server as typed:
 * it accepts YYYY-MM-DD, a Jalali date or an ISO instant.
 */
export function DateField({ value, onChange, label }: { value: string; onChange: (v: string) => void; label: string }) {
  const { lang, t, date } = useT();

  if (lang !== "fa") {
    return <input type="date" aria-label={label} className={inputClass} value={value} onChange={(e) => onChange(e.target.value)} />;
  }
  const trimmed = value.trim();
  const parsed = trimmed ? parseJalali(trimmed) : null;
  const iso = parsed ? jalaliToIsoDate(trimmed) : null;
  return (
    <div>
      <input
        aria-label={label}
        inputMode="numeric"
        dir="ltr"
        placeholder="۱۴۰۵/۰۷/۲۵"
        className={`${inputClass} ltr text-start`}
        value={value}
        onChange={(e) => onChange(latinDigits(e.target.value))}
        aria-invalid={trimmed !== "" && !parsed}
      />
      <p className={`mt-1 text-xs ${trimmed && !parsed ? "text-bad" : "text-ink-3"}`}>
        {trimmed === "" ? t("date.hintJalali") : parsed && iso ? date(`${iso}T12:00:00Z`).split("،")[0] : t("date.invalid")}
      </p>
    </div>
  );
}
