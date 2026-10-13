package com.aiso.service;

import com.aiso.domain.AppUser;
import com.aiso.domain.Item;
import com.aiso.domain.Operation;
import com.aiso.domain.PlanBaseline;
import com.aiso.domain.Predecessor;
import com.aiso.domain.Project;
import com.aiso.domain.ScheduleForce;
import com.aiso.domain.WorkResource;
import com.aiso.repo.ItemRepository;
import com.aiso.repo.OperationRepository;
import com.aiso.repo.PlanBaselineRepository;
import com.aiso.repo.PredecessorRepository;
import com.aiso.repo.ProjectRepository;
import com.aiso.repo.ScheduleForceRepository;
import com.aiso.repo.UserRepository;
import com.aiso.repo.WorkResourceRepository;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.time.temporal.ChronoUnit;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.TreeMap;
import java.util.function.Function;
import java.util.stream.Collectors;

@Service
public class ProjectDataService {

    private final OperationRepository operations;
    private final PredecessorRepository predecessors;
    private final WorkResourceRepository resources;
    private final UserRepository users;
    private final ItemRepository items;
    private final ProjectRepository projects;
    private final SettingsService settings;
    private final ScheduleForceRepository forces;
    private final PlanBaselineRepository baseline;

    public ProjectDataService(OperationRepository operations, PredecessorRepository predecessors,
                              WorkResourceRepository resources, UserRepository users, ItemRepository items,
                              ProjectRepository projects, SettingsService settings, ScheduleForceRepository forces,
                              PlanBaselineRepository baseline) {
        this.operations = operations;
        this.predecessors = predecessors;
        this.resources = resources;
        this.users = users;
        this.items = items;
        this.projects = projects;
        this.settings = settings;
        this.forces = forces;
        this.baseline = baseline;
    }

    @Transactional
    public ProjectData load() {
        Map<String, Operation> ops = new LinkedHashMap<>();
        operations.findAll().stream()
                .sorted((a, b) -> a.getId().compareTo(b.getId()))
                .forEach(o -> ops.put(o.getId(), o));

        Map<String, List<Predecessor>> preds = new HashMap<>();
        Map<String, List<String>> succ = new HashMap<>();
        for (Predecessor p : predecessors.findAll()) {
            preds.computeIfAbsent(p.getOperationId(), k -> new ArrayList<>()).add(p);
            succ.computeIfAbsent(p.getPredecessorId(), k -> new ArrayList<>()).add(p.getOperationId());
        }

        return new ProjectData(
                settings.get(),
                projects.findAll().stream().collect(Collectors.toMap(Project::getId, Function.identity(), (a, b) -> a, LinkedHashMap::new)),
                ops,
                resources.findAll().stream().collect(Collectors.toMap(WorkResource::getId, Function.identity(), (a, b) -> a, LinkedHashMap::new)),
                users.findAll().stream().collect(Collectors.toMap(AppUser::getId, Function.identity(), (a, b) -> a, LinkedHashMap::new)),
                items.findAll().stream().collect(Collectors.toMap(Item::getId, Function.identity(), (a, b) -> a, LinkedHashMap::new)),
                preds,
                succ,
                forces.findAll().stream().collect(Collectors.toMap(ScheduleForce::getOperationId, Function.identity(), (a, b) -> a, TreeMap::new)),
                baseline.findAll().stream().collect(Collectors.toMap(PlanBaseline::getOperationId, Function.identity(), (a, b) -> a, TreeMap::new)),
                Instant.now().truncatedTo(ChronoUnit.MILLIS)); // planner times are in ms: FORCEd instants round-trip exactly
    }
}
