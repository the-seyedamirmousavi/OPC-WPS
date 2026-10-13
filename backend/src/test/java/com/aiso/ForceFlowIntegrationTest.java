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
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.time.Instant;
import java.time.temporal.ChronoUnit;
import java.util.List;
import java.util.Map;

import static org.assertj.core.api.Assertions.assertThat;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.delete;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.multipart;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.put;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

/**
 * Manager FORCE end to end: preview, apply, refusal of impossible FORCEs (with Persian messages), the forced executor
 * in the assignment flow, rescheduling of assigned work, removal, re-optimisation and reset.
 * <p>
 * Data: A (4 h) and B (4 h) on machine R (capacity 1), C (2 h) on S after A. Without FORCEs: A [0,4), B [4,8), C [4,6).
 */
@SpringBootTest(properties = {
        "spring.datasource.url=jdbc:h2:mem:aiso-force;MODE=PostgreSQL;DATABASE_TO_LOWER=TRUE;DB_CLOSE_DELAY=-1",
        "aiso.jwt.secret=integration-test-secret-integration-test-secret",
        "aiso.seed.password=Passw0rd!test",
        "aiso.seed.language=en",
        "aiso.llm.api-key="
})
@AutoConfigureMockMvc
@TestMethodOrder(MethodOrderer.OrderAnnotation.class)
class ForceFlowIntegrationTest {

    private static final String PASSWORD = "Passw0rd!test";
    /** absolute FORCE time used across the ordered tests (well ahead of the test run) */
    private static final Instant B_AT = Instant.now().plus(2, ChronoUnit.HOURS).truncatedTo(ChronoUnit.MINUTES);

    @Autowired
    MockMvc mvc;

    private String login(String user) throws Exception {
        MvcResult r = mvc.perform(post("/api/auth/login").contentType(MediaType.APPLICATION_JSON)
                .content("{\"userId\":\"" + user + "\",\"password\":\"" + PASSWORD + "\"}")).andExpect(status().isOk()).andReturn();
        return JsonPath.read(r.getResponse().getContentAsString(), "$.token");
    }

    private <B extends AbstractMockHttpServletRequestBuilder<B>> B as(B b, String token) {
        return b.header("Authorization", "Bearer " + token);
    }

    private static String body(MvcResult r) throws Exception {
        return r.getResponse().getContentAsString(StandardCharsets.UTF_8);
    }

    private static String json(String start, String userId, String reason) {
        return "{\"start\":" + (start == null ? "null" : "\"" + start + "\"") + ",\"userId\":"
                + (userId == null ? "null" : "\"" + userId + "\"") + ",\"reason\":\"" + reason + "\"}";
    }

    private MvcResult preview(String token, String op, String body, String lang) throws Exception {
        return mvc.perform(as(post("/api/scheduling/forces/" + op + "/preview").contentType(MediaType.APPLICATION_JSON)
                .header("Accept-Language", lang).content(body), token)).andExpect(status().isOk()).andReturn();
    }

    private Map<String, Object> op(String token, String id) throws Exception {
        MvcResult r = mvc.perform(as(get("/api/operations/" + id), token)).andExpect(status().isOk()).andReturn();
        return JsonPath.read(body(r), "$.operation");
    }

    private static long seconds(Instant a, String iso) {
        return Math.abs(Duration.between(a, Instant.parse(iso)).toSeconds());
    }

    @Test
    @Order(1)
    void setup() throws Exception {
        String owner = login("owner");
        mvc.perform(as(post("/api/admin/reset").contentType(MediaType.APPLICATION_JSON)
                .content("{\"reason\":\"qa\",\"confirm\":\"RESET\"}"), owner)).andExpect(status().isOk());
        byte[] file = workbook(List.of(List.of("R", "Machine R", 1), List.of("S", "Machine S", 1)),
                List.of(List.of("A", "Task A", "R", 4), List.of("B", "Task B", "R", 4), List.of("C", "Task C", "S", 2)),
                List.of(List.of("C", "A")));
        MvcResult r = mvc.perform(as(multipart("/api/import").file(new MockMultipartFile("file", "f.xlsx",
                "application/octet-stream", file)).param("apply", "true"), owner)).andExpect(status().isOk()).andReturn();
        assertThat((String) JsonPath.read(body(r), "$.status")).isEqualTo("APPLIED");
        MvcResult board = mvc.perform(as(get("/api/scheduling/forces"), login("manager"))).andExpect(status().isOk()).andReturn();
        assertThat((List<?>) JsonPath.read(body(board), "$.forces")).isEmpty();
    }

    @Test
    @Order(2)
    void previewShowsTheImpactAndStoresNothing() throws Exception {
        String manager = login("manager");
        MvcResult r = preview(manager, "B", json(B_AT.toString(), null, "customer visit"), "en");
        assertThat((Boolean) JsonPath.read(body(r), "$.feasible")).isTrue();
        assertThat(seconds(B_AT, JsonPath.read(body(r), "$.plannedStart"))).isLessThanOrEqualTo(1);
        // A no longer fits before B: A and its successor C move
        List<String> moved = JsonPath.read(body(r), "$.moved[*].operationId");
        assertThat(moved).containsExactlyInAnyOrder("A", "C");
        MvcResult board = mvc.perform(as(get("/api/scheduling/forces"), manager)).andReturn();
        assertThat((List<?>) JsonPath.read(body(board), "$.forces")).isEmpty();
    }

    @Test
    @Order(3)
    void applyingTheForceFixesTheStartInEveryView() throws Exception {
        String manager = login("manager");
        MvcResult r = mvc.perform(as(put("/api/scheduling/forces/B").contentType(MediaType.APPLICATION_JSON)
                .content(json(B_AT.toString(), null, "customer visit")), manager)).andExpect(status().isOk()).andReturn();
        assertThat((List<String>) JsonPath.read(body(r), "$.forces[*].operationId")).containsExactly("B");
        assertThat((String) JsonPath.read(body(r), "$.forces[0].state")).isEqualTo("HONOURED");
        Map<String, Object> b = op(manager, "B");
        assertThat(seconds(B_AT, (String) b.get("projectedStart"))).isLessThanOrEqualTo(1);
        assertThat(b.get("forceState")).isEqualTo("HONOURED");
        Map<String, Object> a = op(manager, "A");
        assertThat(Instant.parse((String) a.get("projectedStart"))).isAfterOrEqualTo(B_AT.plus(4, ChronoUnit.HOURS).minusSeconds(1));
    }

    @Test
    @Order(4)
    void impossibleForcesAreRefusedWithTheReason_inPersianToo() throws Exception {
        String manager = login("manager");
        // capacity: A one hour after B on the single-lane machine
        MvcResult cap = preview(manager, "A", json(B_AT.plus(1, ChronoUnit.HOURS).toString(), null, "x"), "en");
        assertThat((Boolean) JsonPath.read(body(cap), "$.feasible")).isFalse();
        assertThat((String) JsonPath.read(body(cap), "$.conflicts[0].kind")).isEqualTo("CAPACITY");
        assertThat((Boolean) JsonPath.read(body(cap), "$.conflicts[0].proven")).isTrue();
        assertThat((List<String>) JsonPath.read(body(cap), "$.conflicts[0].related")).containsExactly("B");
        assertThat((String) JsonPath.read(body(cap), "$.conflicts[0].message")).contains("Machine R").contains("taken by B");

        MvcResult refused = mvc.perform(as(put("/api/scheduling/forces/A").contentType(MediaType.APPLICATION_JSON)
                        .header("Accept-Language", "fa").content(json(B_AT.plus(1, ChronoUnit.HOURS).toString(), null, "x")), manager))
                .andExpect(status().isConflict()).andReturn();
        String fa = JsonPath.read(body(refused), "$.message");
        assertThat(fa).startsWith("اجبار مدیر پذیرفته نشد").contains("ظرفیت").contains("۱۴۰"); // Solar Hijri year

        // precedence: C needs A (4 h) first
        MvcResult prec = preview(manager, "C", json(Instant.now().plus(1, ChronoUnit.HOURS).toString(), null, "x"), "en");
        assertThat((String) JsonPath.read(body(prec), "$.conflicts[0].kind")).isEqualTo("PRECEDENCE");
        assertThat((List<String>) JsonPath.read(body(prec), "$.conflicts[0].related")).containsExactly("A");

        // the past
        MvcResult past = preview(manager, "A", json("2020-01-01 08:00", null, "x"), "en");
        assertThat((String) JsonPath.read(body(past), "$.conflicts[0].kind")).isEqualTo("START_IN_PAST");

        // nothing changed
        MvcResult board = mvc.perform(as(get("/api/scheduling/forces"), manager)).andReturn();
        assertThat((List<String>) JsonPath.read(body(board), "$.forces[*].operationId")).containsExactly("B");
    }

    @Test
    @Order(5)
    void forcedExecutorDrivesTheProposalAndBlocksOtherManualAssignments() throws Exception {
        String manager = login("manager");
        mvc.perform(as(put("/api/scheduling/forces/B").contentType(MediaType.APPLICATION_JSON)
                .content(json(B_AT.toString(), "user3", "certified welder")), manager)).andExpect(status().isOk());

        MvcResult run = mvc.perform(as(post("/api/assignments/suggest").contentType(MediaType.APPLICATION_JSON)
                .content("{\"mode\":\"ALGORITHM\"}"), manager)).andExpect(status().isOk()).andReturn();
        List<Map<String, Object>> proposals = JsonPath.read(body(run), "$.proposals[?(@.operationId == 'B')]");
        assertThat(proposals).singleElement().satisfies(p -> {
            assertThat(p.get("userId")).isEqualTo("user3");
            assertThat((String) p.get("reason")).contains("forced by the manager");
        });
        Integer id = (Integer) proposals.get(0).get("id");
        mvc.perform(as(post("/api/assignments/approve").contentType(MediaType.APPLICATION_JSON)
                .content("{\"proposalIds\":[" + id + "]}"), manager)).andExpect(status().isOk());
        Map<String, Object> b = op(manager, "B");
        assertThat(b.get("status")).isEqualTo("ASSIGNED");
        assertThat(b.get("assignedUserId")).isEqualTo("user3");
        assertThat(seconds(B_AT, (String) b.get("plannedStart"))).isLessThanOrEqualTo(1);

        MvcResult manual = mvc.perform(as(post("/api/operations/B/assign").contentType(MediaType.APPLICATION_JSON)
                .content("{\"userId\":\"user1\"}"), manager)).andExpect(status().isConflict()).andReturn();
        assertThat((String) JsonPath.read(body(manual), "$.message")).contains("forced to executor user3");
    }

    @Test
    @Order(6)
    void movingTheForceOfAssignedWorkReschedulesItAndTellsTheExecutor() throws Exception {
        String manager = login("manager");
        Instant later = B_AT.plus(1, ChronoUnit.HOURS);
        MvcResult pv = preview(manager, "B", json(later.toString(), "user3", "moved"), "en");
        assertThat((Boolean) JsonPath.read(body(pv), "$.feasible")).isTrue();
        mvc.perform(as(put("/api/scheduling/forces/B").contentType(MediaType.APPLICATION_JSON)
                .content(json(later.toString(), "user3", "moved")), manager)).andExpect(status().isOk());
        Map<String, Object> b = op(manager, "B");
        assertThat(seconds(later, (String) b.get("plannedStart"))).isLessThanOrEqualTo(1);
        assertThat(b.get("assignedUserId")).isEqualTo("user3");

        MvcResult notes = mvc.perform(as(get("/api/notifications"), login("user3"))).andExpect(status().isOk()).andReturn();
        assertThat(body(notes)).contains("OPERATION_RESCHEDULED");
    }

    @Test
    @Order(7)
    void removingAndReoptimisingAreAudited_andExecutorsCannotForce() throws Exception {
        String manager = login("manager");
        MvcResult r = mvc.perform(as(delete("/api/scheduling/forces/B"), manager)).andExpect(status().isOk()).andReturn();
        assertThat((List<?>) JsonPath.read(body(r), "$.forces")).isEmpty();
        assertThat(op(manager, "B").get("assignedUserId")).isEqualTo("user3"); // the assignment itself stays
        mvc.perform(as(delete("/api/scheduling/forces/B"), manager)).andExpect(status().isNotFound());

        MvcResult re = mvc.perform(as(post("/api/scheduling/reoptimize"), manager)).andExpect(status().isOk()).andReturn();
        assertThat((String) JsonPath.read(body(re), "$.baselineSavedAt")).isNotNull();

        MvcResult audit = mvc.perform(as(get("/api/audit"), login("owner"))).andExpect(status().isOk()).andReturn();
        assertThat(body(audit)).contains("FORCE_SET").contains("FORCE_REMOVED").contains("PLAN_REOPTIMIZED");

        mvc.perform(as(get("/api/scheduling/forces"), login("user1"))).andExpect(status().isForbidden());
    }

    @Test
    @Order(8)
    void resetRemovesForcesAndTheReferencePlan() throws Exception {
        String manager = login("manager");
        mvc.perform(as(put("/api/scheduling/forces/A").contentType(MediaType.APPLICATION_JSON)
                .content(json(null, "user2", "x")), manager)).andExpect(status().isOk());
        String owner = login("owner");
        mvc.perform(as(post("/api/admin/reset").contentType(MediaType.APPLICATION_JSON)
                .content("{\"reason\":\"qa\",\"confirm\":\"RESET\"}"), owner)).andExpect(status().isOk());
        MvcResult board = mvc.perform(as(get("/api/scheduling/forces"), manager)).andExpect(status().isOk()).andReturn();
        assertThat((List<?>) JsonPath.read(body(board), "$.forces")).isEmpty();
        assertThat((Object) JsonPath.read(body(board), "$.baselineSavedAt")).isNull();
    }

    private static byte[] workbook(List<List<Object>> resources, List<List<Object>> ops, List<List<Object>> preds) throws Exception {
        try (Workbook wb = new XSSFWorkbook()) {
            sheet(wb, "Resources", List.of("Resource_ID", "Resource_Name", "Capacity"), resources);
            sheet(wb, "OPC", List.of("Operation_ID", "Operation_Name", "Resource_ID", "Direct_Time"), ops);
            sheet(wb, "Predecessors", List.of("Operation_ID", "Predecessor_Operation_ID"), preds);
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
