package com.aiso;

import com.jayway.jsonpath.JsonPath;
import org.apache.poi.ss.usermodel.Row;
import org.apache.poi.ss.usermodel.Sheet;
import org.apache.poi.ss.usermodel.Workbook;
import org.apache.poi.xssf.usermodel.XSSFWorkbook;
import org.junit.jupiter.api.MethodOrderer;
import org.junit.jupiter.api.Order;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.TestMethodOrder;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.boot.webmvc.test.autoconfigure.AutoConfigureMockMvc;
import org.springframework.http.MediaType;
import org.springframework.mock.web.MockMultipartFile;
import org.springframework.test.web.servlet.MockMvc;
import org.springframework.test.web.servlet.MvcResult;
import org.springframework.test.web.servlet.request.AbstractMockHttpServletRequestBuilder;

import java.io.ByteArrayOutputStream;
import java.util.List;

import static org.assertj.core.api.Assertions.assertThat;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.multipart;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.put;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

/**
 * End-to-end scenario through the real HTTP layer, security and an in-memory database, driven by the sample
 * workbook delivered with the project documentation.
 */
@SpringBootTest(properties = {
        "spring.datasource.url=jdbc:h2:mem:aiso-it;MODE=PostgreSQL;DATABASE_TO_LOWER=TRUE;DB_CLOSE_DELAY=-1",
        "aiso.jwt.secret=integration-test-secret-integration-test-secret",
        "aiso.seed.password=Passw0rd!test",
        "aiso.seed.language=en",
        "aiso.llm.api-key="
})
@AutoConfigureMockMvc
@TestMethodOrder(MethodOrderer.OrderAnnotation.class)
class AisoFlowIntegrationTest {

    private static final String PASSWORD = "Passw0rd!test";

    @Autowired
    MockMvc mvc;

    private String login(String user) throws Exception {
        MvcResult r = mvc.perform(post("/api/auth/login").contentType(MediaType.APPLICATION_JSON)
                .content("{\"userId\":\"" + user + "\",\"password\":\"" + PASSWORD + "\"}"))
                .andExpect(status().isOk()).andReturn();
        return JsonPath.read(r.getResponse().getContentAsString(), "$.token");
    }

    private <B extends AbstractMockHttpServletRequestBuilder<B>> B as(B b, String token) {
        return b.header("Authorization", "Bearer " + token);
    }

    private String body(MvcResult r) throws Exception {
        return r.getResponse().getContentAsString();
    }

    @Test
    @Order(1)
    void authenticationAndRoleBoundaries() throws Exception {
        mvc.perform(post("/api/auth/login").contentType(MediaType.APPLICATION_JSON)
                .content("{\"userId\":\"owner\",\"password\":\"wrong-password\"}")).andExpect(status().isUnauthorized());
        mvc.perform(get("/api/operations")).andExpect(status().isUnauthorized());

        String user1 = login("user1");
        mvc.perform(as(get("/api/operations"), user1)).andExpect(status().isForbidden());
        mvc.perform(as(get("/api/dashboard/manager"), user1)).andExpect(status().isForbidden());
        mvc.perform(as(get("/api/users"), user1)).andExpect(status().isForbidden());

        String manager = login("manager");
        mvc.perform(as(get("/api/users"), manager)).andExpect(status().isOk());
        mvc.perform(as(post("/api/users").contentType(MediaType.APPLICATION_JSON)
                .content("{\"id\":\"x1\",\"fullName\":\"X\",\"role\":\"USER_1\",\"password\":\"abcdefgh1\"}"), manager))
                .andExpect(status().isForbidden());
        mvc.perform(as(post("/api/admin/reset").contentType(MediaType.APPLICATION_JSON)
                .content("{\"reason\":\"x\",\"confirm\":\"RESET\"}"), manager)).andExpect(status().isForbidden());
    }

    @Test
    @Order(2)
    void sampleWorkbookImportsAndDependenciesDecideReadiness() throws Exception {
        String owner = login("owner");
        MvcResult r = mvc.perform(as(post("/api/admin/demo-data"), owner)).andExpect(status().isOk()).andReturn();
        assertThat((String) JsonPath.read(body(r), "$.status")).isEqualTo("APPLIED");
        assertThat((String) JsonPath.read(body(r), "$.format")).isEqualTo("SIMPLE");

        String manager = login("manager");
        MvcResult dash = mvc.perform(as(get("/api/dashboard/manager"), manager)).andExpect(status().isOk()).andReturn();
        assertThat((Integer) JsonPath.read(body(dash), "$.progress.totalOperations")).isEqualTo(16);
        // operations without predecessors: rows 1, 4, 9, 10, 11 of the sample
        assertThat((Integer) JsonPath.read(body(dash), "$.statusCounts.READY")).isEqualTo(5);
        assertThat((Integer) JsonPath.read(body(dash), "$.statusCounts.NOT_READY")).isEqualTo(11);

        // OP-014 (assembly) needs rows 3 and 8: it must explain what it is waiting for
        MvcResult op = mvc.perform(as(get("/api/operations/OP-014"), manager)).andExpect(status().isOk()).andReturn();
        List<String> waiting = JsonPath.read(body(op), "$.operation.waitingFor[*].id");
        assertThat(waiting).containsExactlyInAnyOrder("OP-003", "OP-008");
    }

    @Test
    @Order(3)
    void algorithmProposesApprovesAndExecutorsDriveTheDependencyChain() throws Exception {
        String manager = login("manager");
        MvcResult run = mvc.perform(as(post("/api/assignments/suggest").contentType(MediaType.APPLICATION_JSON)
                .content("{\"mode\":\"ALGORITHM\"}"), manager)).andExpect(status().isOk()).andReturn();
        assertThat((String) JsonPath.read(body(run), "$.effectiveMode")).isEqualTo("ALGORITHM");
        assertThat((Boolean) JsonPath.read(body(run), "$.fallback")).isFalse();

        // each of the two single-slot resources (outsourcing, purchasing) takes exactly one operation:
        // the one with the longest remaining chain (OP-001 chain 50h vs OP-011 25h; OP-004 chain 96h vs 56h)
        List<String> proposed = JsonPath.read(body(run), "$.proposals[*].operationId");
        assertThat(proposed).containsExactlyInAnyOrder("OP-001", "OP-004");
        List<Integer> ids = JsonPath.read(body(run), "$.proposals[*].id");

        mvc.perform(as(post("/api/assignments/approve").contentType(MediaType.APPLICATION_JSON)
                .content("{\"proposalIds\":" + ids + "}"), manager)).andExpect(status().isOk());

        MvcResult op1 = mvc.perform(as(get("/api/operations/OP-001"), manager)).andReturn();
        assertThat((String) JsonPath.read(body(op1), "$.operation.status")).isEqualTo("ASSIGNED");
        String assignee = JsonPath.read(body(op1), "$.operation.assignedUserId");
        String other = assignee.equals("user1") ? "user2" : "user1";

        String assigneeToken = login(assignee);
        // somebody else's task is invisible and untouchable
        mvc.perform(as(post("/api/operations/OP-001/start"), login(other))).andExpect(status().isForbidden());
        mvc.perform(as(get("/api/operations/OP-001"), login(other))).andExpect(status().isForbidden());
        // cannot complete before starting
        mvc.perform(as(post("/api/operations/OP-001/complete"), assigneeToken)).andExpect(status().isConflict());

        mvc.perform(as(post("/api/operations/OP-001/start"), assigneeToken)).andExpect(status().isOk());
        mvc.perform(as(post("/api/operations/OP-001/progress").contentType(MediaType.APPLICATION_JSON)
                .content("{\"percent\":50,\"note\":\"half way\"}"), assigneeToken)).andExpect(status().isOk());
        mvc.perform(as(post("/api/operations/OP-001/complete").contentType(MediaType.APPLICATION_JSON)
                .content("{\"note\":\"done\"}"), assigneeToken)).andExpect(status().isOk());

        // OP-002 (weld) only needed OP-001: it is READY now; OP-003 still waits for OP-002
        MvcResult op2 = mvc.perform(as(get("/api/operations/OP-002"), manager)).andReturn();
        assertThat((String) JsonPath.read(body(op2), "$.operation.status")).isEqualTo("READY");
        MvcResult op3 = mvc.perform(as(get("/api/operations/OP-003"), manager)).andReturn();
        assertThat((String) JsonPath.read(body(op3), "$.operation.status")).isEqualTo("NOT_READY");

        // the executor sees the work in the personal dashboard and got a notification
        MvcResult mine = mvc.perform(as(get("/api/dashboard/user"), assigneeToken)).andExpect(status().isOk()).andReturn();
        assertThat((Integer) JsonPath.read(body(mine), "$.performance.completed")).isEqualTo(1);
        assertThat((Integer) JsonPath.read(body(mine), "$.unreadNotifications")).isGreaterThan(0);

        // every step above left a trace with actor and time
        MvcResult audit = mvc.perform(as(get("/api/audit?entityType=Operation&entityId=OP-001"), manager)).andReturn();
        List<String> actions = JsonPath.read(body(audit), "$[*].action");
        assertThat(actions).contains("STATUS_CHANGE", "ASSIGN");
    }

    @Test
    @Order(4)
    void llmModeWithoutApiKeyFallsBackVisiblyAndModeCanBeSwitched() throws Exception {
        String manager = login("manager");
        mvc.perform(as(put("/api/settings/assignment-mode").contentType(MediaType.APPLICATION_JSON)
                .content("{\"mode\":\"LLM\"}"), manager)).andExpect(status().isOk());
        MvcResult settings = mvc.perform(as(get("/api/settings"), manager)).andReturn();
        assertThat((String) JsonPath.read(body(settings), "$.assignmentMode")).isEqualTo("LLM");
        assertThat((Boolean) JsonPath.read(body(settings), "$.llmAvailable")).isFalse();

        // no mode in the body -> uses the configured one (LLM) -> no key -> explicit fallback
        MvcResult run = mvc.perform(as(post("/api/assignments/suggest"), manager)).andExpect(status().isOk()).andReturn();
        assertThat((String) JsonPath.read(body(run), "$.requestedMode")).isEqualTo("LLM");
        assertThat((String) JsonPath.read(body(run), "$.effectiveMode")).isEqualTo("ALGORITHM");
        assertThat((Boolean) JsonPath.read(body(run), "$.fallback")).isTrue();
        List<String> warnings = JsonPath.read(body(run), "$.warnings");
        assertThat(warnings).anyMatch(w -> w.contains("ANTHROPIC_API_KEY"));

        mvc.perform(as(put("/api/settings/assignment-mode").contentType(MediaType.APPLICATION_JSON)
                .content("{\"mode\":\"ALGORITHM\"}"), manager)).andExpect(status().isOk());
    }

    @Test
    @Order(5)
    void invalidWorkbookIsRejectedWithSheetRowColumnAndNothingIsChanged() throws Exception {
        String owner = login("owner");
        byte[] bad = masterWorkbook(true);
        MvcResult r = mvc.perform(as(multipart("/api/import")
                .file(new MockMultipartFile("file", "bad.xlsx", "application/octet-stream", bad))
                .param("apply", "true"), owner)).andExpect(status().isOk()).andReturn();
        assertThat((String) JsonPath.read(body(r), "$.status")).isEqualTo("REJECTED");
        List<String> sheets = JsonPath.read(body(r), "$.errors[*].sheet");
        List<String> messages = JsonPath.read(body(r), "$.errors[*].message");
        List<Integer> rows = JsonPath.read(body(r), "$.errors[*].row");
        assertThat(sheets).contains("OPC", "Predecessors");
        assertThat(messages).anyMatch(m -> m.contains("Resource 'R-NOPE' does not exist"));
        assertThat(messages).anyMatch(m -> m.contains("Dependency cycle"));
        assertThat(rows).allMatch(n -> n >= 1);

        // nothing leaked in
        MvcResult ops = mvc.perform(as(get("/api/operations?q=BAD-"), owner)).andReturn();
        assertThat((List<?>) JsonPath.read(body(ops), "$")).isEmpty();
    }

    @Test
    @Order(8)
    void cleanMasterWorkbookAndTheDownloadedTemplateImport() throws Exception {
        String owner = login("owner");
        MvcResult tpl = mvc.perform(as(get("/api/import/template"), owner)).andExpect(status().isOk()).andReturn();

        // OP-001 is COMPLETED in the current data and the template maps it to another resource: structural
        // changes to started work are refused
        MvcResult refused = mvc.perform(as(multipart("/api/import")
                .file(new MockMultipartFile("file", "template.xlsx", "application/octet-stream", tpl.getResponse().getContentAsByteArray())),
                owner)).andExpect(status().isOk()).andReturn();
        assertThat((String) JsonPath.read(body(refused), "$.status")).isEqualTo("REJECTED");
        List<String> why = JsonPath.read(body(refused), "$.errors[*].message");
        assertThat(why).anyMatch(m -> m.contains("OP-001") && m.contains("COMPLETED"));

        // after a controlled reset the template itself must be a valid master file
        mvc.perform(as(post("/api/admin/reset").contentType(MediaType.APPLICATION_JSON)
                .content("{\"reason\":\"load template\",\"confirm\":\"RESET\"}"), owner)).andExpect(status().isOk());
        MvcResult dry = mvc.perform(as(multipart("/api/import")
                .file(new MockMultipartFile("file", "template.xlsx", "application/octet-stream", tpl.getResponse().getContentAsByteArray())),
                owner)).andExpect(status().isOk()).andReturn();
        assertThat((String) JsonPath.read(body(dry), "$.status")).as(body(dry)).isEqualTo("VALID");

        MvcResult applied = mvc.perform(as(multipart("/api/import")
                .file(new MockMultipartFile("file", "template.xlsx", "application/octet-stream", tpl.getResponse().getContentAsByteArray()))
                .param("apply", "true"), owner)).andExpect(status().isOk()).andReturn();
        assertThat((String) JsonPath.read(body(applied), "$.status")).as(body(applied)).isEqualTo("APPLIED");
        List<String> tempPasswordUsers = JsonPath.read(body(applied), "$.newUsers[*].userId");
        assertThat(tempPasswordUsers).containsExactlyInAnyOrder("USER_1", "USER_2", "USER_3");

        // re-importing the same file is an update, not a duplicate
        MvcResult again = mvc.perform(as(multipart("/api/import")
                .file(new MockMultipartFile("file", "template.xlsx", "application/octet-stream", tpl.getResponse().getContentAsByteArray()))
                .param("apply", "true"), owner)).andExpect(status().isOk()).andReturn();
        assertThat((String) JsonPath.read(body(again), "$.status")).isEqualTo("APPLIED");
        List<Integer> created = JsonPath.read(body(again), "$.counts[?(@.entity=='operations')].created");
        assertThat(created).containsExactly(0);
    }

    @Test
    @Order(6)
    void suspendedSystemBlocksEveryoneButTheOwner() throws Exception {
        String owner = login("owner");
        String manager = login("manager");
        mvc.perform(as(put("/api/admin/system-status").contentType(MediaType.APPLICATION_JSON)
                .content("{\"status\":\"SUSPENDED\",\"reason\":\"maintenance\"}"), owner)).andExpect(status().isOk());
        mvc.perform(as(get("/api/dashboard/manager"), manager)).andExpect(status().isOk());        // reads still work
        mvc.perform(as(post("/api/assignments/suggest"), manager)).andExpect(status().isLocked());   // writes do not
        mvc.perform(as(put("/api/admin/system-status").contentType(MediaType.APPLICATION_JSON)
                .content("{\"status\":\"ACTIVE\",\"reason\":\"done\"}"), owner)).andExpect(status().isOk());
        mvc.perform(as(post("/api/assignments/suggest"), manager)).andExpect(status().isOk());
    }

    @Test
    @Order(7)
    void excelReportDownloads() throws Exception {
        String manager = login("manager");
        MvcResult r = mvc.perform(as(get("/api/reports/export"), manager)).andExpect(status().isOk()).andReturn();
        try (Workbook wb = new XSSFWorkbook(new java.io.ByteArrayInputStream(r.getResponse().getContentAsByteArray()))) {
            assertThat(wb.getSheet("Operations")).isNotNull();
            assertThat(wb.getSheet("Operations").getLastRowNum()).isGreaterThan(10);
            assertThat(wb.getSheet("Audit")).isNotNull();
            assertThat(wb.getSheet("Import_History")).isNotNull();
        }
    }

    @Test
    @Order(9)
    void resetKeepsUsersAndAuditTrail() throws Exception {
        String owner = login("owner");
        mvc.perform(as(post("/api/admin/reset").contentType(MediaType.APPLICATION_JSON)
                .content("{\"reason\":\"test\",\"confirm\":\"nope\"}"), owner)).andExpect(status().isBadRequest());
        mvc.perform(as(post("/api/admin/reset").contentType(MediaType.APPLICATION_JSON)
                .content("{\"reason\":\"end of test\",\"confirm\":\"RESET\"}"), owner)).andExpect(status().isOk());
        MvcResult ops = mvc.perform(as(get("/api/operations"), owner)).andReturn();
        assertThat((List<?>) JsonPath.read(body(ops), "$")).isEmpty();
        MvcResult audit = mvc.perform(as(get("/api/audit?limit=500"), owner)).andReturn();
        List<String> actions = JsonPath.read(body(audit), "$[*].action");
        assertThat(actions).contains("SYSTEM_RESET", "IMPORT_APPLIED");
        login("user1"); // accounts survive
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    /** Master workbook with: unknown resource, unknown predecessor, and a three-operation cycle. */
    private static byte[] masterWorkbook(boolean broken) throws Exception {
        try (Workbook wb = new XSSFWorkbook()) {
            sheet(wb, "Resources", List.of("Resource_ID", "Resource_Name", "Capacity"), List.of(List.of("R-X", "Machine X", 1)));
            sheet(wb, "OPC", List.of("Operation_ID", "Operation_Name", "Resource_ID", "Direct_Time"),
                    List.of(List.of("BAD-1", "One", "R-X", 2), List.of("BAD-2", "Two", "R-X", 2),
                            List.of("BAD-3", "Three", "R-X", 2), List.of("BAD-4", "Four", broken ? "R-NOPE" : "R-X", 2)));
            sheet(wb, "Predecessors", List.of("Operation_ID", "Predecessor_Operation_ID"),
                    List.of(List.of("BAD-1", "BAD-3"), List.of("BAD-2", "BAD-1"), List.of("BAD-3", "BAD-2")));
            ByteArrayOutputStream out = new ByteArrayOutputStream();
            wb.write(out);
            return out.toByteArray();
        }
    }

    private static void sheet(Workbook wb, String name, List<String> headers, List<List<Object>> rows) {
        Sheet s = wb.createSheet(name);
        Row h = s.createRow(0);
        for (int i = 0; i < headers.size(); i++) h.createCell(i).setCellValue(headers.get(i));
        int r = 1;
        for (List<Object> row : rows) {
            Row x = s.createRow(r++);
            for (int i = 0; i < row.size(); i++) {
                Object v = row.get(i);
                if (v instanceof Number n) x.createCell(i).setCellValue(n.doubleValue());
                else x.createCell(i).setCellValue(String.valueOf(v));
            }
        }
    }
}
