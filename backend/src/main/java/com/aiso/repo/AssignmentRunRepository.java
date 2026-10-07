package com.aiso.repo;

import com.aiso.domain.AssignmentRun;
import org.springframework.data.domain.Pageable;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;

public interface AssignmentRunRepository extends JpaRepository<AssignmentRun, String> {
    List<AssignmentRun> findAllByOrderByCreatedAtDesc(Pageable pageable);
}
