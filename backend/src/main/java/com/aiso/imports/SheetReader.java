package com.aiso.imports;

import org.apache.poi.ss.usermodel.Cell;
import org.apache.poi.ss.usermodel.CellType;
import org.apache.poi.ss.usermodel.DateUtil;
import org.apache.poi.ss.usermodel.Row;
import org.apache.poi.ss.usermodel.Sheet;
import org.apache.poi.ss.usermodel.Workbook;

import java.math.BigDecimal;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;

/** Header-driven access to one worksheet. Column lookup ignores case, spaces, underscores and Persian letter variants. */
final class SheetReader {

    final String name;
    private final Sheet sheet;
    private final Map<String, Integer> columns = new HashMap<>();
    private final Map<String, String> displayNames = new HashMap<>();

    private SheetReader(String name, Sheet sheet) {
        this.name = name;
        this.sheet = sheet;
        Row header = sheet.getRow(sheet.getFirstRowNum());
        if (header != null) {
            for (Cell c : header) {
                String text = text(c);
                if (text != null) {
                    String key = normalize(text);
                    columns.putIfAbsent(key, c.getColumnIndex());
                    displayNames.putIfAbsent(key, text);
                    // a Persian header is also known under its canonical (English) name
                    String canonical = FaNames.canonicalColumn(key);
                    if (canonical != null) {
                        String canonicalKey = normalize(canonical);
                        columns.putIfAbsent(canonicalKey, c.getColumnIndex());
                        displayNames.putIfAbsent(canonicalKey, text);
                    }
                }
            }
        }
    }

    /** Finds a sheet by name, ignoring case; the Persian name of a master-template sheet is accepted too. */
    static SheetReader find(Workbook wb, String wanted) {
        for (String name : FaNames.sheetNames(wanted)) {
            for (int i = 0; i < wb.getNumberOfSheets(); i++) {
                Sheet s = wb.getSheetAt(i);
                if (normalize(s.getSheetName()).equals(normalize(name))) {
                    return new SheetReader(s.getSheetName(), s);
                }
            }
        }
        return null;
    }

    static boolean has(Workbook wb, String wanted) {
        return find(wb, wanted) != null;
    }

    static String normalize(String s) {
        StringBuilder b = new StringBuilder();
        for (char ch : s.toLowerCase(Locale.ROOT).toCharArray()) {
            if (ch == '‌' || Character.isWhitespace(ch) || ch == '_' || ch == '-' || ch == ' ') {
                continue;
            }
            if (ch == 'ي') {
                ch = 'ی'; // Arabic yeh -> Persian yeh
            } else if (ch == 'ك') {
                ch = 'ک'; // Arabic kaf -> Persian kaf
            }
            b.append(ch);
        }
        return b.toString();
    }

    boolean hasColumn(String column) {
        return columns.containsKey(normalize(column));
    }

    /** Data rows (everything below the header) that contain at least one non-empty cell. */
    List<Row> rows() {
        List<Row> out = new ArrayList<>();
        for (int i = sheet.getFirstRowNum() + 1; i <= sheet.getLastRowNum(); i++) {
            Row r = sheet.getRow(i);
            if (r == null) {
                continue;
            }
            boolean any = false;
            for (Cell c : r) {
                if (text(c) != null) {
                    any = true;
                    break;
                }
            }
            if (any) {
                out.add(r);
            }
        }
        return out;
    }

    /** Excel row number as the user sees it. */
    static int rowNumber(Row r) {
        return r.getRowNum() + 1;
    }

    String str(Row r, String column) {
        Integer idx = columns.get(normalize(column));
        if (idx == null) {
            return null;
        }
        return text(r.getCell(idx));
    }

    /** Parses a number; returns null when empty. Adds an error and returns null when not numeric. */
    Double number(Row r, String column, ParsedData sink) {
        Integer idx = columns.get(normalize(column));
        if (idx == null) {
            return null;
        }
        Cell c = r.getCell(idx);
        if (c == null) {
            return null;
        }
        CellType type = c.getCellType() == CellType.FORMULA ? c.getCachedFormulaResultType() : c.getCellType();
        if (type == CellType.NUMERIC && !DateUtil.isCellDateFormatted(c)) {
            return c.getNumericCellValue();
        }
        String t = text(c);
        if (t == null) {
            return null;
        }
        try {
            return Double.parseDouble(persianDigitsToAscii(t).replace(",", "").replace("٫", "."));
        } catch (NumberFormatException e) {
            sink.error(name, rowNumber(r), columnLabel(column), "'" + t + "' is not a number");
            return null;
        }
    }

    String columnLabel(String column) {
        return displayNames.getOrDefault(normalize(column), column);
    }

    private static String text(Cell c) {
        if (c == null) {
            return null;
        }
        CellType type = c.getCellType() == CellType.FORMULA ? c.getCachedFormulaResultType() : c.getCellType();
        String s = switch (type) {
            case STRING -> c.getStringCellValue();
            case NUMERIC -> DateUtil.isCellDateFormatted(c)
                    ? c.getLocalDateTimeCellValue().toString()
                    : BigDecimal.valueOf(c.getNumericCellValue()).stripTrailingZeros().toPlainString();
            case BOOLEAN -> c.getBooleanCellValue() ? "TRUE" : "FALSE";
            default -> null;
        };
        if (s == null) {
            return null;
        }
        s = s.trim();
        return s.isEmpty() ? null : s;
    }

    static String persianDigitsToAscii(String s) {
        StringBuilder b = new StringBuilder(s.length());
        for (char ch : s.toCharArray()) {
            if (ch >= '۰' && ch <= '۹') {
                b.append((char) ('0' + (ch - '۰')));
            } else if (ch >= '٠' && ch <= '٩') {
                b.append((char) ('0' + (ch - '٠')));
            } else {
                b.append(ch);
            }
        }
        return b.toString();
    }
}
