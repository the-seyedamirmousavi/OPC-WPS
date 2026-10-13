package com.aiso.web;

import com.aiso.security.CurrentUser;
import com.aiso.service.ForceService;
import com.aiso.service.Forces.Board;
import com.aiso.service.Forces.Impact;
import org.springframework.security.access.prepost.PreAuthorize;
import org.springframework.security.core.Authentication;
import org.springframework.web.bind.annotation.DeleteMapping;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.PutMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

/** Manager FORCEs on the schedule: list, preview, set/replace, remove, and re-optimise from scratch. */
@RestController
@RequestMapping("/api/scheduling")
@PreAuthorize(Access.MANAGEMENT)
public class ForceController {

    private final ForceService forces;

    public ForceController(ForceService forces) {
        this.forces = forces;
    }

    @GetMapping("/forces")
    public Board list() {
        return forces.board();
    }

    /** What the FORCE would change; nothing is stored. */
    @PostMapping("/forces/{operationId}/preview")
    public Impact preview(@PathVariable String operationId, @RequestBody ForceService.Request req) {
        return forces.preview(operationId, req);
    }

    /** Sets or replaces the FORCE on the operation; 409 with the reason when it is impossible. */
    @PutMapping("/forces/{operationId}")
    public Board apply(Authentication auth, @PathVariable String operationId, @RequestBody ForceService.Request req) {
        return forces.apply(operationId, req, CurrentUser.from(auth));
    }

    @DeleteMapping("/forces/{operationId}")
    public Board remove(Authentication auth, @PathVariable String operationId) {
        return forces.remove(operationId, CurrentUser.from(auth));
    }

    /** Re-plans from scratch (FORCEs kept, no stability preference) and makes that the new reference plan. */
    @PostMapping("/reoptimize")
    public Board reoptimize(Authentication auth) {
        return forces.reoptimize(CurrentUser.from(auth));
    }
}
