package com.aiso.imports;

import com.aiso.domain.AppUser;
import com.aiso.domain.ImportLog;
import com.aiso.domain.Item;
import com.aiso.domain.Operation;
import com.aiso.domain.OperationStatus;
import com.aiso.domain.Predecessor;
import com.aiso.domain.Project;
import com.aiso.domain.ProjectStatus;
import com.aiso.domain.Role;
import com.aiso.domain.SystemSetting;
import com.aiso.domain.WorkResource;
import com.aiso.imports.ImportResult.Count;
import com.aiso.imports.ImportResult.NewUser;
import com.aiso.imports.ImportResult.ProjectRef;
import com.aiso.imports.ParsedData.ItemRow;
import com.aiso.imports.ParsedData.OpRow;
import com.aiso.imports.ParsedData.PredRow;
import com.aiso.imports.ParsedData.ResRow;
import com.aiso.imports.ParsedData.UserRow;
import com.aiso.repo.ImportLogRepository;
import com.aiso.repo.ItemRepository;
import com.aiso.repo.OperationRepository;
import com.aiso.repo.PredecessorRepository;
import com.aiso.repo.ProjectRepository;
import com.aiso.repo.UserRepository;
import com.aiso.repo.WorkResourceRepository;
import com.aiso.security.CurrentUser;
import com.aiso.service.AuditService;
import com.aiso.service.DependencyService;
import com.aiso.service.GraphUtil;
import com.aiso.service.ProjectData;
import com.aiso.service.ProjectDataService;
import com.aiso.service.Scheduling.Impact;
import com.aiso.service.SchedulingService;
import com.aiso.service.SettingsService;
import com.aiso.web.ApiException;
import org.apache.poi.ss.usermodel.Workbook;
import org.apache.poi.ss.usermodel.WorkbookFactory;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.stereotype.Service;
import org.springframework.transaction.support.TransactionTemplate;

import java.io.IOException;
import java.io.InputStream;
import java.security.SecureRandom;
import java.time.Instant;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.stream.Collectors;

/**
 * Validates and imports the Excel master file.
 * <p>
 * Nothing is written unless the whole file validates. Every problem is reported with sheet, row, column and a
 * description. Re-importing is an upsert by business id: operations already started keep their status, and
 * structural changes (resource, predecessors) to them are rejected.
 */
@Service
public class ImportService {

    private static final Logger log = LoggerFactory.getLogger(ImportService.class);
    private static final String ALPHABET = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789";

    private final UserRepository users;
    private final WorkResourceRepository resources;
    private final ItemRepository items;
    private final OperationRepository operations;
    private final PredecessorRepository predecessors;
    private final ImportLogRepository logs;
    private final SettingsService settingsService;
    private final DependencyService dependencies;
    private final AuditService audit;
    private final PasswordEncoder encoder;
    private final TransactionTemplate tx;
    private final ProjectRepository projects;
    private final ProjectDataService dataService;
    private final SchedulingService scheduling;
    private final SecureRandom random = new SecureRandom();

    public ImportService(UserRepository users, WorkResourceRepository resources, ItemRepository items,
                         OperationRepository operations, PredecessorRepository predecessors, ImportLogRepository logs,
                         SettingsService settingsService, DependencyService dependencies, AuditService audit,
                         PasswordEncoder encoder, TransactionTemplate tx, ProjectRepository projects,
                         ProjectDataService dataService, SchedulingService scheduling) {
        this.users = users;
        this.resources = resources;
        this.items = items;
        this.operations = operations;
        this.predecessors = predecessors;
        this.logs = logs;
        this.settingsService = settingsService;
        this.dependencies = dependencies;
        this.audit = audit;
        this.encoder = encoder;
        this.tx = tx;
        this.projects = projects;
        this.dataService = dataService;
        this.scheduling = scheduling;
    }

    public ImportResult process(InputStream in, String fileName, boolean apply, CurrentUser actor) {
        return process(in, fileName, apply, actor, ImportTarget.none());
    }

    /**
     * @param apply false = validate only (dry run); true = validate and, when clean, apply
     * @param target which project the file belongs to and, for a new project, the priority ranking
     */
    public ImportResult process(InputStream in, String fileName, boolean apply, CurrentUser actor, ImportTarget target) {
        ParsedData data;
        try (Workbook wb = WorkbookFactory.create(in)) {
            if (SimpleParser.matches(wb)) {
                data = SimpleParser.parse(wb);
            } else if (SheetReader.has(wb, "Resources") && SheetReader.has(wb, "OPC")) {
                data = MasterParser.parse(wb);
            } else {
                ParsedData bad = new ParsedData();
                bad.format = "UNKNOWN";
                bad.error("-", 0, "-", "Unrecognised workbook. Expected the master template (sheets Resources, OPC, ...) "
                        + "or the simple layout (sheets opc and source).");
                return finish(bad, "REJECTED", fileName, actor, List.of(), List.of(), null);
            }
        } catch (IOException | RuntimeException e) {
            ParsedData bad = new ParsedData();
            bad.format = "UNKNOWN";
            bad.error("-", 0, "-", "The file is not a readable Excel workbook: " + e.getMessage());
            return finish(bad, "REJECTED", fileName, actor, List.of(), List.of(), null);
        }

        Resolved r = resolveTarget(target, data);
        Map<String, Integer> ranks = r == null ? null : resolveRanking(target, r, data);
        if (data.errors.isEmpty() && r != null) {
            validate(data, actor, r);
        }
        if (!data.errors.isEmpty() || r == null) {
            return finish(data, "REJECTED", fileName, actor, List.of(), List.of(), extras(r, null));
        }

        boolean needsPriority = r.isNew() && !r.others().isEmpty() && ranks == null;
        Impact impact = impact(data, r, ranks != null ? ranks : defaultRanking(r));
        Extras ex = extras(r, impact);
        if (needsPriority) {
            return finish(data, "PRIORITY_REQUIRED", fileName, actor, summaryCounts(data), List.of(), ex);
        }
        if (!apply) {
            return finish(data, "VALID", fileName, actor, summaryCounts(data), List.of(), ex);
        }

        List<NewUser> created = new ArrayList<>();
        List<Count> counts = tx.execute(status -> applyData(data, actor, created, r, ranks));
        return finish(data, "APPLIED", fileName, actor, counts, created, ex);
    }

    // ---- target project & priorities --------------------------------------------------------------------------

    /** @param existing null when the import creates the project; @param others the other ACTIVE projects, by priority */
    private record Resolved(Project existing, String id, String name, boolean isNew, Instant dueDate, List<Project> others) {
    }

    private Resolved resolveTarget(ImportTarget t, ParsedData d) {
        List<Project> active = projects.findByStatusOrderByPriorityAscIdAsc(ProjectStatus.ACTIVE);
        if (t.projectId() != null && !t.projectId().isBlank()) {
            Project p = projects.findById(t.projectId().trim()).orElse(null);
            if (p == null) {
                d.error("-", 0, "projectId", "Project '" + t.projectId() + "' does not exist");
                return null;
            }
            if (p.getStatus() != ProjectStatus.ACTIVE) {
                d.error("-", 0, "projectId", "Project '" + p.getId() + "' is archived");
                return null;
            }
            return new Resolved(p, p.getId(), p.getName(), false, null,
                    active.stream().filter(x -> !x.getId().equals(p.getId())).toList());
        }
        if (t.newProjectName() != null && !t.newProjectName().isBlank()) {
            String id = t.newProjectId() != null && !t.newProjectId().isBlank()
                    ? t.newProjectId().trim() : uniqueProjectId("PRJ-" + (projects.count() + 1));
            if (!id.matches("[A-Za-z0-9_.-]{1,64}")) {
                d.error("-", 0, "newProjectId", "Project id may only contain letters, digits, dot, dash and underscore");
                return null;
            }
            if (projects.existsById(id)) {
                d.error("-", 0, "newProjectId", "Project id '" + id + "' already exists");
                return null;
            }
            return new Resolved(null, id, t.newProjectName().trim(), true, t.dueDate(), active);
        }
        if (active.size() == 1) {
            Project p = active.get(0);
            return new Resolved(p, p.getId(), p.getName(), false, null, List.of());
        }
        if (active.isEmpty()) {
            SystemSetting s = settingsService.get();
            String base = s.getProjectId() == null || s.getProjectId().isBlank() ? "P-001" : s.getProjectId();
            String id = uniqueProjectId(base);
            String name = s.getProjectName() == null || s.getProjectName().isBlank() ? id : s.getProjectName();
            return new Resolved(null, id, name, true, t.dueDate(), List.of());
        }
        d.error("-", 0, "projectId", "Several projects are active. Choose the target project (projectId) or create a new one (newProjectName).");
        return null;
    }

    private String uniqueProjectId(String base) {
        String id = base;
        for (int i = 2; projects.existsById(id); i++) {
            id = base + "-" + i;
        }
        return id;
    }

    /**
     * Validates the requested priority order. Returns project id -> rank for all active projects including the new
     * one, or null when no ranking applies (existing project, first project, or none supplied yet).
     */
    private Map<String, Integer> resolveRanking(ImportTarget t, Resolved r, ParsedData d) {
        if (!r.isNew()) {
            return null;
        }
        if (r.others().isEmpty()) {
            return Map.of(r.id(), 1);
        }
        if (t.ranking() == null || t.ranking().isEmpty()) {
            return null;
        }
        List<String> order = t.ranking().stream().map(x -> x.equals(r.id()) ? ImportTarget.NEW : x).toList();
        Set<String> expected = new HashSet<>();
        r.others().forEach(p -> expected.add(p.getId()));
        expected.add(ImportTarget.NEW);
        Set<String> seen = new HashSet<>();
        for (String id : order) {
            if (!seen.add(id) || !expected.contains(id)) {
                d.error("-", 0, "ranking", "Ranking entry '" + id + "' is duplicated or not an active project");
                return null;
            }
        }
        if (seen.size() != expected.size()) {
            d.error("-", 0, "ranking", "The ranking must list every active project and NEW exactly once");
            return null;
        }
        Map<String, Integer> ranks = new HashMap<>();
        for (int i = 0; i < order.size(); i++) {
            ranks.put(order.get(i).equals(ImportTarget.NEW) ? r.id() : order.get(i), i + 1);
        }
        return ranks;
    }

    /** Ranks used for the preview when none was given yet: others keep their order, a new project goes last. */
    private static Map<String, Integer> defaultRanking(Resolved r) {
        Map<String, Integer> ranks = new HashMap<>();
        int i = 1;
        for (Project p : r.others()) {
            ranks.put(p.getId(), r.existing() != null ? p.getPriority() : i);
            i++;
        }
        if (r.existing() != null) {
            ranks.put(r.existing().getId(), r.existing().getPriority());
        } else {
            ranks.put(r.id(), i);
        }
        return ranks;
    }

    private record Extras(String projectId, String projectName, boolean isNew, List<ProjectRef> active, Impact impact) {
    }

    private static Extras extras(Resolved r, Impact impact) {
        if (r == null) {
            return null;
        }
        return new Extras(r.id(), r.name(), r.isNew(),
                r.others().stream().map(p -> new ProjectRef(p.getId(), p.getName(), p.getPriority())).toList(), impact);
    }

    // ---- schedule impact (what-if, nothing is stored) ---------------------------------------------------------

    private Impact impact(ParsedData d, Resolved r, Map<String, Integer> ranks) {
        try {
            ProjectData base = dataService.load();
            ProjectData hypo = hypothetical(base, d, r, ranks);
            return scheduling.impact(base, hypo, ranks);
        } catch (RuntimeException e) {
            log.warn("Could not compute schedule impact", e);
            return null;
        }
    }

    /** The project data as it would be after the import, built in memory from copies. */
    private ProjectData hypothetical(ProjectData base, ParsedData d, Resolved r, Map<String, Integer> ranks) {
        Map<String, Project> projs = new LinkedHashMap<>();
        base.projects().forEach((id, p) -> projs.put(id, copy(p)));
        if (r.isNew()) {
            Project np = new Project();
            np.setId(r.id());
            np.setName(r.name());
            np.setDueDate(r.dueDate());
            np.setPriority(1);
            projs.put(r.id(), np);
        }
        if (ranks != null) {
            projs.values().forEach(p -> {
                Integer rk = ranks.get(p.getId());
                if (rk != null) {
                    p.setPriority(rk);
                }
            });
        }

        Map<String, WorkResource> res = new LinkedHashMap<>(base.resources());
        for (ResRow rr : d.resources) {
            WorkResource w = new WorkResource();
            w.setId(rr.id());
            w.setName(rr.name());
            w.setCapacity(rr.capacity());
            w.setStatus(rr.status());
            res.put(rr.id(), w);
        }

        Map<String, Operation> ops = new LinkedHashMap<>(base.ops());
        for (OpRow o : d.ops) {
            Operation old = base.ops().get(o.id());
            Operation op = new Operation();
            op.setId(o.id());
            op.setProjectId(r.id());
            op.setName(o.name());
            op.setResourceId(o.resourceId());
            op.setPreparationTime(o.prep());
            op.setTransportTime(o.transport());
            op.setSetupTime(o.setup());
            op.setDirectTime(o.direct());
            if (old != null) {
                op.setStatus(old.getStatus());
                op.setProgressPercent(old.getProgressPercent());
            } else {
                op.setStatus(o.status() == null ? OperationStatus.NOT_READY : o.status());
            }
            ops.put(o.id(), op);
        }

        Set<String> replaced = new HashSet<>();
        if (d.hasPredecessorSheet) {
            d.ops.forEach(o -> replaced.add(o.id()));
            d.preds.forEach(p -> replaced.add(p.operationId()));
        }
        List<Predecessor> all = new ArrayList<>();
        base.predecessors().values().forEach(list -> list.stream()
                .filter(p -> !replaced.contains(p.getOperationId())).forEach(all::add));
        for (PredRow pr : d.preds) {
            Predecessor p = new Predecessor();
            p.setOperationId(pr.operationId());
            p.setPredecessorId(pr.predecessorId());
            p.setDependencyType(pr.type());
            p.setMandatory(pr.mandatory());
            all.add(p);
        }
        Map<String, List<Predecessor>> predsByOp = new HashMap<>();
        Map<String, List<String>> succ = new HashMap<>();
        for (Predecessor p : all) {
            predsByOp.computeIfAbsent(p.getOperationId(), k -> new ArrayList<>()).add(p);
            succ.computeIfAbsent(p.getPredecessorId(), k -> new ArrayList<>()).add(p.getOperationId());
        }
        return new ProjectData(base.settings(), projs, ops, res, base.users(), base.items(), predsByOp, succ, base.now());
    }

    private static Project copy(Project p) {
        Project c = new Project();
        c.setId(p.getId());
        c.setName(p.getName());
        c.setPriority(p.getPriority());
        c.setDueDate(p.getDueDate());
        c.setStatus(p.getStatus());
        return c;
    }

    // ---- validation -------------------------------------------------------------------------------------------

    private void validate(ParsedData d, CurrentUser actor, Resolved target) {
        Map<String, AppUser> dbUsers = users.findAll().stream().collect(Collectors.toMap(AppUser::getId, u -> u));
        Map<String, WorkResource> dbResources = resources.findAll().stream().collect(Collectors.toMap(WorkResource::getId, r -> r));
        Map<String, Item> dbItems = items.findAll().stream().collect(Collectors.toMap(Item::getId, i -> i));
        Map<String, Operation> dbOps = operations.findAll().stream().collect(Collectors.toMap(Operation::getId, o -> o));
        List<Predecessor> dbPreds = predecessors.findAll();

        duplicates(d.resourceSheet, d.resources.stream().map(r -> new Dup(r.row(), r.id())).toList(), "Resource_ID", d);
        duplicates(d.itemSheet, d.items.stream().map(r -> new Dup(r.row(), r.id())).toList(), "Item_ID", d);
        duplicates(d.opSheet, d.ops.stream().map(r -> new Dup(r.row(), r.id())).toList(), "Operation_ID", d);
        duplicates("Users", d.users.stream().map(r -> new Dup(r.row(), r.id())).toList(), "User_ID", d);

        // operations are globally unique: an id already used by another project cannot be taken over
        for (OpRow o : d.ops) {
            Operation existing = dbOps.get(o.id());
            if (existing != null && !existing.getProjectId().equals(target.id())) {
                d.error(d.opSheet, o.row(), "Operation_ID", "Operation '" + o.id() + "' already belongs to project "
                        + existing.getProjectId() + "; operation ids must be unique across projects");
            }
        }
        for (ResRow rr : d.resources) {
            WorkResource old = dbResources.get(rr.id());
            if (old != null && old.getCapacity() != rr.capacity()) {
                d.warnings.add("Resource " + rr.id() + " is shared by all projects: its capacity changes from "
                        + old.getCapacity() + " to " + rr.capacity() + ".");
            }
        }

        // users
        Map<String, Role> roleOf = new HashMap<>();
        dbUsers.values().forEach(u -> roleOf.put(u.getId(), u.getRole()));
        for (UserRow u : d.users) {
            AppUser existing = dbUsers.get(u.id());
            if (u.role() == Role.OWNER || (existing != null && existing.getRole() == Role.OWNER)) {
                d.error("Users", u.row(), "Role", "The OWNER account cannot be created or changed by an import");
                continue;
            }
            if (u.role() == Role.MANAGER && !actor.isOwner()) {
                d.error("Users", u.row(), "Role", "Only the OWNER can import a MANAGER");
                continue;
            }
            if (existing != null && existing.getRole() == Role.MANAGER && !actor.isOwner()) {
                d.error("Users", u.row(), "User_ID", "Only the OWNER can change the MANAGER account");
                continue;
            }
            roleOf.put(u.id(), u.role());
        }
        Set<String> executors = roleOf.entrySet().stream().filter(e -> e.getValue().isExecutor())
                .map(Map.Entry::getKey).collect(Collectors.toSet());

        Set<String> resourceIds = new HashSet<>(dbResources.keySet());
        d.resources.forEach(r -> resourceIds.add(r.id()));
        Set<String> itemIds = new HashSet<>(dbItems.keySet());
        d.items.forEach(i -> itemIds.add(i.id()));
        Set<String> opIds = new HashSet<>(dbOps.keySet());
        d.ops.forEach(o -> opIds.add(o.id()));

        for (ResRow r : d.resources) {
            if (r.userId() != null && !executors.contains(r.userId())) {
                d.error(d.resourceSheet, r.row(), "Responsible_User_ID", "User '" + r.userId() + "' does not exist or is not an executive user");
            }
        }
        for (ItemRow i : d.items) {
            if (i.relatedOperationId() != null && !opIds.contains(i.relatedOperationId())) {
                d.error(d.itemSheet, i.row(), "Related_Operation_ID", "Operation '" + i.relatedOperationId() + "' does not exist");
            }
        }
        for (OpRow o : d.ops) {
            if (o.resourceId() != null && !resourceIds.contains(o.resourceId())) {
                d.error(d.opSheet, o.row(), "Resource_ID", "Resource '" + o.resourceId() + "' does not exist");
            }
            if (o.itemId() != null && !itemIds.contains(o.itemId())) {
                d.error(d.opSheet, o.row(), "Item_ID", "Item '" + o.itemId() + "' does not exist in the BOM");
            }
            if (o.userId() != null && !executors.contains(o.userId())) {
                d.error(d.opSheet, o.row(), "Responsible_User_ID", "User '" + o.userId() + "' does not exist or is not an executive user");
            }
        }

        // predecessors
        Set<String> seenPairs = new HashSet<>();
        Set<String> fileOpIds = d.ops.stream().map(OpRow::id).collect(Collectors.toSet());
        for (PredRow p : d.preds) {
            Operation dbOp = dbOps.get(p.operationId());
            Operation dbPred = dbOps.get(p.predecessorId());
            if (dbPred != null && !dbPred.getProjectId().equals(target.id())) {
                d.error(d.predSheet, p.row(), d.predColumn, "Predecessor '" + p.predecessorId()
                        + "' belongs to project " + dbPred.getProjectId() + "; dependencies cannot cross projects");
            }
            if (dbOp != null && !dbOp.getProjectId().equals(target.id())) {
                d.error(d.predSheet, p.row(), "Operation_ID", "Operation '" + p.operationId()
                        + "' belongs to project " + dbOp.getProjectId());
            }
            if (!opIds.contains(p.operationId())) {
                d.error(d.predSheet, p.row(), "Operation_ID", "Operation '" + p.operationId() + "' does not exist");
            }
            if (!opIds.contains(p.predecessorId())) {
                d.error(d.predSheet, p.row(), d.predColumn, "Predecessor '" + p.predecessorId() + "' does not exist");
            }
            if (p.operationId().equals(p.predecessorId())) {
                d.error(d.predSheet, p.row(), d.predColumn, "An operation cannot be its own predecessor");
            }
            if (!seenPairs.add(p.operationId() + ">" + p.predecessorId())) {
                d.error(d.predSheet, p.row(), d.predColumn, "Duplicate predecessor " + p.predecessorId() + " for " + p.operationId());
            }
            if (d.hasPredecessorSheet && !fileOpIds.contains(p.operationId()) && opIds.contains(p.operationId())) {
                d.warnings.add("Predecessors row " + p.row() + ": operation " + p.operationId()
                        + " is not in the OPC sheet of this file; its predecessors are replaced anyway.");
            }
        }

        // settings
        if (d.settings != null) {
            if (d.settings.ownerId() != null && !roleOf.containsKey(d.settings.ownerId())) {
                d.error("Settings", d.settings.row(), "Owner_ID", "User '" + d.settings.ownerId() + "' does not exist");
            }
            if (d.settings.managerId() != null && !roleOf.containsKey(d.settings.managerId())) {
                d.error("Settings", d.settings.row(), "Manager_ID", "User '" + d.settings.managerId() + "' does not exist");
            }
        }

        // structural changes to operations that have already started
        Map<String, Set<String>> dbPredSets = new HashMap<>();
        dbPreds.forEach(p -> dbPredSets.computeIfAbsent(p.getOperationId(), k -> new HashSet<>())
                .add(p.getPredecessorId() + ":" + p.getDependencyType() + ":" + p.isMandatory()));
        Map<String, Set<String>> filePredSets = new HashMap<>();
        d.preds.forEach(p -> filePredSets.computeIfAbsent(p.operationId(), k -> new HashSet<>())
                .add(p.predecessorId() + ":" + p.type() + ":" + p.mandatory()));
        for (OpRow o : d.ops) {
            Operation existing = dbOps.get(o.id());
            if (existing == null || existing.getStatus() == OperationStatus.NOT_READY || existing.getStatus() == OperationStatus.READY) {
                continue;
            }
            if (o.resourceId() != null && !o.resourceId().equals(existing.getResourceId())) {
                d.error(d.opSheet, o.row(), "Resource_ID", "Operation " + o.id() + " is " + existing.getStatus()
                        + "; its resource cannot be changed");
            }
            if (d.hasPredecessorSheet && !dbPredSets.getOrDefault(o.id(), Set.of()).equals(filePredSets.getOrDefault(o.id(), Set.of()))) {
                d.error(d.opSheet, o.row(), "Operation_ID", "Operation " + o.id() + " is " + existing.getStatus()
                        + "; its predecessors cannot be changed");
            }
        }

        // cycles in the merged graph
        Map<String, Set<String>> merged = new HashMap<>();
        for (String id : opIds) {
            merged.put(id, new HashSet<>());
        }
        for (Predecessor p : dbPreds) {
            if (!d.hasPredecessorSheet || !fileOpIds.contains(p.getOperationId())) {
                merged.computeIfAbsent(p.getOperationId(), k -> new HashSet<>()).add(p.getPredecessorId());
            }
        }
        for (PredRow p : d.preds) {
            if (opIds.contains(p.operationId()) && opIds.contains(p.predecessorId()) && !p.operationId().equals(p.predecessorId())) {
                merged.computeIfAbsent(p.operationId(), k -> new HashSet<>()).add(p.predecessorId());
            }
        }
        List<String> cycle = GraphUtil.findCycle(merged);
        if (!cycle.isEmpty()) {
            int row = d.preds.stream()
                    .filter(p -> p.operationId().equals(cycle.get(0)) && p.predecessorId().equals(cycle.get(1)))
                    .map(PredRow::row).findFirst().orElse(1);
            d.error(d.predSheet, row, d.predColumn, "Dependency cycle: " + String.join(" -> ", cycle)
                    + ". A real loop in the process must be resolved by the manager, it is not removed automatically.");
        }

        long missing = dbOps.values().stream().filter(o -> o.getProjectId().equals(target.id()) && !fileOpIds.contains(o.getId())).count();
        if (missing > 0 && !d.ops.isEmpty()) {
            d.warnings.add(missing + " existing operation(s) are not in this file and were left unchanged.");
        }
    }

    private record Dup(int row, String id) {
    }

    private void duplicates(String sheet, List<Dup> rows, String column, ParsedData d) {
        Map<String, Integer> first = new HashMap<>();
        for (Dup r : rows) {
            Integer prev = first.putIfAbsent(r.id(), r.row());
            if (prev != null) {
                d.error(sheet, r.row(), column, "Duplicate id '" + r.id() + "' (first used in row " + prev + ")");
            }
        }
    }

    // ---- apply ------------------------------------------------------------------------------------------------

    private List<Count> applyData(ParsedData d, CurrentUser actor, List<NewUser> createdUsers, Resolved target,
                                  Map<String, Integer> ranks) {
        Map<String, Integer> created = new LinkedHashMap<>();
        Map<String, Integer> updated = new LinkedHashMap<>();
        for (String k : List.of("users", "resources", "items", "operations", "predecessors")) {
            created.put(k, 0);
            updated.put(k, 0);
        }

        if (target.isNew()) {
            Project np = new Project();
            np.setId(target.id());
            np.setName(target.name());
            np.setDueDate(target.dueDate());
            np.setPriority(ranks == null ? 1 : ranks.getOrDefault(target.id(), 1));
            projects.save(np);
            audit.record(actor.id(), "PROJECT_CREATED", "Project", np.getId(), null,
                    np.getName() + " (priority " + np.getPriority() + ")", "Excel import");
            if (ranks != null && !target.others().isEmpty()) {
                StringBuilder before = new StringBuilder();
                StringBuilder after = new StringBuilder();
                for (Project other : target.others()) {
                    before.append(other.getId()).append('=').append(other.getPriority()).append(' ');
                    Project fresh = projects.findById(other.getId()).orElse(other);
                    fresh.setPriority(ranks.get(other.getId()));
                    projects.save(fresh);
                    after.append(other.getId()).append('=').append(fresh.getPriority()).append(' ');
                }
                audit.record(actor.id(), "PROJECT_PRIORITIES_CHANGED", "Project", "*", before.toString().trim(),
                        after.append(np.getId()).append('=').append(np.getPriority()).toString(),
                        "New project " + np.getId() + " added");
            }
            projects.flush();
        }

        SystemSetting s = settingsService.get();
        if (d.settings != null) {
            if (d.settings.projectId() != null) s.setProjectId(d.settings.projectId());
            if (d.settings.projectName() != null) s.setProjectName(d.settings.projectName());
            if (d.settings.ownerId() != null) s.setOwnerId(d.settings.ownerId());
            if (d.settings.managerId() != null) s.setManagerId(d.settings.managerId());
            if (d.settings.messengerPlatform() != null) s.setMessengerPlatform(d.settings.messengerPlatform().toUpperCase());
        }
        String oldVersion = s.getDataVersion();
        s.setDataVersion(d.settings != null && d.settings.dataVersion() != null ? d.settings.dataVersion() : nextVersion(oldVersion));
        settingsService.saveChanged(s);

        for (UserRow u : d.users) {
            AppUser existing = users.findById(u.id()).orElse(null);
            if (existing == null) {
                String temp = tempPassword();
                AppUser n = new AppUser();
                n.setId(u.id());
                n.setFullName(u.name());
                n.setRole(u.role());
                n.setMessengerId(u.messengerId());
                n.setActive(u.active());
                n.setContactInfo(u.contact());
                n.setPasswordHash(encoder.encode(temp));
                n.setMustChangePassword(true);
                users.save(n);
                createdUsers.add(new NewUser(u.id(), u.name(), temp));
                audit.record(actor.id(), "USER_CREATED", "User", u.id(), null, u.role(), "Excel import");
                created.merge("users", 1, Integer::sum);
            } else {
                String before = existing.getRole() + "/" + existing.isActive();
                existing.setFullName(u.name());
                existing.setRole(u.role());
                existing.setMessengerId(u.messengerId());
                existing.setActive(u.active());
                existing.setContactInfo(u.contact());
                users.save(existing);
                audit.record(actor.id(), "USER_UPDATED", "User", u.id(), before, u.role() + "/" + u.active(), "Excel import");
                updated.merge("users", 1, Integer::sum);
            }
        }

        for (ResRow r : d.resources) {
            WorkResource res = resources.findById(r.id()).orElse(null);
            boolean isNew = res == null;
            String before = isNew ? null : describe(res);
            if (isNew) {
                res = new WorkResource();
                res.setId(r.id());
            }
            res.setName(r.name());
            res.setResourceType(r.type());
            res.setResponsibleUserId(r.userId());
            res.setCapacity(r.capacity());
            res.setStatus(r.status());
            res.setDescription(r.description());
            resources.save(res);
            audit.record(actor.id(), isNew ? "RESOURCE_CREATED" : "RESOURCE_UPDATED", "Resource", r.id(), before, describe(res), "Excel import");
            (isNew ? created : updated).merge("resources", 1, Integer::sum);
        }

        for (ItemRow i : d.items) {
            Item item = items.findById(i.id()).orElse(null);
            boolean isNew = item == null;
            if (isNew) {
                item = new Item();
                item.setId(i.id());
            }
            item.setName(i.name());
            item.setQuantity(i.quantity());
            item.setUnit(i.unit());
            item.setSupplyType(i.supplyType());
            item.setRelatedOperationId(i.relatedOperationId());
            item.setDescription(i.description());
            items.save(item);
            (isNew ? created : updated).merge("items", 1, Integer::sum);
        }

        Instant now = Instant.now();
        for (OpRow o : d.ops) {
            Operation op = operations.findById(o.id()).orElse(null);
            boolean isNew = op == null;
            String before = isNew ? null : describe(op);
            if (isNew) {
                op = new Operation();
                op.setId(o.id());
                op.setProjectId(target.id());
                OperationStatus st = o.status() == null ? OperationStatus.NOT_READY : o.status();
                op.setStatus(st);
                if (st == OperationStatus.COMPLETED) {
                    op.setCompletedAt(now);
                    op.setProgressPercent(100);
                    op.setCompletionApproved(true);
                } else if (st == OperationStatus.CANCELLED) {
                    op.setCancelReason("Imported as CANCELLED");
                }
            }
            op.setName(o.name());
            op.setItemId(o.itemId());
            op.setResourceId(o.resourceId());
            op.setResponsibleUserId(o.userId());
            op.setPreparationTime(o.prep());
            op.setTransportTime(o.transport());
            op.setSetupTime(o.setup());
            op.setDirectTime(o.direct());
            op.setDescription(o.description());
            op.setUpdatedAt(now);
            operations.save(op);
            audit.record(actor.id(), isNew ? "OPERATION_CREATED" : "OPERATION_UPDATED", "Operation", o.id(), before, describe(op), "Excel import");
            (isNew ? created : updated).merge("operations", 1, Integer::sum);
        }
        operations.flush();

        if (d.hasPredecessorSheet) {
            Set<String> ids = d.ops.stream().map(OpRow::id).collect(Collectors.toSet());
            d.preds.forEach(p -> ids.add(p.operationId()));
            predecessors.deleteByOperationIdIn(ids);
            predecessors.flush();
            for (PredRow p : d.preds) {
                Predecessor n = new Predecessor();
                n.setOperationId(p.operationId());
                n.setPredecessorId(p.predecessorId());
                n.setDependencyType(p.type());
                n.setStartCondition(p.condition());
                n.setMandatory(p.mandatory());
                n.setDescription(p.description());
                predecessors.save(n);
                created.merge("predecessors", 1, Integer::sum);
            }
        }

        audit.record(actor.id(), "IMPORT_APPLIED", "Import", d.format, oldVersion, s.getDataVersion(),
                "Created " + created + ", updated " + updated);
        dependencies.evaluateAll(actor.id());

        List<Count> out = new ArrayList<>();
        for (String k : created.keySet()) {
            out.add(new Count(k, created.get(k), updated.get(k)));
        }
        return out;
    }

    private List<Count> summaryCounts(ParsedData d) {
        return List.of(new Count("users", d.users.size(), 0), new Count("resources", d.resources.size(), 0),
                new Count("items", d.items.size(), 0), new Count("operations", d.ops.size(), 0),
                new Count("predecessors", d.preds.size(), 0));
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    private ImportResult finish(ParsedData d, String status, String fileName, CurrentUser actor,
                                List<Count> counts, List<NewUser> newUsers, Extras ex) {
        try {
            ImportLog entry = new ImportLog();
            entry.setActorId(actor.id());
            entry.setFileName(fileName);
            entry.setFormat(d.format);
            entry.setStatus(status);
            entry.setErrorCount(d.errors.size());
            entry.setSummary(status + (ex == null ? "" : " [" + ex.projectId() + "]") + " - " + d.errors.size()
                    + " error(s), " + d.warnings.size() + " warning(s)");
            String details = d.errors.stream().limit(100)
                    .map(e -> e.sheet() + " row " + e.row() + " [" + e.column() + "]: " + e.message())
                    .collect(Collectors.joining("\n"));
            entry.setDetails(details.length() > 9000 ? details.substring(0, 9000) : details);
            logs.save(entry);
        } catch (RuntimeException e) {
            log.warn("Could not write import log", e);
        }
        return new ImportResult(status, d.format, d.errors, d.warnings, counts, newUsers,
                ex == null ? null : ex.projectId(), ex == null ? null : ex.projectName(), ex != null && ex.isNew(),
                ex == null ? List.of() : ex.active(), ex == null ? null : ex.impact());
    }

    private static String describe(WorkResource r) {
        return "name=" + r.getName() + ", capacity=" + r.getCapacity() + ", status=" + r.getStatus()
                + ", responsible=" + r.getResponsibleUserId();
    }

    private static String describe(Operation o) {
        return "name=" + o.getName() + ", resource=" + o.getResourceId() + ", item=" + o.getItemId()
                + ", hours=" + o.totalHours() + ", fixedUser=" + o.getResponsibleUserId();
    }

    private static String nextVersion(String old) {
        try {
            return String.valueOf(Long.parseLong(old == null ? "0" : old.trim()) + 1);
        } catch (NumberFormatException e) {
            return old + ".1";
        }
    }

    private String tempPassword() {
        StringBuilder b = new StringBuilder(12);
        for (int i = 0; i < 12; i++) {
            b.append(ALPHABET.charAt(random.nextInt(ALPHABET.length())));
        }
        return b.toString();
    }

    /** Used by the demo-data endpoint. */
    public ImportResult processClasspath(String resource, boolean apply, CurrentUser actor) {
        try (InputStream in = getClass().getResourceAsStream(resource)) {
            if (in == null) {
                throw ApiException.notFound("Sample file " + resource + " not found");
            }
            return process(in, resource.substring(resource.lastIndexOf('/') + 1), apply, actor);
        } catch (IOException e) {
            throw new IllegalStateException(e);
        }
    }
}
