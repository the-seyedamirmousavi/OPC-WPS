package com.aiso.service;

import com.aiso.domain.AppUser;
import com.aiso.domain.Item;
import com.aiso.domain.Operation;
import com.aiso.domain.OperationReport;
import com.aiso.domain.OperationStatus;
import com.aiso.domain.Predecessor;
import com.aiso.repo.OperationReportRepository;
import com.aiso.service.Views.OperationDetail;
import com.aiso.service.Views.OperationView;
import com.aiso.service.Views.PredView;
import com.aiso.service.Views.ReportView;
import com.aiso.web.ApiException;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Duration;
import java.util.ArrayList;
import java.util.List;

@Service
public class OperationViewService {

    private final ProjectDataService dataService;
    private final PlanService planService;
    private final OperationReportRepository reports;

    public OperationViewService(ProjectDataService dataService, PlanService planService, OperationReportRepository reports) {
        this.dataService = dataService;
        this.planService = planService;
        this.reports = reports;
    }

    @Transactional
    public List<OperationView> all() {
        ProjectData data = dataService.load();
        PlanService.PlanView plan = planService.plan(data);
        return data.ops().values().stream().map(o -> toView(o, data, plan)).toList();
    }

    @Transactional
    public List<OperationView> forUser(String userId) {
        return all().stream().filter(v -> userId.equals(v.assignedUserId())).toList();
    }

    @Transactional
    public OperationDetail detail(String id) {
        ProjectData data = dataService.load();
        Operation op = data.ops().get(id);
        if (op == null) {
            throw ApiException.notFound("Operation " + id + " not found");
        }
        PlanService.PlanView plan = planService.plan(data);
        List<ReportView> rv = new ArrayList<>();
        for (OperationReport r : reports.findByOperationIdOrderByCreatedAtAsc(id)) {
            rv.add(new ReportView(r.getId(), r.getUserId(), data.userName(r.getUserId()), r.getReportType(),
                    r.getProgressPercent(), r.getNote(), r.getCreatedAt()));
        }
        List<PredView> succ = new ArrayList<>();
        for (String sid : data.successors().getOrDefault(id, List.of())) {
            Operation s = data.ops().get(sid);
            Predecessor edge = data.predsOf(sid).stream().filter(p -> p.getPredecessorId().equals(id)).findFirst().orElse(null);
            if (s != null && edge != null) {
                succ.add(new PredView(s.getId(), s.getName(), s.getStatus(), edge.getDependencyType(), edge.isMandatory(),
                        DependencyService.satisfied(edge, op, data.settings().isRequireCompletionApproval())));
            }
        }
        return new OperationDetail(toView(op, data, plan), rv, succ);
    }

    public OperationView toView(Operation o, ProjectData data, PlanService.PlanView plan) {
        boolean requireApproval = data.settings().isRequireCompletionApproval();
        List<PredView> preds = new ArrayList<>();
        List<PredView> waiting = new ArrayList<>();
        for (Predecessor p : data.predsOf(o.getId())) {
            Operation pred = data.ops().get(p.getPredecessorId());
            boolean ok = pred != null && DependencyService.satisfied(p, pred, requireApproval);
            PredView pv = new PredView(p.getPredecessorId(), pred == null ? "?" : pred.getName(),
                    pred == null ? null : pred.getStatus(), p.getDependencyType(), p.isMandatory(), ok);
            preds.add(pv);
            if (!ok) {
                waiting.add(pv);
            }
        }
        Item item = o.getItemId() == null ? null : data.items().get(o.getItemId());
        AppUser assigned = o.getAssignedUserId() == null ? null : data.users().get(o.getAssignedUserId());
        OperationStatus st = o.getStatus();

        boolean delayed = !st.isTerminal() && o.getPlannedEnd() != null && data.now().isAfter(o.getPlannedEnd());
        double delayHours = delayed ? Duration.between(o.getPlannedEnd(), data.now()).toSeconds() / 3600.0 : 0;
        boolean late = st == OperationStatus.COMPLETED && o.getPlannedEnd() != null && o.getCompletedAt() != null
                && o.getCompletedAt().isAfter(o.getPlannedEnd());

        return new OperationView(
                o.getId(), o.getName(),
                o.getProjectId(), data.projectName(o.getProjectId()), data.rankOf(o),
                o.getItemId(), item == null ? null : item.getName(),
                o.getResourceId(), data.resourceName(o.getResourceId()),
                o.getResponsibleUserId(),
                o.getAssignedUserId(), assigned == null ? null : assigned.getFullName(),
                st,
                o.totalHours(), o.getDirectTime(),
                o.getProgressPercent(),
                o.getPlannedStart(), o.getPlannedEnd(),
                plan.start(o.getId()), plan.end(o.getId()),
                o.getAssignedAt(), o.getStartedAt(), o.getCompletedAt(),
                o.isCompletionApproved(),
                o.getBlockReason(), o.getCancelReason(),
                preds, st == OperationStatus.NOT_READY ? waiting : List.of(),
                delayed, delayHours, late,
                plan.tailHours().getOrDefault(o.getId(), 0.0),
                o.getDescription());
    }
}
