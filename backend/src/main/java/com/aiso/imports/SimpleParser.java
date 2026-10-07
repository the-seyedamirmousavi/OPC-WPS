package com.aiso.imports;

import com.aiso.domain.DependencyType;
import com.aiso.imports.ParsedData.ItemRow;
import com.aiso.imports.ParsedData.OpRow;
import com.aiso.imports.ParsedData.PredRow;
import com.aiso.imports.ParsedData.ResRow;
import org.apache.poi.ss.usermodel.Row;
import org.apache.poi.ss.usermodel.Workbook;

import java.util.HashMap;
import java.util.LinkedHashSet;
import java.util.Map;
import java.util.Set;

/**
 * Parses the compact two-sheet layout used by the first sample file (Persian headers):
 * <pre>
 * sheet "opc":    row no | name | spec | code | quantity | step order | step | predecessor rows | resource | direct hours
 * sheet "source": row no | resource name | simultaneous resources
 * </pre>
 * Predecessors reference the "row no" column of the same sheet ("0" or empty = none, "3,8" = rows 3 and 8).
 * Direct hours are taken as the total duration of the operation (not multiplied by quantity).
 */
final class SimpleParser {

    private static final String ROW_NO = "ردیف";
    private static final String NAME = "نام";
    private static final String SPEC = "مشخصات";
    private static final String CODE = "کد";
    private static final String QTY = "تعداد";
    private static final String STEP = "مرحله";
    private static final String PRED = "پیش نیازی از ردیف";
    private static final String RESOURCE = "منابع";
    private static final String HOURS = "زمان مستقیم ساعت";

    private static final String RES_NAME = "نام منابع";
    private static final String RES_CAPACITY = "تعداد منابع همزمان";

    private SimpleParser() {
    }

    static boolean matches(Workbook wb) {
        return SheetReader.has(wb, "opc") && SheetReader.has(wb, "source") && !SheetReader.has(wb, "Resources");
    }

    static ParsedData parse(Workbook wb) {
        ParsedData d = new ParsedData();
        d.format = "SIMPLE";
        d.resourceSheet = "source";
        d.itemSheet = "opc";
        d.opSheet = "opc";
        d.predSheet = "opc";
        d.predColumn = PRED;
        d.hasPredecessorSheet = true;

        SheetReader src = SheetReader.find(wb, "source");
        SheetReader opc = SheetReader.find(wb, "opc");
        require(src, d, RES_NAME, RES_CAPACITY);
        require(opc, d, ROW_NO, NAME, CODE, STEP, PRED, RESOURCE, HOURS);
        if (!d.errors.isEmpty()) {
            return d;
        }

        Map<String, String> resourceIdByName = new HashMap<>();
        for (Row r : src.rows()) {
            int n = SheetReader.rowNumber(r);
            String name = src.str(r, RES_NAME);
            if (name == null) {
                d.error(src.name, n, src.columnLabel(RES_NAME), "Resource name is required");
                continue;
            }
            Double cap = src.number(r, RES_CAPACITY, d);
            int capacity = cap == null ? 1 : (int) Math.round(cap);
            if (capacity < 1) {
                d.error(src.name, n, src.columnLabel(RES_CAPACITY), "Simultaneous resources must be >= 1");
                capacity = 1;
            }
            String id = "RES-" + pad(src.str(r, ROW_NO) != null ? src.str(r, ROW_NO) : String.valueOf(n - 1));
            if (resourceIdByName.putIfAbsent(name, id) != null) {
                d.error(src.name, n, src.columnLabel(RES_NAME), "Duplicate resource name '" + name + "'");
                continue;
            }
            d.resources.add(new ResRow(n, id, name, "WORK_CENTER", null, capacity, "ACTIVE", null));
        }

        Set<String> rowNumbers = new LinkedHashSet<>();
        Set<String> itemIds = new LinkedHashSet<>();
        for (Row r : opc.rows()) {
            int n = SheetReader.rowNumber(r);
            String rowNo = opc.str(r, ROW_NO);
            if (rowNo == null) {
                d.error(opc.name, n, opc.columnLabel(ROW_NO), "Row number is required");
                continue;
            }
            if (!rowNumbers.add(rowNo)) {
                d.error(opc.name, n, opc.columnLabel(ROW_NO), "Duplicate row number " + rowNo);
                continue;
            }
            String opId = "OP-" + pad(rowNo);
            String name = opc.str(r, NAME);
            String spec = opc.str(r, SPEC);
            String code = opc.str(r, CODE);
            String step = opc.str(r, STEP);
            String resourceName = opc.str(r, RESOURCE);
            if (step == null) {
                d.error(opc.name, n, opc.columnLabel(STEP), "Step (operation name) is required");
            }

            String itemId = null;
            if (code != null) {
                itemId = "ITM-" + code;
                if (itemIds.add(itemId)) {
                    Double qty = opc.number(r, QTY, d);
                    String itemName = (name == null ? code : name) + (spec == null ? "" : " " + spec);
                    d.items.add(new ItemRow(n, itemId, itemName, qty == null ? 1 : qty, null, null, null, null));
                }
            }

            String resourceId = null;
            if (resourceName == null) {
                d.error(opc.name, n, opc.columnLabel(RESOURCE), "Resource is required");
            } else {
                resourceId = resourceIdByName.get(resourceName);
                if (resourceId == null) {
                    d.error(opc.name, n, opc.columnLabel(RESOURCE),
                            "Resource '" + resourceName + "' is not defined in sheet '" + src.name + "'");
                }
            }

            Double hours = opc.number(r, HOURS, d);
            if (hours != null && hours < 0) {
                d.error(opc.name, n, opc.columnLabel(HOURS), "Time cannot be negative");
                hours = 0.0;
            }
            String opName = (step == null ? opId : step) + (name == null ? "" : " - " + name + (spec == null ? "" : " " + spec));
            d.ops.add(new OpRow(n, opId, opName, itemId, resourceId, null, 0, 0, 0, hours == null ? 0 : hours, null, null));
        }

        // second pass: predecessors refer to row numbers
        for (Row r : opc.rows()) {
            int n = SheetReader.rowNumber(r);
            String rowNo = opc.str(r, ROW_NO);
            String preds = opc.str(r, PRED);
            if (rowNo == null || preds == null) {
                continue;
            }
            for (String token : preds.split("[,،;\\s]+")) {
                String t = SheetReader.persianDigitsToAscii(token).trim();
                if (t.isEmpty() || t.equals("0")) {
                    continue;
                }
                if (!rowNumbers.contains(t)) {
                    d.error(opc.name, n, opc.columnLabel(PRED), "Predecessor row " + t + " does not exist");
                    continue;
                }
                d.preds.add(new PredRow(n, "OP-" + pad(rowNo), "OP-" + pad(t), DependencyType.FINISH_TO_START, null, true, null));
            }
        }
        return d;
    }

    private static void require(SheetReader s, ParsedData d, String... columns) {
        for (String c : columns) {
            if (!s.hasColumn(c)) {
                d.error(s.name, 1, c, "Required column '" + c + "' is missing");
            }
        }
    }

    private static String pad(String number) {
        String digits = SheetReader.persianDigitsToAscii(number).trim();
        return digits.length() >= 3 ? digits : "0".repeat(3 - digits.length()) + digits;
    }
}
