package com.aiso.assign;

import com.aiso.assign.AssignmentContext.ReadyOp;
import com.aiso.assign.AssignmentContext.ResourceInfo;
import com.aiso.assign.AssignmentContext.UserInfo;
import com.aiso.assign.Suggestion.Pick;
import com.aiso.assign.Suggestion.Skip;
import com.aiso.domain.AssignmentMode;
import org.springframework.stereotype.Component;

import java.util.ArrayList;
import java.util.Comparator;
import java.util.List;
import java.util.Locale;

/**
 * Deterministic dispatching rule (same input, same output, fully explainable).
 * <ol>
 *   <li>Rank READY operations: project priority first, then the optimised plan's order (earlier planned start), then
 *       critical-path weight: longest remaining chain first, then longer own duration,
 *       then id.</li>
 *   <li>For each operation in that order, skip it if its resource has no free slot.</li>
 *   <li>Among the users allowed by {@link AssignmentState}, pick the one with the lowest effective load, where the
 *       resource's responsible user gets a configurable bonus (a soft preference). Ties: fewer tasks, then id.</li>
 *   <li>Update loads and resource slots, and continue.</li>
 * </ol>
 */
@Component
public class AlgorithmStrategy implements AssignmentStrategy {

    @Override
    public AssignmentMode mode() {
        return AssignmentMode.ALGORITHM;
    }

    @Override
    public Suggestion suggest(AssignmentContext ctx) {
        AssignmentState state = new AssignmentState(ctx);
        List<ReadyOp> ranked = new ArrayList<>(ctx.ready());
        // Higher-priority projects first, then the optimised plan's own order (earlier planned start first), then the
        // critical-path weight. This keeps the proposals consistent with the schedule the optimiser computed.
        ranked.sort(Comparator.comparingInt(ReadyOp::projectRank)
                .thenComparingLong((ReadyOp o) -> Math.round(o.plannedStartHours() * 1e6))
                .thenComparing(Comparator.comparingDouble(ReadyOp::tailHours).reversed())
                .thenComparing(Comparator.comparingDouble(ReadyOp::hours).reversed())
                .thenComparing(ReadyOp::id));

        List<Pick> picks = new ArrayList<>();
        List<Skip> skips = new ArrayList<>();
        int rank = 0;
        for (ReadyOp op : ranked) {
            rank++;
            ResourceInfo res = state.resources().get(op.resourceId());
            if (res == null || !res.active()) {
                skips.add(new Skip(op.id(), "Resource " + op.resourceName() + " is inactive"));
                continue;
            }
            if (state.freeSlots(op.resourceId()) <= 0) {
                skips.add(new Skip(op.id(), "Resource " + res.name() + " has no free slot (" + res.capacity() + " of "
                        + res.capacity() + " busy)"));
                continue;
            }
            List<UserInfo> allowed = ctx.users().stream()
                    .filter(u -> state.reject(op.id(), u.id()).isEmpty())
                    .toList();
            if (allowed.isEmpty()) {
                String why = op.fixedUserId() != null
                        ? "Fixed executor " + op.fixedUserId() + " is unavailable: "
                        + state.reject(op.id(), op.fixedUserId()).orElse("not allowed")
                        : "No executive user is below the active-task limit (" + ctx.maxActiveTasksPerUser() + ")";
                skips.add(new Skip(op.id(), why));
                continue;
            }
            UserInfo chosen = allowed.stream()
                    .min(Comparator.comparingDouble((UserInfo u) -> score(u, res, state, ctx))
                            .thenComparingInt(u -> state.tasksOf(u.id()))
                            .thenComparing(UserInfo::id))
                    .orElseThrow();

            String who;
            if (op.fixedUserId() != null) {
                who = "fixed executor in OPC";
            } else if (chosen.id().equals(res.responsibleUserId())) {
                who = String.format(Locale.ROOT, "responsible user of %s, load %.1f h", res.name(), state.loadOf(chosen.id()));
            } else {
                who = String.format(Locale.ROOT, "lowest load (%.1f h, %d active)", state.loadOf(chosen.id()), state.tasksOf(chosen.id()));
            }
            String reason = String.format(Locale.ROOT,
                    "Rank %d (chain %.1f h); %s slot %d of %d; %s: %s",
                    rank, op.tailHours(), res.name(), state.usedSlots(op.resourceId()) + 1, res.capacity(),
                    chosen.fullName(), who)
                    + (op.projectRank() > 0 ? String.format(Locale.ROOT, "; project %s (priority %d)", op.projectName(), op.projectRank()) : "");
            picks.add(new Pick(op.id(), chosen.id(), reason));
            state.apply(op.id(), chosen.id());
        }
        String summary = picks.size() + " of " + ranked.size() + " READY operations proposed by the dispatching rule"
                + (skips.isEmpty() ? "." : "; " + skips.size() + " left unassigned.");
        return Suggestion.of(picks, skips, summary);
    }

    private double score(UserInfo user, ResourceInfo res, AssignmentState state, AssignmentContext ctx) {
        double load = state.loadOf(user.id());
        return user.id().equals(res.responsibleUserId()) ? load - ctx.responsibleBonusHours() : load;
    }
}
