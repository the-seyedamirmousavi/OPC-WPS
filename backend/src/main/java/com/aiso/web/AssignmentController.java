package com.aiso.web;

import com.aiso.assign.AssignmentService;
import com.aiso.domain.AssignmentMode;
import com.aiso.security.CurrentUser;
import com.aiso.service.Views.ProposalView;
import com.aiso.service.Views.RunView;
import jakarta.validation.Valid;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotEmpty;
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
@PreAuthorize(Access.MANAGEMENT)
public class AssignmentController {

    /** mode is optional: null means "use the mode configured in Settings". */
    public record SuggestRequest(AssignmentMode mode) {
    }

    public record IdsRequest(@NotEmpty List<Long> proposalIds, String note) {
    }

    public record ManualAssign(@NotBlank String userId, String note) {
    }

    private final AssignmentService assignments;

    public AssignmentController(AssignmentService assignments) {
        this.assignments = assignments;
    }

    @PostMapping("/assignments/suggest")
    public RunView suggest(Authentication auth, @RequestBody(required = false) SuggestRequest req) {
        return assignments.suggest(req == null ? null : req.mode(), CurrentUser.from(auth));
    }

    @GetMapping("/assignments/latest")
    public RunView latest() {
        return assignments.latest();
    }

    @GetMapping("/assignments/runs")
    public List<RunView> runs(@RequestParam(defaultValue = "10") int limit) {
        return assignments.history(limit);
    }

    @PostMapping("/assignments/approve")
    public List<ProposalView> approve(Authentication auth, @Valid @RequestBody IdsRequest req) {
        return assignments.approve(req.proposalIds(), CurrentUser.from(auth));
    }

    @PostMapping("/assignments/reject")
    public List<ProposalView> reject(Authentication auth, @Valid @RequestBody IdsRequest req) {
        return assignments.reject(req.proposalIds(), req.note(), CurrentUser.from(auth));
    }

    @PostMapping("/operations/{id}/assign")
    public void manual(Authentication auth, @PathVariable String id, @Valid @RequestBody ManualAssign req) {
        assignments.manualAssign(id, req.userId(), req.note(), CurrentUser.from(auth));
    }
}
