package com.aiso.web;

import com.aiso.domain.ImportLog;
import com.aiso.imports.ImportResult;
import com.aiso.imports.ImportService;
import com.aiso.imports.ImportTarget;
import com.aiso.service.FaTranslator;
import com.aiso.service.LanguageService;
import com.aiso.service.ProjectService;
import com.aiso.repo.ImportLogRepository;
import com.aiso.security.CurrentUser;
import com.aiso.service.ExcelService;
import org.springframework.data.domain.PageRequest;
import org.springframework.http.ContentDisposition;
import org.springframework.http.HttpHeaders;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.security.access.prepost.PreAuthorize;
import org.springframework.security.core.Authentication;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;
import org.springframework.web.multipart.MultipartFile;

import java.io.IOException;
import java.util.List;

@RestController
@RequestMapping("/api/import")
@PreAuthorize(Access.MANAGEMENT)
public class ImportController {

    static final MediaType XLSX = MediaType.parseMediaType("application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");

    private final ImportService importer;
    private final ImportLogRepository logs;
    private final ExcelService excel;

    private final LanguageService lang;

    public ImportController(ImportService importer, ImportLogRepository logs, ExcelService excel, LanguageService lang) {
        this.lang = lang;
        this.importer = importer;
        this.logs = logs;
        this.excel = excel;
    }

    /**
     * apply=false validates only; apply=true validates and, when the file is clean, imports it.
     * Target project: projectId (existing) or newProjectName (+ optional newProjectId, dueDate). When a new project joins
     * active ones, ranking (comma separated project ids, first = highest priority, NEW = the new project) is required;
     * without it the answer is status PRIORITY_REQUIRED together with the schedule impact of the default order.
     */
    @PostMapping
    public ImportResult upload(Authentication auth, @RequestParam("file") MultipartFile file,
                               @RequestParam(defaultValue = "false") boolean apply,
                               @RequestParam(required = false) String projectId,
                               @RequestParam(required = false) String newProjectName,
                               @RequestParam(required = false) String newProjectId,
                               @RequestParam(required = false) String dueDate,
                               @RequestParam(required = false) String ranking) throws IOException {
        if (file.isEmpty()) {
            throw ApiException.badRequest("The file is empty");
        }
        List<String> order = ranking == null || ranking.isBlank() ? null
                : java.util.Arrays.stream(ranking.split(",")).map(String::trim).filter(x -> !x.isEmpty()).toList();
        ImportTarget target = new ImportTarget(projectId, newProjectName, newProjectId, ProjectService.parseDue(dueDate), order);
        return localized(importer.process(file.getInputStream(), file.getOriginalFilename(), apply, CurrentUser.from(auth), target));
    }

    private ImportResult localized(ImportResult r) {
        if (!lang.requestIsPersian()) {
            return r;
        }
        return new ImportResult(r.status(), r.format(),
                r.errors().stream().map(e -> new com.aiso.imports.ImportIssue(e.sheet(), e.row(), e.column(), FaTranslator.translate(e.message()))).toList(),
                r.warnings().stream().map(FaTranslator::translate).toList(), r.counts(), r.newUsers(),
                r.projectId(), r.projectName(), r.newProject(), r.activeProjects(), r.impact());
    }

    /** The master template. {@code lang=fa} gives Persian sheet names, headers and sample values (also accepted on import). */
    @GetMapping("/template")
    public ResponseEntity<byte[]> template(@RequestParam(required = false) String lang) {
        boolean fa = lang == null ? this.lang.requestIsPersian() : "fa".equalsIgnoreCase(lang);
        return download(excel.template(fa), fa ? "AISO-قالب-اصلی.xlsx" : "AISO-master-template.xlsx");
    }

    @GetMapping("/history")
    public List<ImportLog> history() {
        return logs.findAllByOrderByImportedAtDesc(PageRequest.of(0, 50));
    }

    static ResponseEntity<byte[]> download(byte[] bytes, String name) {
        return ResponseEntity.ok()
                .contentType(XLSX)
                .header(HttpHeaders.CONTENT_DISPOSITION, ContentDisposition.attachment().filename(name, java.nio.charset.StandardCharsets.UTF_8).build().toString())
                .body(bytes);
    }
}
