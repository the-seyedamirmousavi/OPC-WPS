package com.aiso.repo;

import com.aiso.domain.Operation;
import com.aiso.domain.OperationStatus;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.Collection;
import java.util.List;

public interface OperationRepository extends JpaRepository<Operation, String> {
    List<Operation> findByStatus(OperationStatus status);

    List<Operation> findByAssignedUserId(String userId);

    List<Operation> findByStatusIn(Collection<OperationStatus> statuses);
}
