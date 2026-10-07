package com.aiso.repo;

import com.aiso.domain.Project;
import com.aiso.domain.ProjectStatus;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;

public interface ProjectRepository extends JpaRepository<Project, String> {
    List<Project> findByStatusOrderByPriorityAscIdAsc(ProjectStatus status);
}
