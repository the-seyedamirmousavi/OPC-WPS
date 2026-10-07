"""
Generates the Excel files used by TEST-SCENARIOS.md (standard library only, no openpyxl needed).

    python test-data/generate_test_data.py

All files use the master layout (sheets Resources, OPC, Predecessors). Users are NOT included, so the seeded
accounts user1/user2/user3 stay the executive users. Times are hours (Direct_Time only, the other times are 0).
"""
import os
import zipfile
from xml.sax.saxutils import escape

OUT = os.path.dirname(os.path.abspath(__file__))


def col(i):
    return chr(ord("A") + i)


def sheet_xml(rows):
    out = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
           '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>']
    for r, row in enumerate(rows, start=1):
        out.append(f'<row r="{r}">')
        for c, v in enumerate(row):
            ref = f"{col(c)}{r}"
            if v is None or v == "":
                continue
            if isinstance(v, (int, float)) and not isinstance(v, bool):
                out.append(f'<c r="{ref}"><v>{v}</v></c>')
            else:
                out.append(f'<c r="{ref}" t="inlineStr"><is><t>{escape(str(v))}</t></is></c>')
        out.append("</row>")
    out.append("</sheetData></worksheet>")
    return "".join(out)


def write_xlsx(name, sheets):
    """sheets: ordered dict-like list of (sheet name, rows)"""
    path = os.path.join(OUT, name)
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("[Content_Types].xml",
                   '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                   '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                   '<Default Extension="xml" ContentType="application/xml"/>'
                   '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                   + "".join(f'<Override PartName="/xl/worksheets/sheet{i}.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                             for i in range(1, len(sheets) + 1)) + "</Types>")
        z.writestr("_rels/.rels",
                   '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                   '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>')
        z.writestr("xl/workbook.xml",
                   '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                   'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
                   + "".join(f'<sheet name="{escape(n)}" sheetId="{i}" r:id="rId{i}"/>' for i, (n, _) in enumerate(sheets, start=1))
                   + "</sheets></workbook>")
        z.writestr("xl/_rels/workbook.xml.rels",
                   '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                   + "".join(f'<Relationship Id="rId{i}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet{i}.xml"/>'
                             for i in range(1, len(sheets) + 1)) + "</Relationships>")
        for i, (_, rows) in enumerate(sheets, start=1):
            z.writestr(f"xl/worksheets/sheet{i}.xml", sheet_xml(rows))
    print("wrote", name)


RES_H = ["Resource_ID", "Resource_Name", "Capacity", "Responsible_User_ID"]
OPC_H = ["Operation_ID", "Operation_Name", "Resource_ID", "Responsible_User_ID", "Direct_Time"]
PRED_H = ["Operation_ID", "Predecessor_Operation_ID", "Dependency_Type", "Is_Mandatory"]


def master(name, resources, ops, preds=()):
    """resources: (id, name, capacity, responsible?)  ops: (id, name, resource, fixed_user?, hours)  preds: (op, pred[, type[, mandatory]])"""
    write_xlsx(name, [
        ("Resources", [RES_H] + [list(r) for r in resources]),
        ("OPC", [OPC_H] + [list(o) for o in ops]),
        ("Predecessors", [PRED_H] + [list(p) for p in preds]),
    ])


# ---------------------------------------------------------------------------------------------- scheduling (S)
master("S01-sequential.xlsx",
       [("R1", "Machine 1", 1, "")],
       [("A", "Task A", "R1", "", 4), ("B", "Task B", "R1", "", 3), ("C", "Task C", "R1", "", 2)],
       [("B", "A"), ("C", "B")])

master("S02a-parallel-capacity2.xlsx",
       [("R1", "Machine 1", 2, "")],
       [("A", "Task A", "R1", "", 5), ("B", "Task B", "R1", "", 5)])

master("S02b-parallel-capacity1.xlsx",
       [("R1", "Machine 1", 1, "")],
       [("A", "Task A", "R1", "", 5), ("B", "Task B", "R1", "", 5)])

master("S03-join.xlsx",
       [("R1", "Machine 1", 1, ""), ("R2", "Machine 2", 1, ""), ("R3", "Machine 3", 1, "")],
       [("A", "Task A", "R1", "", 3), ("B", "Task B", "R2", "", 6), ("C", "Task C", "R3", "", 2)],
       [("C", "A"), ("C", "B")])

master("S04-critical-first.xlsx",
       [("R", "Machine R", 1, ""), ("S", "Machine S", 1, "")],
       [("Z", "Task Z", "R", "", 5), ("X", "Task X", "R", "", 5), ("Y", "Task Y", "S", "", 20)],
       [("Y", "X")])

master("S05-gap-fill.xlsx",
       [("R", "Machine R", 1, ""), ("S", "Machine S", 1, "")],
       [("X", "Task X", "S", "", 10), ("A", "Task A", "R", "", 5), ("B", "Task B", "R", "", 4)],
       [("A", "X")])

master("S06-gap-too-small.xlsx",
       [("R", "Machine R", 1, ""), ("S", "Machine S", 1, "")],
       [("X", "Task X", "S", "", 3), ("A", "Task A", "R", "", 5), ("B", "Task B", "R", "", 4)],
       [("A", "X")])

master("S07-long-chain.xlsx",
       [("R", "Machine R", 1, "")],
       [(f"O{i}", f"Step {i}", "R", "", 2) for i in range(1, 7)],
       [(f"O{i}", f"O{i-1}") for i in range(2, 7)])

master("S08-overload.xlsx",
       [("R", "Machine R", 2, "")],
       [(f"T{i}", f"Task {i}", "R", "", 3) for i in range(1, 5)])

master("S09-start-to-start.xlsx",
       [("R1", "Machine 1", 1, ""), ("R2", "Machine 2", 1, "")],
       [("A", "Task A", "R1", "", 10), ("B", "Task B", "R2", "", 4)],
       [("B", "A", "START_TO_START", "TRUE")])

master("S10-optional-dependency.xlsx",
       [("R1", "Machine 1", 1, ""), ("R2", "Machine 2", 1, "")],
       [("A", "Task A", "R1", "", 10), ("B", "Task B", "R2", "", 4)],
       [("B", "A", "FINISH_TO_START", "FALSE")])

# two-step running example: A (8h) is started by a user and set to 50%; B waits for the same single machine
master("S11-running-task.xlsx",
       [("R", "Machine R", 1, "")],
       [("A", "Task A", "R", "", 8), ("B", "Task B", "R", "", 2)])

# ---------------------------------------------------------------------------------------------- assignment (A)
master("A1-three-equal.xlsx",
       [("R", "Machine R", 5, "")],
       [("A", "Task A", "R", "", 10), ("B", "Task B", "R", "", 10), ("C", "Task C", "R", "", 10)])

master("A3-task-limit.xlsx",
       [("R", "Machine R", 10, "")],
       [("T1", "Task 1", "R", "", 8), ("T2", "Task 2", "R", "", 7), ("T3", "Task 3", "R", "", 6), ("T4", "Task 4", "R", "", 5)])

master("A4-capacity.xlsx",
       [("R", "Machine R", 1, "")],
       [("C1", "Task C1", "R", "", 6), ("C2", "Task C2", "R", "", 5)])

master("A5-critical-path.xlsx",
       [("R", "Machine R", 1, ""), ("S", "Machine S", 1, "")],
       [("C1", "Task C1", "R", "", 6), ("C2", "Task C2", "R", "", 5), ("D", "Task D", "S", "", 20)],
       [("D", "C2")])

master("A6-fixed-executor.xlsx",
       [("R", "Machine R", 5, "")],
       [("F", "Task F", "R", "user3", 3), ("G", "Task G", "R", "", 3)])

master("A7a-responsible-user-no-win.xlsx",
       [("Q", "Machine Q", 1, ""), ("R", "Machine R", 5, "user2")],
       [("P", "Task P", "Q", "user2", 10), ("N", "Task N", "R", "", 4)])

master("A7b-responsible-user-wins.xlsx",
       [("Q", "Machine Q", 1, ""), ("R", "Machine R", 5, "user2")],
       [("P", "Task P", "Q", "user2", 5), ("N", "Task N", "R", "", 4)])


# ---------------------------------------------------------------------------------------------- multi-project (M)
# Two projects on the same two single-lane machines. Project "Alpha" leaves idle gaps (WELD 10-16, PAINT 0-10) that the
# lower-priority project "Beta" can fill without delaying Alpha.
master("M1-alpha.xlsx",
       [("WELD", "Welding", 1, ""), ("PAINT", "Painting", 1, "")],
       [("A-W1", "Weld frame", "WELD", "", 10), ("A-P1", "Paint frame", "PAINT", "", 6), ("A-W2", "Weld brackets", "WELD", "", 4)],
       [("A-P1", "A-W1"), ("A-W2", "A-P1")])

master("M2-beta.xlsx",
       [("WELD", "Welding", 1, ""), ("PAINT", "Painting", 1, "")],
       [("B-W3", "Weld small part", "WELD", "", 5), ("B-P3", "Paint panel", "PAINT", "", 8)])

# A third, very small project (to try ranking three projects).
master("M3-gamma.xlsx",
       [("WELD", "Welding", 1, ""), ("PAINT", "Painting", 1, "")],
       [("G-W1", "Weld bracket", "WELD", "", 3), ("G-P1", "Paint bracket", "PAINT", "", 2)],
       [("G-P1", "G-W1")])

# ---------------------------------------------------------------------------------------------- import validation (I)
master("I01-valid.xlsx",
       [("R-CUT", "Cutting", 1, ""), ("R-WELD", "Welding", 2, "user1"), ("R-PAINT", "Painting", 1, "")],
       [("OP-1", "Cut plates", "R-CUT", "", 6), ("OP-2", "Weld frame", "R-WELD", "", 8), ("OP-3", "Paint frame", "R-PAINT", "", 4)],
       [("OP-2", "OP-1"), ("OP-3", "OP-2")])

write_xlsx("I02-missing-column.xlsx", [
    ("Resources", [RES_H, ["R1", "Machine 1", 1, ""]]),
    ("OPC", [["Operation_ID", "Operation_Name", "Direct_Time"], ["A", "Task A", 4]]),   # no Resource_ID column
])

write_xlsx("I03-missing-sheet.xlsx", [
    ("Resources", [RES_H, ["R1", "Machine 1", 1, ""]]),
    ("Other", [["x"], ["y"]]),
])

write_xlsx("I04-invalid-values.xlsx", [
    ("Resources", [RES_H, ["R1", "Machine 1", 0, ""], ["R2", "Machine 2", "two", ""]]),
    ("OPC", [OPC_H + ["Operation_Status"],
             ["A", "Task A", "R1", "", "abc", ""],      # non-numeric duration
             ["B", "Task B", "R1", "", -5, ""],         # negative duration
             ["C", "Task C", "R1", "", 3, "RUNNING"],   # unknown status
             ["D", "Task D", "R1", "", 3, "IN_PROGRESS"]]),   # status not allowed as initial
])

write_xlsx("I05-bad-references.xlsx", [   # cross-reference errors (second validation layer)
    ("Resources", [RES_H, ["R1", "Machine 1", 1, ""]]),
    ("OPC", [OPC_H, ["A", "Task A", "R1", "", 2], ["B", "Task B", "R1", "", 2], ["C", "Task C", "R-NOPE", "", 2]]),
    ("Predecessors", [PRED_H, ["A", "GHOST", "", ""], ["B", "B", "", ""], ["B", "A", "", ""], ["B", "A", "", ""]]),
])

write_xlsx("I05b-bad-dependency-type.xlsx", [   # cell-level error (first validation layer)
    ("Resources", [RES_H, ["R1", "Machine 1", 1, ""]]),
    ("OPC", [OPC_H, ["A", "Task A", "R1", "", 2], ["B", "Task B", "R1", "", 2]]),
    ("Predecessors", [PRED_H, ["B", "A", "SIDEWAYS", ""]]),
])

master("I06-cycle.xlsx",
       [("R1", "Machine 1", 1, "")],
       [("A", "Task A", "R1", "", 2), ("B", "Task B", "R1", "", 2), ("C", "Task C", "R1", "", 2)],
       [("A", "C"), ("B", "A"), ("C", "B")])

master("I07-duplicates.xlsx",
       [("R1", "Machine 1", 1, ""), ("R1", "Machine 1 again", 1, "")],
       [("A", "Task A", "R1", "", 2), ("A", "Task A copy", "R1", "", 3)])

master("I08-headers-only.xlsx", [], [], [])

write_xlsx("I09-partial-bad-row.xlsx", [   # 4 good rows + 1 bad row: nothing may be imported
    ("Resources", [RES_H, ["R1", "Machine 1", 1, ""]]),
    ("OPC", [OPC_H, ["PB-1", "ok", "R1", "", 1], ["PB-2", "ok", "R1", "", 1], ["PB-3", "ok", "R1", "", 1],
             ["PB-4", "ok", "R1", "", 1], ["PB-5", "bad resource", "NOPE", "", 1]]),
])

write_xlsx("I10-users-owner.xlsx", [
    ("Resources", [RES_H, ["R1", "Machine 1", 1, ""]]),
    ("OPC", [OPC_H, ["A", "Task A", "R1", "", 2]]),
    ("Users", [["User_ID", "Full_Name", "Role", "Active_Status"], ["boss", "Boss", "OWNER", "TRUE"], ["m2", "Second Manager", "MANAGER", "TRUE"]]),
])

# ---------------------------------------------------------------------------------------------- not-Excel files
with open(os.path.join(OUT, "I11-empty.xlsx"), "wb"):
    pass
print("wrote I11-empty.xlsx (0 bytes)")
with open(os.path.join(OUT, "I12-malformed.xlsx"), "wb") as f:
    f.write(b"PK\x03\x04this is not really a workbook" + bytes(range(256)))
print("wrote I12-malformed.xlsx")
with open(os.path.join(OUT, "I13-not-excel.txt"), "w", encoding="utf8") as f:
    f.write("Operation_ID,Operation_Name\nA,Task A\n")
print("wrote I13-not-excel.txt")
