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

import java.io.ByteArrayInputStream;
import java.io.ByteArrayOutputStream;
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.time.Instant;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.regex.Pattern;

import static org.assertj.core.api.Assertions.assertThat;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.multipart;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.patch;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.put;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

/**
 * Several projects on shared resources: importing a project into a running schedule asks for priorities, the
 * optimiser honours them, and the Persian outputs (Excel, messages, Solar Hijri dates) work.
 */
@SpringBootTest(properties = {
        "spring.datasource.url=jdbc:h2:mem:aiso-mp;MODE=PostgreSQL;DATABASE_TO_LOWER=TRUE;DB_CLOSE_DELAY=-1",
        "aiso.jwt.secret=integration-test-secret-integration-test-secret",
        "aiso.seed.password=Passw0rd!test",
        "aiso.seed.language=en",
        "aiso.llm.api-key="
})
@AutoConfigureMockMvc
@TestMethodOrder(MethodOrderer.OrderAnnotation.class)
class MultiProjectFlowIntegrationTest {

    private static final String PASSWORD = "Passw0rd!test";

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

    private void reset(String owner) throws Exception {
        mvc.perform(as(post("/api/admin/reset").contentType(MediaType.APPLICATION_JSON)
                .content("{\"reason\":\"qa\",\"confirm\":\"RESET\"}"), owner)).andExpect(status().isOk());
    }

    private MvcResult upload(String token, byte[] file, boolean apply, Map<String, String> params) throws Exception {
        var req = multipart("/api/import").file(new MockMultipartFile("file", "p.xlsx", "application/octet-stream", file))
                .param("apply", String.valueOf(apply));
        params.forEach(req::param);
        return mvc.perform(as(req, token)).andExpect(status().isOk()).andReturn();
    }

    /** project 1: A(4h) -> B(4h) on R (capacity 1) */
    private static byte[] projectOne() throws Exception {
        return workbook(List.of(List.of("R", "Machine R", 1)),
                List.of(List.of("A", "Task A", "R", 4), List.of("B", "Task B", "R", 4)), List.of(List.of("B", "A")));
    }

    /** project 2: C(6h) on the same machine */
    private static byte[] projectTwo() throws Exception {
        return workbook(List.of(List.of("R", "Machine R", 1)), List.of(List.of("C", "Task C", "R", 6)), List.of());
    }

    /** offsets in hours of projectedStart/End, relative to the earliest projectedStart of all operations */
    private Map<String, double[]> plan(String token) throws Exception {
        MvcResult r = mvc.perform(as(get("/api/operations"), token)).andExpect(status().isOk()).andReturn();
        List<String> ids = JsonPath.read(body(r), "$[*].id");
        List<String> starts = JsonPath.read(body(r), "$[*].projectedStart");
        List<String> ends = JsonPath.read(body(r), "$[*].projectedEnd");
        Instant t0 = starts.stream().map(Instant::parse).min(Instant::compareTo).orElseThrow();
        Map<String, double[]> out = new HashMap<>();
        for (int i = 0; i < ids.size(); i++) {
            out.put(ids.get(i), new double[]{hours(t0, starts.get(i)), hours(t0, ends.get(i))});
        }
        return out;
    }

    private static double hours(Instant t0, String iso) {
        return Duration.between(t0, Instant.parse(iso)).toSeconds() / 3600.0;
    }

    @Test
    @Order(1)
    void firstProjectNeedsNoPriorityAndBecomesTheDefaultProject() throws Exception {
        String owner = login("owner");
        reset(owner);
        MvcResult r = upload(owner, projectOne(), true, Map.of());
        assertThat((String) JsonPath.read(body(r), "$.status")).isEqualTo("APPLIED");
        assertThat((Boolean) JsonPath.read(body(r), "$.newProject")).isTrue();
        MvcResult list = mvc.perform(as(get("/api/projects"), owner)).andReturn();
        assertThat((List<?>) JsonPath.read(body(list), "$")).hasSize(1);
        assertThat((Integer) JsonPath.read(body(list), "$[0].priority")).isEqualTo(1);
    }

    @Test
    @Order(2)
    void addingAProjectToARunningScheduleAsksForPriorities_andStoresNothingUntilAnswered() throws Exception {
        String owner = login("owner");
        MvcResult r = upload(owner, projectTwo(), true, Map.of("newProjectName", "Urgent job", "newProjectId", "URG"));
        assertThat((String) JsonPath.read(body(r), "$.status")).isEqualTo("PRIORITY_REQUIRED");
        List<String> active = JsonPath.read(body(r), "$.activeProjects[*].id");
        assertThat(active).hasSize(1);
        // the answer already shows what the default order (new project last) would do
        assertThat((Object) JsonPath.read(body(r), "$.impact.proposed.projects")).isNotNull();
        assertThat((Double) JsonPath.read(body(r), "$.impact.proposed.wasteHours")).isNotNegative();
        MvcResult ops = mvc.perform(as(get("/api/operations"), owner)).andReturn();
        assertThat((List<?>) JsonPath.read(body(ops), "$")).hasSize(2); // nothing was imported
    }

    @Test
    @Order(3)
    void invalidRankingsAndAmbiguousTargetsAreRejected() throws Exception {
        String owner = login("owner");
        String existing = projectIdOf(owner, "$[0].id");
        MvcResult missing = upload(owner, projectTwo(), true,
                Map.of("newProjectName", "Urgent job", "newProjectId", "URG", "ranking", "NEW"));
        assertThat((String) JsonPath.read(body(missing), "$.status")).isEqualTo("REJECTED");
        assertThat(body(missing)).contains("ranking");

        MvcResult unknown = upload(owner, projectTwo(), true,
                Map.of("newProjectName", "Urgent job", "newProjectId", "URG", "ranking", "NEW,GHOST"));
        assertThat((String) JsonPath.read(body(unknown), "$.status")).isEqualTo("REJECTED");

        MvcResult dup = upload(owner, projectOne(), true,
                Map.of("newProjectName", "Copy", "newProjectId", "CPY", "ranking", "NEW," + existing));
        assertThat((String) JsonPath.read(body(dup), "$.status")).isEqualTo("REJECTED");
        assertThat(body(dup)).contains("already belongs to project " + existing);
    }

    @Test
    @Order(4)
    void answeringWithARankingCreatesTheProjectAndTheOptimiserHonoursIt() throws Exception {
        String owner = login("owner");
        String existing = projectIdOf(owner, "$[0].id");
        MvcResult r = upload(owner, projectTwo(), true,
                Map.of("newProjectName", "Urgent job", "newProjectId", "URG", "ranking", "NEW," + existing));
        assertThat((String) JsonPath.read(body(r), "$.status")).isEqualTo("APPLIED");

        MvcResult list = mvc.perform(as(get("/api/projects"), owner)).andReturn();
        List<String> ids = JsonPath.read(body(list), "$[*].id");
        List<Integer> priorities = JsonPath.read(body(list), "$[*].priority");
        assertThat(ids).containsExactly("URG", existing);
        assertThat(priorities).containsExactly(1, 2);

        // URG is more important and both want the single machine: C 0-6, then A 6-10, B 10-14
        Map<String, double[]> p = plan(login("manager"));
        assertThat(p.get("C")).containsExactly(new double[]{0, 6}, org.assertj.core.data.Offset.offset(0.01));
        assertThat(p.get("A")).containsExactly(new double[]{6, 10}, org.assertj.core.data.Offset.offset(0.01));
        assertThat(p.get("B")).containsExactly(new double[]{10, 14}, org.assertj.core.data.Offset.offset(0.01));
    }

    @Test
    @Order(5)
    void previewShowsTheEffectOfAReRankingWithoutChangingAnything() throws Exception {
        String manager = login("manager");
        String existing = projectIdOf(manager, "$[1].id");
        MvcResult r = mvc.perform(as(post("/api/scheduling/preview").contentType(MediaType.APPLICATION_JSON)
                .content("{\"ranking\":[\"" + existing + "\",\"URG\"]}"), manager)).andExpect(status().isOk()).andReturn();
        // now: URG first -> URG finishes at 6, other at 14. Proposed: other first -> other at 8, URG at 14
        List<String> ids = JsonPath.read(body(r), "$.deltas[*].projectId");
        List<Double> delta = JsonPath.read(body(r), "$.deltas[*].deltaHours");
        assertThat(ids).containsExactly(existing, "URG");
        assertThat(delta.get(0)).isCloseTo(-6.0, org.assertj.core.data.Offset.offset(0.01));
        assertThat(delta.get(1)).isCloseTo(8.0, org.assertj.core.data.Offset.offset(0.01));
        // the stored ranking is unchanged
        MvcResult list = mvc.perform(as(get("/api/projects"), manager)).andReturn();
        assertThat((List<String>) JsonPath.read(body(list), "$[*].id")).containsExactly("URG", existing);

        mvc.perform(as(post("/api/scheduling/preview").contentType(MediaType.APPLICATION_JSON)
                .content("{\"ranking\":[\"URG\"]}"), manager)).andExpect(status().isBadRequest());
    }

    @Test
    @Order(6)
    void assignmentFollowsProjectPriorityOnAScarceMachine() throws Exception {
        String manager = login("manager");
        MvcResult run = mvc.perform(as(post("/api/assignments/suggest").contentType(MediaType.APPLICATION_JSON)
                .content("{\"mode\":\"ALGORITHM\"}"), manager)).andExpect(status().isOk()).andReturn();
        // machine R has one slot; both A (project 2) and C (URG, priority 1) are READY -> only C
        List<String> picked = JsonPath.read(body(run), "$.proposals[*].operationId");
        assertThat(picked).containsExactly("C");
        assertThat(body(run)).contains("project Urgent job (priority 1)");
    }

    @Test
    @Order(7)
    void reRankingMovesTheScheduleAndIsAudited() throws Exception {
        String manager = login("manager");
        String existing = projectIdOf(manager, "$[1].id");
        mvc.perform(as(put("/api/projects/priorities").contentType(MediaType.APPLICATION_JSON)
                .content("{\"ranking\":[\"" + existing + "\",\"URG\"],\"dueDates\":{\"URG\":\"1405/07/25\"}}"), manager))
                .andExpect(status().isOk());
        Map<String, double[]> p = plan(manager);
        assertThat(p.get("A")[0]).isCloseTo(0, org.assertj.core.data.Offset.offset(0.01));
        assertThat(p.get("B")[1]).isCloseTo(8, org.assertj.core.data.Offset.offset(0.01));
        assertThat(p.get("C")[0]).isCloseTo(8, org.assertj.core.data.Offset.offset(0.01));
        // Jalali due date was understood: 1405/07/25 = 2026-10-17
        MvcResult list = mvc.perform(as(get("/api/projects"), manager)).andReturn();
        List<String> due = JsonPath.read(body(list), "$[?(@.id=='URG')].dueDate");
        assertThat(due.get(0)).startsWith("2026-10-17");
        MvcResult audit = mvc.perform(as(get("/api/audit?entityType=Project&entityId=*"), manager)).andReturn();
        assertThat((List<String>) JsonPath.read(body(audit), "$[*].action")).contains("PROJECT_PRIORITIES_CHANGED");
    }

    @Test
    @Order(8)
    void importNeedsATargetWhenSeveralProjectsAreActive_andRolesAreEnforced() throws Exception {
        String owner = login("owner");
        MvcResult r = upload(owner, projectTwo(), true, Map.of());
        assertThat((String) JsonPath.read(body(r), "$.status")).isEqualTo("REJECTED");
        assertThat(body(r)).contains("Several projects are active");

        String user1 = login("user1");
        mvc.perform(as(get("/api/projects"), user1)).andExpect(status().isForbidden());
        mvc.perform(as(post("/api/scheduling/preview").contentType(MediaType.APPLICATION_JSON)
                .content("{\"ranking\":[\"X\"]}"), user1)).andExpect(status().isForbidden());
        mvc.perform(as(put("/api/projects/priorities").contentType(MediaType.APPLICATION_JSON)
                .content("{\"ranking\":[\"X\"]}"), user1)).andExpect(status().isForbidden());

        // a project with unfinished work cannot be archived
        mvc.perform(as(patch("/api/projects/URG").contentType(MediaType.APPLICATION_JSON)
                .content("{\"status\":\"ARCHIVED\"}"), owner)).andExpect(status().isConflict());
    }

    @Test
    @Order(9)
    void overviewComparesTheOptimisedScheduleWithTheNaiveOne() throws Exception {
        String manager = login("manager");
        MvcResult r = mvc.perform(as(get("/api/scheduling/overview"), manager)).andExpect(status().isOk()).andReturn();
        double optimized = JsonPath.<Number>read(body(r), "$.optimized.wasteHours").doubleValue();
        double naive = JsonPath.<Number>read(body(r), "$.naive.wasteHours").doubleValue();
        assertThat(optimized).isLessThanOrEqualTo(naive + 1e-9);
        assertThat((Integer) JsonPath.read(body(r), "$.optimized.candidatesTried")).isGreaterThan(1);
        MvcResult dash = mvc.perform(as(get("/api/dashboard/manager"), manager)).andReturn();
        assertThat((List<?>) JsonPath.read(body(dash), "$.projects")).hasSize(2);
    }

    // ------------------------------------------------------------------------------------------------ Persian

    @Test
    @Order(10)
    void persianMessagesFollowTheRequestLanguage() throws Exception {
        String manager = login("manager");
        MvcResult fa = mvc.perform(as(post("/api/operations/ZZZ/assign").contentType(MediaType.APPLICATION_JSON)
                .content("{\"userId\":\"user1\"}").header("Accept-Language", "fa"), manager)).andExpect(status().isNotFound()).andReturn();
        assertThat((String) JsonPath.read(body(fa), "$.message")).isEqualTo("عملیات ZZZ پیدا نشد");
        MvcResult en = mvc.perform(as(post("/api/operations/ZZZ/assign").contentType(MediaType.APPLICATION_JSON)
                .content("{\"userId\":\"user1\"}").header("Accept-Language", "en"), manager)).andExpect(status().isNotFound()).andReturn();
        assertThat((String) JsonPath.read(body(en), "$.message")).isEqualTo("Operation ZZZ not found");
        MvcResult login = mvc.perform(post("/api/auth/login").contentType(MediaType.APPLICATION_JSON)
                .header("Accept-Language", "fa").content("{\"userId\":\"owner\",\"password\":\"bad-password\"}")).andReturn();
        assertThat((String) JsonPath.read(body(login), "$.message")).isEqualTo("شناسه کاربر یا رمز عبور نادرست است");
    }

    @Test
    @Order(11)
    void persianTemplateIsAcceptedOnImportAndFindingsAreInPersian() throws Exception {
        String owner = login("owner");
        reset(owner);
        MvcResult tpl = mvc.perform(as(get("/api/import/template?lang=fa"), owner)).andExpect(status().isOk()).andReturn();
        byte[] file = tpl.getResponse().getContentAsByteArray();
        try (Workbook wb = new XSSFWorkbook(new ByteArrayInputStream(file))) {
            assertThat(wb.getSheet("عملیات")).isNotNull();
            assertThat(wb.getSheet("منابع").getRow(0).getCell(0).getStringCellValue()).isEqualTo("شناسه منبع");
            assertThat(wb.getSheet("عملیات").isRightToLeft()).isTrue();
        }
        MvcResult dry = upload(owner, file, false, Map.of());
        assertThat((String) JsonPath.read(body(dry), "$.status")).as(body(dry)).isEqualTo("VALID");
        MvcResult applied = upload(owner, file, true, Map.of());
        assertThat((String) JsonPath.read(body(applied), "$.status")).as(body(applied)).isEqualTo("APPLIED");
        MvcResult ops = mvc.perform(as(get("/api/operations"), owner)).andReturn();
        assertThat((List<String>) JsonPath.read(body(ops), "$[*].id")).containsExactlyInAnyOrder("OP-001", "OP-002");

        // findings are translated for a Persian request
        MvcResult bad = mvc.perform(as(multipart("/api/import")
                .file(new MockMultipartFile("file", "x.xlsx", "application/octet-stream",
                        workbook(List.of(List.of("R", "M", 1)), List.of(List.of("A", "A", "NOPE", 1)), List.of())))
                .header("Accept-Language", "fa"), owner)).andReturn();
        assertThat(body(bad)).contains("منبع «NOPE» وجود ندارد");
    }

    @Test
    @Order(12)
    void persianReportUsesPersianSheetsAndJalaliDates() throws Exception {
        String manager = login("manager");
        MvcResult r = mvc.perform(as(get("/api/reports/export?lang=fa"), manager)).andExpect(status().isOk()).andReturn();
        try (Workbook wb = new XSSFWorkbook(new ByteArrayInputStream(r.getResponse().getContentAsByteArray()))) {
            assertThat(wb.getSheet("پروژه‌ها")).isNotNull();
            Sheet audit = wb.getSheet("ممیزی");
            assertThat(audit).isNotNull();
            assertThat(audit.isRightToLeft()).isTrue();
            String time = audit.getRow(1).getCell(1).getStringCellValue();
            assertThat(time).matches(Pattern.compile("۱۴\\d\\d/\\d\\d/\\d\\d \\d\\d:\\d\\d".replace("\\d", "[۰-۹]")));
        }
        MvcResult en = mvc.perform(as(get("/api/reports/export?lang=en"), manager)).andExpect(status().isOk()).andReturn();
        try (Workbook wb = new XSSFWorkbook(new ByteArrayInputStream(en.getResponse().getContentAsByteArray()))) {
            assertThat(wb.getSheet("Projects")).isNotNull();
        }
    }

    @Test
    @Order(13)
    void systemLanguageControlsStoredTexts() throws Exception {
        String owner = login("owner");
        String manager = login("manager");
        mvc.perform(as(patch("/api/settings").contentType(MediaType.APPLICATION_JSON).content("{\"language\":\"fa\"}"), owner))
                .andExpect(status().isOk());
        mvc.perform(as(patch("/api/settings").contentType(MediaType.APPLICATION_JSON).content("{\"language\":\"de\"}"), owner))
                .andExpect(status().isBadRequest());
        mvc.perform(as(post("/api/operations/OP-001/assign").contentType(MediaType.APPLICATION_JSON)
                .content("{\"userId\":\"user1\"}"), manager)).andExpect(status().isOk());
        MvcResult inbox = mvc.perform(as(get("/api/notifications"), login("user1"))).andReturn();
        assertThat((List<String>) JsonPath.read(body(inbox), "$[*].message")).anyMatch(m -> m.startsWith("وظیفهٔ جدید: OP-001"));
        mvc.perform(as(patch("/api/settings").contentType(MediaType.APPLICATION_JSON).content("{\"language\":\"en\"}"), owner))
                .andExpect(status().isOk());
    }

    // ------------------------------------------------------------------------------------------------ helpers

    private String projectIdOf(String token, String path) throws Exception {
        MvcResult list = mvc.perform(as(get("/api/projects"), token)).andReturn();
        return JsonPath.read(body(list), path);
    }

    /** resources: id, name, capacity. ops: id, name, resource, hours. preds: op, predecessor. */
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
