package com.aiso.web;

import com.aiso.domain.AssignmentMode;
import com.aiso.domain.SystemStatus;
import com.aiso.imports.ImportResult;
import com.aiso.imports.ImportService;
import com.aiso.security.CurrentUser;
import com.aiso.service.AdminService;
import com.aiso.service.AdminService.SettingsView;
import jakarta.validation.Valid;
import jakarta.validation.constraints.NotNull;
import org.springframework.security.access.prepost.PreAuthorize;
import org.springframework.security.core.Authentication;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PatchMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.PutMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

@RestController
@RequestMapping("/api")
public class AdminController {

    public record SettingsUpdate(String projectName, String messengerPlatform, Integer maxActiveTasksPerUser,
                                 Boolean requireCompletionApproval, String language) {
    }

    public record ModeRequest(@NotNull AssignmentMode mode) {
    }

    public record StatusRequest(@NotNull SystemStatus status, String reason) {
    }

    public record ResetRequest(String reason, String confirm) {
    }

    private final AdminService admin;
    private final ImportService importer;

    public AdminController(AdminService admin, ImportService importer) {
        this.admin = admin;
        this.importer = importer;
    }

    @GetMapping("/settings")
    @PreAuthorize(Access.MANAGEMENT)
    public SettingsView settings() {
        return admin.view();
    }

    @PatchMapping("/settings")
    @PreAuthorize(Access.OWNER)
    public SettingsView update(Authentication auth, @RequestBody SettingsUpdate req) {
        return admin.update(req.projectName(), req.messengerPlatform(), req.maxActiveTasksPerUser(),
                req.requireCompletionApproval(), req.language(), CurrentUser.from(auth));
    }

    /** Switches between the algorithm and the LLM for task assignment. */
    @PutMapping("/settings/assignment-mode")
    @PreAuthorize(Access.MANAGEMENT)
    public SettingsView assignmentMode(Authentication auth, @Valid @RequestBody ModeRequest req) {
        return admin.setAssignmentMode(req.mode(), CurrentUser.from(auth));
    }

    @PutMapping("/admin/system-status")
    @PreAuthorize(Access.OWNER)
    public SettingsView systemStatus(Authentication auth, @Valid @RequestBody StatusRequest req) {
        return admin.setSystemStatus(req.status(), req.reason(), CurrentUser.from(auth));
    }

    @PostMapping("/admin/reset")
    @PreAuthorize(Access.OWNER)
    public void reset(Authentication auth, @RequestBody ResetRequest req) {
        if (!"RESET".equals(req.confirm())) {
            throw ApiException.badRequest("Type RESET in the confirm field to proceed");
        }
        admin.reset(req.reason(), CurrentUser.from(auth));
    }

    /** Loads the bundled sample (the simple Excel layout) so the system can be tried immediately. */
    @PostMapping("/admin/demo-data")
    @PreAuthorize(Access.OWNER)
    public ImportResult demoData(Authentication auth) {
        return importer.processClasspath("/samples/simple-sample.xlsx", true, CurrentUser.from(auth));
    }
}
