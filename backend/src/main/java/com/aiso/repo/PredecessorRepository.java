package com.aiso.repo;

import com.aiso.domain.Predecessor;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.Collection;
import java.util.List;

public interface PredecessorRepository extends JpaRepository<Predecessor, Long> {
    List<Predecessor> findByOperationId(String operationId);

    List<Predecessor> findByPredecessorId(String predecessorId);

    void deleteByOperationIdIn(Collection<String> operationIds);
}
