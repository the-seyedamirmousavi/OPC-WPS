package com.aiso.imports;

import com.aiso.domain.DependencyType;
import com.aiso.domain.OperationStatus;
import com.aiso.domain.Role;
import com.aiso.imports.ParsedData.ItemRow;
import com.aiso.imports.ParsedData.OpRow;
import com.aiso.imports.ParsedData.PredRow;
import com.aiso.imports.ParsedData.ResRow;
import com.aiso.imports.ParsedData.SettingRow;
import com.aiso.imports.ParsedData.UserRow;
import org.apache.poi.ss.usermodel.Row;
import org.apache.poi.ss.usermodel.Workbook;

import java.util.List;
import java.util.Locale;
import java.util.Set;

/** Parses the master template (sheets Resources, BOM, OPC, Predecessors, Users, Settings). */
final class MasterParser {

    private static final Set<OperationStatus> INITIAL_STATUSES = Set.of(
            OperationStatus.NOT_READY, OperationStatus.READY, OperationStatus.COMPLETED, OperationStatus.CANCELLED);

    private MasterParser() {
    }

    static ParsedData parse(Workbook wb) {
        ParsedData d = new ParsedData();
        d.format = "MASTER";

        resources(wb, d);
        bom(wb, d);
        opc(wb, d);
        predecessors(wb, d);
        users(wb, d);
        settings(wb, d);
        return d;
    }

    private static SheetReader sheet(Workbook wb, ParsedData d, String name, boolean required, List<String> mustHave) {
        SheetReader s = SheetReader.find(wb, name);
        if (s == null) {
            if (required) {
                d.error(name, 1, "-", "Required sheet '" + name + "' is missing");
            } else {
                d.warnings.add("Sheet '" + name + "' is not in the file; existing " + name + " data is left unchanged.");
            }
            return null;
        }
        boolean ok = true;
        for (String col : mustHave) {
            if (!s.hasColumn(col)) {
                d.error(s.name, 1, col, "Required column '" + col + "' is missing");
                ok = false;
            }
        }
        return ok ? s : null;
    }

    private static void resources(Workbook wb, ParsedData d) {
        SheetReader s = sheet(wb, d, "Resources", true, List.of("Resource_ID", "Resource_Name"));
        if (s == null) {
            return;
        }
        for (Row r : s.rows()) {
            int n = SheetReader.rowNumber(r);
            String id = s.str(r, "Resource_ID");
            String name = s.str(r, "Resource_Name");
            if (id == null) {
                d.error(s.name, n, "Resource_ID", "Resource_ID is required");
                continue;
            }
            if (name == null) {
                d.error(s.name, n, "Resource_Name", "Resource_Name is required");
            }
            Double cap = s.number(r, "Capacity", d);
            int capacity = cap == null ? 1 : (int) Math.round(cap);
            if (cap != null && (cap < 1 || cap != Math.rint(cap))) {
                d.error(s.name, n, "Capacity", "Capacity must be a whole number >= 1");
                capacity = 1;
            }
            String status = upper(s.str(r, "Status"), "ACTIVE");
            if (!status.equals("ACTIVE") && !status.equals("INACTIVE")) {
                d.error(s.name, n, "Status", "Status must be ACTIVE or INACTIVE");
                status = "ACTIVE";
            }
            d.resources.add(new ResRow(n, id, name == null ? id : name, s.str(r, "Resource_Type"),
                    s.str(r, "Responsible_User_ID"), capacity, status, s.str(r, "Description")));
        }
    }

    private static void bom(Workbook wb, ParsedData d) {
        SheetReader s = sheet(wb, d, "BOM", false, List.of("Item_ID", "Item_Name"));
        if (s == null) {
            return;
        }
        for (Row r : s.rows()) {
            int n = SheetReader.rowNumber(r);
            String id = s.str(r, "Item_ID");
            String name = s.str(r, "Item_Name");
            if (id == null) {
                d.error(s.name, n, "Item_ID", "Item_ID is required");
                continue;
            }
            if (name == null) {
                d.error(s.name, n, "Item_Name", "Item_Name is required");
            }
            Double qty = s.number(r, "Quantity", d);
            if (qty != null && qty < 0) {
                d.error(s.name, n, "Quantity", "Quantity cannot be negative");
            }
            d.items.add(new ItemRow(n, id, name == null ? id : name, qty == null ? 1 : Math.max(0, qty), s.str(r, "Unit"),
                    s.str(r, "Supply_Type"), s.str(r, "Related_Operation_ID"), s.str(r, "Description")));
        }
    }

    private static void opc(Workbook wb, ParsedData d) {
        SheetReader s = sheet(wb, d, "OPC", true, List.of("Operation_ID", "Operation_Name", "Resource_ID"));
        if (s == null) {
            return;
        }
        for (Row r : s.rows()) {
            int n = SheetReader.rowNumber(r);
            String id = s.str(r, "Operation_ID");
            String name = s.str(r, "Operation_Name");
            String resource = s.str(r, "Resource_ID");
            if (id == null) {
                d.error(s.name, n, "Operation_ID", "Operation_ID is required");
                continue;
            }
            if (name == null) {
                d.error(s.name, n, "Operation_Name", "Operation_Name is required");
            }
            if (resource == null) {
                d.error(s.name, n, "Resource_ID", "Resource_ID is required");
            }
            double prep = time(s, r, "Preparation_Time", n, d);
            double transport = time(s, r, "Transport_Time", n, d);
            double setup = time(s, r, "Setup_Time", n, d);
            double direct = time(s, r, "Direct_Time", n, d);

            OperationStatus status = null;
            String st = upper(s.str(r, "Operation_Status"), null);
            if (st != null) {
                try {
                    status = OperationStatus.valueOf(st);
                    if (!INITIAL_STATUSES.contains(status)) {
                        d.error(s.name, n, "Operation_Status",
                                "Initial status must be empty, NOT_READY, READY, COMPLETED or CANCELLED (got " + st + ")");
                        status = null;
                    }
                } catch (IllegalArgumentException e) {
                    d.error(s.name, n, "Operation_Status", "Unknown status '" + st + "'");
                }
            }
            d.ops.add(new OpRow(n, id, name == null ? id : name, s.str(r, "Item_ID"), resource,
                    s.str(r, "Responsible_User_ID"), prep, transport, setup, direct, status, s.str(r, "Description")));
        }
    }

    private static double time(SheetReader s, Row r, String column, int rowNo, ParsedData d) {
        Double v = s.number(r, column, d);
        if (v == null) {
            return 0;
        }
        if (v < 0) {
            d.error(s.name, rowNo, column, "Time cannot be negative");
            return 0;
        }
        return v;
    }

    private static void predecessors(Workbook wb, ParsedData d) {
        SheetReader s = sheet(wb, d, "Predecessors", false, List.of("Operation_ID", "Predecessor_Operation_ID"));
        if (s == null) {
            return;
        }
        d.hasPredecessorSheet = true;
        for (Row r : s.rows()) {
            int n = SheetReader.rowNumber(r);
            String op = s.str(r, "Operation_ID");
            String pred = s.str(r, "Predecessor_Operation_ID");
            if (op == null || pred == null) {
                d.error(s.name, n, op == null ? "Operation_ID" : "Predecessor_Operation_ID", "Both ids are required");
                continue;
            }
            DependencyType type = DependencyType.FINISH_TO_START;
            String t = upper(s.str(r, "Dependency_Type"), null);
            if (t != null) {
                switch (t) {
                    case "FS", "FINISH_TO_START", "FINISHTOSTART" -> type = DependencyType.FINISH_TO_START;
                    case "SS", "START_TO_START", "STARTTOSTART" -> type = DependencyType.START_TO_START;
                    default -> d.error(s.name, n, "Dependency_Type",
                            "Dependency_Type must be FINISH_TO_START or START_TO_START (got " + t + ")");
                }
            }
            boolean mandatory = true;
            String m = upper(s.str(r, "Is_Mandatory"), null);
            if (m != null) {
                Boolean b = bool(m);
                if (b == null) {
                    d.error(s.name, n, "Is_Mandatory", "Is_Mandatory must be TRUE or FALSE");
                } else {
                    mandatory = b;
                }
            }
            d.preds.add(new PredRow(n, op, pred, type, s.str(r, "Start_Condition"), mandatory, s.str(r, "Description")));
        }
    }

    private static void users(Workbook wb, ParsedData d) {
        SheetReader s = sheet(wb, d, "Users", false, List.of("User_ID", "Full_Name", "Role"));
        if (s == null) {
            return;
        }
        for (Row r : s.rows()) {
            int n = SheetReader.rowNumber(r);
            String id = s.str(r, "User_ID");
            String name = s.str(r, "Full_Name");
            String roleText = upper(s.str(r, "Role"), null);
            if (id == null) {
                d.error(s.name, n, "User_ID", "User_ID is required");
                continue;
            }
            if (name == null) {
                d.error(s.name, n, "Full_Name", "Full_Name is required");
            }
            Role role = null;
            if (roleText == null) {
                d.error(s.name, n, "Role", "Role is required");
            } else {
                try {
                    role = Role.valueOf(roleText);
                } catch (IllegalArgumentException e) {
                    d.error(s.name, n, "Role", "Role must be one of OWNER, MANAGER, USER_1, USER_2, USER_3");
                }
            }
            boolean active = true;
            String a = upper(s.str(r, "Active_Status"), null);
            if (a != null) {
                Boolean b = bool(a);
                if (b == null) {
                    d.error(s.name, n, "Active_Status", "Active_Status must be TRUE/FALSE or ACTIVE/INACTIVE");
                } else {
                    active = b;
                }
            }
            if (role != null) {
                d.users.add(new UserRow(n, id, name == null ? id : name, role, s.str(r, "Messenger_ID"), active,
                        s.str(r, "Contact_Info")));
            }
        }
    }

    private static void settings(Workbook wb, ParsedData d) {
        SheetReader s = sheet(wb, d, "Settings", false, List.of());
        if (s == null) {
            return;
        }
        List<Row> rows = s.rows();
        if (rows.isEmpty()) {
            return;
        }
        if (rows.size() > 1) {
            d.error(s.name, SheetReader.rowNumber(rows.get(1)), "-", "The Settings sheet must contain exactly one data row");
        }
        Row r = rows.get(0);
        d.settings = new SettingRow(SheetReader.rowNumber(r), s.str(r, "Project_ID"), s.str(r, "Project_Name"),
                s.str(r, "Owner_ID"), s.str(r, "Manager_ID"), s.str(r, "Messenger_Platform"), s.str(r, "Data_Version"));
    }

    private static String upper(String s, String fallback) {
        return s == null ? fallback : FaNames.value(s.trim()).trim().toUpperCase(Locale.ROOT).replace(' ', '_');
    }

    private static Boolean bool(String upper) {
        return switch (upper) {
            case "TRUE", "YES", "Y", "1", "ACTIVE" -> true;
            case "FALSE", "NO", "N", "0", "INACTIVE" -> false;
            default -> null;
        };
    }
}
