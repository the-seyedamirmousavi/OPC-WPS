package com.aiso.web;

import com.aiso.domain.OperationStatus;
import com.aiso.security.CurrentUser;
import com.aiso.service.OperationService;
import com.aiso.service.OperationViewService;
import com.aiso.service.Views.OperationDetail;
import com.aiso.service.Views.OperationView;
import jakarta.validation.Valid;
import jakarta.validation.constraints.Max;
import jakarta.validation.constraints.Min;
import jakarta.validation.constraints.NotBlank;
import org.springframework.security.access.prepost.PreAuthorize;
import org.springframework.security.core.Authentication;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;

import java.util.List;

@RestController
@RequestMapping("/api")
public class OperationController {

    public record ProgressRequest(@Min(0) @Max(100) int percent, String note) {
    }

    public record NoteRequest(String note) {
    }

    public record ReasonRequest(@NotBlank String reason) {
    }

    private final OperationViewService views;
    private final OperationService ops;

    public OperationController(OperationViewService views, OperationService ops) {
        this.views = views;
        this.ops = ops;
    }

    // ---- reading ----------------------------------------------------------------------------------------------

    @GetMapping("/operations")
    @PreAuthorize(Access.MANAGEMENT)
    public List<OperationView> list(@RequestParam(required = false) OperationStatus status,
                                    @RequestParam(required = false) String resourceId,
                                    @RequestParam(required = false) String projectId,
                                    @RequestParam(required = false) String userId,
                                    @RequestParam(required = false) String q) {
        String needle = q == null ? null : q.toLowerCase();
        return views.all().stream()
                .filter(v -> status == null || v.status() == status)
                .filter(v -> resourceId == null || resourceId.equals(v.resourceId()))
                .filter(v -> projectId == null || projectId.equals(v.projectId()))
                .filter(v -> userId == null || userId.equals(v.assignedUserId()))
                .filter(v -> needle == null || v.id().toLowerCase().contains(needle) || v.name().toLowerCase().contains(needle))
                .toList();
    }

    @GetMapping("/operations/{id}")
    public OperationDetail detail(Authentication auth, @PathVariable String id) {
        OperationDetail d = views.detail(id);
        CurrentUser me = CurrentUser.from(auth);
        if (me.role().isExecutor() && !me.id().equals(d.operation().assignedUserId())) {
            throw ApiException.forbidden("This operation is not assigned to you");
        }
        return d;
    }

    @GetMapping("/my/tasks")
    @PreAuthorize(Access.EXECUTOR)
    public List<OperationView> myTasks(Authentication auth) {
        return views.forUser(CurrentUser.from(auth).id());
    }

    // ---- executor actions -------------------------------------------------------------------------------------

    @PostMapping("/operations/{id}/start")
    @PreAuthorize(Access.EXECUTOR)
    public void start(Authentication auth, @PathVariable String id) {
        ops.start(id, CurrentUser.from(auth));
    }

    @PostMapping("/operations/{id}/progress")
    @PreAuthorize(Access.EXECUTOR)
    public void progress(Authentication auth, @PathVariable String id, @Valid @RequestBody ProgressRequest req) {
        ops.progress(id, req.percent(), req.note(), CurrentUser.from(auth));
    }

    @PostMapping("/operations/{id}/complete")
    @PreAuthorize(Access.EXECUTOR)
    public void complete(Authentication auth, @PathVariable String id, @RequestBody(required = false) NoteRequest req) {
        ops.complete(id, req == null ? null : req.note(), CurrentUser.from(auth));
    }

    @PostMapping("/operations/{id}/block")
    @PreAuthorize(Access.EXECUTOR)
    public void block(Authentication auth, @PathVariable String id, @Valid @RequestBody ReasonRequest req) {
        ops.block(id, req.reason(), CurrentUser.from(auth));
    }

    @PostMapping("/operations/{id}/comment")
    public void comment(Authentication auth, @PathVariable String id, @Valid @RequestBody ReasonRequest req) {
        ops.comment(id, req.reason(), CurrentUser.from(auth));
    }

    // ---- manager actions --------------------------------------------------------------------------------------

    @PostMapping("/operations/{id}/unblock")
    @PreAuthorize(Access.MANAGEMENT)
    public void unblock(Authentication auth, @PathVariable String id, @RequestBody(required = false) NoteRequest req) {
        ops.unblock(id, req == null ? null : req.note(), CurrentUser.from(auth));
    }

    @PostMapping("/operations/{id}/cancel")
    @PreAuthorize(Access.MANAGEMENT)
    public void cancel(Authentication auth, @PathVariable String id, @Valid @RequestBody ReasonRequest req) {
        ops.cancel(id, req.reason(), CurrentUser.from(auth));
    }

    @PostMapping("/operations/{id}/approve")
    @PreAuthorize(Access.MANAGEMENT)
    public void approve(Authentication auth, @PathVariable String id, @RequestBody(required = false) NoteRequest req) {
        ops.approve(id, req == null ? null : req.note(), CurrentUser.from(auth));
    }

    @PostMapping("/operations/{id}/unassign")
    @PreAuthorize(Access.MANAGEMENT)
    public void unassign(Authentication auth, @PathVariable String id) {
        ops.unassign(id, CurrentUser.from(auth));
    }
}
